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
    public function dashboard(Request $req): never
    {
        $user = WebAuth::require();
        $since = Clock::now(-30 * 86400);
        $stalls = Database::one("SELECT COUNT(*) AS n, SUM(s.verified_at IS NOT NULL) AS verified, SUM(s.has_physical_place = 0) AS no_place, COUNT(DISTINCT s.site_id) AS sites FROM stalls s JOIN users u ON u.id = s.user_id WHERE s.status = 'active' AND u.status = 'active'");
        $orders = Database::one("SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS amount, SUM(reception_mode = 'bus') AS outside FROM orders WHERE paid_at > ?", [$since]);
        $min = Settings::int('min_group_size');
        $enough = (int) $stalls['n'] >= $min;
        $kpis = [
            'Étals inscrits' => $enough ? (string) $stalls['n'] : "< $min",
            'Étals vérifiés sur place' => $enough ? (string) (int) $stalls['verified'] : '—',
            'Étals sans place' => $enough ? (string) (int) $stalls['no_place'] : '—',
            'Sites actifs' => (string) $stalls['sites'],
            'Commandes payées (30 j)' => $enough ? (string) $orders['n'] : '—',
            'Commandes hors Cotonou (30 j)' => $enough && (int) $orders['n'] > 0 ? round(100 * $orders['outside'] / $orders['n']) . ' %' : '—',
        ];
        $todo = '';
        if ($user['partner_type'] === 'axi') {
            $kpis['Montant payé (30 j)'] = View::fcfa($orders['amount']);
            $counts = Database::one(
                "SELECT (SELECT COUNT(*) FROM agent_visit_requests WHERE status IN ('new','scheduled')) AS visits,
                        (SELECT COUNT(*) FROM disputes WHERE status = 'open') AS disputes,
                        (SELECT COUNT(*) FROM campaigns WHERE status = 'pending_review') AS campaigns,
                        (SELECT COUNT(DISTINCT stall_id) FROM order_stalls WHERE escrow_state = 'released' AND payout_id IS NULL) AS payouts,
                        (SELECT COUNT(*) FROM payments WHERE status = 'pending' AND created_at < ?) AS stale",
                [Clock::now(-3600)],
            );
            $todo = '<h2>À traiter</h2><div class="grid">'
                . self::todo('Vérifications sur place', (int) $counts['visits'], '/pro/verifications')
                . self::todo('Litiges ouverts', (int) $counts['disputes'], '/pro/litiges')
                . self::todo('Campagnes à relire', (int) $counts['campaigns'], '/pro/campagnes')
                . self::todo('Commerçants à payer', (int) $counts['payouts'], '/pro/versements')
                . self::todo('Paiements en attente depuis plus d\'1 h', (int) $counts['stale'], '/pro/paiements?status=pending')
                . '</div>';
        }
        Html::page('Bonjour ' . $user['first_name'], Html::kpis($kpis) . $todo, $user['org_name'] . ' · données agrégées et anonymes');
    }

    private static function todo(string $label, int $n, string $href): string
    {
        return '<a class="card kpi" style="text-decoration:none;color:inherit" href="' . View::url($href) . '"><div class="v">' . $n . '</div><div class="l">' . View::e($label) . '</div></a>';
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
        Html::page('Suivi d\'activité', '<h2 style="margin-top:0">Par mois</h2>' . Html::table(['Mois', '#Étals ayant vendu', '#Commandes', '#Ventes'], array_map(static fn (array $r): array => [
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
