<?php
declare(strict_types=1);

namespace Axi\Web\Controllers;

use Axi\Controllers\ProfileController;
use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Web\Html;
use Axi\Web\View;
use Axi\Web\WebAuth;

/**
 * Agent Axi : profils signalés depuis l'application. Bloquer l'accès (motif envoyé à la personne,
 * déconnexion de tous ses appareils) ou classer le signalement.
 */
final class Reports
{
    public function index(Request $req): never
    {
        WebAuth::require(['axi']);
        $status = in_array($_GET['status'] ?? 'open', ['open', 'blocked', 'dismissed'], true) ? ($_GET['status'] ?? 'open') : 'open';
        $rows = Database::all(
            'SELECT r.*, t.name AS target, t.email AS target_email, t.role AS target_role, t.status AS target_status, p.name AS reporter,
                    (SELECT COUNT(*) FROM reports x WHERE x.target_user_id = r.target_user_id) AS total
             FROM reports r JOIN users t ON t.id = r.target_user_id JOIN users p ON p.id = r.reporter_id
             WHERE r.status = ? ORDER BY r.id DESC LIMIT 200',
            [$status],
        );
        $tabs = '<nav class="tabs">';
        foreach (['open' => 'À examiner', 'blocked' => 'Comptes bloqués', 'dismissed' => 'Classés'] as $k => $label) {
            $n = (int) Database::value('SELECT COUNT(*) FROM reports WHERE status = ?', [$k]);
            $tabs .= '<a href="' . View::url('/pro/signalements', ['status' => $k]) . '" class="' . ($k === $status ? 'on' : '') . '">' . View::e($label) . " ($n)</a>";
        }
        $tabs .= '</nav>';
        $body = $tabs . Html::table(['Reçu le', 'Profil signalé', 'Motif', 'Signalé par', 'Décision'], array_map(static function (array $r) use ($status): array {
            $decision = $status === 'blocked'
                ? ($r['target_status'] === 'suspended'
                    ? View::badge('Bloqué', 'danger') . ' ' . View::action('/pro/signalements/' . $r['id'] . '/debloquer', 'Débloquer', [], 'Cette personne pourra de nouveau utiliser Axi. Elle est prévenue par email et dans l\'application.', 'btn ok sm')
                    : View::badge('Déjà débloqué', 'ok'))
                : ($status === 'dismissed' ? View::badge('Classé') : '<div class="actions">'
                  . ($r['target_status'] === 'active' ? Clients::blockPopup('/pro/signalements/' . $r['id'] . '/bloquer', $r['target']) : '')
                  . View::action('/pro/signalements/' . $r['id'] . '/classer', 'Classer', [], 'Le signalement sera classé sans suite. Le compte reste actif.', 'btn ghost sm') . '</div>');
            return [
                View::date($r['created_at']),
                '<a href="' . View::url('/pro/clients/' . $r['target_user_id']) . '"><strong>' . View::e($r['target']) . '</strong></a><br><span class="muted small">'
                    . View::e(($r['target_role'] === 'merchant' ? 'Commerçant' : 'Client') . ' · ' . $r['target_email']) . ' · ' . (int) $r['total'] . ' signalement(s)</span>'
                    . ($r['target_status'] !== 'active' ? ' ' . View::badge('Déjà bloqué', 'danger') : ''),
                View::badge(ProfileController::reasonLabel($r['reason']), 'warn') . ($r['details'] ? '<br><span class="small">' . View::e($r['details']) . '</span>' : ''),
                View::e($r['reporter']),
                $decision,
            ];
        }, $rows), $status === 'open' ? 'Aucun signalement à examiner.' : 'Aucun signalement.');
        // Annonces signalées : ouverture directe pour les corriger
        $ads = Database::all("SELECT c.id, c.title, o.name AS org, COUNT(*) AS n, MAX(r.created_at) AS last_at FROM campaign_reports r JOIN campaigns c ON c.id = r.campaign_id JOIN organizations o ON o.id = c.organization_id WHERE r.status = 'open' GROUP BY c.id, c.title, o.name ORDER BY last_at DESC");
        $adsHtml = $ads === [] ? '' : '<h2>Annonces signalées (' . count($ads) . ')</h2>' . Html::table(['Annonce', 'Annonceur', '#Signalements', 'Dernier le', ''], array_map(static fn (array $a): array => [
            '<strong>' . View::e($a['title']) . '</strong>', View::e($a['org']), (string) $a['n'], View::date($a['last_at']),
            '<a class="btn sm" href="' . View::url('/pro/campagnes/' . $a['id'] . '/modifier') . '">Voir et modifier</a>',
        ], $ads));
        Html::page('Signalements', $adsHtml . '<h2>Profils signalés</h2>' . $body, 'Annonces et profils signalés par les clients et les commerçants depuis l\'application.');
    }

    public function block(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $r = $this->find((int) ($req->params['id'] ?? 0));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            View::redirect('/pro/signalements', 'Indiquez le motif du blocage : il est montré à la personne.', 'err');
        }
        Clients::applyStatus($req, $agent, (int) $r['target_user_id'], 'suspended', $reason);
        // Tous les signalements ouverts sur ce compte sont traités
        Database::run("UPDATE reports SET status = 'blocked', handled_by = ?, handled_at = ? WHERE target_user_id = ? AND status = 'open'", [$agent['id'], Clock::now(), $r['target_user_id']]);
        View::redirect('/pro/signalements', 'Accès bloqué : la personne est déconnectée et voit le motif si elle essaie de se connecter.');
    }

    /** Lever le blocage : accès rendu, personne prévenue, signalements classés (ils restent visibles dans « Classés ») */
    public function unblock(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $r = $this->find((int) ($req->params['id'] ?? 0));
        if (Database::value('SELECT status FROM users WHERE id = ?', [$r['target_user_id']]) !== 'suspended') {
            View::redirect('/pro/signalements?status=blocked', 'Ce compte n\'est plus bloqué.', 'err');
        }
        Clients::applyStatus($req, $agent, (int) $r['target_user_id'], 'active');
        Database::run("UPDATE reports SET status = 'dismissed', handled_by = ?, handled_at = ? WHERE target_user_id = ? AND status = 'blocked'", [$agent['id'], Clock::now(), $r['target_user_id']]);
        View::redirect('/pro/signalements?status=blocked', 'Accès débloqué : la personne a été prévenue.');
    }

    public function dismiss(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $r = $this->find((int) ($req->params['id'] ?? 0));
        Database::run("UPDATE reports SET status = 'dismissed', handled_by = ?, handled_at = ? WHERE id = ?", [$agent['id'], Clock::now(), $r['id']]);
        View::redirect('/pro/signalements', 'Signalement classé.');
    }

    private function find(int $id): array
    {
        return Database::one('SELECT * FROM reports WHERE id = ?', [$id]) ?? throw new HttpException(404, 'NOT_FOUND', 'Signalement introuvable.');
    }
}
