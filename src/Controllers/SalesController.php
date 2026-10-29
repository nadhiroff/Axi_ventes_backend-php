<?php
declare(strict_types=1);

namespace Axi\Controllers;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\Ids;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Services\Payment\Escrow;
use Axi\Services\SignedUrl;

/**
 * « Ventes & paiements » : historique du client (ses achats) ou du commerçant (ses ventes), avec filtres
 * période / statut, recherche, totaux et export CSV. Données réelles de la base.
 */
final class SalesController
{
    private const ESCROW_LABELS = [
        'pending' => 'En attente de paiement',
        'held' => 'Payé, bloqué jusqu\'à la réception',
        'delivered' => 'Livré',
        'released' => 'Libéré au commerçant',
        'refunded' => 'Remboursé',
        'disputed' => 'Litige en cours',
    ];

    private const PROVIDERS = ['fedapay' => 'FedaPay', 'kkiapay' => 'KkiaPay', 'fake' => 'Test'];

    public function index(Request $req): never
    {
        $filters = $this->filters($req->queryAll());
        [$rows, $totals] = $this->rows($req->userId(), (string) $req->role(), $filters);
        Response::ok(['rows' => $rows, 'totals' => $totals, 'filters' => $filters]);
    }

    /** Lien d'export CSV signé, valable 10 minutes (ouvert dans le navigateur du téléphone) */
    public function exportLink(Request $req): never
    {
        $filters = $this->filters($req->all() + $req->queryAll());
        Response::ok(['url' => SignedUrl::make('/v1/sales/export.csv', ['u' => $req->userId(), 'r' => $req->role()] + array_filter($filters, static fn ($v) => $v !== null), 600)]);
    }

    public function export(Request $req): never
    {
        $params = SignedUrl::verify('/v1/sales/export.csv', $req->queryAll());
        [$rows, $totals] = $this->rows((int) $params['u'], (string) $params['r'], $this->filters($params), 5000);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="axi-ventes-' . gmdate('Y-m-d') . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM : accents corrects dans Excel
        $merchant = $params['r'] === 'merchant';
        fputcsv($out, $merchant
            ? ['Date', 'Commande', 'Client', 'Montant (FCFA)', 'Commission (FCFA)', 'Net (FCFA)', 'Statut', 'Paiement', 'Référence passerelle']
            : ['Date', 'Commande', 'Étals', 'Montant (FCFA)', 'Statut', 'Paiement', 'Référence passerelle'], ';');
        foreach ($rows as $r) {
            fputcsv($out, $merchant
                ? [$r['date'], $r['order_id'], $r['counterpart'], $r['amount'], $r['commission'], $r['net_amount'], $r['status_label'], $r['payment_method'], $r['gateway_ref']]
                : [$r['date'], $r['order_id'], $r['counterpart'], $r['amount'], $r['status_label'], $r['payment_method'], $r['gateway_ref']], ';');
        }
        fputcsv($out, ['Total', '', '', $totals['amount'], $merchant ? $totals['commission'] : '', $merchant ? $totals['net_amount'] : '', $totals['count'] . ' ligne(s)'], ';');
        fclose($out);
        exit;
    }

