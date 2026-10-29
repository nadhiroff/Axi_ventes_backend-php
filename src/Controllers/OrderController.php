<?php
declare(strict_types=1);

namespace Axi\Controllers;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Ids;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Services\Notifier;
use Axi\Services\Payment\Escrow;
use Axi\Services\Payment\Gateways;
use Axi\Services\Settings;
use Axi\Support\Stalls;

/**
 * Panier multi-étals (gardé sur le téléphone) : devis, commande, paiement unique Mobile Money,
 * suivi, réception, litige, historique. Montants calculés par le serveur, jamais fournis par le client.
 */
final class OrderController
{
    private const RECEPTION_LABELS = ['delivery' => 'Livraison', 'bus' => 'Expédition car', 'axi_point' => 'Retrait au point Axi'];

    public function quote(Request $req): never
    {
        [$lines, $reception, $paymentMode] = $this->input($req);
        $quote = $this->compute($lines, $reception['mode'], $paymentMode);
        unset($quote['_parts'], $quote['_commissions']);
        Response::ok($quote);
    }

    /**
     * Commande et paiement, sans jamais débiter deux fois :
     * - même Idempotency-Key (en-tête ou champ idempotency_key) = même commande renvoyée ;
     * - sans clé, même panier envoyé deux fois en 15 minutes = même commande renvoyée ;
     * - verrou par client contre deux requêtes simultanées, un seul paiement par commande en base ;
     * - la commande et son paiement (référence fixe) sont enregistrés AVANT d'appeler la passerelle,
     *   qui refuse elle-même un doublon de référence.
     * Le stock des produits suivis est réservé dans la même transaction (jamais vendu deux fois) et rendu
     * si la commande est annulée avant paiement.
     * payment_mode « on_site » : le client paie en ligne les frais (réception, regroupement, service Axi)
     * et la marchandise en espèces au commerçant à la remise.
     */
    public function create(Request $req): never
    {
        [$lines, $reception, $paymentMode] = $this->input($req);
        $payment = $req->input('payment');
        $operator = is_array($payment) ? ($payment['operator'] ?? null) : null;
        if ($operator !== null && !in_array($operator, ['mtn', 'moov', 'celtiis'], true)) {
            Validator::fail(['payment.operator' => 'Choisissez MTN, Moov ou Celtiis.'], ['payment.operator' => 'Opérateur']);
        }
        $phone = null;
        if (is_array($payment) && ($payment['phone'] ?? '') !== '') {
            $phone = is_string($payment['phone']) ? Validator::phone($payment['phone']) : null;
            if ($phone === null) {
                throw new HttpException(422, 'INVALID_PHONE', 'Ce numéro Mobile Money ne semble pas valide.');
            }
        }
        $key = $req->header('idempotency-key') ?? $req->input('idempotency_key');
        if ($key !== null && (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key))) {
            Validator::fail(['idempotency_key' => '8 à 64 caractères : lettres, chiffres, - ou _.'], ['idempotency_key' => 'Clé de paiement']);
        }

        $detail = trim((string) ($reception['detail'] ?? ''));
        $pointId = null;
        if ($reception['mode'] === 'axi_point') {
            $pointId = Ids::tryDecode('pt', $detail);
            if ($pointId === null || Database::value('SELECT id FROM axi_points WHERE id = ? AND active = 1', [$pointId]) === null) {
                throw new HttpException(422, 'RECEPTION_DETAIL_REQUIRED', 'Choisissez un point Axi.');
            }
        } elseif ($detail === '') {
            throw new HttpException(422, 'RECEPTION_DETAIL_REQUIRED', $reception['mode'] === 'bus' ? 'Indiquez la ville de destination.' : 'Indiquez votre quartier et un repère.');
        }

