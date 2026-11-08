<?php
declare(strict_types=1);

namespace Axi\Controllers\Merchant;

use Axi\Core\Clock;
use Axi\Core\Cursor;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Ids;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Support\Merchant;
use Axi\Support\Stalls;

/**
 * « Mes commandes » du commerçant : les commandes reçues (pas ses achats), en trois onglets.
 * - todo     : à préparer et prêtes (ce que le commerçant doit faire) ;
 * - shipping : remises au coursier, pas encore reçues par le client ;
 * - done     : livrées ou annulées (historique).
 * Une commande quitte « à traiter » dès qu'elle est remise au coursier : la liste du jour reste courte.
 */
final class OrdersController
{
    private const PER_PAGE = 20;
    public const STATUS_LABELS = ['to_prepare' => 'À préparer', 'ready' => 'Prête', 'picked_up' => 'Remise au coursier', 'delivered' => 'Livrée', 'cancelled' => 'Annulée'];
    private const TABS = [
        'todo' => "os.status IN ('to_prepare','ready')",
        'shipping' => "os.status = 'picked_up'",
        'done' => "os.status IN ('delivered','cancelled')",
    ];
    private const OPERATORS = ['mtn' => 'MTN MoMo', 'moov' => 'Moov Money', 'celtiis' => 'Celtiis Cash'];

    public function index(Request $req): never
    {
        $stall = Merchant::stall($req);
        $tab = (string) $req->query('tab', 'todo');
        if (!isset(self::TABS[$tab])) {
            Validator::fail(['tab' => 'Valeur non autorisée.'], ['tab' => 'Onglet']);
        }
        $offset = Cursor::offset($req->query('cursor'));
        $base = "FROM order_stalls os JOIN orders o ON o.id = os.order_id JOIN users u ON u.id = o.client_id
                 LEFT JOIN axi_points p ON p.id = o.axi_point_id
                 WHERE os.stall_id = ? AND os.status <> 'awaiting_payment'";
        // À traiter : les plus anciennes d'abord (à préparer avant prêtes) ; sinon les plus récentes d'abord
        $order = $tab === 'todo' ? "os.status = 'to_prepare' DESC, o.paid_at ASC, o.id ASC" : 'o.id DESC';
        $rows = Database::all(
            "SELECT os.*, o.id AS order_id, o.created_at AS ordered_at, o.paid_at, o.reception_mode, o.reception_detail, o.payment_mode,
                    u.name AS customer_name, p.name AS axi_point_name
             $base AND " . self::TABS[$tab] . " ORDER BY $order LIMIT " . (self::PER_PAGE + 1) . " OFFSET $offset",
            [$stall['id']],
        );
        $counts = [];
        foreach (self::TABS as $key => $where) {
            $counts[$key] = (int) Database::value("SELECT COUNT(*) $base AND $where", [$stall['id']]);
        }
        $items = self::itemsFor(array_map(static fn (array $r): int => (int) $r['id'], array_slice($rows, 0, self::PER_PAGE)));
        Response::ok([
            'tab' => $tab,
            'counts' => $counts,
            'orders' => array_map(static fn (array $r): array => self::row($r, $items[(int) $r['id']] ?? []), array_slice($rows, 0, self::PER_PAGE)),
            'next_cursor' => Cursor::next($offset, self::PER_PAGE, count($rows)),
        ]);
    }

