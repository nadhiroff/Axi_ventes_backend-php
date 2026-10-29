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
            $decision = $status !== 'open' ? View::badge($status === 'blocked' ? 'Bloqué' : 'Classé', $status === 'blocked' ? 'danger' : 'muted')
                : '<details><summary class="btn sm danger">Bloquer l\'accès</summary>' . Clients::blockForm('/pro/signalements/' . $r['id'] . '/bloquer') . '</details>'
                  . View::action('/pro/signalements/' . $r['id'] . '/classer', 'Classer', [], 'Le signalement sera classé sans suite. Le compte reste actif.', 'btn ghost sm');
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
        Html::page('Signalements', $body, 'Profils signalés par les clients et les commerçants depuis l\'application.');
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
