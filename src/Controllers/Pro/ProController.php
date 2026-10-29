<?php
declare(strict_types=1);

namespace Axi\Controllers\Pro;

use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Ids;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;

/**
 * Axi Pro : données agrégées et anonymes. Aucun chiffre calculé sur moins de 5 étals,
 * aucune liste de commerçants ou de clients, sauf les dossiers que les commerçants ont accepté de partager.
 */
final class ProController
{
    /** Types d'organisation autorisés par onglet (le rôle admin voit tout) */
    private const ACCESS = [
        'overview' => ['anagem', 'advertiser', 'mfi', 'research'],
        'campaigns' => ['advertiser'],
        'census' => ['anagem'],
        'financing' => ['mfi'],
    ];

    private const CAMPAIGN_STATUS = ['pending_review' => 'En relecture', 'active' => 'En cours', 'ended' => 'Terminée', 'rejected' => 'Refusée'];
    private const FORMATS = ['supplier_offer' => 'Offre fournisseur aux commerçants', 'sponsored_home' => 'Promotion sur l\'accueil client'];
    private const FILE_STATUS = ['new' => 'Nouveau', 'in_review' => 'En étude', 'granted' => 'Accordé', 'declined' => 'Refusé'];

    public function overview(Request $req): never
    {
        $this->allow($req, 'overview');
        $site = trim((string) $req->query('site', ''));
        if ($site !== '' && Database::value('SELECT id FROM sites WHERE id = ?', [$site]) === null) {
            Validator::fail(['site' => 'Site inconnu.'], ['site' => 'Site']);
        }
        [$scope, $params] = $site === '' ? ['1', []] : ['s.site_id = ?', [$site]];
        $since = Clock::now(-30 * 86400);
        $min = $this->min();

        $stalls = Database::one(
            "SELECT COUNT(*) AS n, SUM(s.has_physical_place = 0) AS without_place,
                    SUM(s.updated_at > ? OR EXISTS (SELECT 1 FROM products p WHERE p.stall_id = s.id AND p.updated_at > ?)
                        OR EXISTS (SELECT 1 FROM order_stalls os WHERE os.stall_id = s.id AND os.created_at > ?)) AS active
             FROM stalls s WHERE s.status = 'active' AND $scope",
            [$since, $since, $since, ...$params],
        );
        $orders = Database::one(
            "SELECT COUNT(*) AS n, SUM(o.reception_mode = 'bus') AS outside FROM orders o
             WHERE o.paid_at > ? AND EXISTS (SELECT 1 FROM order_stalls os JOIN stalls s ON s.id = os.stall_id WHERE os.order_id = o.id AND $scope)",
            [$since, ...$params],
        );
        $credits = (int) Database::value(
            "SELECT COUNT(*) FROM credits c JOIN stalls s ON s.user_id = c.merchant_user_id WHERE c.status IN ('granted', 'repaid') AND $scope",
            $params,
        );
        $enough = (int) $stalls['n'] >= $min;

        Response::ok([
            'kpis' => [
                'stalls_registered' => $enough ? (int) $stalls['n'] : 0,
                'stalls_without_place' => $enough ? (int) $stalls['without_place'] : 0,
                'stalls_active_month' => $enough ? (int) $stalls['active'] : 0,
                'sites_count' => (int) Database::value("SELECT COUNT(DISTINCT site_id) FROM stalls WHERE status = 'active'"),
                'online_orders_month' => $enough ? (int) $orders['n'] : 0,
                // Les commandes expédiées par car partent hors de Cotonou
                'outside_cotonou_pct' => $enough && (int) $orders['n'] > 0 ? (int) round(100 * (int) $orders['outside'] / (int) $orders['n']) : 0,
                'credits_granted' => $enough ? $credits : 0,
            ],
            // true : moins de 5 étals dans ce périmètre, les indicateurs sont masqués (0)
            'insufficient_data' => !$enough,
            'sites' => Database::all('SELECT id, name FROM sites ORDER BY sort_order, name'),
            'median_prices' => $this->medianPrices($site),
        ], 200, 3600);
    }

