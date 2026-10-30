<?php
declare(strict_types=1);

namespace Axi\Web\Controllers;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\Request;
use Axi\Services\Settings;
use Axi\Web\Html;
use Axi\Web\View;
use Axi\Web\WebAuth;

/**
 * Tableau de bord et statistiques. Pour ANaGeM, annonceurs et IMF : données AGRÉGÉES et ANONYMES
 * seulement, jamais calculées sur moins de N étals (réglage min_group_size, 5 par défaut).
 */
final class Stats
{
    /**
     * Tableau de bord, comme la maquette de la note de cadrage : quatre chiffres clés, prix médians et, à droite,
     * une action utile au partenaire (nouvelle campagne, demandes de crédit, ou tout ce qu'un agent Axi doit traiter).
     */
    public function dashboard(Request $req): never
    {
        $user = WebAuth::require();
        $type = $user['partner_type'];
        $min = Settings::int('min_group_size');
        $month = Clock::now(-30 * 86400);
        $stalls = Database::one("SELECT COUNT(*) AS n, SUM(s.has_physical_place = 0) AS no_place FROM stalls s JOIN users u ON u.id = s.user_id WHERE s.status = 'active' AND u.status = 'active'");
        $active = Database::one("SELECT COUNT(DISTINCT os.stall_id) AS n, COUNT(DISTINCT s.site_id) AS sites FROM order_stalls os JOIN orders o ON o.id = os.order_id JOIN stalls s ON s.id = os.stall_id WHERE o.paid_at > ?", [$month]);
        $orders = Database::one("SELECT COUNT(*) AS n, SUM(reception_mode = 'bus') AS outside FROM orders WHERE paid_at > ?", [$month]);
        $credits = Database::one("SELECT COUNT(*) AS n FROM credits WHERE status IN ('granted','repaid')" . ($type === 'mfi' ? ' AND organization_id = ?' : ''), $type === 'mfi' ? [$user['organization_id']] : []);
        $sites = (int) Database::value("SELECT COUNT(*) FROM sites WHERE active = 1 AND id <> 'autre'");
        // Seuil d'anonymat pour les partenaires extérieurs ; l'équipe Axi voit les vrais chiffres
        $enough = $type === 'axi' || (int) $stalls['n'] >= $min;
        $hide = static fn (int|string $v): string => $enough ? (string) $v : '< ' . $min;
        $kpis = Html::kpis([
            'Étals recensés' => [$hide((int) $stalls['n']), $enough ? 'dont ' . (int) $stalls['no_place'] . ' sans place physique' : 'statistiques publiées à partir de ' . $min . ' étals'],
            'Étals actifs ce mois' => [$enough ? (string) (int) $active['n'] : '—', 'sur ' . $sites . ' sites'],
            'Commandes en ligne' => [$enough ? (string) (int) $orders['n'] : '—', $enough && (int) $orders['n'] > 0 ? 'dont ' . round(100 * $orders['outside'] / $orders['n']) . ' % hors Cotonou' : '30 derniers jours'],
            'Crédits accordés via Axi' => [(string) (int) $credits['n'], 'avec l\'accord des commerçants'],
        ]);

        $todo = '';
        if ($type === 'axi') {
            $c = Database::one(
                "SELECT (SELECT COUNT(*) FROM merchant_applications WHERE status = 'pending') AS applications,
                        (SELECT COUNT(*) FROM disputes WHERE status = 'open') AS disputes,
                        (SELECT COUNT(*) FROM agent_visit_requests WHERE status IN ('new','scheduled')) AS visits,
                        (SELECT COUNT(DISTINCT stall_id) FROM order_stalls WHERE escrow_state = 'released' AND payout_id IS NULL AND net_amount > 0) AS payouts,
                        (SELECT COUNT(*) FROM campaigns WHERE status = 'pending_review') AS campaigns,
                        (SELECT COUNT(*) FROM payments WHERE status = 'pending' AND created_at < ?) AS stale",
                [Clock::now(-3600)],
            );
            $todo = Html::title('À traiter') . str_replace('class="grid"', 'class="grid three"', Html::kpis([
                'Demandes commerçants' => [(string) $c['applications'], 'pièces à vérifier', '/pro/demandes'],
                'Litiges ouverts' => [(string) $c['disputes'], 'argent bloqué en attente', '/pro/litiges'],
                'Commerçants à payer' => [(string) $c['payouts'], 'sommes libérées', '/pro/versements'],
                'Vérifications sur place' => [(string) $c['visits'], 'étals déplacés', '/pro/verifications'],
                'Campagnes à relire' => [(string) $c['campaigns'], 'annonceurs', '/pro/campagnes'],
                'Paiements bloqués' => [(string) $c['stale'], 'en attente depuis plus d\'une heure', '/pro/paiements?status=pending'],
            ]));
        }

        $prices = Database::all(
            'SELECT ps.median_price, ps.sample_size, r.name AS product, si.name AS site,
                    (SELECT old.median_price FROM price_stats_daily old WHERE old.product_ref_id = ps.product_ref_id AND old.site_id = ps.site_id AND old.day <= ps.day - INTERVAL 30 DAY AND old.sample_size >= ? ORDER BY old.day DESC LIMIT 1) AS old_price
             FROM price_stats_daily ps JOIN product_refs r ON r.id = ps.product_ref_id JOIN sites si ON si.id = ps.site_id
             WHERE ps.sample_size >= ? AND ps.day = (SELECT MAX(day) FROM price_stats_daily l WHERE l.product_ref_id = ps.product_ref_id AND l.site_id = ps.site_id)
             ORDER BY ps.sample_size DESC LIMIT 8',
            [$min, $min],
        );
        $priceCard = '<div class="card"><div class="section-title"><h2>Prix médians par produit</h2><a class="btn ghost sm" href="' . View::url('/pro/prix') . '">Toutes les tendances</a></div>'
            . Html::table(['Produit', 'Site', '#Prix médian', '#Sur 30 jours'], array_map(static function (array $r): array {
                $change = $r['old_price'] !== null && (int) $r['old_price'] > 0 ? round(100 * ((int) $r['median_price'] - (int) $r['old_price']) / (int) $r['old_price'], 1) : null;
                return [View::e($r['product']), View::e($r['site']), number_format((int) $r['median_price'], 0, ',', ' ') . ' F', $change === null ? '—' : ($change > 0 ? '+' : '') . str_replace('.', ',', (string) $change) . ' %'];
            }, $prices), 'Les prix médians apparaissent dès que ' . $min . ' étals vendent le même produit sur un site.') . '</div>';

        $side = match ($type) {
            'advertiser' => '<div class="card"><h2>Nouvelle annonce</h2><p class="muted">Un titre, une description, une image ou une vidéo : elle est publiée après relecture.</p><a class="btn" href="' . View::url('/pro/campagnes') . '">Créer une annonce</a><p class="muted small" style="margin:14px 0 0">Aucune donnée personnelle n\'est transmise à l\'annonceur.</p></div>',
            'mfi', 'axi' => self::creditsCard($user),
            default => '<div class="card"><h2>Recensement</h2><p class="muted">Répartition des étals par site et par allée, données agrégées et anonymes.</p><a class="btn" href="' . View::url('/pro/recensement') . '">Voir le recensement</a></div>',
        };

        // Bandeau : le plus utile d'abord (ce qui attend une action pour l'équipe Axi, le rôle du partenaire sinon)
        $links = match ($type) {
            'axi' => [['Demandes commerçants', '/pro/demandes'], ['Paiements et versements', '/pro/paiements']],
            'advertiser' => [['Mes annonces', '/pro/campagnes'], ['Tendances du marché', '/pro/prix']],
            'mfi' => [['Demandes de financement', '/pro/credits'], ['Tendances du marché', '/pro/prix']],
            default => [['Recensement des étals', '/pro/recensement'], ['Tendances du marché', '/pro/prix']],
        };
        Html::page(
            'Bonjour ' . $user['first_name'],
            $kpis . $todo . '<div class="split">' . $priceCard . '<div>' . $side . '</div></div>',
            'Voici ce qui se passe sur les marchés aujourd\'hui. Les données montrées aux partenaires sont agrégées et anonymes.',
            ['eyebrow' => 'Tableau de bord', 'links' => $links],
        );
    }

