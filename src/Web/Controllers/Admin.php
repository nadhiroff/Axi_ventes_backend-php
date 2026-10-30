<?php
declare(strict_types=1);

namespace Axi\Web\Controllers;

use Axi\Core\Auth;
use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Core\Url;
use Axi\Core\Validator;
use Axi\Services\AdminLog;
use Axi\Services\EmailCodes;
use Axi\Services\Notifier;
use Axi\Services\Settings;
use Axi\Support\Users;
use Axi\Web\Html;
use Axi\Web\View;
use Axi\Web\WebAuth;

/** Agent Axi : comptes partenaires et organisations, réglages métier (montants en base), journal d'audit */
final class Admin
{
    public function partners(Request $req): never
    {
        WebAuth::require(['axi']);
        $orgs = Database::all('SELECT o.*, (SELECT COUNT(*) FROM users u WHERE u.organization_id = o.id AND u.status <> \'deleted\') AS members FROM organizations o ORDER BY o.type, o.name');
        $users = Database::all("SELECT u.*, o.name AS org, o.type FROM users u JOIN organizations o ON o.id = u.organization_id WHERE u.role = 'partner' AND u.status <> 'deleted' ORDER BY o.name, u.name");
        $orgOptions = [];
        foreach ($orgs as $o) {
            $orgOptions[$o['id']] = $o['name'] . ' (' . Users::PARTNER_TYPES[$o['type']] . ')';
        }
        $body = '<div class="row2"><div class="card"><h2>Nouvelle organisation</h2>' . Html::form('/pro/partenaires/organisation', [
            'name' => ['Nom', 'text', '', ['attrs' => ' required maxlength="150"']],
            'type' => ['Type', 'select', 'advertiser', Users::PARTNER_TYPES],
        ], 'Créer l\'organisation') . '</div>'
            . '<div class="card"><h2>Nouveau compte partenaire</h2><p class="muted">La personne reçoit un email avec un code pour choisir son mot de passe.</p>' . Html::form('/pro/partenaires/compte', [
                'first_name' => ['Prénom', 'text', '', ['attrs' => ' required']],
                'last_name' => ['Nom', 'text', '', ['attrs' => ' required']],
                'email' => ['Email', 'email', '', ['attrs' => ' required']],
                'organization_id' => ['Organisation', 'select', '', $orgOptions],
            ], 'Créer le compte') . '</div></div>'
            . '<h2>Organisations</h2>' . Html::table(['Organisation', 'Type', '#Comptes', 'État', ''], array_map(static fn (array $o): array => [
                View::e($o['name']), View::e(Users::PARTNER_TYPES[$o['type']]), (string) $o['members'], $o['status'] === 'active' ? View::badge('Active', 'ok') : View::badge('Suspendue', 'danger'),
                (int) $o['id'] === 1 ? '' : View::action('/pro/partenaires/organisation/' . $o['id'], $o['status'] === 'active' ? 'Suspendre' : 'Réactiver', [], $o['status'] === 'active' ? 'Suspendre cette organisation ? Ses comptes ne pourront plus se connecter.' : null, 'btn ghost'),
            ], $orgs))
            . '<h2>Comptes partenaires</h2>' . Html::table(['Nom', 'Email', 'Organisation', 'État', ''], array_map(static fn (array $u): array => [
                View::e($u['name']), View::e($u['email']), View::e($u['org'] . ' · ' . Users::PARTNER_TYPES[$u['type']]),
                $u['status'] === 'active' ? View::badge('Actif', 'ok') : View::badge('Suspendu', 'danger'),
                View::action('/pro/partenaires/compte/' . $u['id'], $u['status'] === 'active' ? 'Suspendre' : 'Réactiver', [], $u['status'] === 'active' ? 'Suspendre ce compte partenaire ?' : null, 'btn ghost'),
            ], $users));
        Html::page('Comptes partenaires', $body, 'Agents Axi, ANaGeM, annonceurs et IMF : les comptes partenaires sont créés ici, jamais dans l\'application.');
    }