    public function campaigns(Request $req): never
    {
        $org = $this->allow($req, 'campaigns');
        $rows = Database::all(
            'SELECT * FROM campaigns' . ($org === null ? '' : ' WHERE organization_id = ?') . ' ORDER BY id DESC LIMIT 100',
            $org === null ? [] : [$org['id']],
        );
        Response::ok([
            'campaigns' => array_map([$this, 'campaign'], $rows),
            'audiences' => array_values(array_map(static fn (array $a): array => ['id' => $a['id'], 'label' => $a['label'], 'reach' => $a['reach']], $this->audiences())),
            'formats' => array_map(static fn (string $id, string $label): array => ['id' => $id, 'label' => $label], array_keys(self::FORMATS), self::FORMATS),
            'min_budget' => (int) Config::get('pro.min_campaign_budget'),
        ], 200, 60);
    }

    /** La campagne est créée « En relecture » : diffusée seulement après validation par l'équipe Axi */
    public function createCampaign(Request $req): never
    {
        $org = $this->allow($req, 'campaigns');
        if ($org === null) {
            throw new HttpException(422, 'ORGANIZATION_REQUIRED', 'Un compte administrateur doit créer la campagne depuis le compte de l\'annonceur.');
        }
        $in = Validator::validate($req->all(), [
            'audience_id' => 'required|string|max:80',
            'format' => 'required|in:supplier_offer,sponsored_home',
            'budget' => 'required|int|min:1|maxval:1000000000',
            'title' => 'string|max:150',
            'text' => 'string|max:500',
        ], ['audience_id' => 'Public', 'format' => 'Format', 'budget' => 'Budget', 'title' => 'Titre', 'text' => 'Texte']);

        $audience = $this->audiences()[$in['audience_id']] ?? throw new HttpException(422, 'INVALID_AUDIENCE', 'Ce public n\'est pas disponible.');
        $min = (int) Config::get('pro.min_campaign_budget');
        if ($in['budget'] < $min) {
            throw new HttpException(422, 'BUDGET_TOO_LOW', 'Le budget minimum est de ' . number_format($min, 0, ',', ' ') . ' FCFA.');
        }
        $id = Database::insert(
            'INSERT INTO campaigns (organization_id, created_by, audience_type, aisle_id, site_id, format, title, body, budget, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$org['id'], $req->userId(), $audience['type'], $audience['aisle_id'], $audience['site_id'], $in['format'], $in['title'] ?? null, $in['text'] ?? null, $in['budget'], Clock::now()],
        );
        Response::ok($this->campaign(Database::one('SELECT * FROM campaigns WHERE id = ?', [$id])), 201);
    }

    public function census(Request $req): never
    {
        $this->allow($req, 'census');
        $columns = "COUNT(*) AS stalls, SUM(s.has_physical_place = 0) AS without_place, SUM(s.verified_at IS NOT NULL) AS verified, SUM(s.moved_at IS NOT NULL) AS relocated";
        $totals = Database::one("SELECT $columns FROM stalls s WHERE s.status = 'active'");
        $bySite = Database::all("SELECT si.name AS label, $columns FROM stalls s JOIN sites si ON si.id = s.site_id WHERE s.status = 'active' GROUP BY si.id, si.name, si.sort_order ORDER BY si.sort_order");
        $byAisle = Database::all("SELECT a.name AS label, $columns FROM stalls s JOIN aisles a ON a.id = s.aisle_id WHERE s.status = 'active' GROUP BY a.id, a.name, a.sort_order ORDER BY a.sort_order");
        $enough = (int) $totals['stalls'] >= $this->min();

        Response::ok([
            'totals' => $enough ? $this->censusNumbers($totals) : ['stalls' => 0, 'without_place' => 0, 'verified' => 0, 'relocated' => 0],
            'by_site' => $enough ? $this->anonymize($bySite) : [],
            'by_aisle' => $enough ? $this->anonymize($byAisle) : [],
            'updated_at' => Clock::iso((string) (Database::value("SELECT MAX(updated_at) FROM stalls") ?? Clock::now())),
        ], 200, 3600);
    }