    /** Dernières demandes de crédit (IMF : les siennes ; agent Axi : toutes) */
    private static function creditsCard(array $user): string
    {
        $isAxi = $user['partner_type'] === 'axi';
        $rows = Database::all(
            "SELECT c.status, c.amount_requested, c.requested_at, s.name AS stall FROM credits c JOIN stalls s ON s.user_id = c.merchant_user_id
             WHERE c.status IN ('new','in_review')" . ($isAxi ? '' : ' AND c.organization_id = ?') . ' ORDER BY c.id DESC LIMIT 6',
            $isAxi ? [] : [$user['organization_id']],
        );
        $list = $rows === [] ? '<p class="muted">Aucune demande de crédit en attente.</p>' : '<dl>' . implode('', array_map(static fn (array $r): string =>
            '<dt>' . View::e($r['stall']) . '</dt><dd>' . ($r['amount_requested'] !== null ? View::fcfa($r['amount_requested']) : 'Montant à définir') . ' · ' . ($r['status'] === 'new' ? View::badge('Nouvelle', 'warn') : View::badge('En étude')) . '</dd>', $rows)) . '</dl>';
        return '<div class="card"><h2>Financement</h2>' . $list . '<p style="margin:16px 0 0"><a class="btn ghost" href="' . View::url('/pro/credits') . '">Toutes les demandes</a></p>'
            . '<p class="muted small" style="margin:14px 0 0">Seuls les commerçants qui ont accepté de partager leur historique apparaissent.</p></div>';
    }

