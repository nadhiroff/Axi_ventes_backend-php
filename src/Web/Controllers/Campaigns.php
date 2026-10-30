<?php
declare(strict_types=1);

namespace Axi\Web\Controllers;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Core\Url;
use Axi\Services\AdminLog;
use Axi\Services\Settings;
use Axi\Services\Uploads;
use Axi\Web\Html;
use Axi\Web\View;
use Axi\Web\WebAuth;

/**
 * Annonceur : campagnes ciblées (bannière d'accueil, catégorie sponsorisée, étal mis en avant, offre aux
 * commerçants) et leurs résultats. Agent Axi : relecture avant diffusion. Le ciblage reste chez Axi :
 * l'annonceur ne voit qu'un nombre d'étals touchés, jamais une liste. Aucune publicité sur la page d'un étal.
 */
final class Campaigns
{
    public const FORMATS = [
        'sponsored_home' => 'Bannière sur l\'accueil client',
        'sponsored_aisle' => 'Catégorie (allée) sponsorisée',
        'featured_stall' => 'Étal mis en avant',
        'supplier_offer' => 'Offre fournisseur aux commerçants',
    ];
    private const STATUS = ['pending_review' => ['En relecture', 'warn'], 'active' => ['En cours', 'ok'], 'ended' => ['Terminée', 'muted'], 'rejected' => ['Refusée', 'danger']];

