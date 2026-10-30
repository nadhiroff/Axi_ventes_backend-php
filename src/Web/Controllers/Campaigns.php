<?php
declare(strict_types=1);

namespace Axi\Web\Controllers;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Core\Url;
use Axi\Services\Ads;
use Axi\Services\AdminLog;
use Axi\Services\Settings;
use Axi\Services\SignedUrl;
use Axi\Services\Uploads;
use Axi\Support\Stalls;
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
    public const AUDIENCES = ['clients' => 'Clients', 'merchants' => 'Commerçants', 'all' => 'Tous (clients et commerçants)'];
    /** Toute annonce se modifie, même terminée ; côté annonceur, la modification repasse par la relecture */
    private const EDITABLE = ['pending_review', 'rejected', 'active', 'ended'];
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
        $reports = [];
        foreach (Database::all("SELECT campaign_id, COUNT(*) AS n FROM campaign_reports WHERE status = 'open' GROUP BY campaign_id") as $r) {
            $reports[(int) $r['campaign_id']] = (int) $r['n'];
        }
        $table = Html::table(['Annonce', 'Partagée à', '#Budget', '#Affichages', '#Clics', 'Statut', ''], array_map(static function (array $c) use ($isAxi, $reports): array {
            [$label, $tone] = self::STATUS[$c['status']];
            $actions = '';
            if ($isAxi && $c['status'] === 'pending_review') {
                $actions = View::action('/pro/campagnes/' . $c['id'] . '/statut', 'Valider', ['status' => 'active'], 'Valider et diffuser cette annonce ?', 'btn ok sm')
                    . ' ' . View::action('/pro/campagnes/' . $c['id'] . '/statut', 'Refuser', ['status' => 'rejected'], 'Refuser cette annonce ?', 'btn danger sm');
            } elseif ($c['status'] === 'active') {
                $actions = View::action('/pro/campagnes/' . $c['id'] . '/statut', 'Arrêter', ['status' => 'ended'], 'Arrêter la diffusion de cette annonce ?', 'btn ghost sm');
            }
            if ($isAxi || in_array($c['status'], self::EDITABLE, true)) {
                $actions = '<a class="btn ghost sm" href="' . View::url('/pro/campagnes/' . $c['id'] . '/modifier') . '">Modifier</a> ' . $actions;
            }
            if ($c['status'] === 'ended') {
                $actions .= View::action('/pro/campagnes/' . $c['id'] . '/relancer', 'Relancer', [], $isAxi ? 'Remettre cette annonce en ligne pour 30 jours ?' : 'Relancer cette annonce pour 30 jours ? Elle repassera en relecture par l\'équipe Axi.', 'btn ok sm');
            }
            $actions .= ' ' . View::action('/pro/campagnes/' . $c['id'] . '/supprimer', 'Supprimer', [], 'Supprimer définitivement l\'annonce « ' . $c['title'] . ' » ?', 'btn danger sm');
            $count = $reports[(int) $c['id']] ?? 0;
            return [
                ($c['image_path'] !== null ? '<img class="ad-thumb" alt="" src="' . View::e(Stalls::image($c['image_path'], 600, 300)['thumb_url']) . '">' : '')
                    . '<strong>' . View::e($c['title']) . '</strong>' . ($count > 0 ? ' ' . View::badge($count . ' signalement' . ($count > 1 ? 's' : ''), 'danger') : '')
                    . '<br><span class="muted">' . View::e(mb_strimwidth((string) $c['body'], 0, 90, '…')) . ($isAxi ? ' · ' . View::e($c['org']) : '') . '</span>',
                View::e(self::AUDIENCES[$c['audience_type']]),
                View::fcfa($c['budget']), (string) $c['impressions'], (string) $c['clicks'] . ((int) $c['impressions'] > 0 ? '<br><span class="muted">' . round(100 * $c['clicks'] / $c['impressions'], 1) . ' %</span>' : ''),
                View::badge($label, $tone), '<div class="actions">' . $actions . '</div>',
            ];
        }, $rows), 'Aucune annonce.');

        $form = '';
        if (!$isAxi) {
            $form = '<details class="card new-campaign"' . ($rows === [] ? ' open' : '') . '><summary>Nouvelle annonce</summary>'
                . '<p class="muted">Budget minimum : ' . View::fcfa(Settings::int('min_campaign_budget')) . '. Elle sera publiée après relecture par l\'équipe Axi.</p>'
                . Html::form('/pro/campagnes', self::fields(null), 'Envoyer en relecture', null, true) . '</details>'
                . '<h2>Portée estimée</h2><p class="sub">Un nombre d\'étals ou de clients, jamais une liste.</p>' . self::reachTable();
        }
        Html::page('Annonces', '<h2>' . ($isAxi ? 'Toutes les annonces' : 'Mes annonces') . '</h2>' . $table . $form);
    }

    /** Modifier : l'annonceur repasse en relecture ; l'équipe Axi corrige directement, l'annonce reste en ligne */
    public function edit(Request $req): never
    {
        [$user, $c] = $this->editable($req);
        $isAxi = $user['partner_type'] === 'axi';
        $reports = Database::all("SELECT r.reason, r.created_at, u.name FROM campaign_reports r JOIN users u ON u.id = r.user_id WHERE r.campaign_id = ? AND r.status = 'open' ORDER BY r.id DESC", [$c['id']]);
        $intro = $isAxi
            ? '<p class="muted">Vous corrigez directement : l\'annonce garde son statut et les signalements ouverts sont classés.</p>'
            : match ($c['status']) {
                'active' => '<p class="note">Cette annonce est en ligne. L\'enregistrer l\'arrête jusqu\'à la nouvelle relecture par l\'équipe Axi.</p>',
                'ended' => '<p class="note">Cette annonce est terminée. L\'enregistrer la relance avec ces dates, après relecture par l\'équipe Axi.</p>',
                default => '<p class="muted">L\'annonce repartira en relecture par l\'équipe Axi.</p>',
            };
        $reported = $isAxi && $reports !== []
            ? '<div class="card"><h2>Signalements (' . count($reports) . ')</h2>' . Html::table(['Reçu le', 'Motif', 'Signalé par'], array_map(static fn (array $r): array => [View::date($r['created_at']), View::badge(Ads::REPORT_REASONS[$r['reason']] ?? $r['reason'], 'warn'), View::e($r['name'])], $reports))
              . '<p style="margin:14px 0 0">' . View::action('/pro/campagnes/' . $c['id'] . '/signalements', 'Classer sans modifier', [], 'Les signalements seront classés, l\'annonce ne change pas.', 'btn ghost') . '</p></div>'
            : '';
        // Photo ou vidéo actuelle : toucher la photo l'ouvre en grand
        $image = Stalls::image($c['image_path'], 600, 300);
        $preview = $image !== null
            ? '<a class="ad-preview" href="' . View::e($image['medium_url']) . '" target="_blank" rel="noopener" aria-label="Voir la photo en grand"><img alt="" src="' . View::e($image['medium_url']) . '"></a>'
            : ($c['video_path'] !== null ? '<div class="ad-preview"><video controls preload="metadata" src="' . View::e(SignedUrl::make('/v1/media/video', ['p' => $c['video_path']], 3600)) . '"></video></div>' : '');
        Html::page('Modifier l\'annonce', $reported . '<div class="card">' . $intro . $preview . Html::form('/pro/campagnes/' . $c['id'] . '/modifier', self::fields($c), 'Enregistrer les modifications', null, true) . '</div>');
    }

    public function update(Request $req): never
    {
        [$user, $c] = $this->editable($req);
        WebAuth::checkCsrf();
        $isAxi = $user['partner_type'] === 'axi';
        $back = '/pro/campagnes/' . $c['id'] . '/modifier';
        $v = self::validated($_POST, $back);
        // Une annonce dont la fin est passée ne s'afficherait jamais, même validée
        if (!$isAxi && $v['ends'] !== null && $v['ends'] <= Clock::now()) {
            View::redirect($back, 'La date de fin doit être dans le futur.', 'err');
        }
        $media = $this->uploadedMedia($req, $back) ?? ['image' => $c['image_path'], 'video' => $c['video_path']];
        Database::run(
            'UPDATE campaigns SET status = ?, reviewed_by = ?, audience_type = ?, title = ?, body = ?, url = ?, image_path = ?, video_path = ?, budget = ?, starts_at = ?, ends_at = ? WHERE id = ?',
            [$isAxi ? $c['status'] : 'pending_review', $isAxi ? $user['id'] : null, $v['audience'], $v['title'], $v['body'], $v['url'], $media['image'], $media['video'], $v['budget'], $v['starts'], $v['ends'], $c['id']],
        );
        if ($isAxi) {
            Database::run("UPDATE campaign_reports SET status = 'handled' WHERE campaign_id = ?", [$c['id']]);
        }
        $req->user = $user;
        AdminLog::record($req, 'campaign.update', 'campaign', (int) $c['id'], ['budget' => $v['budget']]);
        View::redirect('/pro/campagnes', $isAxi ? 'Annonce corrigée.' : 'Annonce modifiée et renvoyée en relecture à l\'équipe Axi.');
    }

    /** Relancer une annonce terminée : mêmes titre, texte et média, 30 jours à partir d'aujourd'hui. L'annonceur repasse en relecture. */
    public function relaunch(Request $req): never
    {
        $user = WebAuth::require(['axi', 'advertiser']);
        WebAuth::checkCsrf();
        $id = (int) ($req->params['id'] ?? 0);
        $c = Database::one('SELECT * FROM campaigns WHERE id = ?', [$id]) ?? throw new HttpException(404, 'NOT_FOUND', 'Annonce introuvable.');
        $isAxi = $user['partner_type'] === 'axi';
        if (!$isAxi && (int) $c['organization_id'] !== (int) $user['organization_id']) {
            throw new HttpException(403, 'FORBIDDEN', 'Action non autorisée sur cette annonce.');
        }
        if ($c['status'] !== 'ended') {
            View::redirect('/pro/campagnes', 'Seule une annonce terminée peut être relancée.', 'err');
        }
        $today = Clock::localDate();
        Database::run(
            'UPDATE campaigns SET status = ?, reviewed_by = ?, starts_at = ?, ends_at = ? WHERE id = ?',
            [$isAxi ? 'active' : 'pending_review', $isAxi ? $user['id'] : null, Clock::localDateStart($today), Clock::localDateStart(date('Y-m-d', strtotime($today . ' +30 days'))), $id],
        );
        $req->user = $user;
        AdminLog::record($req, 'campaign.relaunch', 'campaign', $id);
        View::redirect('/pro/campagnes', $isAxi ? 'Annonce remise en ligne pour 30 jours.' : 'Annonce relancée : elle repart en relecture à l\'équipe Axi.');
    }

    /** Signalements classés sans modifier l'annonce */
    public function dismissReports(Request $req): never
    {
        $user = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $id = (int) ($req->params['id'] ?? 0);
        Database::run("UPDATE campaign_reports SET status = 'handled' WHERE campaign_id = ?", [$id]);
        $req->user = $user;
        AdminLog::record($req, 'campaign.reports_dismissed', 'campaign', $id);
        View::redirect('/pro/campagnes', 'Signalements classés.');
    }

    /** @return array{0: array, 1: array} partenaire connecté et annonce modifiable (agent Axi : toutes) */
    private function editable(Request $req): array
    {
        $user = WebAuth::require(['advertiser', 'axi']);
        $id = (int) ($req->params['id'] ?? 0);
        $isAxi = $user['partner_type'] === 'axi';
        $c = ($isAxi ? Database::one('SELECT * FROM campaigns WHERE id = ?', [$id]) : Database::one('SELECT * FROM campaigns WHERE id = ? AND organization_id = ?', [$id, $user['organization_id']]))
            ?? throw new HttpException(404, 'NOT_FOUND', 'Annonce introuvable.');
        return [$user, $c];
    }

    /** Formulaire volontairement court : le message, à qui, le média, le lien ; budget et dates en dernier ($c : annonce à modifier) */
    private static function fields(?array $c): array
    {
        $day = static fn (?string $utc): string => $utc === null ? '' : date('Y-m-d', (int) strtotime($utc . ' UTC + 12 hours'));
        // Nouvelle annonce, ou annonce terminée à relancer : 30 jours à partir d'aujourd'hui
        $fresh = $c === null || $c['status'] === 'ended';
        return [
            'title' => ['Titre', 'text', $c['title'] ?? '', ['attrs' => ' maxlength="150" required']],
            'body' => ['Description', 'textarea', $c['body'] ?? '', null],
            'audience_type' => ['Partager à', 'select', $c['audience_type'] ?? 'clients', self::AUDIENCES],
            'media' => [$c !== null && ($c['image_path'] !== null || $c['video_path'] !== null) ? 'Remplacer l\'image ou la vidéo' : 'Image ou vidéo (facultatif)', 'file', '', ['accept' => 'image/*,video/mp4,video/quicktime']],
            'url' => ['Lien (facultatif, https)', 'text', $c['url'] ?? '', null],
            'budget' => ['Budget (FCFA)', 'number', (string) ($c['budget'] ?? Settings::int('min_campaign_budget')), ['attrs' => ' min="1" required']],
            'starts_at' => ['Début', 'date', $fresh ? Clock::localDate() : $day($c['starts_at']), null],
            'ends_at' => ['Fin', 'date', $fresh ? date('Y-m-d', strtotime('+30 days')) : $day($c['ends_at']), null],
        ];
    }

    public function create(Request $req): never
    {
        $user = WebAuth::require(['advertiser']);
        WebAuth::checkCsrf();
        $v = self::validated($_POST, '/pro/campagnes');
        $media = $this->uploadedMedia($req, '/pro/campagnes') ?? ['image' => null, 'video' => null];
        $id = Database::insert(
            'INSERT INTO campaigns (organization_id, created_by, audience_type, format, title, body, url, image_path, video_path, budget, starts_at, ends_at)
             VALUES (?, ?, ?, \'sponsored_home\', ?, ?, ?, ?, ?, ?, ?, ?)',
            [$user['organization_id'], $user['id'], $v['audience'], $v['title'], $v['body'], $v['url'], $media['image'], $media['video'], $v['budget'], $v['starts'], $v['ends']],
        );
        $req->user = $user;
        AdminLog::record($req, 'campaign.create', 'campaign', $id, ['budget' => $v['budget']]);
        View::redirect('/pro/campagnes', 'Annonce envoyée en relecture à l\'équipe Axi.');
    }

    /** Règles communes à la création et à la modification ; en cas d'erreur, retour au formulaire avec le message */
    private static function validated(array $p, string $back): array
    {
        $title = trim((string) ($p['title'] ?? ''));
        $budget = (int) ($p['budget'] ?? 0);
        $url = trim((string) ($p['url'] ?? ''));
        $errors = match (true) {
            $title === '' || mb_strlen($title) > 150 => 'Titre obligatoire (150 caractères maximum).',
            trim((string) ($p['body'] ?? '')) === '' => 'Écrivez une description de ce que vous annoncez.',
            $budget < Settings::int('min_campaign_budget') => 'Le budget minimum est de ' . View::fcfa(Settings::int('min_campaign_budget')) . '.',
            $url !== '' && !str_starts_with($url, 'https://') => 'Le lien doit commencer par https://.',
            default => null,
        };
        if ($errors !== null) {
            View::redirect($back, $errors, 'err');
        }
        $date = static fn (string $k): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p[$k] ?? '')) ? Clock::localDateStart($p[$k]) : null;
        return [
            'audience' => array_key_exists($p['audience_type'] ?? '', self::AUDIENCES) ? $p['audience_type'] : 'clients', 'title' => $title,
            'body' => mb_substr(trim((string) ($p['body'] ?? '')), 0, 500), 'url' => $url === '' ? null : $url,
            'budget' => $budget, 'starts' => $date('starts_at'), 'ends' => $date('ends_at'),
        ];
    }

    /** Image ou vidéo envoyée : remplace l'ancien média. @return array{image: ?string, video: ?string}|null */
    private function uploadedMedia(Request $req, string $back): ?array
    {
        if (($file = $req->file('media')) === null) {
            return null;
        }
        try {
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            return str_starts_with($mime, 'video/') ? ['image' => null, 'video' => Uploads::video($file)] : ['image' => Uploads::photo($file)['path'], 'video' => null];
        } catch (HttpException $e) {
            View::redirect($back, $e->getMessage(), 'err');
        }
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

    /** Supprimer : l'annonceur ses propres campagnes, l'équipe Axi toutes. La campagne disparaît tout de suite de l'application. */
    public function destroy(Request $req): never
    {
        $user = WebAuth::require(['axi', 'advertiser']);
        WebAuth::checkCsrf();
        $id = (int) ($req->params['id'] ?? 0);
        $c = Database::one('SELECT * FROM campaigns WHERE id = ?', [$id]) ?? throw new HttpException(404, 'NOT_FOUND', 'Campagne introuvable.');
        if ($user['partner_type'] !== 'axi' && (int) $c['organization_id'] !== (int) $user['organization_id']) {
            throw new HttpException(403, 'FORBIDDEN', 'Action non autorisée sur cette campagne.');
        }
        Database::run('DELETE FROM campaigns WHERE id = ?', [$id]);
        $req->user = $user;
        AdminLog::record($req, 'campaign.delete', 'campaign', $id, ['title' => $c['title'], 'budget' => $c['budget']]);
        View::redirect('/pro/campagnes', 'Campagne supprimée.');
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
