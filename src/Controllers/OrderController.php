<?php
declare(strict_types=1);

namespace Axi\Controllers;

use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Ids;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Services\Orders;
use Axi\Services\Payment\Payments;

/** Panier multi-étals (gardé sur le téléphone) : devis, commande, suivi. Les prix font foi côté serveur. */
final class OrderController
{
    private const STATUS_LABELS = [
        'awaiting_payment' => 'En attente de votre validation Mobile Money',
        'paid' => 'Paiement reçu, gardé par Axi jusqu\'à la réception',
        'preparing' => 'Vos commerçants préparent la commande',
        'on_the_way' => 'Commande en route',
        'delivered' => 'Commande reçue',
        'cancelled' => 'Commande annulée',
    ];

    public function quote(Request $req): never
    {
        [$lines, $reception] = $this->input($req);
        $quote = $this->compute($lines, $reception['mode']);
        unset($quote['_parts']);
        Response::ok($quote);
    }

    /**
     * Commande et demande de paiement Mobile Money, sans jamais débiter deux fois :
     * - même Idempotency-Key (en-tête ou champ idempotency_key) = même commande renvoyée ;
     * - sans clé, même panier envoyé deux fois en 15 minutes = même commande renvoyée ;
     * - verrou par client contre deux requêtes simultanées, un seul paiement par commande en base ;
     * - la commande est enregistrée avant d'appeler le prestataire, avec une référence fixe.
     */
    public function create(Request $req): never
    {
        [$lines, $reception] = $this->input($req);
        $payment = $req->input('payment');
        $operator = is_array($payment) ? ($payment['operator'] ?? null) : null;
        if (!in_array($operator, ['mtn', 'moov', 'celtiis'], true)) {
            Validator::fail(['payment.operator' => 'Choisissez MTN, Moov ou Celtiis.'], ['payment.operator' => 'Opérateur']);
        }
        $phone = is_string($payment['phone'] ?? null) ? Validator::phone($payment['phone']) : null;
        if ($phone === null) {
            throw new HttpException(422, 'INVALID_PHONE', 'Ce numéro Mobile Money ne semble pas valide.');
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

        // Empreinte du panier : produits triés, quantités, réception et téléphone de paiement
        $normalized = array_map(static fn (array $l): string => $l['public_id'] . 'x' . $l['quantity'], $lines);
        sort($normalized);
        $hash = hash('sha256', json_encode([$normalized, $reception['mode'], $detail, $operator, $phone]));
        $provider = Payments::provider();

        [$orderId, $replayed] = Database::withLock('axi_order_' . $req->userId(), 15, function () use ($req, $key, $hash, $lines, $reception, $detail, $pointId, $operator, $phone, $provider): array {
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
                return [(int) $existing['id'], true];
            }

            $quote = $this->compute($lines, $reception['mode']);
            if ($quote['unavailable_product_ids'] !== []) {
                throw new HttpException(422, 'PRODUCT_UNAVAILABLE', 'Certains produits ne sont plus disponibles. Retirez-les du panier pour continuer.', ['unavailable_product_ids' => $quote['unavailable_product_ids']]);
            }
            $orderId = Database::transaction(function () use ($req, $key, $hash, $quote, $reception, $detail, $pointId, $operator, $phone, $provider): int {
                $now = Clock::now();
                $orderId = Database::insert(
                    'INSERT INTO orders (client_id, idempotency_key, request_hash, items_total, grouping_fee, reception_fee, total, reception_mode, reception_detail, axi_point_id, payment_operator, payment_phone, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$req->userId(), $key, $hash, $quote['items_total'], $quote['grouping_fee'], $quote['reception_fee'], $quote['total'],
                        $reception['mode'], mb_substr($detail, 0, 255), $pointId, $operator, $phone, $now],
                );
                foreach ($quote['_parts'] as $stallId => $items) {
                    $partId = Database::insert(
                        'INSERT INTO order_stalls (order_id, stall_id, items_total, created_at) VALUES (?, ?, ?, ?)',
                        [$orderId, $stallId, array_sum(array_column($items, 'line_total')), $now],
                    );
                    foreach ($items as $item) {
                        Database::run(
                            'INSERT INTO order_items (order_stall_id, product_id, name, unit_price, quantity, line_total) VALUES (?, ?, ?, ?, ?, ?)',
                            [$partId, $item['product_id'], $item['name'], $item['unit_price'], $item['quantity'], $item['line_total']],
                        );
                    }
                }
                // Le paiement existe avant tout appel au prestataire ; sa référence ne changera plus
                Database::run(
                    'INSERT INTO payments (order_id, provider, reference, operator, phone, amount, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$orderId, $provider->name(), 'axi_pay_' . $orderId, $operator, $phone, $quote['total'], $now],
                );
                return $orderId;
            });
            return [$orderId, false];
        });

