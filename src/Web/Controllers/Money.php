<?php
declare(strict_types=1);

namespace Axi\Web\Controllers;

use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Services\AdminLog;
use Axi\Services\Payment\Escrow;
use Axi\Web\Html;
use Axi\Web\View;
use Axi\Web\WebAuth;

/** Agent Axi : transactions FedaPay / KkiaPay, réconciliation avec la base, séquestre et versements */
final class Money
{
    private const PER_PAGE = 40;
    private const STATUS = [
        'pending' => ['En attente', 'warn'], 'paid' => ['Payé', 'ok'], 'failed' => ['Refusé', 'danger'], 'cancelled' => ['Annulé', 'muted'],
        'expired' => ['Expiré', 'muted'], 'refunded' => ['Remboursé', 'muted'], 'partially_refunded' => ['Remboursé en partie', 'warn'],
    ];

    public function payments(Request $req): never
    {
        WebAuth::require(['axi']);
        $where = ['1'];
        $params = [];
        if (($s = (string) ($_GET['status'] ?? '')) !== '' && isset(self::STATUS[$s])) {
            $where[] = 'p.status = ?';
            $params[] = $s;
        }
        if (($q = trim((string) ($_GET['q'] ?? ''))) !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(p.reference LIKE ? OR p.provider_ref LIKE ? OR u.name LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $page = View::page();
        $rows = Database::all(
            'SELECT p.*, u.name FROM payments p JOIN users u ON u.id = p.user_id WHERE ' . implode(' AND ', $where)
            . ' ORDER BY p.id DESC LIMIT ' . (self::PER_PAGE + 1) . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params,
        );
        $escrow = Database::one(
            "SELECT COALESCE(SUM(CASE WHEN escrow_state IN ('held','disputed') THEN net_amount END),0) AS held,
                    COALESCE(SUM(CASE WHEN escrow_state = 'released' AND payout_id IS NULL THEN net_amount END),0) AS to_pay,
                    COALESCE(SUM(CASE WHEN escrow_state = 'disputed' THEN net_amount END),0) AS disputed,
                    COALESCE(SUM(CASE WHEN escrow_state IN ('held','released','delivered') THEN commission END),0) AS commission
             FROM order_stalls",
        );
        $body = Html::kpis([
            'Bloqué chez Axi (séquestre)' => View::fcfa($escrow['held']),
            'Libéré, à verser aux commerçants' => View::fcfa($escrow['to_pay']),
            'En litige' => View::fcfa($escrow['disputed']),
            'Commissions Axi' => View::fcfa($escrow['commission']),
        ]) . '<div class="actions" style="margin-bottom:14px">' . View::action('/pro/paiements/reconcilier', 'Réconcilier les paiements en attente avec la passerelle', [], 'Interroger la passerelle pour tous les paiements en attente ?', 'btn ghost') . '</div>'
            . Html::filters('/pro/paiements', ['q' => ['Référence Axi, passerelle ou client', 'search', null], 'status' => ['Statut', 'select', ['' => 'Tous'] + array_map(static fn (array $s): string => $s[0], self::STATUS)]])
            . Html::table(['Date', 'Référence Axi', 'Passerelle', 'Objet', 'Client', '#Montant', 'Statut', 'Dernière vérification', ''], array_map(static function (array $p): array {
                [$label, $tone] = self::STATUS[$p['status']];
                return [
                    View::date($p['created_at']), View::e($p['reference']), View::e($p['provider']) . '<br><span class="muted">' . View::e($p['provider_ref'] ?? '—') . ' · ' . View::e($p['gateway_status'] ?? '') . '</span>',
                    $p['order_id'] !== null ? '<a href="' . View::url('/pro/commandes/' . $p['order_id']) . '">cmd_' . (int) $p['order_id'] . '</a>' : 'Abonnement',
                    View::e($p['name']), View::fcfa($p['amount']) . ((int) $p['refunded_amount'] > 0 ? '<br><span class="muted">remb. ' . View::fcfa($p['refunded_amount']) . '</span>' : ''),
                    View::badge($label, $tone), View::date($p['checked_at']),
                    $p['status'] === 'pending' ? View::action('/pro/paiements/' . $p['id'] . '/verifier', 'Vérifier', [], null, 'btn ghost') : '',
                ];
            }, array_slice($rows, 0, self::PER_PAGE)), 'Aucun paiement.')
            . View::pager('/pro/paiements', array_intersect_key($_GET, array_flip(['q', 'status'])), $page, count($rows) > self::PER_PAGE);
        Html::page('Paiements et séquestre', $body, 'Transactions FedaPay / KkiaPay : le statut « payé » est toujours vérifié auprès de la passerelle.');
    }

    public function verify(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $id = (int) ($req->params['id'] ?? 0);
        $before = Database::one('SELECT * FROM payments WHERE id = ?', [$id]) ?? throw new HttpException(404, 'NOT_FOUND', 'Paiement introuvable.');
        Escrow::ensureInitiated($id);
        $after = Escrow::refresh($id, 'partner', (int) $agent['id']);
        $req->user = $agent;
        AdminLog::record($req, 'payment.verify', 'payment', $id, ['before' => $before['status'], 'after' => $after['status'], 'gateway' => $after['gateway_status']]);
        $back = $before['order_id'] !== null && str_contains((string) ($_SERVER['HTTP_REFERER'] ?? ''), '/commandes/') ? '/pro/commandes/' . $before['order_id'] : '/pro/paiements';
        View::redirect($back, 'Passerelle : ' . ($after['gateway_status'] ?? 'pas de réponse') . ' → statut Axi « ' . self::STATUS[$after['status']][0] . ' ».');
    }

    /** Réconciliation : chaque paiement en attente est vérifié auprès de la passerelle */
    public function reconcile(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $changed = 0;
        $ids = array_column(Database::all("SELECT id FROM payments WHERE status = 'pending' AND payment_url IS NOT NULL ORDER BY id DESC LIMIT 100"), 'id');
        foreach ($ids as $id) {
            $changed += (int) (Escrow::refresh((int) $id, 'partner', (int) $agent['id'])['status'] !== 'pending');
        }
        $req->user = $agent;
        AdminLog::record($req, 'payment.reconcile', 'payment', null, ['checked' => count($ids), 'changed' => $changed]);
        View::redirect('/pro/paiements', count($ids) . ' paiement(s) vérifié(s), ' . $changed . ' mis à jour.');
    }

    public function payouts(Request $req): never
    {
        WebAuth::require(['axi']);
        $due = Database::all(
            "SELECT s.id, s.name, s.phone, SUM(os.net_amount) AS amount, COUNT(*) AS parts FROM order_stalls os JOIN stalls s ON s.id = os.stall_id
             WHERE os.escrow_state = 'released' AND os.payout_id IS NULL GROUP BY s.id, s.name, s.phone HAVING amount > 0 ORDER BY amount DESC",
        );
        $history = Database::all('SELECT p.*, s.name FROM payouts p JOIN stalls s ON s.id = p.stall_id ORDER BY p.id DESC LIMIT 50');
        $body = '<h2 style="margin-top:0">À verser</h2>' . Html::table(['Étal', 'Téléphone Mobile Money', '#Commandes', '#Montant', ''], array_map(static fn (array $d): array => [
            '<a href="' . View::url('/pro/commercants/' . $d['id']) . '">' . View::e($d['name']) . '</a>', View::e($d['phone'] ?? '—'), (string) $d['parts'], View::fcfa($d['amount']),
            View::action('/pro/commercants/' . $d['id'] . '/verser', 'Verser', [], 'Confirmer le versement de ' . View::fcfa($d['amount']) . ' à ' . $d['name'] . ' ?', 'btn ok'),
        ], $due), 'Aucune somme libérée en attente de versement.')
            . '<h2>Historique des versements</h2>' . Html::table(['Date', 'Étal', '#Montant', 'Passerelle', 'Statut'], array_map(static fn (array $p): array => [
                View::date($p['created_at']), View::e($p['name']), View::fcfa($p['amount']), View::e($p['provider'] . ' ' . ($p['provider_ref'] ?? '')),
                View::badge(['pending' => 'En cours', 'simulated' => 'Simulé (sandbox)', 'paid' => 'Versé', 'failed' => 'Échec'][$p['status']], $p['status'] === 'failed' ? 'danger' : 'ok'),
            ], $history), 'Aucun versement.');
        Html::page('Versements', $body, 'En sandbox, les versements sont simulés et enregistrés ; en production, ils passent par l\'API de la passerelle.');
    }
}
