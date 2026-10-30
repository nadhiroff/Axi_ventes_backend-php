<?php
declare(strict_types=1);

namespace Axi\Web\Controllers;

use Axi\Core\Auth;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Services\AdminLog;
use Axi\Web\Html;
use Axi\Web\View;
use Axi\Web\WebAuth;

/** Agent Axi : comptes clients (recherche, fiche, commandes, suspension / réactivation) */
final class Clients
{
    private const PER_PAGE = 40;

    public function index(Request $req): never
    {
        WebAuth::require(['axi']);
        $where = ["u.role IN ('client','merchant')", "u.status <> 'deleted'"];
        $params = [];
        if (in_array($_GET['role'] ?? '', ['client', 'merchant'], true)) {
            $where[] = 'u.role = ?';
            $params[] = $_GET['role'];
        }
        if (($q = trim((string) ($_GET['q'] ?? ''))) !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        if (($s = (string) ($_GET['status'] ?? '')) !== '' && in_array($s, ['active', 'suspended'], true)) {
            $where[] = 'u.status = ?';
            $params[] = $s;
        }
        $page = View::page();
        $rows = Database::all(
            'SELECT u.*, (SELECT COUNT(*) FROM orders o WHERE o.client_id = u.id AND o.status <> \'awaiting_payment\' AND o.status <> \'cancelled\') AS orders,
                    (SELECT COALESCE(SUM(total),0) FROM orders o WHERE o.client_id = u.id AND o.paid_at IS NOT NULL) AS spent
             FROM users u WHERE ' . implode(' AND ', $where) . ' ORDER BY u.id DESC LIMIT ' . (self::PER_PAGE + 1) . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params,
        );
        Html::page('Utilisateurs', Html::filters('/pro/clients', [
            'q' => ['Nom, email ou téléphone', 'search', null],
            'role' => ['Profil', 'select', ['' => 'Tous', 'client' => 'Clients', 'merchant' => 'Commerçants']],
            'status' => ['Accès', 'select', ['' => 'Tous', 'active' => 'Actifs', 'suspended' => 'Bloqués']],
        ])
            . Html::table(['Personne', 'Email', 'Profil', '#Commandes', 'Inscrit le', 'Accès'], array_map(static fn (array $u): array => [
                '<a href="' . View::url('/pro/clients/' . $u['id']) . '"><strong>' . View::e($u['name']) . '</strong></a>', View::e($u['email']) . ($u['email_verified_at'] ? '' : ' ' . View::badge('non confirmé', 'warn')),
                $u['role'] === 'merchant' ? View::badge('Commerçant', 'warn') : View::badge('Client'), (string) $u['orders'], View::date($u['created_at'], false),
                $u['status'] === 'active' ? View::badge('Actif', 'ok') : View::badge('Bloqué', 'danger'),
            ], array_slice($rows, 0, self::PER_PAGE)), 'Aucun utilisateur.')
            . View::pager('/pro/clients', array_intersect_key($_GET, array_flip(['q', 'status', 'role'])), $page, count($rows) > self::PER_PAGE),
            'Clients et commerçants : bloquer ou débloquer l\'accès à l\'application, avec un motif envoyé à la personne.');
    }

    public function show(Request $req): never
    {
        WebAuth::require(['axi']);
        $u = $this->client((int) ($req->params['id'] ?? 0));
        $orders = Database::all('SELECT * FROM orders WHERE client_id = ? ORDER BY id DESC LIMIT 50', [$u['id']]);
        $disputes = (int) Database::value('SELECT COUNT(*) FROM disputes WHERE client_id = ?', [$u['id']]);
        $logins = Database::all('SELECT * FROM login_events WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$u['id']]);
        $base = '/pro/clients/' . $u['id'];
        $body = '<div class="row2"><div class="card"><dl><dt>Email</dt><dd>' . View::e($u['email']) . '</dd><dt>Téléphone</dt><dd>' . View::e($u['phone'] ?? '—') . '</dd>'
            . '<dt>Langue</dt><dd>' . View::e($u['lang']) . '</dd><dt>Inscrit le</dt><dd>' . View::date($u['created_at']) . '</dd><dt>Litiges</dt><dd>' . $disputes . '</dd>'
            . '<dt>Profil</dt><dd>' . ($u['role'] === 'merchant' ? 'Commerçant' : 'Client') . '</dd>'
            . '<dt>Signalements</dt><dd>' . (int) Database::value('SELECT COUNT(*) FROM reports WHERE target_user_id = ?', [$u['id']]) . '</dd>'
            . '<dt>Accès</dt><dd>' . ($u['status'] === 'active' ? View::badge('Actif', 'ok') : View::badge('Bloqué', 'danger') . ($u['suspended_reason'] ? '<br><span class="muted small">Motif : ' . View::e($u['suspended_reason']) . '</span>' : '')) . '</dd></dl></div>'
            . '<div class="card"><h2>' . ($u['status'] === 'active' ? 'Bloquer l\'accès' : 'Débloquer l\'accès') . '</h2>'
            . ($u['status'] === 'active'
                ? self::blockForm("$base/suspendre")
                : '<p class="muted">La personne pourra de nouveau se connecter. Elle est prévenue par email.</p>' . View::action("$base/activer", 'Débloquer l\'accès', [], 'Cette personne pourra de nouveau utiliser Axi.', 'btn ok'))
            . '</div>'
            . '</div><div class="card"><h2>Dernières connexions</h2>' . Html::table(['Date', 'Résultat', 'Appareil'], array_map(static fn (array $l): array => [View::date($l['created_at']), $l['success'] ? View::badge('Réussie', 'ok') : View::badge('Refusée', 'danger'), View::e($l['device'] ?? '')], $logins)) . '</div></div>'
            . '<h2>Commandes</h2>' . Html::table(['Commande', 'Date', '#Total', 'Statut'], array_map(static fn (array $o): array => [
                '<a href="' . View::url('/pro/commandes/' . $o['id']) . '">n° ' . (int) $o['id'] . '</a>', View::date($o['created_at']), View::fcfa($o['total']), Orders::orderBadge($o['status']),
            ], $orders), 'Aucune commande.');
        Html::page($u['name'], $body, $u['role'] === 'merchant' ? 'Fiche utilisateur (commerçant)' : 'Fiche utilisateur (client)');
    }

    public function suspend(Request $req): never
    {
        $this->setStatus($req, 'suspended');
    }

    public function activate(Request $req): never
    {
        $this->setStatus($req, 'active');
    }

    /** Formulaire « Bloquer l'accès » : motif fréquent ou libre, envoyé à la personne */
    public static function blockForm(string $action, array $hidden = []): string
    {
        $html = '<form method="post" action="' . View::url($action) . '" class="form"' . View::confirmAttrs('La personne sera déconnectée de tous ses appareils et ne pourra plus se connecter.', 'Bloquer l\'accès', true) . '>' . View::csrf();
        foreach ($hidden as $k => $v) {
            $html .= '<input type="hidden" name="' . View::e($k) . '" value="' . View::e($v) . '">';
        }
        return $html . '<div class="full"><label for="reason_pick">Motif</label><select id="reason_pick" onchange="document.getElementById(\'block_reason\').value=this.value"><option value="">Choisir un motif fréquent…</option>'
            . implode('', array_map(static fn (string $r): string => '<option>' . View::e($r) . '</option>', [
                'Tentative d\'arnaque signalée par d\'autres utilisateurs.',
                'Insultes ou harcèlement dans la messagerie.',
                'Faux profil ou usurpation d\'identité.',
                'Messages indésirables répétés.',
            ])) . '</select></div>'
            . '<div class="full"><textarea id="block_reason" name="reason" rows="3" required maxlength="300" placeholder="Le motif est montré à la personne quand elle essaie de se connecter"></textarea></div>'
            . '<div class="full"><button class="btn danger" type="submit">Bloquer l\'accès</button></div></form>';
    }

    /** Bloquer ou débloquer l'accès d'un client ou d'un commerçant (son étal suit) ; journalisé, personne prévenue par email */
    public static function applyStatus(Request $req, array $agent, int $userId, string $status, string $reason = ''): void
    {
        $u = Database::one('SELECT * FROM users WHERE id = ?', [$userId]);
        Database::run('UPDATE users SET status = ?, suspended_reason = ? WHERE id = ?', [$status, $status === 'suspended' ? mb_substr($reason, 0, 300) : null, $userId]);
        if ($u['role'] === 'merchant') {
            Database::run('UPDATE stalls SET status = ? WHERE user_id = ?', [$status, $userId]);
        }
        if ($status === 'suspended') {
            Auth::revokeAll($userId);
        }
        $req->user = $agent;
        AdminLog::record($req, 'user.' . ($status === 'active' ? 'unblock' : 'block'), 'user', $userId, $reason !== '' ? ['reason' => $reason] : []);
        $mail = $status === 'suspended'
            ? ['Votre accès à Axi est bloqué', 'L\'équipe Axi a bloqué l\'accès à votre compte. Motif : ' . $reason]
            : ['Votre accès à Axi est rétabli', 'L\'équipe Axi a débloqué votre compte : vous pouvez de nouveau vous connecter.'];
        \Axi\Services\Notifier::send($userId, 'account', $mail[0], $mail[1], [], [[$mail[1]]], true);
    }

    private function setStatus(Request $req, string $status): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $u = $this->client((int) ($req->params['id'] ?? 0));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($status === 'suspended' && mb_strlen($reason) < 5) {
            View::redirect('/pro/clients/' . $u['id'], 'Indiquez le motif du blocage : il est montré à la personne.', 'err');
        }
        self::applyStatus($req, $agent, (int) $u['id'], $status, $reason);
        View::redirect('/pro/clients/' . $u['id'], $status === 'active' ? 'Accès débloqué : la personne a été prévenue par email.' : 'Accès bloqué : la personne est déconnectée et voit le motif si elle essaie de se connecter.');
    }

    private function client(int $id): array
    {
        return Database::one("SELECT * FROM users WHERE id = ? AND role IN ('client','merchant') AND status <> 'deleted'", [$id]) ?? throw new HttpException(404, 'NOT_FOUND', 'Utilisateur introuvable.');
    }
}