        if ($replayed) {
            header('Idempotent-Replayed: true');
        }
        $instructions = Payments::ensureInitiated($orderId);
        Response::ok($this->present($orderId, $instructions), $replayed ? 200 : 201);
    }

    /** Le client confirme avoir reçu sa commande : l'argent gardé par Axi est libéré aux commerçants */
    public function confirmReceipt(Request $req): never
    {
        $id = $this->ownOrder($req);
        $status = (string) Database::value('SELECT status FROM orders WHERE id = ?', [$id]);
        if (in_array($status, ['awaiting_payment', 'cancelled'], true)) {
            throw new HttpException(409, 'ORDER_NOT_PAID', 'Cette commande n\'a pas été payée.');
        }
        Payments::release($id);
        Response::ok($this->present($id));
    }

    public function show(Request $req): never
    {
        $id = $this->ownOrder($req);
        Payments::refresh($id);
        Response::ok($this->present($id));
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

    /** @return array{0: array<int, array{product_id: int, quantity: int, public_id: string}>, 1: array} */
    private function input(Request $req): array
    {
        $rawLines = $req->input('lines');
        $reception = $req->input('reception');
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
        return [$lines, $reception];
    }

    /** Devis : produits épuisés, supprimés ou à prix débattu signalés dans unavailable_product_ids */
    private function compute(array $lines, string $mode): array
    {
        $ids = array_values(array_filter(array_column($lines, 'product_id')));
        $products = [];
        if ($ids !== []) {
            foreach (Database::all(
                'SELECT p.* FROM products p JOIN stalls s ON s.id = p.stall_id
                 WHERE p.id IN (' . Database::in($ids) . ') AND p.status = \'active\' AND p.in_stock = 1 AND p.price_amount IS NOT NULL AND s.status = \'active\'',
                $ids,
            ) as $p) {
                $products[(int) $p['id']] = $p;
            }
        }

        $unavailable = [];
        $parts = [];
        foreach ($lines as $line) {
            $p = $products[$line['product_id'] ?? 0] ?? null;
            if ($p === null) {
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
        $groupingFee = $stallCount > 1 ? $stallCount * (int) Config::get('pricing.grouping_fee_per_stall') : 0;
        $reception = Config::get("pricing.reception.$mode");

        return [
            'stall_count' => $stallCount,
            'items_total' => $itemsTotal,
            'grouping_fee' => $groupingFee,
            'reception_fee' => (int) $reception['fee'],
            'reception_fee_label' => $reception['label'],
            'total' => $itemsTotal + $groupingFee + (int) $reception['fee'],
            'axi_points' => array_map(static fn (array $pt): array => [
                'id' => Ids::encode('pt', $pt['id']),
                'name' => $pt['name'],
                'address' => $pt['address'],
            ], Database::all('SELECT * FROM axi_points WHERE active = 1 ORDER BY sort_order, id')),
            'unavailable_product_ids' => array_values(array_unique($unavailable)),
            '_parts' => $parts,
        ];
    }

    private function present(int $orderId, ?string $instructions = null): array
    {
        $order = Database::one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        $sent = Database::value('SELECT provider_ref FROM payments WHERE order_id = ?', [$orderId]) !== null;
        $stalls = Database::all(
            'SELECT os.status, s.id, s.name FROM order_stalls os JOIN stalls s ON s.id = os.stall_id WHERE os.order_id = ? ORDER BY os.id',
            [$orderId],
        );
        return [
            'id' => Ids::encode('cmd', $order['id']),
            'status' => $order['status'],
            'status_label' => self::STATUS_LABELS[$order['status']],
            'total' => (int) $order['total'],
            'payment_instructions' => $order['status'] !== 'awaiting_payment' ? null
                : ($sent ? ($instructions ?? Payments::INSTRUCTIONS) : 'La demande de paiement n\'a pas pu partir. Réessayez : vous ne serez débité qu\'une seule fois.'),
            'stalls' => array_map(static fn (array $s): array => [
                'id' => Ids::encode('stl', $s['id']),
                'name' => $s['name'],
                'status_label' => Orders::STALL_LABELS[$s['status']],
            ], $stalls),
            'created_at' => Clock::iso($order['created_at']),
        ];
    }
}
