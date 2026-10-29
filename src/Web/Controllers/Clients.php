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
        $where = ["u.role = 'client'", "u.status <> 'deleted'"];
        $params = [];
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
        Html::page('Clients', Html::filters('/pro/clients', ['q' => ['Nom, email ou téléphone', 'search', null], 'status' => ['Compte', 'select', ['' => 'Tous', 'active' => 'Actifs', 'suspended' => 'Suspendus']]])
            . Html::table(['Client', 'Email', 'Téléphone', '#Commandes', '#Dépensé', 'Inscrit le', 'Compte'], array_map(static fn (array $u): array => [
                '<a href="' . View::url('/pro/clients/' . $u['id']) . '"><strong>' . View::e($u['name']) . '</strong></a>', View::e($u['email']) . ($u['email_verified_at'] ? '' : ' ' . View::badge('non confirmé', 'warn')),
                View::e($u['phone'] ?? '—'), (string) $u['orders'], View::fcfa($u['spent']), View::date($u['created_at'], false),
                $u['status'] === 'active' ? View::badge('Actif', 'ok') : View::badge('Suspendu', 'danger'),
            ], array_slice($rows, 0, self::PER_PAGE)), 'Aucun client.')
            . View::pager('/pro/clients', array_intersect_key($_GET, array_flip(['q', 'status'])), $page, count($rows) > self::PER_PAGE));
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
            . '<dt>Compte</dt><dd>' . ($u['status'] === 'active' ? View::badge('Actif', 'ok') : View::badge('Suspendu', 'danger')) . '</dd></dl>'
            . '<div class="actions" style="margin-top:12px">' . ($u['status'] === 'active'
                ? View::action("$base/suspendre", 'Suspendre ce client', [], 'Êtes-vous sûr de vouloir suspendre ce client ? Il sera déconnecté de tous ses appareils.', 'btn danger')
                : View::action("$base/activer", 'Réactiver ce client', [], 'Réactiver ce compte client ?', 'btn ok')) . '</div></div>'
            . '<div class="card"><h2 style="margin-top:0">Dernières connexions</h2>' . Html::table(['Date', 'Résultat', 'Appareil'], array_map(static fn (array $l): array => [View::date($l['created_at']), $l['success'] ? View::badge('Réussie', 'ok') : View::badge('Refusée', 'danger'), View::e($l['device'] ?? '')], $logins)) . '</div></div>'
            . '<h2>Commandes</h2>' . Html::table(['Commande', 'Date', '#Total', 'Statut'], array_map(static fn (array $o): array => [
                '<a href="' . View::url('/pro/commandes/' . $o['id']) . '">cmd_' . (int) $o['id'] . '</a>', View::date($o['created_at']), View::fcfa($o['total']), Orders::orderBadge($o['status']),
            ], $orders), 'Aucune commande.');
        Html::page($u['name'], $body, 'Fiche client');
    }

    public function suspend(Request $req): never
    {
        $this->setStatus($req, 'suspended');
    }

    public function activate(Request $req): never
    {
        $this->setStatus($req, 'active');
    }

    private function setStatus(Request $req, string $status): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $u = $this->client((int) ($req->params['id'] ?? 0));
        Database::run('UPDATE users SET status = ? WHERE id = ?', [$status, $u['id']]);
        if ($status === 'suspended') {
            Auth::revokeAll((int) $u['id']);
        }
        $req->user = $agent;
        AdminLog::record($req, 'client.' . ($status === 'active' ? 'activate' : 'suspend'), 'user', (int) $u['id']);
        View::redirect('/pro/clients/' . $u['id'], $status === 'active' ? 'Client réactivé.' : 'Client suspendu et déconnecté.');
    }

    private function client(int $id): array
    {
        return Database::one("SELECT * FROM users WHERE id = ? AND role = 'client' AND status <> 'deleted'", [$id]) ?? throw new HttpException(404, 'NOT_FOUND', 'Client introuvable.');
    }
}