    /** Détail : client, articles, montants (commission, net), réception, paiement, étapes, versement */
    public function show(Request $req): never
    {
        $stall = Merchant::stall($req);
        $orderId = $req->id('cmd', 'ORDER_NOT_FOUND', 'Commande introuvable.');
        $r = Database::one(
            "SELECT os.*, o.id AS order_id, o.created_at AS ordered_at, o.paid_at, o.reception_mode, o.reception_detail, o.payment_mode,
                    o.payment_operator, o.payment_phone, o.reception_fee, o.client_id, o.cancelled_at,
                    u.name AS customer_name, u.status AS customer_status, p.name AS axi_point_name, p.address AS axi_point_address,
                    pay.processed_at AS payout_at, pay.status AS payout_status
             FROM order_stalls os JOIN orders o ON o.id = os.order_id JOIN users u ON u.id = o.client_id
             LEFT JOIN axi_points p ON p.id = o.axi_point_id LEFT JOIN payouts pay ON pay.id = os.payout_id
             WHERE os.order_id = ? AND os.stall_id = ? AND os.status <> 'awaiting_payment'",
            [$orderId, $stall['id']],
        ) ?? throw new HttpException(404, 'ORDER_NOT_FOUND', 'Commande introuvable.');

        $lines = Database::all('SELECT * FROM order_items WHERE order_stall_id = ? ORDER BY id', [$r['id']]);
        $images = Stalls::imagesFor(array_values(array_unique(array_map(static fn (array $l): int => (int) $l['product_id'], $lines))));
        $client = Database::one('SELECT id, invite_status, orders_count FROM merchant_clients WHERE stall_id = ? AND user_id = ?', [$stall['id'], $r['client_id']]);
        $active = $r['customer_status'] === 'active';
        $cash = $r['escrow_state'] === 'cash';

        Response::ok(self::row($r, $lines) + [
            'customer' => [
                'name' => $r['customer_name'],
                'phone' => $r['payment_phone'],
                // Fiche dans « Mes clients » et discussion (compte actif seulement)
                'client_id' => $client === null ? null : Ids::encode('cli', $client['id']),
                'can_message' => $active && $client !== null && $client['invite_status'] === 'accepted',
                'orders_count' => $client === null ? null : (int) $client['orders_count'],
            ],
            'lines' => array_map(static fn (array $l): array => [
                'product_id' => Ids::encode('prd', $l['product_id']),
                'name' => $l['name'],
                'quantity' => (int) $l['quantity'],
                'unit_price' => (int) $l['unit_price'],
                'total' => (int) $l['line_total'],
                'image' => $images[(int) $l['product_id']][0] ?? null,
            ], $lines),
            'amounts' => [
                'items_total' => (int) $r['items_total'],
                'commission' => (int) $r['commission'],
                'commission_pct' => (int) $r['items_total'] > 0 ? round(100 * (int) $r['commission'] / (int) $r['items_total'], 1) : 0,
                // Espèces : le commerçant encaisse lui-même, Axi ne lui verse rien
                'net' => $cash ? (int) $r['items_total'] : (int) $r['net_amount'],
                'refunded' => (int) $r['refunded_amount'],
            ],
            'reception' => [
                'mode' => $r['reception_mode'],
                'label' => self::receptionLabel($r),
                'detail' => $r['reception_mode'] === 'axi_point' ? ($r['axi_point_address'] ?? null) : $r['reception_detail'],
            ],
            'payment' => [
                'mode' => $r['payment_mode'],
                'label' => $r['payment_mode'] === 'on_site'
                    ? 'Paiement à la réception (espèces)'
                    : 'Mobile Money' . ($r['payment_operator'] !== null ? ' · ' . (self::OPERATORS[$r['payment_operator']] ?? $r['payment_operator']) : ''),
                'paid_at' => Clock::iso($r['paid_at']),
            ],
            'money' => self::moneyState($r),
            'steps' => self::steps($r),
            'can_mark_ready' => $r['status'] === 'to_prepare',
        ]);
    }

    /** @return array<int, array<int, array>> lignes de chaque part de commande */
    private static function itemsFor(array $partIds): array
    {
        if ($partIds === []) {
            return [];
        }
        $out = [];
        foreach (Database::all('SELECT * FROM order_items WHERE order_stall_id IN (' . Database::in($partIds) . ') ORDER BY id', $partIds) as $l) {
            $out[(int) $l['order_stall_id']][] = $l;
        }
        return $out;
    }