    /** Recensement des étals par site et par allée (groupes trop petits regroupés ou masqués) */
    public function census(Request $req): never
    {
        WebAuth::require(['axi', 'anagem']);
        $cols = "COUNT(*) AS stalls, SUM(s.has_physical_place = 0) AS without_place, SUM(s.verified_at IS NOT NULL) AS verified, SUM(s.moved_at IS NOT NULL) AS relocated";
        $base = "FROM stalls s JOIN users u ON u.id = s.user_id WHERE s.status = 'active' AND u.status = 'active'";
        $totals = Database::one("SELECT $cols $base");
        $bySite = Database::all("SELECT si.name AS label, $cols FROM stalls s JOIN users u ON u.id = s.user_id JOIN sites si ON si.id = s.site_id WHERE s.status = 'active' AND u.status = 'active' GROUP BY si.id, si.name ORDER BY stalls DESC");
        $byAisle = Database::all("SELECT a.name AS label, $cols FROM stalls s JOIN users u ON u.id = s.user_id JOIN aisles a ON a.id = s.aisle_id WHERE s.status = 'active' AND u.status = 'active' GROUP BY a.id, a.name ORDER BY stalls DESC");
        $min = Settings::int('min_group_size');
        $head = ['Groupe', '#Étals', '#Sans place', '#Vérifiés', '#Déménagés'];
        $fmt = static fn (array $rows): array => array_map(static fn (array $r): array => [View::e($r['label']), (string) $r['stalls'], (string) (int) $r['without_place'], (string) (int) $r['verified'], (string) (int) $r['relocated']], self::anonymize($rows, $min));
        $body = (int) $totals['stalls'] < $min
            ? '<div class="card muted">Moins de ' . $min . ' étals inscrits : aucune statistique publiée pour l\'instant.</div>'
            : Html::kpis(['Étals' => $totals['stalls'], 'Sans place' => (int) $totals['without_place'], 'Vérifiés sur place' => (int) $totals['verified'], 'Déménagés' => (int) $totals['relocated']])
                . '<h2>Par site</h2>' . Html::table($head, $fmt($bySite)) . '<h2>Par allée</h2>' . Html::table($head, $fmt($byAisle))
                . '<p class="muted">Les groupes de moins de ' . $min . ' étals sont regroupés dans « Autres » ou masqués.</p>';
        Html::page('Recensement des étals', $body, 'Répartition par site et par allée, données agrégées.');
    }

