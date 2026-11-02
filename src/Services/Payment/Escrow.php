<?php
declare(strict_types=1);

namespace Axi\Services\Payment;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Logger;
use Axi\Core\Url;
use Axi\Services\Notifier;
use Axi\Services\Settings;

/**
 * Séquestre géré par Axi (les passerelles n'en fournissent pas). Un paiement client par panier,
 * réparti en lignes internes par étal (order_stalls) :
 *   pending → held (payé, bloqué) → released (libéré au commerçant) | refunded | disputed
 * Chaque changement d'état est écrit dans payment_events : qui, quand, avant, après, référence passerelle.
 */
final class Escrow
{
    public const ORDER_LABELS = [
        'awaiting_payment' => 'En attente de votre paiement Mobile Money',
        'paid' => 'Paiement reçu, gardé par Axi jusqu\'à la réception',
        'preparing' => 'Vos commerçants préparent la commande',
        'on_the_way' => 'Commande en route',
        'delivered' => 'Commande reçue, commerçants payés',
        'disputed' => 'Litige en cours d\'examen par Axi',
        'refunded' => 'Commande remboursée',
        'cancelled' => 'Commande annulée',
    ];

    public const STALL_LABELS = [
        'awaiting_payment' => 'En attente du paiement',
        'to_prepare' => 'Prépare votre commande',
        'ready' => 'Prête pour le coursier',
        'picked_up' => 'Remise au coursier',
        'delivered' => 'Livrée',
        'cancelled' => 'Annulée',
    ];

