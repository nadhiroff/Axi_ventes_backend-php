<?php
declare(strict_types=1);

namespace Axi\Web\Controllers;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Services\AdminLog;
use Axi\Services\Credit;
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
    private const STATUS = ['new' => ['Nouvelle demande', 'warn'], 'in_review' => ['En étude', 'warn'], 'offered' => ['Offre envoyée', 'warn'], 'accepted' => ['À verser', 'warn'], 'granted' => ['En remboursement', 'ok'], 'declined' => ['Refusé', 'danger'], 'repaid' => ['Remboursé', 'ok']];

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
        $scope = $isAxi ? '' : ' AND organization_id = ?';
        $scopeArgs = $isAxi ? [] : [$user['organization_id']];
        $totals = Database::one("SELECT SUM(status = 'granted') AS running, COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(repaid_amount), 0) AS repaid, COALESCE(SUM(commission), 0) AS commission FROM credits WHERE status IN ('granted', 'repaid')" . $scope, $scopeArgs);
        $withheld = (int) Database::value("SELECT COALESCE(SUM(r.amount), 0) FROM credit_repayments r JOIN credits c ON c.id = r.credit_id WHERE r.source = 'payout'" . ($isAxi ? '' : ' AND c.organization_id = ?'), $scopeArgs);
        $files = Database::all(
            'SELECT k.*, s.id AS stall_id, s.name AS stall, si.name AS site, a.name AS aisle FROM data_sharing_consents k JOIN stalls s ON s.user_id = k.merchant_user_id
             JOIN sites si ON si.id = s.site_id JOIN aisles a ON a.id = s.aisle_id WHERE k.revoked_at IS NULL' . ($isAxi ? '' : ' AND k.organization_id = ?') . ' ORDER BY k.id DESC',
            $isAxi ? [] : [$user['organization_id']],
        );
        $groups = ['attente' => ['new', 'in_review'], 'offres' => ['offered', 'accepted'], 'accordes' => ['granted'], 'clos' => ['declined', 'repaid']];
        $view = (string) ($_GET['vue'] ?? 'attente');
        $view = isset($groups[$view]) || $view === 'dossiers' ? $view : 'attente';
        $count = static fn (string $key): int => count(array_filter($credits, static fn (array $c): bool => in_array($c['status'], $groups[$key], true)));
        $tabs = ['attente' => 'À traiter (' . $count('attente') . ')', 'offres' => 'Offres et versements (' . $count('offres') . ')', 'accordes' => 'En remboursement (' . $count('accordes') . ')', 'clos' => 'Clos (' . $count('clos') . ')', 'dossiers' => 'Dossiers partagés (' . count($files) . ')'];
        $nav = '<nav class="tabs" aria-label="Demandes">';
        foreach ($tabs as $key => $label) {
            $nav .= '<a href="' . View::url('/pro/credits', ['vue' => $key]) . '" class="' . ($view === $key ? 'on' : '') . '">' . View::e($label) . '</a>';
        }
        $nav .= '</nav>';

        if ($view === 'dossiers') {
            $list = Html::table(['Commerçant', 'Site · allée', 'Accord donné le', 'Périmètre', ''], array_map(static fn (array $f): array => [
                View::e($f['stall']), View::e($f['site'] . ' · ' . $f['aisle']), View::date($f['granted_at'], false), View::e($f['scope']),
                '<a class="btn ghost sm" href="' . View::url('/pro/credits/dossier/' . $f['id']) . '">Voir l\'historique</a>',
            ], $files), 'Aucun commerçant n\'a encore accepté de partager son historique.');
        } else {
            $rows = array_filter($credits, static fn (array $c): bool => in_array($c['status'], $groups[$view], true));
            $list = $rows === []
                ? '<div class="card empty">' . View::e($view === 'attente' ? 'Aucune demande à traiter. Les nouvelles demandes apparaissent ici automatiquement.' : 'Aucune demande ici pour l\'instant.') . '</div>'
                : '<div class="credits">' . implode('', array_map(static fn (array $c): string => self::requestCard($c, $isAxi), $rows)) . '</div>';
        }
        $body = Html::kpis([
            'Crédits en cours' => (string) (int) $totals['running'],
            'Montant versé' => View::fcfa($totals['amount']),
            'Déjà remboursé' => [View::fcfa($totals['repaid']), 'dont ' . View::fcfa($withheld) . ' retenus sur les ventes'],
            'Dossiers partagés' => (string) count($files),
        ] + ($isAxi ? ['Commission Axi' => [View::fcfa($totals['commission']), 'sur les crédits versés']] : [])) . $nav . $list;
        $explain = '<details class="note"><summary>Comment ça marche ?</summary><p>Axi ne prête pas. Un commerçant qui a accepté de partager son historique de ventes peut demander un crédit (stock, équipement) : '
            . 'l\'institution de microfinance (IMF) étudie le dossier sans garantie à fournir et décide. Axi touche une commission sur chaque crédit accordé. '
            . 'Étapes : étudier la demande, envoyer une offre (montant, total à rembourser, durée, retenue sur les ventes), attendre l\'accord du commerçant, verser l\'argent, puis suivre le remboursement. '
            . 'Axi ne déplace pas l\'argent de l\'institution : le versement se fait par ses moyens habituels, puis se note ici. Les retenues sur les ventes restent sur le compte Axi jusqu\'à leur reversement à l\'institution.</p></details>';
        Html::page('Financement', $body . $explain, 'Seuls les commerçants qui ont accepté de partager leur historique apparaissent ici ; l\'accord est révocable à tout moment.');
    }

    /** Une demande : qui, combien, statut, puis la décision. L'IMF décide ; l'agent Axi aussi (pilote sans IMF, ou dossier transmis hors ligne). */
    private static function requestCard(array $c, bool $isAxi): string
    {
        [$label, $tone] = self::STATUS[$c['status']];
        $name = $c['consent_id'] !== null
            ? '<a href="' . View::url('/pro/credits/dossier/' . $c['consent_id']) . '">' . View::e($c['stall']) . '</a>'
            : View::e($c['stall']);
        $facts = [
            'Demandé' => $c['amount_requested'] === null ? 'À définir' : View::fcfa($c['amount_requested']),
            'Reçue le' => View::date($c['requested_at'], false),
            'Marché' => View::e($c['site'] . ' · ' . $c['aisle']),
        ];
        if ($c['repay_total'] !== null) {
            $facts = ['Montant prêté' => View::fcfa($c['amount']), 'À rembourser' => View::fcfa($c['repay_total']), 'Durée' => (int) $c['term_months'] . ' mois', 'Retenue sur les ventes' => (int) $c['withhold_pct'] > 0 ? (int) $c['withhold_pct'] . ' %' : 'Aucune'] + $facts;
        }
        if ($c['disbursed_at'] !== null) {
            $facts['Versé le'] = View::date($c['disbursed_at'], false) . ($c['disbursed_ref'] ? ' · ' . View::e($c['disbursed_ref']) : '');
        }
        $html = '<article class="credit"><header><div><h3>' . $name . '</h3>'
            . ($isAxi ? '<p class="muted small">' . View::e($c['org']) . '</p>' : '')
            . '</div><div class="credit-tags">' . ($c['consent_id'] === null ? View::badge('Partage retiré') : '') . View::badge($label, $tone) . '</div></header><dl class="credit-facts">';
        foreach ($facts as $k => $v) {
            $html .= '<div><dt>' . View::e($k) . '</dt><dd>' . $v . '</dd></div>';
        }
        $html .= '</dl>' . ($c['note'] !== null ? '<p class="credit-note">' . View::e($c['note']) . '</p>' : '');
        if (in_array($c['status'], ['granted', 'repaid'], true)) {
            $pct = (int) round(100 * (int) $c['repaid_amount'] / max(1, (int) $c['repay_total']));
            $html .= '<div class="credit-progress"><div class="credit-bar"><span style="width:' . min(100, $pct) . '%"></span></div><p class="muted small">' . View::fcfa($c['repaid_amount']) . ' remboursés sur ' . View::fcfa($c['repay_total']) . ' · reste ' . View::fcfa(Credit::remaining($c)) . '</p></div>';
        }
        $form = match ($c['status']) {
            'new', 'in_review' => Html::form('/pro/credits/' . $c['id'], [
                'status' => ['Décision', 'select', 'in_review', ['in_review' => 'En étude', 'offered' => 'Envoyer une offre', 'declined' => 'Refuser']],
                'amount' => ['Montant prêté (FCFA)', 'number', '', ['attrs' => ' min="1"']],
                'repay_total' => ['Total à rembourser (FCFA)', 'number', '', ['attrs' => ' min="1"']],
                'term_months' => ['Durée (mois)', 'number', '', ['attrs' => ' min="1" max="36"']],
                'withhold_pct' => ['Retenue sur les ventes (%)', 'number', '0', ['attrs' => ' min="0" max="' . Credit::MAX_WITHHOLD_PCT . '"']],
                'note' => ['Note pour le commerçant', 'text', '', null],
            ], 'Enregistrer', 'La décision sera enregistrée et le commerçant prévenu.'),
            'offered' => '<p class="muted small" style="margin:0 0 10px">En attente de la réponse du commerçant dans l\'application.</p>' . Html::form('/pro/credits/' . $c['id'], [
                'status' => ['', 'hidden', 'declined', null],
                'note' => ['Motif du retrait', 'text', '', null],
            ], 'Retirer l\'offre', 'L\'offre sera retirée et le commerçant prévenu.'),
            'accepted' => '<p class="muted small" style="margin:0 0 10px">Le commerçant a accepté. Versez l\'argent par vos moyens habituels, puis notez-le ici.</p>' . Html::form('/pro/credits/' . $c['id'] . '/versement', [
                'disbursed_ref' => ['Référence du versement', 'text', '', ['attrs' => ' maxlength="80" required']],
            ], 'Marquer comme versé', 'Le crédit passera en remboursement et le commerçant sera prévenu.'),
            'granted' => Html::form('/pro/credits/' . $c['id'] . '/remboursement', [
                'amount' => ['Remboursement reçu (FCFA)', 'number', '', ['attrs' => ' min="1" max="' . Credit::remaining($c) . '" required']],
            ], 'Enregistrer le remboursement', 'Ce remboursement sera ajouté et le commerçant prévenu.'),
            default => '',
        };
        return $html . ($form !== '' ? '<div class="credit-form">' . $form . '</div>' : '') . '</article>';
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
        $user = WebAuth::require(['mfi', 'axi']);
        WebAuth::checkCsrf();
        $id = (int) ($req->params['id'] ?? 0);
        $c = ($user['partner_type'] === 'axi'
            ? Database::one('SELECT * FROM credits WHERE id = ?', [$id])
            : Database::one('SELECT * FROM credits WHERE id = ? AND organization_id = ?', [$id, $user['organization_id']])) ?? throw new HttpException(404, 'NOT_FOUND', 'Demande introuvable.');
        $status = (string) ($_POST['status'] ?? '');
        $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 300);
        $allowed = in_array($c['status'], ['new', 'in_review'], true) ? ['in_review', 'offered', 'declined'] : ($c['status'] === 'offered' ? ['declined'] : []);
        if (!in_array($status, $allowed, true)) {
            View::redirect('/pro/credits', 'Cette décision n\'est plus possible pour cette demande.', 'err');
        }
        $amount = max(0, (int) ($_POST['amount'] ?? 0));
        $total = max(0, (int) ($_POST['repay_total'] ?? 0));
        $term = (int) ($_POST['term_months'] ?? 0);
        $withhold = (int) ($_POST['withhold_pct'] ?? 0);
        if ($status === 'offered') {
            $error = match (true) {
                $amount <= 0 => 'Indiquez le montant prêté.',
                $total < $amount => 'Le total à rembourser ne peut pas être inférieur au montant prêté.',
                $term < 1 || $term > 36 => 'Indiquez une durée de 1 à 36 mois.',
                $withhold < 0 || $withhold > Credit::MAX_WITHHOLD_PCT => 'La retenue sur les ventes va de 0 à ' . Credit::MAX_WITHHOLD_PCT . ' %.',
                default => null,
            };
            if ($error !== null) {
                View::redirect('/pro/credits', $error, 'err');
            }
            Database::run('UPDATE credits SET status = \'offered\', amount = ?, repay_total = ?, term_months = ?, withhold_pct = ?, note = ?, decided_by = ?, decided_at = ? WHERE id = ?', [$amount, $total, $term, $withhold, $note ?: null, $user['id'], Clock::now(), $id]);
        } else {
            Database::run('UPDATE credits SET status = ?, note = ?, decided_by = ?, decided_at = ? WHERE id = ?', [$status, $note ?: null, $user['id'], Clock::now(), $id]);
        }
        $req->user = $user;
        AdminLog::record($req, 'credit.update', 'credit', $id, ['status' => $status, 'amount' => $amount]);
        $text = match ($status) {
            'offered' => 'Offre reçue : ' . View::fcfa($amount) . ' prêtés, ' . View::fcfa($total) . ' à rembourser en ' . $term . ' mois. Répondez dans « Mon argent ».',
            'declined' => 'Votre demande de crédit n\'a pas été retenue.',
            default => 'Votre demande de crédit est en cours d\'étude.',
        };
        Notifier::send((int) $c['merchant_user_id'], 'account', 'Votre demande de crédit', $text . ($note !== '' ? ' ' . $note : ''), [], [], true);
        View::redirect('/pro/credits', 'Décision enregistrée.');
    }

    /** L'institution note le versement (fait par ses moyens) : le remboursement commence */
    public function disburse(Request $req): never
    {
        [$user, $c] = $this->own($req);
        $ref = mb_substr(trim((string) ($_POST['disbursed_ref'] ?? '')), 0, 80);
        if ($c['status'] !== 'accepted' || $ref === '') {
            View::redirect('/pro/credits', $ref === '' ? 'Indiquez la référence du versement.' : 'Ce crédit ne peut pas être versé : le commerçant doit avoir accepté l\'offre.', 'err');
        }
        Database::run('UPDATE credits SET status = \'granted\', disbursed_at = ?, disbursed_ref = ? WHERE id = ? AND status = \'accepted\'', [Clock::now(), $ref, $c['id']]);
        $req->user = $user;
        AdminLog::record($req, 'credit.disburse', 'credit', (int) $c['id'], ['ref' => $ref]);
        Notifier::send((int) $c['merchant_user_id'], 'account', 'Votre crédit a été versé', View::fcfa($c['amount']) . ' versés par votre institution. À rembourser : ' . View::fcfa($c['repay_total']) . '.', [], [], true);
        View::redirect('/pro/credits?vue=accordes', 'Versement enregistré.');
    }

    /** Remboursement reçu directement par l'institution */
    public function repayment(Request $req): never
    {
        [$user, $c] = $this->own($req);
        $amount = max(0, (int) ($_POST['amount'] ?? 0));
        if ($c['status'] !== 'granted' || $amount <= 0) {
            View::redirect('/pro/credits?vue=accordes', 'Indiquez le montant remboursé.', 'err');
        }
        $applied = Credit::repay((int) $c['id'], $amount, 'manual', null, (int) $user['id']);
        $req->user = $user;
        AdminLog::record($req, 'credit.repayment', 'credit', (int) $c['id'], ['amount' => $applied]);
        View::redirect('/pro/credits?vue=accordes', View::fcfa($applied) . ' enregistrés.');
    }

    /** @return array{0: array, 1: array} partenaire connecté et crédit (l'IMF ne voit que les siens) */
    private function own(Request $req): array
    {
        $user = WebAuth::require(['mfi', 'axi']);
        WebAuth::checkCsrf();
        $id = (int) ($req->params['id'] ?? 0);
        $c = ($user['partner_type'] === 'axi'
            ? Database::one('SELECT * FROM credits WHERE id = ?', [$id])
            : Database::one('SELECT * FROM credits WHERE id = ? AND organization_id = ?', [$id, $user['organization_id']])) ?? throw new HttpException(404, 'NOT_FOUND', 'Demande introuvable.');
        return [$user, $c];
    }
}