    public function financing(Request $req): never
    {
        $org = $this->allow($req, 'financing');
        [$orgWhere, $params] = $org === null ? ['1', []] : ['organization_id = ?', [$org['id']]];
        $totals = Database::one(
            "SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amount FROM credits WHERE status IN ('granted', 'repaid') AND $orgWhere",
            $params,
        );
        $files = Database::all(
            "SELECT c.id AS consent_id, c.merchant_user_id, c.organization_id, s.id AS stall_id, s.name, si.name AS site, a.name AS aisle
             FROM data_sharing_consents c
             JOIN stalls s ON s.user_id = c.merchant_user_id
             JOIN sites si ON si.id = s.site_id JOIN aisles a ON a.id = s.aisle_id
             WHERE c.revoked_at IS NULL AND " . ($org === null ? '1' : 'c.organization_id = ?') . ' ORDER BY c.id DESC',
            $params,
        );

        Response::ok([
            'totals' => [
                'credits_granted' => (int) $totals['n'],
                'amount_granted' => (int) $totals['amount'],
                // Les remboursements ne sont pas encore suivis dans Axi
                'repayment_rate_pct' => null,
            ],
            'shared_files' => array_map(function (array $f): array {
                $sales = Database::one(
                    "SELECT MIN(o.paid_at) AS first_at, COALESCE(SUM(os.items_total), 0) AS total
                     FROM order_stalls os JOIN orders o ON o.id = os.order_id
                     WHERE os.stall_id = ? AND o.paid_at IS NOT NULL AND os.status <> 'cancelled'",
                    [$f['stall_id']],
                );
                $months = $sales['first_at'] === null ? 0 : max(1, (int) floor((time() - strtotime($sales['first_at'] . ' UTC')) / (30 * 86400)));
                $status = (string) (Database::value(
                    'SELECT status FROM credits WHERE merchant_user_id = ? AND organization_id = ? ORDER BY id DESC LIMIT 1',
                    [$f['merchant_user_id'], $f['organization_id']],
                ) ?? 'new');
                $status = $status === 'repaid' ? 'granted' : $status;
                return [
                    'id' => Ids::encode('dos', $f['consent_id']),
                    'merchant_name' => $f['name'],
                    'site' => $f['site'],
                    'aisle' => $f['aisle'],
                    'months_of_history' => $months,
                    'avg_monthly_sales' => $months === 0 ? 0 : intdiv((int) $sales['total'], $months),
                    'status' => $status,
                    'status_label' => self::FILE_STATUS[$status],
                ];
            }, $files),
        ], 200, 300);
    }

    /** @return array|null l'organisation du compte pro, ou null pour un admin */
    private function allow(Request $req, string $tab): ?array
    {
        if ($req->role() === 'admin') {
            return null;
        }
        $org = Database::one('SELECT * FROM organizations WHERE id = ? AND status = \'active\'', [$req->user['organization_id'] ?? 0]);
        if ($org === null || !in_array($org['type'], self::ACCESS[$tab], true)) {
            throw new HttpException(403, 'FORBIDDEN', 'Cet espace n\'est pas ouvert à votre organisation.');
        }
        return $org;
    }

    private function min(): int
    {
        return (int) Config::get('pro.min_group_size', 5);
    }

    /** Dernier prix médian connu par référence et par site, et son évolution sur 30 jours */
    private function medianPrices(string $site): array
    {
        $rows = Database::all(
            'SELECT ps.*, r.name AS product_name, si.name AS site_name,
                    (SELECT old.median_price FROM price_stats_daily old
                      WHERE old.product_ref_id = ps.product_ref_id AND old.site_id = ps.site_id AND old.day <= ps.day - INTERVAL 30 DAY AND old.sample_size >= ?
                      ORDER BY old.day DESC LIMIT 1) AS old_price
             FROM price_stats_daily ps
             JOIN product_refs r ON r.id = ps.product_ref_id JOIN sites si ON si.id = ps.site_id
             WHERE ps.day = (SELECT MAX(day) FROM price_stats_daily last WHERE last.product_ref_id = ps.product_ref_id AND last.site_id = ps.site_id)
               AND ps.day >= CURDATE() - INTERVAL 7 DAY' . ($site === '' ? '' : ' AND ps.site_id = ?') . '
             ORDER BY r.name, si.sort_order LIMIT 200',
            $site === '' ? [$this->min()] : [$this->min(), $site],
        );
        return array_map(function (array $r): array {
            $enough = (int) $r['sample_size'] >= $this->min();
            $change = $enough && $r['old_price'] !== null && (int) $r['old_price'] > 0
                ? round(100 * ((int) $r['median_price'] - (int) $r['old_price']) / (int) $r['old_price'], 1)
                : null;
            return [
                'product_ref_id' => Ids::encode('ref', $r['product_ref_id']),
                'product_name' => $r['product_name'],
                'site' => $r['site_name'],
                'median_price' => $enough ? (int) $r['median_price'] : null,
                'change_30d_pct' => $change,
                'sample_size' => (int) $r['sample_size'],
            ];
        }, $rows);
    }