    private static function row(array $r, array $lines): array
    {
        $count = array_sum(array_map(static fn (array $l): int => (int) $l['quantity'], $lines));
        return [
            'id' => Ids::encode('cmd', $r['order_id']),
            'customer_name' => $r['customer_name'],
            'created_at' => Clock::iso($r['paid_at'] ?? $r['ordered_at']),
            'items_count' => $count,
            'summary' => implode(', ', array_map(static fn (array $l): string => $l['quantity'] . ' × ' . $l['name'], $lines)),
            'amount' => (int) $r['items_total'],
            'status' => $r['status'],
            'status_label' => self::STATUS_LABELS[$r['status']] ?? $r['status'],
            'reception_label' => self::receptionLabel($r),
        ];
    }

    /** Où en est l'argent de cette commande, en une phrase */
    private static function moneyState(array $r): array
    {
        [$label, $tone] = match ($r['escrow_state']) {
            'held' => ['Payé par le client. Axi garde l\'argent jusqu\'à ce qu\'il confirme la réception.', 'brand'],
            'delivered', 'released' => $r['payout_id'] !== null
                ? ['Versé sur votre Mobile Money' . ($r['payout_at'] !== null ? ' le ' . self::localDate($r['payout_at']) : '') . '.', 'trust']
                : ['Libéré : le client a reçu sa commande. Il sera versé au prochain versement.', 'trust'],
            'cash' => ['Paiement en espèces à la réception : vous encaissez vous-même.', 'neutral'],
            'refunded' => ['Remboursé au client.', 'danger'],
            'disputed' => ['Litige en cours : l\'argent reste bloqué jusqu\'à la décision d\'Axi.', 'danger'],
            default => ['En attente du paiement.', 'neutral'],
        };
        return ['state' => $r['escrow_state'], 'label' => $label, 'tone' => $tone];
    }

    /** Étapes de la commande, cochées au fur et à mesure */
    private static function steps(array $r): array
    {
        if ($r['status'] === 'cancelled') {
            return [
                ['key' => 'paid', 'label' => 'Commande payée', 'at' => Clock::iso($r['paid_at']), 'done' => true],
                ['key' => 'cancelled', 'label' => 'Annulée', 'at' => Clock::iso($r['cancelled_at'] ?? $r['updated_at']), 'done' => true],
            ];
        }
        $released = in_array($r['escrow_state'], ['released', 'delivered'], true);
        return [
            ['key' => 'paid', 'label' => $r['escrow_state'] === 'cash' ? 'Commande reçue' : 'Commande payée', 'at' => Clock::iso($r['paid_at'] ?? $r['ordered_at']), 'done' => true],
            ['key' => 'ready', 'label' => 'Prête', 'at' => Clock::iso($r['ready_at']), 'done' => $r['ready_at'] !== null || $r['status'] !== 'to_prepare'],
            ['key' => 'picked_up', 'label' => 'Remise au coursier', 'at' => Clock::iso($r['picked_up_at']), 'done' => in_array($r['status'], ['picked_up', 'delivered'], true)],
            ['key' => 'delivered', 'label' => 'Reçue par le client', 'at' => Clock::iso($r['delivered_at']), 'done' => $r['status'] === 'delivered'],
            ['key' => 'paid_out', 'label' => $r['escrow_state'] === 'cash' ? 'Encaissée' : 'Argent versé', 'at' => Clock::iso($r['payout_at'] ?? null), 'done' => $r['escrow_state'] === 'cash' ? $r['status'] === 'delivered' : ($released && $r['payout_id'] !== null)],
        ];
    }

    private static function localDate(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone((string) \Axi\Core\Config::get('timezone')))->format('d/m/Y');
    }

    /** « Livraison Akpakpa », « Expédition car Parakou », « Retrait Point Axi Tokpa Yôyô » */
    public static function receptionLabel(array $order): string
    {
        return match ($order['reception_mode']) {
            'delivery' => 'Livraison ' . $order['reception_detail'],
            'bus' => 'Expédition car ' . $order['reception_detail'],
            'axi_point' => 'Retrait ' . ($order['axi_point_name'] ?? 'au point Axi'),
            default => (string) $order['reception_detail'],
        };
    }
}
