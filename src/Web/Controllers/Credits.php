<?php
declare(strict_types=1);

namespace Axi\Web\Controllers;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Services\AdminLog;
use Axi\Services\Notifier;
use Axi\Web\Html;
use Axi\Web\View;
use Axi\Web\WebAuth;

/**
 * IMF : crédits demandés et accordés. L'historique d'un commerçant n'est visible QUE s'il a donné un
 * consentement actif à cette IMF (table data_sharing_consents) ; s'il le retire, le dossier disparaît.
 */
final class Credits
{
    private const STATUS = ['new' => ['Nouvelle demande', 'warn'], 'in_review' => ['En étude', 'warn'], 'granted' => ['Accordé', 'ok'], 'declined' => ['Refusé', 'danger'], 'repaid' => ['Remboursé', 'ok']];

    public function index(Request $req): never
    {
        $user = WebAuth::require(['axi', 'mfi']);
        $isAxi = $user['partner_type'] === 'axi';
        $credits = Database::all(
            'SELECT c.*, s.id AS stall_id, s.name AS stall, si.name AS site, a.name AS aisle, o.name AS org,
                    (SELECT id FROM data_sharing_consents k WHERE k.merchant_user_id = c.merchant_user_id AND k.organization_id = c.organization_id AND k.revoked_at IS NULL LIMIT 1) AS consent_id
             FROM credits c JOIN organizations o ON o.id = c.organization_id JOIN stalls s ON s.user_id = c.merchant_user_id JOIN sites si ON si.id = s.site_id JOIN aisles a ON a.id = s.aisle_id'
            . ($isAxi ? '' : ' WHERE c.organization_id = ?') . ' ORDER BY c.id DESC LIMIT 200',
            $isAxi ? [] : [$user['organization_id']],
        );
        $totals = Database::one(
            "SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS amount, SUM(status = 'repaid') AS repaid, SUM(status IN ('granted','repaid')) AS granted FROM credits WHERE status IN ('granted','repaid')" . ($isAxi ? '' : ' AND organization_id = ?'),
            $isAxi ? [] : [$user['organization_id']],
        );
        $files = Database::all(
            'SELECT k.*, s.id AS stall_id, s.name AS stall, si.name AS site, a.name AS aisle FROM data_sharing_consents k JOIN stalls s ON s.user_id = k.merchant_user_id
             JOIN sites si ON si.id = s.site_id JOIN aisles a ON a.id = s.aisle_id WHERE k.revoked_at IS NULL' . ($isAxi ? '' : ' AND k.organization_id = ?') . ' ORDER BY k.id DESC',
            $isAxi ? [] : [$user['organization_id']],
        );
        $body = Html::kpis([
            'Crédits accordés' => (string) $totals['n'],
            'Montant accordé' => View::fcfa($totals['amount']),
            'Remboursés' => (string) (int) $totals['repaid'],
            'Dossiers partagés' => (string) count($files),
        ]) . '<h2>Demandes de crédit</h2>' . Html::table(['Date', 'Commerçant', 'Site · allée', '#Demandé', '#Accordé', 'Statut', 'Décision'], array_map(function (array $c) use ($isAxi): array {
            [$label, $tone] = self::STATUS[$c['status']];
            $name = $c['consent_id'] !== null
                ? '<a href="' . View::url('/pro/credits/dossier/' . $c['consent_id']) . '">' . View::e($c['stall']) . '</a>'
                : View::e($c['stall']) . '<br><span class="muted">Partage retiré : historique non visible</span>';
            $decision = '';
            if (!$isAxi && in_array($c['status'], ['new', 'in_review', 'granted'], true)) {
                $decision = Html::form('/pro/credits/' . $c['id'], [
                    'status' => ['Statut', 'select', $c['status'], ['in_review' => 'En étude', 'granted' => 'Accordé', 'declined' => 'Refusé', 'repaid' => 'Remboursé']],
                    'amount' => ['Montant accordé', 'number', (string) ($c['amount'] ?? ''), ['attrs' => ' min="0"']],
                    'note' => ['Note pour le commerçant', 'text', (string) ($c['note'] ?? ''), null],
                ], 'Enregistrer', 'Enregistrer cette décision ? Le commerçant sera prévenu.');
            }
            return [View::date($c['requested_at'], false), $name . ($isAxi ? '<br><span class="muted">' . View::e($c['org']) . '</span>' : ''), View::e($c['site'] . ' · ' . $c['aisle']),
                $c['amount_requested'] === null ? '—' : View::fcfa($c['amount_requested']), $c['amount'] === null ? '—' : View::fcfa($c['amount']), View::badge($label, $tone), $decision];
        }, $credits), 'Aucune demande de crédit.')
            . '<h2>Dossiers partagés avec consentement</h2>' . Html::table(['Commerçant', 'Site · allée', 'Accord donné le', 'Périmètre', ''], array_map(static fn (array $f): array => [
                View::e($f['stall']), View::e($f['site'] . ' · ' . $f['aisle']), View::date($f['granted_at'], false), View::e($f['scope']),
                '<a class="btn ghost" href="' . View::url('/pro/credits/dossier/' . $f['id']) . '">Voir l\'historique</a>',
            ], $files), 'Aucun commerçant n\'a encore accepté de partager son historique.');
        Html::page('Crédits', $body, 'Seuls les commerçants qui ont coché « Partager mon historique » apparaissent ici ; l\'accord est révocable à tout moment.');
    }