    public function index(Request $req): never
    {
        $user = WebAuth::require(['axi', 'advertiser']);
        $isAxi = $user['partner_type'] === 'axi';
        $rows = Database::all(
            'SELECT c.*, o.name AS org, a.name AS aisle, si.name AS site, s.name AS stall FROM campaigns c JOIN organizations o ON o.id = c.organization_id
             LEFT JOIN aisles a ON a.id = c.aisle_id LEFT JOIN sites si ON si.id = c.site_id LEFT JOIN stalls s ON s.id = c.target_stall_id'
            . ($isAxi ? '' : ' WHERE c.organization_id = ?') . ' ORDER BY c.status = \'pending_review\' DESC, c.id DESC LIMIT 200',
            $isAxi ? [] : [$user['organization_id']],
        );
        $table = Html::table(['Campagne', 'Format', 'Cible', '#Budget', '#Affichages', '#Clics', 'Statut', ''], array_map(static function (array $c) use ($isAxi): array {
            [$label, $tone] = self::STATUS[$c['status']];
            $actions = '';
            if ($isAxi && $c['status'] === 'pending_review') {
                $actions = View::action('/pro/campagnes/' . $c['id'] . '/statut', 'Valider', ['status' => 'active'], 'Valider et diffuser cette campagne ?', 'btn ok')
                    . ' ' . View::action('/pro/campagnes/' . $c['id'] . '/statut', 'Refuser', ['status' => 'rejected'], 'Refuser cette campagne ?', 'btn danger');
            } elseif ($c['status'] === 'active') {
                $actions = View::action('/pro/campagnes/' . $c['id'] . '/statut', 'Arrêter', ['status' => 'ended'], 'Arrêter la diffusion de cette campagne ?', 'btn ghost');
            }
            return [
                '<strong>' . View::e($c['title']) . '</strong><br><span class="muted">' . View::e(mb_strimwidth($c['body'], 0, 90, '…')) . ($isAxi ? ' · ' . View::e($c['org']) : '') . '</span>',
                View::e(self::FORMATS[$c['format']]),
                View::e(($c['audience_type'] === 'merchants' ? 'Commerçants' : 'Clients') . ($c['aisle'] ? ' · ' . $c['aisle'] : '') . ($c['site'] ? ' · ' . $c['site'] : '') . ($c['stall'] ? ' · étal ' . $c['stall'] : '')),
                View::fcfa($c['budget']), (string) $c['impressions'], (string) $c['clicks'] . ((int) $c['impressions'] > 0 ? '<br><span class="muted">' . round(100 * $c['clicks'] / $c['impressions'], 1) . ' %</span>' : ''),
                View::badge($label, $tone), '<div class="actions">' . $actions . '</div>',
            ];
        }, $rows), 'Aucune campagne.');

        $form = '';
        if (!$isAxi) {
            $reach = self::reachTable();
            $stalls = ['' => '—'];
            foreach (Database::all("SELECT s.id, s.name, si.name AS site FROM stalls s JOIN sites si ON si.id = s.site_id JOIN users u ON u.id = s.user_id WHERE s.status = 'active' AND u.status = 'active' ORDER BY s.name LIMIT 500") as $s) {
                $stalls[$s['id']] = $s['name'] . ' (' . $s['site'] . ')';
            }
            $form = '<div class="card"><h2>Nouvelle campagne</h2><p class="muted">Budget minimum : ' . View::fcfa(Settings::int('min_campaign_budget')) . '. Elle sera diffusée après relecture par l\'équipe Axi.</p>'
                . Html::form('/pro/campagnes', [
                    'format' => ['Format', 'select', 'sponsored_home', self::FORMATS],
                    'audience_type' => ['Public', 'select', 'clients', ['clients' => 'Clients', 'merchants' => 'Commerçants']],
                    'aisle_id' => ['Allée ciblée', 'select', '', ['' => 'Toutes'] + Merchants::options('aisles')],
                    'site_id' => ['Site ciblé', 'select', '', ['' => 'Tous'] + Merchants::options('sites')],
                    'target_stall_id' => ['Étal mis en avant (format « Étal mis en avant »)', 'select', '', $stalls],
                    'title' => ['Titre', 'text', '', ['attrs' => ' maxlength="150" required']],
                    'body' => ['Texte', 'textarea', '', null],
                    'url' => ['Lien (facultatif, https)', 'text', '', null],
                    'budget' => ['Budget (FCFA)', 'number', (string) Settings::int('min_campaign_budget'), ['attrs' => ' min="1" required']],
                    'starts_at' => ['Début', 'date', Clock::localDate(), null],
                    'ends_at' => ['Fin', 'date', date('Y-m-d', strtotime('+30 days')), null],
                    'image' => ['Image de la bannière (facultative)', 'file', '', null],
                ], 'Envoyer en relecture', null, true) . '</div>'
                . '<h2>Portée estimée (nombre d\'étals ou de clients, jamais de liste)</h2>' . $reach;
        }
        Html::page('Campagnes', $form . '<h2>' . ($isAxi ? 'Toutes les campagnes' : 'Mes campagnes') . '</h2>' . $table);
    }