    /**
     * Publics proposés aux annonceurs : jamais une liste, seulement un nombre d'étals ou de clients,
     * et aucun public de moins de 5 personnes.
     * @return array<string, array{id: string, label: string, reach: int, type: string, aisle_id: ?string, site_id: ?string}>
     */
    private function audiences(): array
    {
        $min = $this->min();
        $audiences = [];
        foreach (Database::all(
            "SELECT a.id AS aisle_id, a.name AS aisle, si.id AS site_id, si.name AS site, COUNT(*) AS reach
             FROM stalls s JOIN aisles a ON a.id = s.aisle_id JOIN sites si ON si.id = s.site_id
             WHERE s.status = 'active' GROUP BY a.id, a.name, si.id, si.name HAVING reach >= ? ORDER BY reach DESC",
            [$min],
        ) as $r) {
            $id = 'aud_m_' . $r['aisle_id'] . '_' . $r['site_id'];
            $audiences[$id] = ['id' => $id, 'label' => 'Commerçants ' . mb_strtolower($r['aisle']) . ', ' . $r['site'], 'reach' => (int) $r['reach'], 'type' => 'merchants', 'aisle_id' => $r['aisle_id'], 'site_id' => $r['site_id']];
        }
        foreach (Database::all(
            "SELECT a.id AS aisle_id, a.name AS aisle, COUNT(DISTINCT f.user_id) AS reach
             FROM stall_follows f JOIN stalls s ON s.id = f.stall_id JOIN aisles a ON a.id = s.aisle_id
             WHERE s.status = 'active' GROUP BY a.id, a.name HAVING reach >= ? ORDER BY reach DESC",
            [$min],
        ) as $r) {
            $id = 'aud_c_' . $r['aisle_id'];
            $audiences[$id] = ['id' => $id, 'label' => 'Clients, allée ' . $r['aisle'], 'reach' => (int) $r['reach'], 'type' => 'clients', 'aisle_id' => $r['aisle_id'], 'site_id' => null];
        }
        return $audiences;
    }

    private function campaign(array $c): array
    {
        $aisle = $c['aisle_id'] === null ? null : Database::value('SELECT name FROM aisles WHERE id = ?', [$c['aisle_id']]);
        $site = $c['site_id'] === null ? null : Database::value('SELECT name FROM sites WHERE id = ?', [$c['site_id']]);
        $label = $c['audience_type'] === 'merchants'
            ? 'Commerçants ' . ($aisle === null ? 'toutes allées' : mb_strtolower((string) $aisle)) . ($site === null ? '' : ', ' . $site)
            : 'Clients' . ($aisle === null ? '' : ', allée ' . $aisle) . ($site === null ? '' : ', ' . $site);
        return [
            'id' => Ids::encode('cmp', $c['id']),
            'audience_label' => $label,
            'format' => $c['format'],
            'format_label' => self::FORMATS[$c['format']],
            'budget' => (int) $c['budget'],
            'status' => $c['status'],
            'status_label' => self::CAMPAIGN_STATUS[$c['status']],
            'impressions' => (int) $c['impressions'],
            'clicks' => (int) $c['clicks'],
            'created_at' => Clock::iso($c['created_at']),
        ];
    }

    private function censusNumbers(array $row): array
    {
        return ['stalls' => (int) $row['stalls'], 'without_place' => (int) $row['without_place'], 'verified' => (int) $row['verified'], 'relocated' => (int) $row['relocated']];
    }

    /** Groupes de moins de 5 étals regroupés dans « Autres » (supprimé s'il reste lui-même trop petit) */
    private function anonymize(array $rows): array
    {
        $out = [];
        $other = ['stalls' => 0, 'without_place' => 0, 'verified' => 0, 'relocated' => 0];
        foreach ($rows as $row) {
            if ((int) $row['stalls'] >= $this->min()) {
                $out[] = ['label' => $row['label']] + $this->censusNumbers($row);
            } else {
                foreach ($other as $key => $value) {
                    $other[$key] = $value + (int) $row[$key];
                }
            }
        }
        if ($other['stalls'] >= $this->min()) {
            $out[] = ['label' => 'Autres (groupes de moins de ' . $this->min() . ' étals)'] + $other;
        }
        return $out;
    }
}