    /** Suivi d'activité par mois : étals actifs et commandes (agrégés) */
    public function activity(Request $req): never
    {
        WebAuth::require(['axi', 'anagem']);
        $min = Settings::int('min_group_size');
        $rows = Database::all(
            "SELECT DATE_FORMAT(o.paid_at, '%Y-%m') AS month, COUNT(DISTINCT o.id) AS orders, COUNT(DISTINCT os.stall_id) AS stalls, SUM(os.items_total) AS amount
             FROM orders o JOIN order_stalls os ON os.order_id = o.id WHERE o.paid_at IS NOT NULL GROUP BY month ORDER BY month DESC LIMIT 12",
        );
        $bySite = Database::all(
            "SELECT si.name AS site, COUNT(DISTINCT os.stall_id) AS stalls, COUNT(DISTINCT o.id) AS orders FROM orders o JOIN order_stalls os ON os.order_id = o.id
             JOIN stalls s ON s.id = os.stall_id JOIN sites si ON si.id = s.site_id WHERE o.paid_at > ? GROUP BY si.id, si.name HAVING stalls >= ? ORDER BY orders DESC",
            [Clock::now(-30 * 86400), $min],
        );
        Html::page('Suivi d\'activité', '<h2>Par mois</h2>' . Html::table(['Mois', '#Étals ayant vendu', '#Commandes', '#Ventes'], array_map(static fn (array $r): array => [
            View::e($r['month']), (int) $r['stalls'] >= $min ? (string) $r['stalls'] : "< $min", (int) $r['stalls'] >= $min ? (string) $r['orders'] : '—', (int) $r['stalls'] >= $min ? View::fcfa($r['amount']) : '—',
        ], $rows), 'Pas encore de commandes payées.')
            . '<h2>Par site, 30 derniers jours</h2>' . Html::table(['Site', '#Étals ayant vendu', '#Commandes'], array_map(static fn (array $r): array => [View::e($r['site']), (string) $r['stalls'], (string) $r['orders']], $bySite), "Aucun site avec au moins $min étals actifs."));
    }

    /** Prix médians par produit et par site (calcul nocturne), nuls sous le seuil d'anonymat */
    public function prices(Request $req): never
    {
        WebAuth::require();
        $min = Settings::int('min_group_size');
        $site = (string) ($_GET['site'] ?? '');
        $rows = Database::all(
            'SELECT ps.*, r.name AS product, si.name AS site,
                    (SELECT old.median_price FROM price_stats_daily old WHERE old.product_ref_id = ps.product_ref_id AND old.site_id = ps.site_id AND old.day <= ps.day - INTERVAL 30 DAY AND old.sample_size >= ? ORDER BY old.day DESC LIMIT 1) AS old_price
             FROM price_stats_daily ps JOIN product_refs r ON r.id = ps.product_ref_id JOIN sites si ON si.id = ps.site_id
             WHERE ps.day = (SELECT MAX(day) FROM price_stats_daily l WHERE l.product_ref_id = ps.product_ref_id AND l.site_id = ps.site_id)'
            . ($site !== '' ? ' AND ps.site_id = ?' : '') . ' ORDER BY r.name, si.sort_order LIMIT 300',
            $site !== '' ? [$min, $site] : [$min],
        );
        Html::page('Prix médians', Html::filters('/pro/prix', ['site' => ['Site', 'select', ['' => 'Tous'] + Merchants::options('sites')]])
            . Html::table(['Produit', 'Site', '#Prix médian', '#Évolution 30 j', '#Étals', 'Date'], array_map(static function (array $r) use ($min): array {
                $ok = (int) $r['sample_size'] >= $min;
                $change = $ok && $r['old_price'] !== null && (int) $r['old_price'] > 0 ? round(100 * ((int) $r['median_price'] - (int) $r['old_price']) / (int) $r['old_price'], 1) : null;
                return [View::e($r['product']), View::e($r['site']), $ok ? View::fcfa($r['median_price']) : 'Données insuffisantes', $change === null ? '—' : ($change > 0 ? '+' : '') . $change . ' %', $ok ? (string) $r['sample_size'] : "< $min", View::date($r['day'], false)];
            }, $rows), 'Aucun prix médian calculé : les produits doivent être rattachés au référentiel, et la tâche de nuit doit avoir tourné.'),
            'Calculés chaque nuit ; affichés seulement à partir de ' . $min . ' étals.');
    }