    /** Historique mensuel d'un commerçant, uniquement sous consentement actif */
    public function file(Request $req): never
    {
        $user = WebAuth::require(['axi', 'mfi']);
        $consent = Database::one('SELECT * FROM data_sharing_consents WHERE id = ? AND revoked_at IS NULL', [(int) ($req->params['id'] ?? 0)]);
        if ($consent === null || ($user['partner_type'] !== 'axi' && (int) $consent['organization_id'] !== (int) $user['organization_id'])) {
            throw new HttpException(403, 'FORBIDDEN', 'Ce commerçant n\'a pas (ou plus) donné son accord de partage à votre organisation.');
        }
        $stall = Database::one('SELECT s.*, si.name AS site, a.name AS aisle FROM stalls s JOIN sites si ON si.id = s.site_id JOIN aisles a ON a.id = s.aisle_id WHERE s.user_id = ?', [$consent['merchant_user_id']]);
        $months = Database::all(
            "SELECT DATE_FORMAT(o.paid_at, '%Y-%m') AS month, COUNT(*) AS orders, SUM(os.items_total - os.refunded_amount) AS sales
             FROM order_stalls os JOIN orders o ON o.id = os.order_id WHERE os.stall_id = ? AND o.paid_at IS NOT NULL AND os.status <> 'cancelled'
             GROUP BY month ORDER BY month DESC LIMIT 24",
            [$stall['id']],
        );
        $avg = $months === [] ? 0 : intdiv((int) array_sum(array_column($months, 'sales')), count($months));
        $req->user = $user;
        AdminLog::record($req, 'credit.file_view', 'stall', (int) $stall['id'], ['consent' => $consent['id']]);
        Html::page($stall['name'], Html::kpis([
            'Mois d\'historique' => (string) count($months),
            'Ventes mensuelles moyennes' => View::fcfa($avg),
            'Profil' => $stall['merchant_type'] === 'wholesale' ? 'Grossiste' : 'Détaillant',
            'Vérifié sur place' => $stall['verified_at'] !== null ? 'Oui' : 'Non',
        ]) . '<p class="muted">Accord donné le ' . View::date($consent['granted_at'], false) . ' · périmètre : ' . View::e($consent['scope']) . '. Aucune donnée sur les clients du commerçant n\'est partagée.</p>'
            . Html::table(['Mois', '#Commandes', '#Ventes'], array_map(static fn (array $m): array => [View::e($m['month']), (string) $m['orders'], View::fcfa($m['sales'])], $months), 'Pas encore de ventes payées en ligne.'),
            $stall['site'] . ' · ' . $stall['aisle']);
    }

    public function update(Request $req): never
    {
        $user = WebAuth::require(['mfi']);
        WebAuth::checkCsrf();
        $id = (int) ($req->params['id'] ?? 0);
        $c = Database::one('SELECT * FROM credits WHERE id = ? AND organization_id = ?', [$id, $user['organization_id']]) ?? throw new HttpException(404, 'NOT_FOUND', 'Demande introuvable.');
        $status = array_key_exists($_POST['status'] ?? '', self::STATUS) ? $_POST['status'] : $c['status'];
        $amount = trim((string) ($_POST['amount'] ?? '')) === '' ? null : max(0, (int) $_POST['amount']);
        if ($status === 'granted' && ($amount === null || $amount <= 0)) {
            View::redirect('/pro/credits', 'Indiquez le montant accordé.', 'err');
        }
        Database::run('UPDATE credits SET status = ?, amount = ?, note = ?, decided_by = ?, decided_at = ? WHERE id = ?', [$status, $amount, mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 300) ?: null, $user['id'], Clock::now(), $id]);
        $req->user = $user;
        AdminLog::record($req, 'credit.update', 'credit', $id, ['status' => $status, 'amount' => $amount]);
        Notifier::send((int) $c['merchant_user_id'], 'account', 'Votre demande de crédit', 'Statut : ' . self::STATUS[$status][0] . ($amount ? ' · ' . View::fcfa($amount) : '') . '.');
        View::redirect('/pro/credits', 'Décision enregistrée.');
    }
}