        // Empreinte du panier : produits triés, quantités, réception et paiement
        $normalized = array_map(static fn (array $l): string => $l['public_id'] . 'x' . $l['quantity'], $lines);
        sort($normalized);
        $hash = hash('sha256', json_encode([$normalized, $reception['mode'], $detail, $operator, $phone, $paymentMode]));
        $provider = Gateways::get()->name();

        [$orderId, $paymentId, $replayed] = Database::withLock('axi_order_' . $req->userId(), 15, function () use ($req, $key, $hash, $lines, $reception, $detail, $pointId, $operator, $phone, $provider, $paymentMode): array {
            $existing = $key !== null
                ? Database::one('SELECT id, request_hash FROM orders WHERE client_id = ? AND idempotency_key = ?', [$req->userId(), $key])
                : Database::one(
                    'SELECT id, request_hash FROM orders WHERE client_id = ? AND request_hash = ? AND status = \'awaiting_payment\' AND created_at > ? ORDER BY id DESC LIMIT 1',
                    [$req->userId(), $hash, Clock::now(-15 * 60)],
                );
            if ($existing !== null) {
                if (!hash_equals((string) $existing['request_hash'], $hash)) {
                    throw new HttpException(422, 'IDEMPOTENCY_KEY_REUSED', 'Cette demande de paiement a déjà servi pour un autre panier. Réessayez.');
                }
                return [(int) $existing['id'], (int) Database::value('SELECT id FROM payments WHERE order_id = ?', [$existing['id']]), true];
            }

            $quote = $this->compute($lines, $reception['mode'], $paymentMode);
            if ($quote['unavailable_product_ids'] !== []) {
                throw new HttpException(422, 'PRODUCT_UNAVAILABLE', 'Certains produits ne sont plus disponibles ou plus en quantité suffisante. Retirez-les du panier pour continuer.', ['unavailable_product_ids' => $quote['unavailable_product_ids']]);
            }
            if ($paymentMode === 'on_site' && !$quote['pay_on_site_available']) {
                throw new HttpException(422, 'PAY_ON_SITE_UNAVAILABLE', 'Un des commerçants de votre panier n\'accepte pas le paiement sur place. Payez en ligne.');
            }
            return Database::transaction(function () use ($req, $key, $hash, $quote, $reception, $detail, $pointId, $operator, $phone, $provider, $paymentMode): array {
                $now = Clock::now();
                $orderId = Database::insert(
                    'INSERT INTO orders (client_id, idempotency_key, request_hash, items_total, grouping_fee, reception_fee, payment_mode, service_fee, total, reception_mode, reception_detail, axi_point_id, payment_operator, payment_phone, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$req->userId(), $key, $hash, $quote['items_total'], $quote['grouping_fee'], $quote['reception_fee'], $paymentMode, $quote['service_fee'], $quote['total'],
                        $reception['mode'], mb_substr($detail, 0, 255), $pointId, $operator, $phone, $now],
                );
                $commissionTotal = 0;
                foreach ($quote['_parts'] as $stallId => $items) {
                    $itemsTotal = array_sum(array_column($items, 'line_total'));
                    $commission = $quote['_commissions'][$stallId];
                    $commissionTotal += $commission;
                    // Sur place, le commerçant encaisse lui-même : rien ne lui est dû par Axi (net 0)
                    $partId = Database::insert(
                        'INSERT INTO order_stalls (order_id, stall_id, items_total, commission, net_amount, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                        [$orderId, $stallId, $itemsTotal, $commission, $paymentMode === 'on_site' ? 0 : $itemsTotal - $commission, $now],
                    );
                    foreach ($items as $item) {
                        Database::run(
                            'INSERT INTO order_items (order_stall_id, product_id, name, unit_price, quantity, line_total) VALUES (?, ?, ?, ?, ?, ?)',
                            [$partId, $item['product_id'], $item['name'], $item['unit_price'], $item['quantity'], $item['line_total']],
                        );
                        // Réservation atomique du stock : refusée si un autre client vient de prendre les dernières pièces
                        // (stock non suivi = NULL : rien à réserver)
                        $reserved = Database::run(
                            'UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?',
                            [$item['quantity'], $item['product_id'], $item['quantity']],
                        )->rowCount();
                        if ($reserved === 0 && Database::value('SELECT stock_quantity FROM products WHERE id = ?', [$item['product_id']]) !== null) {
                            throw new HttpException(422, 'PRODUCT_UNAVAILABLE', '« ' . $item['name'] . ' » n\'est plus disponible en quantité suffisante. Modifiez le panier pour continuer.', ['unavailable_product_ids' => [Ids::encode('prd', $item['product_id'])]]);
                        }
                    }
                }
                Database::run('UPDATE orders SET commission_total = ? WHERE id = ?', [$commissionTotal, $orderId]);
                // Le paiement existe avant tout appel à la passerelle ; sa référence ne changera plus
                $paymentId = Database::insert(
                    'INSERT INTO payments (purpose, order_id, user_id, provider, reference, operator, phone, amount, created_at) VALUES (\'order\', ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$orderId, $req->userId(), $provider, 'axi_cmd_' . $orderId . '_' . bin2hex(random_bytes(3)), $operator, $phone, $quote['total'], $now],
                );
                Escrow::log('order_created', ['payment' => $paymentId, 'order' => $orderId], 'client', $req->userId(), null, 'pending', null, $quote['total']);
                return [$orderId, $paymentId, false];
            });
        });

        if ($replayed) {
            header('Idempotent-Replayed: true');
        } else {
            $this->confirmationEmail($orderId);
            $this->notifySoldOut($orderId);
        }
        Escrow::ensureInitiated($paymentId);
        // Pas de vérification ici : le client n'a pas encore pu payer (il ouvre la page de paiement ensuite)
        Response::ok($this->present($orderId, false), $replayed ? 200 : 201);
    }

    public function index(Request $req): never
    {
        $rows = Database::all('SELECT id FROM orders WHERE client_id = ? ORDER BY id DESC LIMIT 100', [$req->userId()]);
        Response::ok(['orders' => array_map(fn (array $o): array => $this->present((int) $o['id'], false), $rows)]);
    }

    public function show(Request $req): never
    {
        $id = $this->ownOrder($req);
        Response::ok($this->present($id, true));
    }

    /**
     * Retour de la page de paiement : le serveur vérifie le statut AUPRÈS DE LA PASSERELLE avant de marquer
     * « payé ». Si le lien de paiement n'avait pas pu être créé (coupure), il est recréé avec la même référence.
     */
    public function verifyPayment(Request $req): never
    {
        $id = $this->ownOrder($req);
        $payment = Database::one('SELECT * FROM payments WHERE order_id = ?', [$id]);
        if ($payment['status'] === 'pending') {
            Escrow::ensureInitiated((int) $payment['id']);
            $providerRef = $req->input('transaction_id');
            Escrow::refresh((int) $payment['id'], 'client', $req->userId(), is_scalar($providerRef) && $payment['provider'] === 'kkiapay' ? (string) $providerRef : null);
        }
        Response::ok($this->present($id, true));
    }

    /** Annuler une commande pas encore payée (confirmation demandée dans l'application) */
    public function cancel(Request $req): never
    {
        $id = $this->ownOrder($req);
        $payment = Database::one('SELECT * FROM payments WHERE order_id = ?', [$id]);
        // Dernière vérification : un paiement validé entre-temps n'est jamais annulé
        Escrow::refresh((int) $payment['id'], 'client', $req->userId());
        $n = Database::run('UPDATE orders SET status = \'cancelled\', cancelled_at = ? WHERE id = ? AND status = \'awaiting_payment\'', [Clock::now(), $id])->rowCount();
        if ($n === 0) {
            throw new HttpException(409, 'ORDER_NOT_CANCELLABLE', 'Cette commande est déjà payée : signalez un litige si besoin.');
        }
        Database::run('UPDATE order_stalls SET status = \'cancelled\' WHERE order_id = ?', [$id]);
        Database::run('UPDATE payments SET status = \'cancelled\', failure_reason = \'annulée par le client\' WHERE order_id = ? AND status = \'pending\'', [$id]);
        Escrow::restoreStock($id);
        Escrow::log('order_cancelled', ['payment' => $payment['id'], 'order' => $id], 'client', $req->userId(), 'pending', 'cancelled');
        Response::ok($this->present($id, false));
    }

    /** « J'ai bien reçu » : l'argent gardé par Axi est libéré aux commerçants */
    public function confirmReceipt(Request $req): never
    {
        $id = $this->ownOrder($req);
        $status = (string) Database::value('SELECT status FROM orders WHERE id = ?', [$id]);
        if (in_array($status, ['awaiting_payment', 'cancelled'], true)) {
            throw new HttpException(409, 'ORDER_NOT_PAID', 'Cette commande n\'a pas été payée.');
        }
        if ($status === 'disputed') {
            throw new HttpException(409, 'ORDER_DISPUTED', 'Un litige est en cours : Axi tranchera avant de libérer l\'argent.');
        }
        Escrow::confirmReceipt($id, 'client', $req->userId());
        Response::ok($this->present($id, false));
    }

    /** Signaler un litige : { reason, stall_id? } ; l'argent reste bloqué jusqu'à la décision d'Axi */
    public function dispute(Request $req): never
    {
        $id = $this->ownOrder($req);
        $in = Validator::validate($req->all(), ['reason' => 'required|string|max:1000', 'stall_id' => 'string'], ['reason' => 'Motif', 'stall_id' => 'Étal']);
        $stallId = isset($in['stall_id']) ? Ids::decode('stl', $in['stall_id'], 'STALL_NOT_FOUND', 'Cet étal ne fait pas partie de la commande.') : null;
        Escrow::openDispute($id, $req->userId(), $in['reason'], $stallId);
        Notifier::send($req->userId(), 'order', 'Litige enregistré', 'Votre signalement pour la commande cmd_' . $id . ' est transmis à l\'équipe Axi. L\'argent reste bloqué jusqu\'à sa décision.', ['order_id' => 'cmd_' . $id]);
        Response::ok($this->present($id, false));
    }

    private function ownOrder(Request $req): int
    {
        $id = $req->id('cmd', 'ORDER_NOT_FOUND', 'Commande introuvable.');
        $owner = Database::value('SELECT client_id FROM orders WHERE id = ?', [$id]);
        if ($owner === null || (int) $owner !== $req->userId()) {
            throw new HttpException(404, 'ORDER_NOT_FOUND', 'Commande introuvable.');
        }
        return $id;
    }

    /** @return array{0: array, 1: array, 2: string} lignes, réception, mode de paiement (online | on_site) */
    private function input(Request $req): array
    {
        $rawLines = $req->input('lines');
        $reception = $req->input('reception');
        $paymentMode = $req->input('payment_mode', 'online');
        if (!in_array($paymentMode, ['online', 'on_site'], true)) {
            Validator::fail(['payment_mode' => 'Choisissez le paiement en ligne ou sur place.'], ['payment_mode' => 'Paiement']);
        }
        if (!is_array($rawLines) || $rawLines === [] || count($rawLines) > 100) {
            Validator::fail(['lines' => 'Le panier est vide.'], ['lines' => 'Panier']);
        }
        if (!is_array($reception) || !in_array($reception['mode'] ?? null, ['delivery', 'bus', 'axi_point'], true)) {
            Validator::fail(['reception.mode' => 'Choisissez livraison, expédition car ou point Axi.'], ['reception.mode' => 'Réception']);
        }
        $lines = [];
        foreach ($rawLines as $line) {
            $quantity = $line['quantity'] ?? null;
            if (!is_array($line) || !is_string($line['product_id'] ?? null) || !is_int($quantity) || $quantity < 1 || $quantity > 999) {
                Validator::fail(['lines' => 'Chaque ligne indique un produit et une quantité entre 1 et 999.'], ['lines' => 'Panier']);
            }
            $lines[] = ['public_id' => $line['product_id'], 'product_id' => Ids::tryDecode('prd', $line['product_id']), 'quantity' => $quantity];
        }
        return [$lines, $reception, $paymentMode];
    }

    /**
     * Devis : produits épuisés (ou stock insuffisant), supprimés ou à prix débattu signalés dans unavailable_product_ids.
     * total = montant payé maintenant en ligne ; sur place, cash_total = marchandise payée au commerçant.
     */
    private function compute(array $lines, string $mode, string $paymentMode = 'online'): array
    {
        $ids = array_values(array_filter(array_column($lines, 'product_id')));
        $products = [];
        if ($ids !== []) {
            foreach (Database::all(
                'SELECT p.* FROM products p JOIN stalls s ON s.id = p.stall_id JOIN users u ON u.id = s.user_id
                 WHERE p.id IN (' . Database::in($ids) . ') AND ' . Stalls::VISIBLE . ' AND p.in_stock = 1 AND p.price_amount IS NOT NULL
                   AND s.status = \'active\' AND u.status = \'active\'',
                $ids,
            ) as $p) {
                $products[(int) $p['id']] = $p;
            }
        }

        $unavailable = [];
        $parts = [];
        foreach ($lines as $line) {
            $p = $products[$line['product_id'] ?? 0] ?? null;
            if ($p === null || ($p['stock_quantity'] !== null && (int) $p['stock_quantity'] < $line['quantity'])) {
                $unavailable[] = $line['public_id'];
                continue;
            }
            $parts[(int) $p['stall_id']][] = [
                'product_id' => (int) $p['id'],
                'name' => $p['name'],
                'unit_price' => (int) $p['price_amount'],
                'quantity' => $line['quantity'],
                'line_total' => (int) $p['price_amount'] * $line['quantity'],
            ];
        }

        $itemsTotal = 0;
        foreach ($parts as $items) {
            $itemsTotal += array_sum(array_column($items, 'line_total'));
        }
        $stallCount = count($parts);
        $groupingFee = $stallCount > 1 ? $stallCount * Settings::int('grouping_fee_per_stall') : 0;
        $receptionFee = Settings::receptionFee($mode);
        $rate = Settings::commissionRate();
        $commissions = [];
        foreach ($parts as $stallId => $items) {
            $commissions[$stallId] = (int) round(array_sum(array_column($items, 'line_total')) * $rate);
        }
        $stallIds = array_keys($parts);
        $onSiteOk = $stallIds !== [] && (int) Database::value(
            'SELECT COUNT(*) FROM stalls WHERE id IN (' . Database::in($stallIds) . ') AND pay_on_delivery = 1',
            $stallIds,
        ) === count($stallIds);
        $onSite = $paymentMode === 'on_site';
        // Sur place : la commission Axi devient des « frais de service » payés en ligne par le client
        $serviceFee = $onSite ? array_sum($commissions) : 0;

        return [
            'stall_count' => $stallCount,
            'items_total' => $itemsTotal,
            'grouping_fee' => $groupingFee,
            'reception_fee' => $receptionFee,
            'reception_fee_label' => self::RECEPTION_LABELS[$mode],
            'payment_mode' => $paymentMode,
            'pay_on_site_available' => $onSiteOk,
            'service_fee' => $serviceFee,
            'cash_total' => $onSite ? $itemsTotal : 0,
            'total' => ($onSite ? 0 : $itemsTotal) + $groupingFee + $receptionFee + $serviceFee,
            'axi_points' => array_map(static fn (array $pt): array => [
                'id' => Ids::encode('pt', $pt['id']),
                'name' => $pt['name'],
                'address' => $pt['address'],
            ], Database::all('SELECT * FROM axi_points WHERE active = 1 ORDER BY sort_order, id')),
            'unavailable_product_ids' => array_values(array_unique($unavailable)),
            '_parts' => $parts,
            '_commissions' => $commissions,
        ];
    }

    /** Objet Order du contrat, enrichi : paiement, détail par étal, actions possibles, litige */
    private function present(int $orderId, bool $refresh): array
    {
        $payment = Database::one('SELECT * FROM payments WHERE order_id = ?', [$orderId]);
        if ($refresh && $payment !== null && $payment['status'] === 'pending' && $payment['provider_ref'] !== null) {
            // Statut toujours vérifié auprès de la passerelle tant que la commande attend son paiement
            $payment = Escrow::refresh((int) $payment['id']);
        }
        $order = Database::one('SELECT o.*, p.name AS point_name FROM orders o LEFT JOIN axi_points p ON p.id = o.axi_point_id WHERE o.id = ?', [$orderId]);
        $parts = Database::all(
            'SELECT os.*, s.name, s.phone AS stall_phone, s.whatsapp AS stall_whatsapp FROM order_stalls os JOIN stalls s ON s.id = os.stall_id WHERE os.order_id = ? ORDER BY os.id',
            [$orderId],
        );
        $dispute = Database::one('SELECT * FROM disputes WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$orderId]);
        $awaiting = $order['status'] === 'awaiting_payment';
        $sent = $payment !== null && $payment['payment_url'] !== null;

        return [
            'id' => Ids::encode('cmd', $order['id']),
            'status' => $order['status'],
            'status_label' => Escrow::ORDER_LABELS[$order['status']],
            'total' => (int) $order['total'],
            'items_total' => (int) $order['items_total'],
            'grouping_fee' => (int) $order['grouping_fee'],
            'reception_fee' => (int) $order['reception_fee'],
            'payment_mode' => $order['payment_mode'],
            'service_fee' => (int) $order['service_fee'],
            'cash_total' => $order['payment_mode'] === 'on_site' ? (int) $order['items_total'] : 0,
            'reception' => [
                'mode' => $order['reception_mode'],
                'label' => self::RECEPTION_LABELS[$order['reception_mode']],
                'detail' => $order['reception_mode'] === 'axi_point' ? (string) $order['point_name'] : $order['reception_detail'],
            ],
            'payment_instructions' => !$awaiting ? null
                : ($sent ? ($order['payment_mode'] === 'on_site'
                        ? 'Payez maintenant les frais en Mobile Money (' . Escrow::fcfa((int) $order['total']) . '). La marchandise (' . Escrow::fcfa((int) $order['items_total']) . ') se paie en espèces au commerçant à la remise.'
                        : 'Ouvrez la page de paiement et validez avec votre compte Mobile Money. Vous ne serez débité qu\'une seule fois.')
                    : 'La demande de paiement n\'a pas pu partir. Réessayez : vous ne serez débité qu\'une seule fois.'),
            'payment' => $payment === null ? null : [
                'reference' => $payment['reference'],
                'provider' => $payment['provider'],
                'provider_ref' => $payment['provider_ref'],
                'status' => $payment['status'],
                'payment_url' => $awaiting ? $payment['payment_url'] : null,
                'return_url_prefix' => \Axi\Core\Url::to('/v1/payments/return'),
                'refunded_amount' => (int) $payment['refunded_amount'],
                'paid_at' => Clock::iso($payment['paid_at']),
            ],
            'stalls' => array_map(static function (array $p): array {
                $items = Database::all('SELECT name, quantity, unit_price, line_total FROM order_items WHERE order_stall_id = ?', [$p['id']]);
                return [
                    'id' => Ids::encode('stl', $p['stall_id']),
                    'name' => $p['name'],
                    'status' => $p['status'],
                    'status_label' => Escrow::STALL_LABELS[$p['status']],
                    'escrow_state' => $p['escrow_state'],
                    'items_total' => (int) $p['items_total'],
                    'refunded_amount' => (int) $p['refunded_amount'],
                    'phone' => $p['stall_phone'],
                    'whatsapp' => $p['stall_whatsapp'],
                    'items' => array_map(static fn (array $i): array => ['name' => $i['name'], 'quantity' => (int) $i['quantity'], 'unit_price' => (int) $i['unit_price'], 'line_total' => (int) $i['line_total']], $items),
                ];
            }, $parts),
            'can_cancel' => $awaiting,
            'can_confirm_receipt' => in_array($order['status'], ['paid', 'preparing', 'on_the_way'], true),
            'can_dispute' => in_array($order['status'], ['paid', 'preparing', 'on_the_way'], true),
            'dispute' => $dispute === null ? null : [
                'status' => $dispute['status'],
                'status_label' => ['open' => 'En cours d\'examen', 'resolved_refund' => 'Remboursement décidé', 'resolved_release' => 'Commerçants payés', 'rejected' => 'Rejeté'][$dispute['status']],
                'reason' => $dispute['reason'],
                'resolution' => $dispute['resolution'],
            ],
            'created_at' => Clock::iso($order['created_at']),
            'paid_at' => Clock::iso($order['paid_at']),
            'delivered_at' => Clock::iso($order['delivered_at']),
        ];
    }

    /** Email « confirmation de commande » avec le détail et le montant à payer */
    private function confirmationEmail(int $orderId): void
    {
        $order = Database::one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        $rows = [];
        foreach (Database::all('SELECT os.items_total, s.name FROM order_stalls os JOIN stalls s ON s.id = os.stall_id WHERE os.order_id = ?', [$orderId]) as $p) {
            $rows[] = [$p['name'], Escrow::fcfa((int) $p['items_total'])];
        }
        if ((int) $order['grouping_fee'] > 0) {
            $rows[] = ['Regroupement', Escrow::fcfa((int) $order['grouping_fee'])];
        }
        $rows[] = [self::RECEPTION_LABELS[$order['reception_mode']], Escrow::fcfa((int) $order['reception_fee'])];
        if ($order['payment_mode'] === 'on_site') {
            $rows[] = ['Frais de service Axi', Escrow::fcfa((int) $order['service_fee'])];
            $rows[] = ['À payer maintenant en Mobile Money', Escrow::fcfa((int) $order['total'])];
            $rows[] = ['À payer en espèces au commerçant', Escrow::fcfa((int) $order['items_total'])];
        } else {
            $rows[] = ['Total à payer', Escrow::fcfa((int) $order['total'])];
        }
        Notifier::send((int) $order['client_id'], 'order', 'Commande cmd_' . $orderId . ' enregistrée', 'Votre commande est enregistrée. Payez-la en Mobile Money pour que vos commerçants la préparent.', ['order_id' => 'cmd_' . $orderId], [
            ['Votre commande est enregistrée. Payez-la une seule fois en Mobile Money : l\'argent reste chez Axi jusqu\'à ce que vous confirmiez la réception.'],
            ['rows' => $rows],
        ]);
    }

    /** Prévient le commerçant quand une commande vient d'épuiser un produit (il peut le réapprovisionner) */
    private function notifySoldOut(int $orderId): void
    {
        $rows = Database::all(
            'SELECT DISTINCT p.name, s.user_id FROM order_items i JOIN order_stalls os ON os.id = i.order_stall_id
             JOIN products p ON p.id = i.product_id JOIN stalls s ON s.id = p.stall_id
             WHERE os.order_id = ? AND p.stock_quantity = 0',
            [$orderId],
        );
        foreach ($rows as $r) {
            Notifier::send((int) $r['user_id'], 'order', 'Stock épuisé : ' . $r['name'], '« ' . $r['name'] . ' » n\'est plus visible des clients. Indiquez votre nouveau stock dans « Mon étal » pour le remettre en vente.', []);
        }
    }
}
