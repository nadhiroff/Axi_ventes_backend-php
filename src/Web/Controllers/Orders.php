<?php
declare(strict_types=1);

namespace Axi\Web\Controllers;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Services\AdminLog;
use Axi\Services\Payment\Escrow;
use Axi\Web\Html;
use Axi\Web\View;
use Axi\Web\WebAuth;

/** Agent Axi : commandes, enlèvement chez les étals, libération ou remboursement du séquestre, litiges */
final class Orders
{
    private const PER_PAGE = 30;

    public const ESCROW = [
        'pending' => ['En attente de paiement', 'muted'],
        'held' => ['Payé, bloqué', 'warn'],
        'delivered' => ['Livré', 'ok'],
        'released' => ['Libéré au commerçant', 'ok'],
        'refunded' => ['Remboursé', 'muted'],
        'disputed' => ['Litige', 'danger'],
        'cash' => ['Espèces à la remise', 'muted'],
    ];

    public static function escrowBadge(string $state): string
    {
        [$label, $tone] = self::ESCROW[$state] ?? [$state, 'muted'];
        return View::badge($label, $tone);
    }

    public static function orderBadge(string $status): string
    {
        $tone = match ($status) {
            'delivered' => 'ok',
            'disputed', 'cancelled' => 'danger',
            'awaiting_payment', 'refunded' => 'muted',
            default => 'warn',
        };
        return View::badge(Escrow::ORDER_LABELS[$status] ?? $status, $tone);
    }