    /** Journal d'audit */
    public static function log(string $event, array $ids, string $actorType, ?int $actorId, ?string $old, ?string $new, ?string $gatewayRef = null, ?int $amount = null, array $details = []): void
    {
        Database::run(
            'INSERT INTO payment_events (payment_id, order_id, order_stall_id, actor_type, actor_id, event, old_state, new_state, gateway_ref, amount, details, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$ids['payment'] ?? null, $ids['order'] ?? null, $ids['stall_part'] ?? null, $actorType, $actorId, $event, $old, $new, $gatewayRef, $amount,
                $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE), Clock::now()],
        );
    }

    /**
     * Crée la transaction chez la passerelle si ce n'est pas déjà fait (référence fixe : jamais deux débits).
     * @return bool false si la passerelle est injoignable (la commande reste en attente, un nouvel essai reprendra)
     */
    public static function ensureInitiated(int $paymentId): bool
    {
        $payment = Database::one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        if ($payment === null || $payment['status'] !== 'pending') {
            return false;
        }
        if ($payment['payment_url'] !== null) {
            return true;
        }
        $customer = Database::one('SELECT first_name, last_name, email FROM users WHERE id = ?', [$payment['user_id']]);
        try {
            $created = Gateways::get($payment['provider'])->create($payment, $customer, Url::to('/v1/payments/return?ref=' . $payment['reference']));
        } catch (GatewayException $e) {
            Logger::write('payments', "ÉCHEC de création {$payment['reference']} : " . $e->getMessage());
            self::log('gateway_error', ['payment' => $paymentId, 'order' => $payment['order_id']], 'system', null, 'pending', 'pending', null, null, ['error' => mb_substr($e->getMessage(), 0, 200)]);
            return false;
        }
        Database::run(
            'UPDATE payments SET provider_ref = COALESCE(provider_ref, ?), payment_url = ? WHERE id = ? AND payment_url IS NULL',
            [$created['provider_ref'], $created['payment_url'], $paymentId],
        );
        self::log('gateway_created', ['payment' => $paymentId, 'order' => $payment['order_id']], 'system', null, 'pending', 'pending', $created['provider_ref'], (int) $payment['amount']);
        return true;
    }

    /** Paiement clos sans encaissement : la passerelle peut encore annoncer un paiement tardif */
    public const CLOSED = ['failed', 'cancelled', 'expired'];

    /**
     * Vérifie le paiement auprès de la passerelle (serveur à serveur) et applique le résultat.
     * $providerRef : identifiant de transaction KkiaPay. Venu de la page de paiement ($trustedRef = false), c'est une
     * simple annonce : retenue pour ne pas rouvrir le paiement, vérifiée, et jamais utilisée pour conclure à un échec.
     */
    public static function refresh(int $paymentId, string $actorType = 'system', ?int $actorId = null, ?string $providerRef = null): array
    {
        return self::check($paymentId, $actorType, $actorId, $providerRef)['payment'];
    }

    /**
     * Comme refresh, et dit si la passerelle a effectivement répondu (reached = false : panne ou réponse illisible,
     * rien n'a été conclu). Un paiement annulé ou expiré est revérifié aussi : s'il a été payé entre-temps, il est
     * traité comme un paiement tardif, jamais ignoré.
     * @return array{payment: array, reached: bool}
     */
    public static function check(int $paymentId, string $actorType = 'system', ?int $actorId = null, ?string $providerRef = null, bool $trustedRef = false): array
    {
        $payment = Database::one('SELECT * FROM payments WHERE id = ?', [$paymentId]) ?? throw new HttpException(404, 'NOT_FOUND', 'Paiement introuvable.');
        $pending = $payment['status'] === 'pending';
        if (!$pending && !in_array($payment['status'], self::CLOSED, true)) {
            return ['payment' => $payment, 'reached' => true];
        }
        if ($providerRef !== null && ($providerRef === '' || strlen($providerRef) > 80)) {
            $providerRef = null;
        }
        // Transaction annoncée par la page de paiement : retenue tout de suite, pour que la page ne rouvre pas
        // le paiement pendant la vérification (un deuxième débit serait possible chez KkiaPay)
        if ($pending && $providerRef !== null && !$trustedRef && $payment['provider_ref'] === null && $payment['claimed_provider_ref'] === null) {
            Database::run('UPDATE payments SET claimed_provider_ref = ? WHERE id = ? AND claimed_provider_ref IS NULL', [$providerRef, $paymentId]);
            $payment['claimed_provider_ref'] = $providerRef;
        }
        $probe = $payment;
        $probe['provider_ref'] ??= $providerRef ?? $payment['claimed_provider_ref'];
        try {
            $status = Gateways::get($payment['provider'])->status($probe);
        } catch (GatewayException $e) {
            Logger::write('payments', "Statut indisponible {$payment['reference']} : " . $e->getMessage());
            return ['payment' => $payment, 'reached' => false];
        }
        Database::run('UPDATE payments SET gateway_status = ?, checked_at = ? WHERE id = ?', [mb_substr($status['raw'], 0, 40), Clock::now(), $paymentId]);
        $txRef = (string) ($status['provider_ref'] ?? $probe['provider_ref'] ?? '');

        if ($status['state'] === 'paid') {
            // Le montant payé doit être annoncé et exactement celui calculé par le serveur
            if ($status['amount'] === null || $status['amount'] !== (int) $payment['amount']) {
                self::log('amount_mismatch', ['payment' => $paymentId, 'order' => $payment['order_id']], $actorType, $actorId, $payment['status'], $payment['status'], $txRef, $status['amount']);
                Logger::write('payments', "MONTANT ABSENT OU DIFFÉRENT {$payment['reference']} : attendu {$payment['amount']}, reçu " . var_export($status['amount'], true));
                return ['payment' => $payment, 'reached' => true];
            }
            if ($pending) {
                self::markPaid($payment, $txRef, $actorType, $actorId);
            } else {
                self::handleLatePayment($payment, $txRef, $actorType, $actorId);
            }
        } elseif ($pending && in_array($status['state'], self::CLOSED, true)) {
            if ($payment['provider'] === 'kkiapay') {
                // KkiaPay : un essai refusé n'empêche pas d'en refaire un autre dans la même page ; seule
                // l'annonce invalide est oubliée (le paiement redevient possible), l'expiration fera le reste
                Database::run('UPDATE payments SET claimed_provider_ref = NULL WHERE id = ? AND claimed_provider_ref = ?', [$paymentId, $probe['provider_ref']]);
            } else {
                self::markFailed($payment, $status['state'], $status['raw'], $actorType, $actorId);
            }
        }
        return ['payment' => Database::one('SELECT * FROM payments WHERE id = ?', [$paymentId]), 'reached' => true];
    }

    /**
     * Paiement confirmé par la passerelle APRÈS l'annulation ou l'expiration : l'argent a été pris, il n'est jamais
     * ignoré. Abonnement : activé. Commande : réactivée si le stock est encore là, sinon litige automatique
     * (argent bloqué chez Axi, un agent rembourse) ; le client et les agents Axi sont prévenus.
     */
    private static function handleLatePayment(array $payment, string $providerRef, string $actorType, ?int $actorId): void
    {
        $closed = "status IN ('failed', 'cancelled', 'expired')";
        $ids = ['payment' => $payment['id'], 'order' => $payment['order_id']];
        Logger::write('payments', "PAIEMENT TARDIF {$payment['reference']} ({$payment['status']}) transaction $providerRef");

        if ($payment['purpose'] === 'subscription') {
            $reopened = Database::transaction(static function () use ($payment, $closed): bool {
                $n = Database::run("UPDATE payments SET status = 'pending', late_paid_at = ? WHERE id = ? AND $closed", [Clock::now(), $payment['id']])->rowCount();
                Database::run("UPDATE subscriptions SET status = 'pending' WHERE id = ? AND status = 'cancelled'", [$payment['subscription_id']]);
                return $n > 0;
            });
            if ($reopened) {
                self::log('payment_late', $ids, $actorType, $actorId, $payment['status'], 'pending', $providerRef, (int) $payment['amount']);
                self::markPaid(Database::one('SELECT * FROM payments WHERE id = ?', [$payment['id']]), $providerRef, $actorType, $actorId);
            }
            return;
        }

        // 1. Réactivation : le stock rendu à l'annulation est repris (tout ou rien)
        try {
            $reactivated = Database::transaction(static function () use ($payment, $closed): bool {
                $n = Database::run("UPDATE payments SET late_paid_at = ? WHERE id = ? AND $closed AND late_paid_at IS NULL", [Clock::now(), $payment['id']])->rowCount();
                $order = Database::one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$payment['order_id']]);
                if ($n === 0 || $order === null || $order['status'] !== 'cancelled') {
                    return false;
                }
                if ($order['stock_restored_at'] !== null) {
                    $items = Database::all(
                        'SELECT i.product_id, SUM(i.quantity) AS qty FROM order_items i JOIN order_stalls os ON os.id = i.order_stall_id
                         WHERE os.order_id = ? GROUP BY i.product_id ORDER BY i.product_id',
                        [$order['id']],
                    );
                    foreach ($items as $item) {
                        $taken = Database::run('UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?', [$item['qty'], $item['product_id'], $item['qty']])->rowCount();
                        if ($taken === 0 && Database::value('SELECT stock_quantity FROM products WHERE id = ?', [$item['product_id']]) !== null) {
                            throw new StockGone();
                        }
                    }
                }
                Database::run('UPDATE orders SET status = \'awaiting_payment\', cancelled_at = NULL, stock_restored_at = NULL WHERE id = ?', [$order['id']]);
                Database::run('UPDATE order_stalls SET status = \'awaiting_payment\' WHERE order_id = ? AND escrow_state = \'pending\'', [$order['id']]);
                Database::run("UPDATE payments SET status = 'pending', failure_reason = NULL WHERE id = ?", [$payment['id']]);
                return true;
            });
        } catch (StockGone) {
            $reactivated = null;
        }
        if ($reactivated === true) {
            self::log('payment_late_reactivated', $ids, $actorType, $actorId, $payment['status'], 'pending', $providerRef, (int) $payment['amount']);
            self::markPaid(Database::one('SELECT * FROM payments WHERE id = ?', [$payment['id']]), $providerRef, $actorType, $actorId);
            Notifier::send((int) $payment['user_id'], 'payment', 'Commande réactivée', 'Votre paiement de la commande n° ' . $payment['order_id'] . ' est arrivé après son annulation. La commande est réactivée : vos commerçants la préparent.', ['order_id' => 'cmd_' . $payment['order_id']]);
            return;
        }
        if ($reactivated === false) {
            return; // déjà traité par un autre chemin (webhook et réconciliation en même temps)
        }

        // 2. Stock parti entre-temps : l'argent est bloqué chez Axi et un litige est ouvert pour le rembourser
        $disputeId = Database::transaction(static function () use ($payment, $providerRef, $closed): ?int {
            $now = Clock::now();
            $n = Database::run(
                "UPDATE payments SET status = 'paid', paid_at = ?, late_paid_at = COALESCE(late_paid_at, ?), provider_ref = COALESCE(provider_ref, ?) WHERE id = ? AND $closed",
                [$now, $now, $providerRef, $payment['id']],
            )->rowCount();
            if ($n === 0) {
                return null;
            }
            $order = Database::one('SELECT client_id FROM orders WHERE id = ?', [$payment['order_id']]);
            Database::run('UPDATE orders SET status = \'disputed\', paid_at = ? WHERE id = ?', [$now, $payment['order_id']]);
            $id = Database::insert(
                'INSERT INTO disputes (order_id, order_stall_id, client_id, reason, created_at) VALUES (?, NULL, ?, ?, ?)',
                [$payment['order_id'], $order['client_id'], 'Automatique : paiement Mobile Money reçu après l\'annulation de la commande, produits plus disponibles. À rembourser.', $now],
            );
            foreach (Database::all('SELECT * FROM order_stalls WHERE order_id = ? AND escrow_state = \'pending\'', [$payment['order_id']]) as $part) {
                Database::run('UPDATE order_stalls SET escrow_state = \'disputed\' WHERE id = ?', [$part['id']]);
                self::log('escrow_disputed', ['payment' => $payment['id'], 'order' => $payment['order_id'], 'stall_part' => $part['id']], 'system', null, 'pending', 'disputed', $providerRef, (int) $part['net_amount'], ['dispute_id' => $id]);
            }
            self::log('payment_late_disputed', ['payment' => $payment['id'], 'order' => $payment['order_id']], 'system', null, $payment['status'], 'paid', $providerRef, (int) $payment['amount'], ['dispute_id' => $id]);
            return $id;
        });
        if ($disputeId === null) {
            return;
        }
        Notifier::send((int) $payment['user_id'], 'payment', 'Paiement reçu après l\'annulation', 'Nous avons reçu ' . self::fcfa((int) $payment['amount']) . ' pour la commande n° ' . $payment['order_id'] . ' après son annulation, et les produits ne sont plus disponibles. L\'argent est gardé par Axi : vous allez être remboursé. Ne payez pas une seconde fois.', ['order_id' => 'cmd_' . $payment['order_id']], [], true);
        self::alertAgents('Paiement tardif à rembourser', 'Commande n° ' . $payment['order_id'] . ' : ' . self::fcfa((int) $payment['amount']) . ' reçus après l\'annulation (référence ' . $payment['reference'] . '). Litige ouvert automatiquement : remboursez depuis Litiges.');
    }

    /**
     * Transaction réussie EN PLUS de celle déjà retenue pour la même référence (KkiaPay : page rouverte puis payée
     * deux fois). Vérifiée auprès de la passerelle, remboursée en totalité, enregistrée, client et agents prévenus.
     * @return string ce qui a été fait (journal du webhook)
     */
    public static function handleExtraTransaction(array $payment, string $txRef): string
    {
        if ($txRef === (string) $payment['provider_ref']
            || Database::value('SELECT 1 FROM payment_duplicates WHERE provider = ? AND provider_ref = ?', [$payment['provider'], $txRef]) !== null) {
            return 'already_handled';
        }
        if (Database::value('SELECT 1 FROM payments WHERE provider = ? AND provider_ref = ?', [$payment['provider'], $txRef]) !== null) {
            return 'belongs_to_other_payment';
        }
        $gateway = Gateways::get($payment['provider']);
        $probe = ['provider_ref' => $txRef, 'refunded_amount' => 0] + $payment;
        $status = $gateway->status($probe); // GatewayException : le webhook sera rejoué
        if ($status['state'] !== 'paid') {
            return 'not_paid';
        }
        $probe['amount'] = $status['amount'] ?? (int) $payment['amount'];
        $refund = $gateway->refund($probe, (int) $probe['amount']);
        $inserted = Database::run(
            'INSERT IGNORE INTO payment_duplicates (payment_id, provider, provider_ref, amount, refund_status, refund_message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$payment['id'], $payment['provider'], $txRef, $probe['amount'], $refund['status'], mb_substr($refund['message'], 0, 255), Clock::now()],
        )->rowCount();
        if ($inserted === 0) {
            return 'already_handled';
        }
        self::log('duplicate_' . $refund['status'], ['payment' => $payment['id'], 'order' => $payment['order_id']], 'system', null, $payment['status'], $payment['status'], $txRef, (int) $probe['amount'], ['message' => $refund['message']]);
        Logger::write('payments', "DOUBLE PAIEMENT {$payment['reference']} transaction $txRef : remboursement {$refund['status']}");
        $done = in_array($refund['status'], ['succeeded', 'simulated'], true);
        Notifier::send((int) $payment['user_id'], 'payment', 'Paiement en double', 'Vous avez payé deux fois ' . self::fcfa((int) $probe['amount']) . ' pour la même ' . ($payment['order_id'] !== null ? 'commande n° ' . $payment['order_id'] : 'demande') . '. ' . ($done ? 'Le second paiement vous est remboursé.' : 'Le second paiement va vous être remboursé par l\'équipe Axi.'), $payment['order_id'] !== null ? ['order_id' => 'cmd_' . $payment['order_id']] : [], [], true);
        if (!$done) {
            self::alertAgents('Paiement en double à rembourser', 'Référence ' . $payment['reference'] . ', transaction ' . $txRef . ' (' . self::fcfa((int) $probe['amount']) . ') : ' . $refund['message']);
        }
        return 'refund_' . $refund['status'];
    }

    /** Alerte aux agents Axi actifs (application et email, même si l'email est désactivé) */
    public static function alertAgents(string $title, string $text): void
    {
        $agents = Database::all(
            "SELECT u.id FROM users u JOIN organizations o ON o.id = u.organization_id WHERE u.role = 'partner' AND o.type = 'axi' AND u.status = 'active'",
        );
        foreach ($agents as $agent) {
            Notifier::send((int) $agent['id'], 'payment', $title, $text, [], [], true);
        }
        Logger::write('payments', "ALERTE AGENTS : $title : $text");
    }

    /**
     * Réconciliation (worker chaque minute, tâche planifiée) : paiements en attente revérifiés, les plus anciens
     * vérifiés d'abord, de moins en moins souvent avec l'âge (1 min, puis 5 min, puis 30 min). Les paiements
     * FedaPay annulés ou expirés depuis moins de 24 h sont revérifiés toutes les 30 min (paiement tardif).
     * @return int nombre de paiements vérifiés
     */
    public static function reconcile(int $limit): int
    {
        $ids = array_column(Database::all(
            "SELECT id FROM payments
             WHERE ((status = 'pending' AND (payment_url IS NOT NULL OR claimed_provider_ref IS NOT NULL) AND created_at > ?
                     AND (checked_at IS NULL OR checked_at < CASE WHEN created_at > ? THEN ? WHEN created_at > ? THEN ? ELSE ? END))
                 OR (status IN ('cancelled', 'expired') AND provider = 'fedapay' AND provider_ref IS NOT NULL AND created_at > ? AND (checked_at IS NULL OR checked_at < ?)))
             ORDER BY checked_at IS NOT NULL, checked_at, id LIMIT " . max(1, $limit),
            [Clock::now(-48 * 3600), Clock::now(-900), Clock::now(-60), Clock::now(-7200), Clock::now(-300), Clock::now(-1800), Clock::now(-24 * 3600), Clock::now(-1800)],
        ), 'id');
        foreach ($ids as $id) {
            self::check((int) $id);
        }
        return count($ids);
    }

    private static function markPaid(array $payment, string $providerRef, string $actorType, ?int $actorId): void
    {
        $paid = Database::transaction(static function () use ($payment, $providerRef, $actorType, $actorId): bool {
            $now = Clock::now();
            // UPDATE conditionnel : une confirmation reçue deux fois (webhook + vérification) n'a d'effet qu'une fois
            $n = Database::run(
                'UPDATE payments SET status = \'paid\', paid_at = ?, provider_ref = COALESCE(provider_ref, ?) WHERE id = ? AND status = \'pending\'',
                [$now, $providerRef, $payment['id']],
            )->rowCount();
            if ($n === 0) {
                return false;
            }
            self::log('payment_paid', ['payment' => $payment['id'], 'order' => $payment['order_id']], $actorType, $actorId, 'pending', 'paid', $providerRef, (int) $payment['amount']);

            if ($payment['purpose'] === 'subscription') {
                $sub = Database::one('SELECT * FROM subscriptions WHERE id = ?', [$payment['subscription_id']]);
                // L'abonnement payé prolonge le précédent s'il court encore
                $last = Database::value('SELECT MAX(ends_on) FROM subscriptions WHERE stall_id = ? AND status = \'active\'', [$sub['stall_id']]);
                $start = $last !== null && $last >= Clock::localDate() ? date('Y-m-d', strtotime($last . ' +1 day')) : Clock::localDate();
                $days = $sub['period'] === 'year' ? 365 : 30;
                Database::run('UPDATE subscriptions SET status = \'active\', starts_on = ?, ends_on = ? WHERE id = ?', [$start, date('Y-m-d', strtotime($start . ' +' . ($days - 1) . ' days')), $sub['id']]);
                // Abonné : les produits masqués par la limite gratuite reviennent en vente
                \Axi\Support\Subscription::resume((int) $sub['stall_id']);
                return true;
            }

            Database::run('UPDATE orders SET status = \'paid\', paid_at = ? WHERE id = ? AND status = \'awaiting_payment\'', [$now, $payment['order_id']]);
            // Paiement sur place : seuls les frais sont passés par Axi ; la marchandise sera payée en espèces au commerçant
            $state = Database::value('SELECT payment_mode FROM orders WHERE id = ?', [$payment['order_id']]) === 'on_site' ? 'cash' : 'held';
            foreach (Database::all('SELECT * FROM order_stalls WHERE order_id = ?', [$payment['order_id']]) as $part) {
                Database::run('UPDATE order_stalls SET status = \'to_prepare\', escrow_state = ? WHERE id = ? AND escrow_state = \'pending\'', [$state, $part['id']]);
                self::log('escrow_' . $state, ['payment' => $payment['id'], 'order' => $payment['order_id'], 'stall_part' => $part['id']], $actorType, $actorId, 'pending', $state, $providerRef, (int) $part['net_amount']);
            }
            self::addToClientFiles((int) $payment['order_id']);
            return true;
        });
        if (!$paid) {
            return;
        }
        // Emails et notifications après la transaction (un SMTP lent ne bloque pas la base)
        if ($payment['purpose'] === 'subscription') {
            $period = Database::value('SELECT period FROM subscriptions WHERE id = ?', [$payment['subscription_id']]);
            Notifier::send((int) $payment['user_id'], 'payment', 'Abonnement Axi payé', 'Votre abonnement est actif pour ' . ($period === 'year' ? 'un an' : 'un mois') . ' : produits sans limite et messages groupés débloqués.', ['payment_reference' => $payment['reference']]);
            return;
        }
        self::notifyPaid((int) $payment['order_id']);
    }

    private static function markFailed(array $payment, string $state, string $raw, string $actorType, ?int $actorId): void
    {
        Database::transaction(static function () use ($payment, $state, $raw, $actorType, $actorId): void {
            $n = Database::run('UPDATE payments SET status = ?, failure_reason = ? WHERE id = ? AND status = \'pending\'', [$state, $raw, $payment['id']])->rowCount();
            if ($n === 0) {
                return;
            }
            self::log('payment_' . $state, ['payment' => $payment['id'], 'order' => $payment['order_id']], $actorType, $actorId, 'pending', $state, $payment['provider_ref']);
            if ($payment['purpose'] === 'order') {
                Database::run('UPDATE orders SET status = \'cancelled\', cancelled_at = ? WHERE id = ? AND status = \'awaiting_payment\'', [Clock::now(), $payment['order_id']]);
                Database::run('UPDATE order_stalls SET status = \'cancelled\' WHERE order_id = ? AND escrow_state = \'pending\'', [$payment['order_id']]);
            } else {
                Database::run('UPDATE subscriptions SET status = \'cancelled\' WHERE id = ? AND status = \'pending\'', [$payment['subscription_id']]);
            }
        });
        if ($payment['purpose'] === 'order') {
            self::restoreStock((int) $payment['order_id']);
        }
        $label = ['failed' => 'refusé', 'cancelled' => 'annulé', 'expired' => 'expiré'][$state];
        Notifier::send((int) $payment['user_id'], 'payment', "Paiement $label", "Votre paiement Mobile Money a été $label. Aucun montant n'a été prélevé. Vous pouvez recommencer depuis votre panier.", ['payment_reference' => $payment['reference']]);
    }

    /**
     * Commande annulée avant paiement : le stock réservé revient en vente. Une seule fois par commande
     * (stock_restored_at), même si l'annulation arrive par plusieurs chemins (client, passerelle, nuit).
     */
    public static function restoreStock(int $orderId): void
    {
        Database::transaction(static function () use ($orderId): void {
            $n = Database::run(
                'UPDATE orders SET stock_restored_at = ? WHERE id = ? AND status = \'cancelled\' AND stock_restored_at IS NULL',
                [Clock::now(), $orderId],
            )->rowCount();
            if ($n === 0) {
                return;
            }
            Database::run(
                'UPDATE products p JOIN (SELECT i.product_id, SUM(i.quantity) AS qty FROM order_items i JOIN order_stalls os ON os.id = i.order_stall_id WHERE os.order_id = ? GROUP BY i.product_id) r ON r.product_id = p.id
                 SET p.stock_quantity = p.stock_quantity + r.qty WHERE p.stock_quantity IS NOT NULL',
                [$orderId],
            );
        });
    }

    /** Le client a reçu sa commande : chaque part bloquée est libérée au commerçant */
    public static function confirmReceipt(int $orderId, string $actorType, ?int $actorId): void
    {
        $released = Database::transaction(static function () use ($orderId, $actorType, $actorId): array {
            $now = Clock::now();
            $n = Database::run(
                'UPDATE orders SET status = \'delivered\', delivered_at = ? WHERE id = ? AND status IN (\'paid\', \'preparing\', \'on_the_way\')',
                [$now, $orderId],
            )->rowCount();
            if ($n === 0) {
                return [];
            }
            $paymentId = (int) Database::value('SELECT id FROM payments WHERE order_id = ?', [$orderId]);
            // Parts payées sur place : livrées, rien à libérer (le commerçant a été payé en espèces)
            Database::run('UPDATE order_stalls SET status = \'delivered\', delivered_at = ? WHERE order_id = ? AND escrow_state = \'cash\'', [$now, $orderId]);
            $parts = Database::all('SELECT * FROM order_stalls WHERE order_id = ? AND escrow_state = \'held\'', [$orderId]);
            foreach ($parts as $part) {
                Database::run('UPDATE order_stalls SET status = \'delivered\', escrow_state = \'released\', delivered_at = ?, released_at = ? WHERE id = ?', [$now, $now, $part['id']]);
                self::log('escrow_delivered', ['payment' => $paymentId, 'order' => $orderId, 'stall_part' => $part['id']], $actorType, $actorId, 'held', 'delivered', null, (int) $part['net_amount']);
                self::log('escrow_released', ['payment' => $paymentId, 'order' => $orderId, 'stall_part' => $part['id']], $actorType, $actorId, 'delivered', 'released', null, (int) $part['net_amount']);
            }
            return $parts;
        });
        foreach ($released as $part) {
            $owner = (int) Database::value('SELECT user_id FROM stalls WHERE id = ?', [$part['stall_id']]);
            Notifier::send($owner, 'payment', 'Argent libéré', 'Le client a reçu la commande n° ' . $orderId . ' : ' . self::fcfa((int) $part['net_amount']) . ' sont libérés pour vous.', ['order_id' => 'cmd_' . $orderId]);
        }
    }

    /** Signalement d'un litige par le client : l'argent reste bloqué jusqu'à la décision d'un agent Axi */
    public static function openDispute(int $orderId, int $clientId, string $reason, ?int $stallId): int
    {
        return Database::transaction(static function () use ($orderId, $clientId, $reason, $stallId): int {
            $order = Database::one('SELECT status FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if (!in_array($order['status'], ['paid', 'preparing', 'on_the_way'], true)) {
                throw new HttpException(409, 'DISPUTE_NOT_ALLOWED', 'Un litige peut être signalé tant que la commande n\'est pas reçue ni remboursée.');
            }
            $partId = $stallId === null ? null : Database::value('SELECT id FROM order_stalls WHERE order_id = ? AND stall_id = ?', [$orderId, $stallId]);
            $id = Database::insert('INSERT INTO disputes (order_id, order_stall_id, client_id, reason, created_at) VALUES (?, ?, ?, ?, ?)', [$orderId, $partId, $clientId, $reason, Clock::now()]);
            Database::run('UPDATE orders SET status = \'disputed\' WHERE id = ?', [$orderId]);
            $where = $partId === null ? 'order_id = ?' : 'id = ?';
            foreach (Database::all("SELECT * FROM order_stalls WHERE $where AND escrow_state IN ('held', 'cash')", [$partId ?? $orderId]) as $part) {
                Database::run('UPDATE order_stalls SET escrow_state = \'disputed\' WHERE id = ?', [$part['id']]);
                self::log('escrow_disputed', ['order' => $orderId, 'stall_part' => $part['id']], 'client', $clientId, 'held', 'disputed', null, (int) $part['net_amount'], ['dispute_id' => $id]);
            }
            return $id;
        });
    }

    /**
     * Décision d'un agent Axi sur un litige : 'refund' (remboursement total ou partiel) ou 'release' (commerçant payé).
     */
    public static function resolveDispute(int $disputeId, string $decision, ?int $amount, string $note, int $agentId): string
    {
        $dispute = Database::one('SELECT * FROM disputes WHERE id = ?', [$disputeId]) ?? throw new HttpException(404, 'NOT_FOUND', 'Litige introuvable.');
        if ($dispute['status'] !== 'open') {
            throw new HttpException(409, 'ALREADY_RESOLVED', 'Ce litige est déjà tranché.');
        }
        $parts = $dispute['order_stall_id'] !== null
            ? Database::all('SELECT * FROM order_stalls WHERE id = ?', [$dispute['order_stall_id']])
            : Database::all('SELECT * FROM order_stalls WHERE order_id = ? AND escrow_state = \'disputed\'', [$dispute['order_id']]);
        $message = '';
        if ($decision === 'refund') {
            $message = self::refund((int) $dispute['order_id'], $parts, $amount, 'Litige : ' . $note, $agentId);
        } else {
            foreach ($parts as $part) {
                self::releasePart($part, 'partner', $agentId, 'dispute_release');
            }
            $message = 'Argent libéré aux commerçants.';
        }
        Database::run(
            'UPDATE disputes SET status = ?, resolution = ?, resolved_by = ?, resolved_at = ? WHERE id = ?',
            [$decision === 'refund' ? 'resolved_refund' : 'resolved_release', $note, $agentId, Clock::now(), $disputeId],
        );
        self::syncOrderAfterEscrow((int) $dispute['order_id']);
        Notifier::send((int) $dispute['client_id'], 'order', 'Votre litige a été examiné', 'Décision d\'Axi pour la commande n° ' . $dispute['order_id'] . ' : ' . ($decision === 'refund' ? 'remboursement' : 'commerçants payés') . '. ' . $note, ['order_id' => 'cmd_' . $dispute['order_id']]);
        return $message;
    }

    /**
     * Remboursement (total si $amount null, sinon partiel réparti sur les parts) : appel à la passerelle,
     * ligne dans refunds, montants et états mis à jour.
     */
    public static function refund(int $orderId, array $parts, ?int $amount, string $reason, int $actorId): string
    {
        $payment = Database::one('SELECT * FROM payments WHERE order_id = ?', [$orderId]) ?? throw new HttpException(404, 'NOT_FOUND', 'Paiement introuvable.');
        if (!in_array($payment['status'], ['paid', 'partially_refunded'], true)) {
            throw new HttpException(409, 'NOT_REFUNDABLE', 'Ce paiement ne peut pas être remboursé.');
        }
        if (Database::value('SELECT payment_mode FROM orders WHERE id = ?', [$orderId]) === 'on_site') {
            return self::refundOnSiteFees($orderId, $payment, $reason, $actorId);
        }
        $refundable = array_sum(array_map(static fn (array $p): int => in_array($p['escrow_state'], ['held', 'disputed', 'delivered'], true) ? (int) $p['items_total'] - (int) $p['refunded_amount'] : 0, $parts));
        $full = $amount === null;
        $amount ??= $refundable;
        if ($amount <= 0 || $amount > $refundable) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Montant à rembourser invalide (maximum ' . self::fcfa($refundable) . ').');
        }
        // Remboursement total de toute la commande : les frais de regroupement et de réception sont rendus aussi
        $allParts = (int) Database::value('SELECT COUNT(*) FROM order_stalls WHERE order_id = ? AND escrow_state <> \'refunded\'', [$orderId]);
        $fees = $full && count($parts) === $allParts && (int) $payment['refunded_amount'] === 0
            ? (int) Database::value('SELECT grouping_fee + reception_fee FROM orders WHERE id = ?', [$orderId])
            : 0;
        $result = Gateways::get($payment['provider'])->refund($payment, $amount + $fees);
        if ($result['status'] === 'failed') {
            throw new HttpException(502, 'REFUND_FAILED', $result['message']);
        }

        Database::transaction(static function () use ($payment, $parts, $amount, $fees, $reason, $actorId, $result, $orderId): void {
            $left = $amount;
            foreach ($parts as $part) {
                $partLeft = (int) $part['items_total'] - (int) $part['refunded_amount'];
                $take = min($partLeft, $left);
                if ($take <= 0 || !in_array($part['escrow_state'], ['held', 'disputed', 'delivered'], true)) {
                    continue;
                }
                $left -= $take;
                $fully = $take === $partLeft;
                // Remboursement partiel : la part du commerçant est réduite d'autant (commission recalculée)
                $newItems = (int) $part['items_total'] - (int) $part['refunded_amount'] - $take;
                $commission = (int) round($newItems * Settings::commissionRate());
                Database::run(
                    'UPDATE order_stalls SET refunded_amount = refunded_amount + ?, escrow_state = ?, commission = ?, net_amount = ?, status = IF(?, \'cancelled\', status) WHERE id = ?',
                    [$take, $fully ? 'refunded' : 'held', $fully ? 0 : $commission, $fully ? 0 : $newItems - $commission, $fully ? 1 : 0, $part['id']],
                );
                Database::run(
                    'INSERT INTO refunds (payment_id, order_stall_id, amount, reason, status, provider_ref, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$payment['id'], $part['id'], $take, mb_substr($reason, 0, 255), $result['status'], $result['provider_ref'], $actorId, Clock::now()],
                );
                self::log('escrow_refunded', ['payment' => $payment['id'], 'order' => $orderId, 'stall_part' => $part['id']], 'partner', $actorId, $part['escrow_state'], $fully ? 'refunded' : 'held', $result['provider_ref'], $take, ['refund_status' => $result['status'], 'reason' => $reason]);
            }
            if ($fees > 0) {
                Database::run(
                    'INSERT INTO refunds (payment_id, order_stall_id, amount, reason, status, provider_ref, created_by, created_at) VALUES (?, NULL, ?, ?, ?, ?, ?, ?)',
                    [$payment['id'], $fees, 'Frais de regroupement et de réception', $result['status'], $result['provider_ref'], $actorId, Clock::now()],
                );
            }
            $total = (int) $payment['refunded_amount'] + $amount + $fees;
            $status = $total >= (int) $payment['amount'] ? 'refunded' : 'partially_refunded';
            Database::run('UPDATE payments SET refunded_amount = ?, status = ? WHERE id = ?', [$total, $status, $payment['id']]);
            self::log('payment_' . $status, ['payment' => $payment['id'], 'order' => $orderId], 'partner', $actorId, $payment['status'], $status, $result['provider_ref'], $amount + $fees);
        });
        self::syncOrderAfterEscrow($orderId);
        Notifier::send((int) $payment['user_id'], 'payment', 'Remboursement', 'Axi a décidé un remboursement de ' . self::fcfa($amount + $fees) . ' pour la commande n° ' . $orderId . '. ' . $result['message'], ['order_id' => 'cmd_' . $orderId]);
        return self::fcfa($amount + $fees) . ' : ' . $result['message'];
    }

    /**
     * Commande payée sur place : Axi n'a reçu que les frais (réception, regroupement, service) ; c'est ce qui
     * est rendu. La marchandise payée en espèces se règle entre le client et le commerçant.
     */
    private static function refundOnSiteFees(int $orderId, array $payment, string $reason, int $actorId): string
    {
        $amount = (int) $payment['amount'] - (int) $payment['refunded_amount'];
        if ($amount <= 0) {
            throw new HttpException(409, 'NOT_REFUNDABLE', 'Les frais de cette commande sont déjà remboursés.');
        }
        $result = Gateways::get($payment['provider'])->refund($payment, $amount);
        if ($result['status'] === 'failed') {
            throw new HttpException(502, 'REFUND_FAILED', $result['message']);
        }
        Database::transaction(static function () use ($orderId, $payment, $amount, $reason, $actorId, $result): void {
            Database::run('UPDATE order_stalls SET escrow_state = \'refunded\', status = \'cancelled\' WHERE order_id = ? AND escrow_state IN (\'cash\', \'disputed\')', [$orderId]);
            Database::run(
                'INSERT INTO refunds (payment_id, order_stall_id, amount, reason, status, provider_ref, created_by, created_at) VALUES (?, NULL, ?, ?, ?, ?, ?, ?)',
                [$payment['id'], $amount, mb_substr('Frais (paiement sur place) : ' . $reason, 0, 255), $result['status'], $result['provider_ref'], $actorId, Clock::now()],
            );
            Database::run('UPDATE payments SET refunded_amount = amount, status = \'refunded\' WHERE id = ?', [$payment['id']]);
            self::log('payment_refunded', ['payment' => $payment['id'], 'order' => $orderId], 'partner', $actorId, $payment['status'], 'refunded', $result['provider_ref'], $amount);
        });
        self::syncOrderAfterEscrow($orderId);
        Notifier::send((int) $payment['user_id'], 'payment', 'Remboursement', 'Axi vous rembourse les frais payés en ligne (' . self::fcfa($amount) . ') pour la commande n° ' . $orderId . '. ' . $result['message'], ['order_id' => 'cmd_' . $orderId]);
        return self::fcfa($amount) . ' : ' . $result['message'];
    }

    /** Libération manuelle par un agent Axi (ou automatique après le délai configuré) */
    public static function releasePart(array $part, string $actorType, ?int $actorId, string $event = 'escrow_released'): void
    {
        $n = Database::run(
            'UPDATE order_stalls SET escrow_state = \'released\', released_at = ?, status = IF(status IN (\'cancelled\'), status, \'delivered\') WHERE id = ? AND escrow_state IN (\'held\', \'disputed\', \'delivered\')',
            [Clock::now(), $part['id']],
        )->rowCount();
        if ($n > 0) {
            $paymentId = Database::value('SELECT id FROM payments WHERE order_id = ?', [$part['order_id']]);
            self::log($event, ['payment' => $paymentId, 'order' => $part['order_id'], 'stall_part' => $part['id']], $actorType, $actorId, $part['escrow_state'], 'released', null, (int) $part['net_amount']);
        }
    }

    /**
     * Versement des sommes libérées à un commerçant (simulé en sandbox, réel en production).
     * Jamais deux fois : un seul versement à la fois par étal (verrou), et les parts sont réservées à CE versement
     * AVANT l'appel à la passerelle. Elles ne redeviennent disponibles que sur un refus explicite ; une réponse
     * perdue (coupure) laisse le versement « en cours » à vérifier dans le tableau de bord de la passerelle.
     */
    public static function payout(int $stallId, int $agentId): array
    {
        return Database::withLock('axi_payout_' . $stallId, 0, static fn (): array => self::payoutLocked($stallId, $agentId));
    }

    private static function payoutLocked(int $stallId, int $agentId): array
    {
        $stall = Database::one('SELECT s.*, u.name AS owner FROM stalls s JOIN users u ON u.id = s.user_id WHERE s.id = ?', [$stallId]);
        $gateway = Gateways::get();
        [$payoutId, $parts] = Database::transaction(static function () use ($stallId, $stall, $gateway, $agentId): array {
            $payoutId = Database::insert(
                'INSERT INTO payouts (stall_id, amount, phone, provider, status, created_by, created_at) VALUES (?, 0, ?, ?, \'processing\', ?, ?)',
                [$stallId, $stall['phone'], $gateway->name(), $agentId, Clock::now()],
            );
            Database::run('UPDATE order_stalls SET payout_id = ? WHERE stall_id = ? AND escrow_state = \'released\' AND payout_id IS NULL', [$payoutId, $stallId]);
            return [$payoutId, Database::all('SELECT * FROM order_stalls WHERE payout_id = ?', [$payoutId])];
        });
        $amount = array_sum(array_map(static fn (array $p): int => (int) $p['net_amount'], $parts));
        if ($amount <= 0) {
            Database::run('UPDATE order_stalls SET payout_id = NULL WHERE payout_id = ?', [$payoutId]);
            Database::run('DELETE FROM payouts WHERE id = ?', [$payoutId]);
            throw new HttpException(409, 'NOTHING_TO_PAY', 'Aucune somme libérée à verser à ce commerçant.');
        }
        // Crédit en cours avec retenue acceptée par le commerçant : la part convenue rembourse son crédit
        $withheld = \Axi\Services\Credit::withholding((int) $stall['user_id'], $amount);
        $amount -= $withheld;
        Database::run('UPDATE payouts SET amount = ? WHERE id = ?', [$amount, $payoutId]);
        try {
            $result = $gateway->payout($amount, (string) $stall['phone'], (string) $stall['owner'], 'axi_payout_' . $payoutId);
        } catch (GatewayException $e) {
            // Réponse perdue : l'argent est peut-être parti. Rien n'est rendu disponible, un agent vérifie
            Logger::write('payments', "VERSEMENT INCERTAIN axi_payout_$payoutId : " . $e->getMessage());
            self::log('payout_uncertain', [], 'partner', $agentId, 'released', 'processing', null, $amount, ['stall_id' => $stallId, 'payout_id' => $payoutId]);
            return ['id' => $payoutId, 'amount' => $amount, 'status' => 'pending', 'provider_ref' => null,
                'message' => 'Réponse de la passerelle perdue : vérifiez le versement axi_payout_' . $payoutId . ' dans son tableau de bord avant tout nouvel essai.'];
        }
        Database::run('UPDATE payouts SET status = ?, provider_ref = ?, processed_at = ? WHERE id = ?', [$result['status'], $result['provider_ref'], Clock::now(), $payoutId]);
        if ($result['status'] === 'failed') {
            // Refus explicite : les parts redeviennent disponibles pour un prochain versement
            Database::run('UPDATE order_stalls SET payout_id = NULL WHERE payout_id = ?', [$payoutId]);
        } else {
            if ($withheld > 0) {
                $creditId = (int) Database::value('SELECT id FROM credits WHERE merchant_user_id = ? AND status = \'granted\' AND withhold_pct > 0 ORDER BY id LIMIT 1', [$stall['user_id']]);
                \Axi\Services\Credit::repay($creditId, $withheld, 'payout', $payoutId, $agentId);
            }
            Notifier::send((int) $stall['user_id'], 'payment', 'Versement de vos ventes', self::fcfa($amount) . ' vous ont été versés' . ($withheld > 0 ? ' (' . self::fcfa($withheld) . ' retenus pour votre crédit)' : '') . ($result['status'] === 'simulated' ? ' (simulation, mode test)' : '') . '.', ['payout_id' => $payoutId]);
        }
        self::log('payout_' . $result['status'], [], 'partner', $agentId, 'released', 'paid_out', $result['provider_ref'], $amount, ['stall_id' => $stallId, 'payout_id' => $payoutId]);
        return ['id' => $payoutId, 'amount' => $amount] + $result;
    }

    /** Statut global de la commande après une décision sur le séquestre */
    public static function syncOrderAfterEscrow(int $orderId): void
    {
        $states = array_column(Database::all('SELECT escrow_state FROM order_stalls WHERE order_id = ?', [$orderId]), 'escrow_state');
        if ($states === [] || in_array('disputed', $states, true)) {
            return;
        }
        $status = match (true) {
            array_diff($states, ['refunded']) === [] => 'refunded',
            array_diff($states, ['released', 'refunded']) === [] => 'delivered',
            default => null,
        };
        if ($status !== null) {
            Database::run('UPDATE orders SET status = ?, delivered_at = IF(? = \'delivered\', COALESCE(delivered_at, ?), delivered_at) WHERE id = ?', [$status, $status, Clock::now(), $orderId]);
        } else {
            Database::run('UPDATE orders SET status = \'paid\' WHERE id = ? AND status = \'disputed\'', [$orderId]);
            self::syncPreparation($orderId);
        }
    }

    /** Statut de préparation déduit des parts : paid → preparing → on_the_way */
    public static function syncPreparation(int $orderId): void
    {
        $statuses = array_column(Database::all('SELECT status FROM order_stalls WHERE order_id = ? AND status <> \'cancelled\'', [$orderId]), 'status');
        if ($statuses === []) {
            return;
        }
        $all = static fn (array $allowed): bool => array_diff($statuses, $allowed) === [];
        $status = match (true) {
            $all(['picked_up', 'delivered']) => 'on_the_way',
            $all(['to_prepare']) => 'paid',
            default => 'preparing',
        };
        Database::run('UPDATE orders SET status = ? WHERE id = ? AND status IN (\'paid\', \'preparing\', \'on_the_way\')', [$status, $orderId]);
    }

    /** Le client entre (ou est mis à jour) dans le fichier de chaque commerçant de la commande */
    private static function addToClientFiles(int $orderId): void
    {
        $order = Database::one('SELECT o.*, u.name, u.email, u.phone FROM orders o JOIN users u ON u.id = o.client_id WHERE o.id = ?', [$orderId]);
        foreach (Database::all('SELECT stall_id, items_total FROM order_stalls WHERE order_id = ?', [$orderId]) as $part) {
            $existing = Database::value('SELECT id FROM merchant_clients WHERE stall_id = ? AND (user_id = ? OR (phone IS NOT NULL AND phone = ?))', [$part['stall_id'], $order['client_id'], $order['phone']]);
            if ($existing !== null) {
                Database::run(
                    'UPDATE merchant_clients SET user_id = COALESCE(user_id, ?), email = COALESCE(email, ?), invite_status = \'accepted\', orders_count = orders_count + 1, total_spent = total_spent + ?, last_order_at = ? WHERE id = ?',
                    [$order['client_id'], $order['email'], $part['items_total'], Clock::now(), $existing],
                );
            } else {
                Database::run(
                    'INSERT INTO merchant_clients (stall_id, user_id, display_name, phone, email, orders_count, total_spent, last_order_at, created_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)',
                    [$part['stall_id'], $order['client_id'], $order['name'], $order['phone'], $order['email'], $part['items_total'], Clock::now(), Clock::now()],
                );
            }
        }
    }

    private static function notifyPaid(int $orderId): void
    {
        $order = Database::one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        $onSite = $order['payment_mode'] === 'on_site';
        $text = $onSite
            ? 'Nous avons reçu ' . self::fcfa((int) $order['total']) . ' de frais pour la commande n° ' . $orderId . '. Vous paierez ' . self::fcfa((int) $order['items_total']) . ' en espèces au commerçant à la remise.'
            : 'Nous avons reçu ' . self::fcfa((int) $order['total']) . ' pour la commande n° ' . $orderId . '. L\'argent reste chez Axi jusqu\'à ce que vous confirmiez la réception.';
        Notifier::send((int) $order['client_id'], 'payment', 'Paiement reçu', $text, ['order_id' => 'cmd_' . $orderId], [
            [$text],
            ['rows' => [['Commande', 'n° ' . $orderId], ['Montant payé', self::fcfa((int) $order['total'])]]],
        ]);
        foreach (Database::all('SELECT os.*, s.user_id FROM order_stalls os JOIN stalls s ON s.id = os.stall_id WHERE os.order_id = ?', [$orderId]) as $part) {
            $items = Database::all('SELECT name, quantity FROM order_items WHERE order_stall_id = ?', [$part['id']]);
            $summary = implode(', ', array_map(static fn (array $i): string => $i['quantity'] . ' × ' . $i['name'], $items));
            Notifier::send((int) $part['user_id'], 'order', $onSite ? 'Nouvelle commande, paiement sur place' : 'Nouvelle commande payée', "À préparer : $summary." . ($onSite ? ' À encaisser en espèces à la remise : ' . self::fcfa((int) $part['items_total']) . '.' : ' (' . self::fcfa((int) $part['items_total']) . ')'), ['order_id' => 'cmd_' . $orderId]);
        }
    }

    public static function fcfa(int $amount): string
    {
        return number_format($amount, 0, ',', ' ') . ' FCFA';
    }
}