    /** Ventes et paiements agrégés (par jour, site, allée, statut), filtres et export CSV */
    public function sales(Request $req): never
    {
        WebAuth::require();
        // Dates jamais vides : par défaut, les 30 jours qui précèdent la dernière vente (il y a toujours des chiffres à l'ouverture)
        if (($_GET['to'] ?? '') === '') {
            $_GET['to'] = Clock::localDate();
        }
        if (($_GET['from'] ?? '') === '') {
            $last = Database::value('SELECT MAX(paid_at) FROM orders WHERE paid_at IS NOT NULL');
            $_GET['from'] = date('Y-m-d', strtotime(($last === null ? $_GET['to'] : substr((string) $last, 0, 10)) . ' -30 days'));
        }
        [$rows, $head] = $this->salesRows();
        if (($_GET['format'] ?? '') === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="axi-ventes-agregees-' . gmdate('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(static fn (string $h): string => ltrim($h, '#'), $head), ';');
            foreach ($rows as $r) {
                fputcsv($out, array_values($r), ';');
            }
            fclose($out);
            exit;
        }
        $total = ['orders' => array_sum(array_column($rows, 'orders')), 'amount' => array_sum(array_column($rows, 'amount'))];
        Html::page('Ventes et paiements', Html::filters('/pro/ventes', [
            'from' => ['Du', 'date', null], 'to' => ['Au', 'date', null],
            'group' => ['Regrouper par', 'select', ['day' => 'Jour', 'site' => 'Site', 'aisle' => 'Allée', 'status' => 'État du séquestre']],
            'site' => ['Site', 'select', ['' => 'Tous'] + Merchants::options('sites')],
        ]) . Html::kpis(['Commandes (parts d\'étals)' => (string) $total['orders'], 'Montant des ventes' => View::fcfa($total['amount'])])
            . '<div class="actions" style="margin-bottom:12px"><a class="btn ghost" href="' . View::url('/pro/ventes', ['format' => 'csv'] + array_intersect_key($_GET, array_flip(['from', 'to', 'group', 'site']))) . '">Exporter en CSV</a></div>'
            . Html::table($head, array_map(static fn (array $r): array => [View::e($r['label']), (string) $r['stalls'], (string) $r['orders'], View::fcfa($r['amount'])], $rows), 'Aucune vente sur cette période.'),
            'Données agrégées : aucune ligne n\'est publiée sous le seuil de ' . Settings::int('min_group_size') . ' étals.');
    }

    /** @return array{0: array, 1: array} */
    private function salesRows(): array
    {
        $min = Settings::int('min_group_size');
        $where = ["o.paid_at IS NOT NULL", "os.status <> 'cancelled'"];
        $params = [];
        foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET[$k] ?? ''))) {
                $where[] = "o.paid_at $op ?";
                $params[] = Clock::localDateStart($k === 'to' ? date('Y-m-d', strtotime($_GET[$k] . ' +1 day')) : $_GET[$k]);
            }
        }
        if (($site = (string) ($_GET['site'] ?? '')) !== '') {
            $where[] = 's.site_id = ?';
            $params[] = $site;
        }
        $group = match ($_GET['group'] ?? 'day') {
            'site' => 'si.name',
            'aisle' => 'a.name',
            'status' => 'os.escrow_state',
            default => "DATE_FORMAT(o.paid_at, '%Y-%m-%d')",
        };
        $rows = Database::all(
            "SELECT $group AS label, COUNT(DISTINCT os.stall_id) AS stalls, COUNT(*) AS orders, SUM(os.items_total - os.refunded_amount) AS amount
             FROM order_stalls os JOIN orders o ON o.id = os.order_id JOIN stalls s ON s.id = os.stall_id JOIN sites si ON si.id = s.site_id JOIN aisles a ON a.id = s.aisle_id
             WHERE " . implode(' AND ', $where) . " GROUP BY label HAVING stalls >= ? ORDER BY label DESC LIMIT 400",
            [...$params, $min],
        );
        return [$rows, ['Groupe', '#Étals', '#Commandes', '#Montant (FCFA)']];
    }

    /** Groupes de moins de $min étals regroupés dans « Autres », masqué s'il reste trop petit */
    private static function anonymize(array $rows, int $min): array
    {
        $out = [];
        $other = ['label' => 'Autres (groupes de moins de ' . $min . ' étals)', 'stalls' => 0, 'without_place' => 0, 'verified' => 0, 'relocated' => 0];
        foreach ($rows as $row) {
            if ((int) $row['stalls'] >= $min) {
                $out[] = $row;
            } else {
                foreach (['stalls', 'without_place', 'verified', 'relocated'] as $k) {
                    $other[$k] += (int) $row[$k];
                }
            }
        }
        if ($other['stalls'] >= $min) {
            $out[] = $other;
        }
        return $out;
    }
}