    public function index(Request $req): never
    {
        WebAuth::require(['axi']);
        $where = ['1'];
        $params = [];
        if (($status = (string) ($_GET['status'] ?? '')) !== '' && isset(Escrow::ORDER_LABELS[$status])) {
            $where[] = 'o.status = ?';
            $params[] = $status;
        }
        if (($q = trim((string) ($_GET['q'] ?? ''))) !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(u.name LIKE ? OR u.email LIKE ? OR CONCAT(\'cmd_\', o.id) LIKE ? OR p.provider_ref LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $page = View::page();
        $rows = Database::all(
            'SELECT o.*, u.name AS client, p.provider, p.provider_ref, p.status AS pay_status,
                    (SELECT GROUP_CONCAT(s.name SEPARATOR \', \') FROM order_stalls os JOIN stalls s ON s.id = os.stall_id WHERE os.order_id = o.id) AS stalls
             FROM orders o JOIN users u ON u.id = o.client_id LEFT JOIN payments p ON p.order_id = o.id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY o.id DESC LIMIT ' . (self::PER_PAGE + 1) . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params,
        );
        $body = Html::filters('/pro/commandes', [
            'q' => ['Recherche (client, n° de commande, référence passerelle)', 'search', null],
            'status' => ['Statut', 'select', ['' => 'Tous'] + Escrow::ORDER_LABELS],
        ]) . Html::table(['Commande', 'Date', 'Client', 'Étals', '#Total', 'Statut', 'Paiement'], array_map(static fn (array $o): array => [
            '<a href="' . View::url('/pro/commandes/' . $o['id']) . '"><strong>n° ' . (int) $o['id'] . '</strong></a>',
            View::date($o['created_at']), View::e($o['client']), View::e($o['stalls']), View::fcfa($o['total']), self::orderBadge($o['status']),
            View::e(($o['provider'] ?? '—') . ' · ' . ($o['pay_status'] ?? '')) . '<br><span class="muted">' . View::e($o['provider_ref'] ?? '') . '</span>',
        ], array_slice($rows, 0, self::PER_PAGE)), 'Aucune commande.')
            . View::pager('/pro/commandes', array_intersect_key($_GET, array_flip(['q', 'status'])), $page, count($rows) > self::PER_PAGE);
        Html::page('Commandes', $body);
    }

    public function show(Request $req): never
    {
        WebAuth::require(['axi']);
        $id = (int) ($req->params['id'] ?? 0);
        $o = Database::one('SELECT o.*, u.name AS client, u.email, u.phone, pt.name AS point FROM orders o JOIN users u ON u.id = o.client_id LEFT JOIN axi_points pt ON pt.id = o.axi_point_id WHERE o.id = ?', [$id])
            ?? throw new HttpException(404, 'NOT_FOUND', 'Commande introuvable.');
        $p = Database::one('SELECT * FROM payments WHERE order_id = ?', [$id]);
        $parts = Database::all('SELECT os.*, s.name FROM order_stalls os JOIN stalls s ON s.id = os.stall_id WHERE os.order_id = ?', [$id]);
        $events = Database::all('SELECT * FROM payment_events WHERE order_id = ? ORDER BY id', [$id]);
        $disputes = Database::all('SELECT * FROM disputes WHERE order_id = ? ORDER BY id DESC', [$id]);
        $refunds = $p === null ? [] : Database::all('SELECT * FROM refunds WHERE payment_id = ? ORDER BY id', [$p['id']]);
        $base = '/pro/commandes/' . $id;

        $summary = Html::kpis([
            'Total payé par le client' => View::fcfa($o['total']),
            'Articles' => View::fcfa($o['items_total']),
            'Regroupement + réception' => View::fcfa((int) $o['grouping_fee'] + (int) $o['reception_fee']),
            'Commission Axi' => View::fcfa($o['commission_total']),
        ]);
        $info = '<div class="card"><dl><dt>Statut</dt><dd>' . self::orderBadge($o['status']) . '</dd>'
            . '<dt>Client</dt><dd>' . View::e($o['client'] . ' · ' . $o['email'] . ($o['phone'] ? ' · ' . $o['phone'] : '')) . '</dd>'
            . '<dt>Réception</dt><dd>' . View::e(['delivery' => 'Livraison', 'bus' => 'Expédition car', 'axi_point' => 'Point Axi'][$o['reception_mode']] . ' : ' . ($o['point'] ?? $o['reception_detail'])) . '</dd>'
            . '<dt>Paiement</dt><dd>' . ($p === null ? '—' : View::e($p['provider'] . ' · ' . $p['status'] . ' · réf. Axi ' . $p['reference'] . ' · passerelle ' . ($p['provider_ref'] ?? '—'))) . '</dd>'
            . '<dt>Créée</dt><dd>' . View::date($o['created_at']) . '</dd><dt>Payée</dt><dd>' . View::date($o['paid_at']) . '</dd><dt>Reçue</dt><dd>' . View::date($o['delivered_at']) . '</dd></dl>'
            . ($p !== null && $p['status'] === 'pending' ? '<div class="actions" style="margin-top:12px">' . View::action('/pro/paiements/' . $p['id'] . '/verifier', 'Vérifier le statut auprès de la passerelle') . '</div>' : '')
            . '</div>';

        $partsRows = array_map(static function (array $part) use ($base): array {
            $items = Database::all('SELECT name, quantity FROM order_items WHERE order_stall_id = ?', [$part['id']]);
            $actions = [];
            if (in_array($part['status'], ['to_prepare', 'ready'], true)) {
                $actions[] = View::action("$base/enlevement", 'Marquer récupéré', ['part' => $part['id']], 'Confirmer que la marchandise de cet étal a été récupérée ?', 'btn ghost');
            }
            if (in_array($part['escrow_state'], ['held', 'delivered'], true)) {
                $actions[] = View::action("$base/liberer", 'Libérer au commerçant', ['part' => $part['id']], 'Libérer ' . View::fcfa($part['net_amount']) . ' au commerçant ? Cette action est définitive.', 'btn ok');
            }
            return [
                '<strong>' . View::e($part['name']) . '</strong><br><span class="muted">' . View::e(implode(', ', array_map(static fn (array $i): string => $i['quantity'] . ' × ' . $i['name'], $items))) . '</span>',
                View::e(Escrow::STALL_LABELS[$part['status']]), self::escrowBadge($part['escrow_state']),
                View::fcfa($part['items_total']), View::fcfa($part['commission']), View::fcfa($part['net_amount']), View::fcfa($part['refunded_amount']),
                '<div class="actions">' . implode('', $actions) . '</div>',
            ];
        }, $parts);

        $refundForm = $p !== null && in_array($p['status'], ['paid', 'partially_refunded'], true)
            ? '<div class="card"><h2>Rembourser le client</h2><p class="muted">Laissez le montant vide pour un remboursement total (articles et frais). Un remboursement partiel réduit d\'autant la part des commerçants.</p>'
                . Html::form("$base/rembourser", [
                    'amount' => ['Montant (FCFA, vide = total)', 'number', '', ['attrs' => ' min="1"']],
                    'reason' => ['Motif', 'text', '', null],
                ], 'Rembourser', 'Êtes-vous sûr de vouloir rembourser ce client ? L\'opération est transmise à la passerelle.') . '</div>'
            : '';

        $disputeHtml = '';
        foreach ($disputes as $d) {
            $disputeHtml .= '<div class="card"><h2>Litige du ' . View::date($d['created_at']) . ' ' . View::badge(['open' => 'Ouvert', 'resolved_refund' => 'Remboursé', 'resolved_release' => 'Commerçants payés', 'rejected' => 'Rejeté'][$d['status']], $d['status'] === 'open' ? 'danger' : 'muted') . '</h2>'
                . '<p>' . nl2br(View::e($d['reason'])) . '</p>'
                . ($d['status'] === 'open'
                    ? Html::form('/pro/litiges/' . $d['id'], [
                        'decision' => ['Décision', 'select', 'refund', ['refund' => 'Rembourser le client', 'release' => 'Payer le commerçant']],
                        'amount' => ['Montant à rembourser (vide = total)', 'number', '', ['attrs' => ' min="1"']],
                        'note' => ['Explication envoyée au client', 'textarea', '', null],
                    ], 'Trancher le litige', 'Confirmer cette décision ? Elle est définitive et le client est prévenu.')
                    : '<p class="muted">Décision : ' . View::e($d['resolution'] ?? '') . ' (' . View::date($d['resolved_at']) . ')</p>')
                . '</div>';
        }

        $body = $summary . '<div class="row2">' . $info . $refundForm . '</div>' . $disputeHtml
            . '<h2>Parts par étal (séquestre)</h2>' . Html::table(['Étal', 'Préparation', 'Séquestre', '#Articles', '#Commission', '#Net commerçant', '#Remboursé', ''], $partsRows)
            . ($refunds !== [] ? '<h2>Remboursements</h2>' . Html::table(['Date', '#Montant', 'Motif', 'Statut'], array_map(static fn (array $r): array => [View::date($r['created_at']), View::fcfa($r['amount']), View::e($r['reason']), View::e($r['status'])], $refunds)) : '')
            . '<h2>Journal des états</h2>' . Html::table(['Date', 'Événement', 'Par', 'Avant → après', 'Référence', '#Montant'], array_map(static fn (array $e): array => [
                View::date($e['created_at']), View::e($e['event']), View::e($e['actor_type'] . ($e['actor_id'] ? ' #' . $e['actor_id'] : '')),
                View::e(($e['old_state'] ?? '∅') . ' → ' . ($e['new_state'] ?? '∅')), View::e($e['gateway_ref'] ?? ''), $e['amount'] === null ? '' : View::fcfa($e['amount']),
            ], $events));
        Html::page('Commande n° ' . $id, $body);
    }

    public function pickup(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $id = (int) ($req->params['id'] ?? 0);
        $partId = (int) ($_POST['part'] ?? 0);
        $n = Database::run("UPDATE order_stalls SET status = 'picked_up', picked_up_at = ? WHERE id = ? AND order_id = ? AND status IN ('to_prepare','ready')", [Clock::now(), $partId, $id])->rowCount();
        if ($n > 0) {
            Escrow::syncPreparation($id);
            Escrow::log('stall_picked_up', ['order' => $id, 'stall_part' => $partId], 'partner', (int) $agent['id'], 'ready', 'picked_up');
            $this->log($req, $agent, 'order.pickup', $id, ['part' => $partId]);
        }
        View::redirect('/pro/commandes/' . $id, $n > 0 ? 'Part marquée récupérée.' : 'Rien à récupérer.');
    }

    public function release(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $id = (int) ($req->params['id'] ?? 0);
        $part = Database::one('SELECT * FROM order_stalls WHERE id = ? AND order_id = ?', [(int) ($_POST['part'] ?? 0), $id]) ?? throw new HttpException(404, 'NOT_FOUND', 'Part introuvable.');
        Escrow::releasePart($part, 'partner', (int) $agent['id']);
        Escrow::syncOrderAfterEscrow($id);
        $this->log($req, $agent, 'escrow.release', $id, ['part' => $part['id'], 'amount' => $part['net_amount']]);
        View::redirect('/pro/commandes/' . $id, View::fcfa($part['net_amount']) . ' libérés au commerçant.');
    }

    public function refund(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $id = (int) ($req->params['id'] ?? 0);
        $amount = trim((string) ($_POST['amount'] ?? '')) === '' ? null : (int) $_POST['amount'];
        $reason = trim((string) ($_POST['reason'] ?? '')) ?: 'Remboursement décidé par un agent Axi';
        $parts = Database::all("SELECT * FROM order_stalls WHERE order_id = ? AND escrow_state IN ('held','disputed','delivered')", [$id]);
        try {
            $message = Escrow::refund($id, $parts, $amount, $reason, (int) $agent['id']);
        } catch (HttpException $e) {
            View::redirect('/pro/commandes/' . $id, $e->getMessage(), 'err');
        }
        $this->log($req, $agent, 'escrow.refund', $id, ['amount' => $amount, 'reason' => $reason]);
        View::redirect('/pro/commandes/' . $id, 'Remboursement enregistré : ' . $message);
    }

    public function disputes(Request $req): never
    {
        WebAuth::require(['axi']);
        $status = (string) ($_GET['status'] ?? 'open');
        $rows = Database::all(
            'SELECT d.*, u.name AS client, o.total FROM disputes d JOIN users u ON u.id = d.client_id JOIN orders o ON o.id = d.order_id'
            . ($status === 'all' ? '' : ' WHERE d.status = \'open\'') . ' ORDER BY d.id DESC LIMIT 200',
        );
        Html::page('Litiges', Html::filters('/pro/litiges', ['status' => ['Afficher', 'select', ['open' => 'Litiges ouverts', 'all' => 'Tous']]])
            . Html::table(['Date', 'Commande', 'Client', '#Montant', 'Motif', 'Statut'], array_map(static fn (array $d): array => [
                View::date($d['created_at']), '<a class="btn ghost" href="' . View::url('/pro/commandes/' . $d['order_id']) . '">n° ' . (int) $d['order_id'] . '</a>',
                View::e($d['client']), View::fcfa($d['total']), View::e(mb_strimwidth($d['reason'], 0, 120, '…')),
                View::badge(['open' => 'Ouvert', 'resolved_refund' => 'Remboursé', 'resolved_release' => 'Commerçants payés', 'rejected' => 'Rejeté'][$d['status']], $d['status'] === 'open' ? 'danger' : 'muted'),
            ], $rows), 'Aucun litige ouvert.'), 'L\'argent reste bloqué tant que le litige n\'est pas tranché.');
    }

    public function resolve(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $id = (int) ($req->params['id'] ?? 0);
        $dispute = Database::one('SELECT * FROM disputes WHERE id = ?', [$id]) ?? throw new HttpException(404, 'NOT_FOUND', 'Litige introuvable.');
        $decision = ($_POST['decision'] ?? '') === 'release' ? 'release' : 'refund';
        $amount = trim((string) ($_POST['amount'] ?? '')) === '' ? null : (int) $_POST['amount'];
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($note === '') {
            View::redirect('/pro/commandes/' . $dispute['order_id'], 'Expliquez la décision au client.', 'err');
        }
        try {
            $message = Escrow::resolveDispute($id, $decision, $amount, mb_substr($note, 0, 1000), (int) $agent['id']);
        } catch (HttpException $e) {
            View::redirect('/pro/commandes/' . $dispute['order_id'], $e->getMessage(), 'err');
        }
        $this->log($req, $agent, 'dispute.resolve', (int) $dispute['order_id'], ['dispute' => $id, 'decision' => $decision, 'amount' => $amount]);
        View::redirect('/pro/commandes/' . $dispute['order_id'], 'Litige tranché. ' . $message);
    }

    private function log(Request $req, array $agent, string $action, int $orderId, array $details = []): void
    {
        $req->user = $agent;
        AdminLog::record($req, $action, 'order', $orderId, $details);
    }
}