    public function createOrganization(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $name = trim((string) ($_POST['name'] ?? ''));
        $type = array_key_exists($_POST['type'] ?? '', Users::PARTNER_TYPES) ? $_POST['type'] : null;
        if ($name === '' || mb_strlen($name) > 150 || $type === null) {
            View::redirect('/pro/partenaires', 'Nom et type obligatoires.', 'err');
        }
        $id = Database::insert('INSERT INTO organizations (name, type) VALUES (?, ?)', [$name, $type]);
        $req->user = $agent;
        AdminLog::record($req, 'organization.create', 'organization', $id, ['name' => $name, 'type' => $type]);
        View::redirect('/pro/partenaires', 'Organisation créée.');
    }

    public function toggleOrganization(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $id = (int) ($req->params['id'] ?? 0);
        if ($id === 1) {
            View::redirect('/pro/partenaires', 'L\'organisation Axi ne peut pas être suspendue.', 'err');
        }
        Database::run("UPDATE organizations SET status = IF(status = 'active', 'suspended', 'active') WHERE id = ?", [$id]);
        $req->user = $agent;
        AdminLog::record($req, 'organization.toggle', 'organization', $id);
        View::redirect('/pro/partenaires', 'Organisation mise à jour.');
    }

    public function createPartner(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        try {
            $in = Validator::validate($_POST, ['first_name' => 'required|string|max:60', 'last_name' => 'required|string|max:60', 'email' => 'required|email', 'organization_id' => 'required|int|min:1'], ['first_name' => 'Prénom', 'last_name' => 'Nom', 'email' => 'Email']);
        } catch (HttpException $e) {
            View::redirect('/pro/partenaires', $e->getMessage(), 'err');
        }
        if (Database::value('SELECT id FROM users WHERE email = ?', [$in['email']]) !== null) {
            View::redirect('/pro/partenaires', 'Un compte existe déjà avec cet email.', 'err');
        }
        if (Database::value('SELECT id FROM organizations WHERE id = ?', [$in['organization_id']]) === null) {
            View::redirect('/pro/partenaires', 'Organisation inconnue.', 'err');
        }
        $id = Database::insert(
            "INSERT INTO users (first_name, last_name, name, email, password_hash, email_verified_at, role, organization_id, created_at) VALUES (?, ?, ?, ?, ?, ?, 'partner', ?, ?)",
            [$in['first_name'], $in['last_name'], $in['first_name'] . ' ' . $in['last_name'], $in['email'], password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), Clock::now(), $in['organization_id'], Clock::now()],
        );
        // Le partenaire choisit lui-même son mot de passe grâce au code reçu par email
        EmailCodes::issue($in['email'], 'reset_password', Users::find($id));
        Notifier::send($id, 'account', 'Votre accès à Axi Pro', 'Un compte partenaire Axi Pro a été créé pour vous. Choisissez votre mot de passe avec le code reçu par email, sur ' . Url::to('/pro/mot-de-passe-oublie') . '.', [], [], true);
        $req->user = $agent;
        AdminLog::record($req, 'partner.create', 'user', $id, ['email' => $in['email'], 'organization_id' => $in['organization_id']]);
        View::redirect('/pro/partenaires', 'Compte créé : un code a été envoyé à ' . $in['email'] . ' pour choisir son mot de passe.');
    }

    public function togglePartner(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $id = (int) ($req->params['id'] ?? 0);
        if ($id === (int) $agent['id']) {
            View::redirect('/pro/partenaires', 'Vous ne pouvez pas suspendre votre propre compte.', 'err');
        }
        Database::run("UPDATE users SET status = IF(status = 'active', 'suspended', 'active') WHERE id = ? AND role = 'partner'", [$id]);
        Auth::revokeAll($id);
        $req->user = $agent;
        AdminLog::record($req, 'partner.toggle', 'user', $id);
        View::redirect('/pro/partenaires', 'Compte mis à jour.');
    }

    public function settings(Request $req): never
    {
        WebAuth::require(['axi']);
        $rows = array_values(Settings::all());
        $body = '<div class="card"><form method="post" action="' . View::url('/pro/reglages') . '" class="form" onsubmit="return confirm(' . View::e(json_encode('Enregistrer ces réglages ? Ils s\'appliquent immédiatement aux nouvelles commandes.', JSON_UNESCAPED_UNICODE)) . ')">' . View::csrf();
        foreach ($rows as $s) {
            $name = 'v[' . View::e($s['k']) . ']';
            $body .= '<div><label>' . View::e($s['label']) . '</label>';
            $body .= match ($s['type']) {
                'bool' => '<select name="' . $name . '"><option value="1"' . ($s['v'] === '1' ? ' selected' : '') . '>Oui</option><option value="0"' . ($s['v'] === '0' ? ' selected' : '') . '>Non</option></select>',
                'percent' => '<select name="' . $name . '">' . implode('', array_map(static fn (string $p): string => '<option value="' . $p . '"' . ($s['v'] === $p ? ' selected' : '') . '>' . ($p === '0' ? 'Désactivée (0 %)' : $p . ' %') . '</option>', ['0', '2', '3', '4'])) . '</select>',
                default => '<input name="' . $name . '" value="' . View::e($s['v']) . '"' . ($s['type'] === 'int' ? ' inputmode="numeric" pattern="\d+"' : '') . '>',
            };
            $body .= '<div class="muted" style="font-size:12px">Modifié le ' . View::date($s['updated_at']) . '</div></div>';
        }
        $body .= '<div class="full"><button class="btn" type="submit">Enregistrer les réglages</button></div></form></div>';
        Html::page('Réglages', $body, 'Hypothèses de la note de cadrage, modifiables à tout moment : frais, abonnements, commission, délais, seuils.');
    }

    public function saveSettings(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $changed = [];
        try {
            foreach ((array) ($_POST['v'] ?? []) as $k => $v) {
                $old = Settings::all()[$k]['v'] ?? null;
                if ($old !== null && (string) $v !== $old) {
                    Settings::set((string) $k, (string) $v, (int) $agent['id']);
                    $changed[$k] = [$old, $v];
                }
            }
        } catch (HttpException $e) {
            View::redirect('/pro/reglages', $e->getMessage(), 'err');
        }
        $req->user = $agent;
        AdminLog::record($req, 'settings.update', 'settings', null, $changed);
        View::redirect('/pro/reglages', $changed === [] ? 'Aucun changement.' : count($changed) . ' réglage(s) modifié(s).');
    }

    public function journal(Request $req): never
    {
        WebAuth::require(['axi']);
        $actions = Database::all('SELECT a.*, u.name FROM admin_actions a JOIN users u ON u.id = a.actor_id ORDER BY a.id DESC LIMIT 200');
        $events = Database::all('SELECT * FROM payment_events ORDER BY id DESC LIMIT 200');
        Html::page('Journal', '<h2>Actions des partenaires</h2>' . Html::table(['Date', 'Qui', 'Action', 'Cible', 'Détails'], array_map(static fn (array $a): array => [
            View::date($a['created_at']), View::e($a['name']), View::e($a['action']), View::e($a['target_type'] . ($a['target_id'] ? ' #' . $a['target_id'] : '')), '<code style="font-size:12px">' . View::e(mb_strimwidth((string) $a['details'], 0, 160, '…')) . '</code>',
        ], $actions)) . '<h2>Paiements et séquestre</h2>' . Html::table(['Date', 'Événement', 'Par', 'Commande', 'Avant → après', 'Référence', '#Montant'], array_map(static fn (array $e): array => [
            View::date($e['created_at']), View::e($e['event']), View::e($e['actor_type'] . ($e['actor_id'] ? ' #' . $e['actor_id'] : '')),
            $e['order_id'] ? '<a href="' . View::url('/pro/commandes/' . $e['order_id']) . '">n° ' . (int) $e['order_id'] . '</a>' : '—',
            View::e(($e['old_state'] ?? '∅') . ' → ' . ($e['new_state'] ?? '∅')), View::e($e['gateway_ref'] ?? ''), $e['amount'] === null ? '' : View::fcfa($e['amount']),
        ], $events)), 'Qui a fait quoi, et quand.');
    }
}