    private function filters(array $q): array
    {
        $in = Validator::validate($q, [
            'from' => 'string|max:10',
            'to' => 'string|max:10',
            'status' => 'in:all,pending,held,released,refunded,disputed,cancelled',
            'q' => 'string|max:80',
        ], ['from' => 'Du', 'to' => 'Au', 'status' => 'Statut']);
        foreach (['from', 'to'] as $k) {
            if (isset($in[$k]) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $in[$k])) {
                Validator::fail([$k => 'Date attendue au format AAAA-MM-JJ.']);
            }
        }
        return ['from' => $in['from'] ?? null, 'to' => $in['to'] ?? null, 'status' => $in['status'] ?? 'all', 'q' => $in['q'] ?? null];
    }

    /** @return array{0: array, 1: array} lignes et totaux */
    private function rows(int $userId, string $role, array $f, int $limit = 300): array
    {
        $where = [];
        $params = [];
        if ($f['from'] !== null) {
            $where[] = 'o.created_at >= ?';
            $params[] = Clock::localDateStart($f['from']);
        }
        if ($f['to'] !== null) {
            $where[] = 'o.created_at < ?';
            $params[] = Clock::localDateStart(date('Y-m-d', strtotime($f['to'] . ' +1 day')));
        }
        $like = $f['q'] === null ? null : '%' . addcslashes($f['q'], '%_\\') . '%';

        if ($role === 'merchant') {
            $stallId = (int) Database::value('SELECT id FROM stalls WHERE user_id = ?', [$userId]);
            $where[] = 'os.stall_id = ?';
            $params[] = $stallId;
            if ($f['status'] === 'cancelled') {
                $where[] = 'os.status = \'cancelled\'';
            } elseif ($f['status'] !== 'all') {
                $where[] = 'os.escrow_state = ?';
                $params[] = $f['status'];
            }
            if ($like !== null) {
                $where[] = '(u.name LIKE ? OR CONCAT(\'cmd_\', o.id) LIKE ?)';
                array_push($params, $like, $like);
            }
            $sql = 'SELECT o.id, o.created_at, u.name AS counterpart, os.items_total AS amount, os.commission, os.net_amount, os.escrow_state, os.status AS part_status,
                           p.provider, p.provider_ref, o.payment_operator
                    FROM order_stalls os JOIN orders o ON o.id = os.order_id JOIN users u ON u.id = o.client_id LEFT JOIN payments p ON p.order_id = o.id
                    WHERE ' . implode(' AND ', $where) . ' ORDER BY o.id DESC LIMIT ' . $limit;
        } else {
            $where[] = 'o.client_id = ?';
            $params[] = $userId;
            if ($f['status'] === 'cancelled') {
                $where[] = 'o.status = \'cancelled\'';
            } elseif ($f['status'] !== 'all') {
                $where[] = 'EXISTS (SELECT 1 FROM order_stalls x WHERE x.order_id = o.id AND x.escrow_state = ?)';
                $params[] = $f['status'];
            }
            if ($like !== null) {
                $where[] = '(EXISTS (SELECT 1 FROM order_stalls x JOIN stalls s ON s.id = x.stall_id WHERE x.order_id = o.id AND s.name LIKE ?) OR CONCAT(\'cmd_\', o.id) LIKE ?)';
                array_push($params, $like, $like);
            }
            $sql = 'SELECT o.id, o.created_at, o.total AS amount, 0 AS commission, o.total AS net_amount, o.status AS order_status,
                           (SELECT GROUP_CONCAT(s.name SEPARATOR \', \') FROM order_stalls x JOIN stalls s ON s.id = x.stall_id WHERE x.order_id = o.id) AS counterpart,
                           (SELECT x.escrow_state FROM order_stalls x WHERE x.order_id = o.id ORDER BY x.id LIMIT 1) AS escrow_state,
                           p.provider, p.provider_ref, o.payment_operator
                    FROM orders o LEFT JOIN payments p ON p.order_id = o.id
                    WHERE ' . implode(' AND ', $where) . ' ORDER BY o.id DESC LIMIT ' . $limit;
        }

        $rows = [];
        $totals = ['count' => 0, 'amount' => 0, 'commission' => 0, 'net_amount' => 0];
        foreach (Database::all($sql, $params) as $r) {
            $cancelled = ($r['part_status'] ?? $r['order_status'] ?? '') === 'cancelled';
            $label = $cancelled ? 'Annulée' : ($role === 'merchant' ? self::ESCROW_LABELS[$r['escrow_state']] : Escrow::ORDER_LABELS[$r['order_status']]);
            $rows[] = [
                'order_id' => Ids::encode('cmd', $r['id']),
                'date' => Clock::iso($r['created_at']),
                'counterpart' => (string) $r['counterpart'],
                'amount' => (int) $r['amount'],
                'commission' => (int) $r['commission'],
                'net_amount' => (int) $r['net_amount'],
                'status' => $cancelled ? 'cancelled' : $r['escrow_state'],
                'status_label' => $label,
                'payment_method' => trim((self::PROVIDERS[$r['provider']] ?? '—') . ' ' . strtoupper((string) $r['payment_operator'])),
                'gateway_ref' => $r['provider_ref'],
            ];
            if (!$cancelled) {
                $totals['count']++;
                $totals['amount'] += (int) $r['amount'];
                $totals['commission'] += (int) $r['commission'];
                $totals['net_amount'] += (int) $r['net_amount'];
            }
        }
        return [$rows, $totals];
    }
}