    public function create(Request $req): never
    {
        $user = WebAuth::require(['advertiser']);
        WebAuth::checkCsrf();
        $p = $_POST;
        $format = array_key_exists($p['format'] ?? '', self::FORMATS) ? $p['format'] : null;
        $title = trim((string) ($p['title'] ?? ''));
        $budget = (int) ($p['budget'] ?? 0);
        $url = trim((string) ($p['url'] ?? ''));
        $errors = match (true) {
            $format === null => 'Format inconnu.',
            $title === '' || mb_strlen($title) > 150 => 'Titre obligatoire (150 caractères maximum).',
            $budget < Settings::int('min_campaign_budget') => 'Le budget minimum est de ' . View::fcfa(Settings::int('min_campaign_budget')) . '.',
            $url !== '' && !str_starts_with($url, 'https://') => 'Le lien doit commencer par https://.',
            $format === 'featured_stall' && empty($p['target_stall_id']) => 'Choisissez l\'étal à mettre en avant.',
            $format === 'sponsored_aisle' && empty($p['aisle_id']) => 'Choisissez l\'allée sponsorisée.',
            default => null,
        };
        if ($errors !== null) {
            View::redirect('/pro/campagnes', $errors, 'err');
        }
        $image = null;
        if (($file = $req->file('image')) !== null) {
            try {
                $image = Uploads::photo($file)['path'];
            } catch (HttpException $e) {
                View::redirect('/pro/campagnes', $e->getMessage(), 'err');
            }
        }
        $date = static fn (string $k): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p[$k] ?? '')) ? Clock::localDateStart($p[$k]) : null;
        $audience = in_array($format, ['supplier_offer'], true) ? 'merchants' : (($p['audience_type'] ?? '') === 'merchants' ? 'merchants' : 'clients');
        $id = Database::insert(
            'INSERT INTO campaigns (organization_id, created_by, audience_type, aisle_id, site_id, format, title, body, target_stall_id, url, image_path, budget, starts_at, ends_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$user['organization_id'], $user['id'], $audience, ($p['aisle_id'] ?? '') ?: null, ($p['site_id'] ?? '') ?: null, $format, $title,
                mb_substr(trim((string) ($p['body'] ?? '')), 0, 500), ($p['target_stall_id'] ?? '') ?: null, $url === '' ? null : $url, $image, $budget, $date('starts_at'), $date('ends_at')],
        );
        $req->user = $user;
        AdminLog::record($req, 'campaign.create', 'campaign', $id, ['format' => $format, 'budget' => $budget]);
        View::redirect('/pro/campagnes', 'Campagne envoyée en relecture à l\'équipe Axi.');
    }

    public function setStatus(Request $req): never
    {
        $user = WebAuth::require(['axi', 'advertiser']);
        WebAuth::checkCsrf();
        $id = (int) ($req->params['id'] ?? 0);
        $c = Database::one('SELECT * FROM campaigns WHERE id = ?', [$id]) ?? throw new HttpException(404, 'NOT_FOUND', 'Campagne introuvable.');
        $status = (string) ($_POST['status'] ?? '');
        $allowed = $user['partner_type'] === 'axi' ? ['active', 'rejected', 'ended'] : ['ended'];
        if (!in_array($status, $allowed, true) || ($user['partner_type'] !== 'axi' && (int) $c['organization_id'] !== (int) $user['organization_id'])) {
            throw new HttpException(403, 'FORBIDDEN', 'Action non autorisée sur cette campagne.');
        }
        Database::run('UPDATE campaigns SET status = ?, reviewed_by = IF(? = \'axi\', ?, reviewed_by) WHERE id = ?', [$status, $user['partner_type'], $user['id'], $id]);
        $req->user = $user;
        AdminLog::record($req, 'campaign.' . $status, 'campaign', $id);
        View::redirect('/pro/campagnes', 'Campagne mise à jour.');
    }

    /** Portée par allée et par site : seulement des nombres, et pas en dessous du seuil d'anonymat */
    private static function reachTable(): string
    {
        $min = Settings::int('min_group_size');
        $rows = Database::all(
            "SELECT a.name AS aisle, si.name AS site, COUNT(*) AS stalls,
                    (SELECT COUNT(DISTINCT f.user_id) FROM stall_follows f JOIN stalls x ON x.id = f.stall_id WHERE x.aisle_id = a.id AND x.site_id = si.id) AS followers
             FROM stalls s JOIN aisles a ON a.id = s.aisle_id JOIN sites si ON si.id = s.site_id JOIN users u ON u.id = s.user_id
             WHERE s.status = 'active' AND u.status = 'active' GROUP BY a.id, a.name, si.id, si.name HAVING stalls >= ? ORDER BY stalls DESC",
            [$min],
        );
        return Html::table(['Allée', 'Site', '#Étals', '#Clients abonnés'], array_map(static fn (array $r): array => [
            View::e($r['aisle']), View::e($r['site']), (string) $r['stalls'], (int) $r['followers'] >= $min ? (string) $r['followers'] : '< ' . $min,
        ], $rows), "Pas encore de groupe d'au moins $min étals.");
    }
}
