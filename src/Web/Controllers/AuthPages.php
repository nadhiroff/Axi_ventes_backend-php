<?php
declare(strict_types=1);

namespace Axi\Web\Controllers;

use Axi\Controllers\AuthController;
use Axi\Core\Auth;
use Axi\Core\Config;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Services\EmailCodes;
use Axi\Support\Users;
use Axi\Web\Html;
use Axi\Web\View;
use Axi\Web\WebAuth;

/** Connexion des partenaires (email + mot de passe), mot de passe oublié, « Mon compte » */
final class AuthPages
{
    public function home(Request $req): never
    {
        View::redirect(WebAuth::user() === null ? '/pro/connexion' : '/pro');
    }

    public function loginForm(Request $req): never
    {
        if (WebAuth::user() !== null) {
            View::redirect('/pro');
        }
        View::render('login', ['mode' => 'login'], 'Connexion');
    }

    public function login(Request $req): never
    {
        WebAuth::checkCsrf();
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        try {
            $user = AuthController::checkCredentials($email, (string) ($_POST['password'] ?? ''), $req->ip(), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'navigateur'), 0, 120), 'web');
        } catch (HttpException $e) {
            WebAuth::flash($e->getMessage(), 'err');
            View::render('login', ['mode' => 'login', 'email' => $email], 'Connexion');
        }
        $org = Database::one('SELECT status FROM organizations WHERE id = ?', [$user['organization_id'] ?? 0]);
        if ($user['role'] !== 'partner' || $org === null || $org['status'] !== 'active') {
            AuthController::recordLogin((int) $user['id'], $email, $req->ip(), 'web', 'web', false, 'not_partner');
            WebAuth::flash('Cet espace est réservé aux partenaires. Clients et commerçants : utilisez l\'application mobile Axi.', 'err');
            View::render('login', ['mode' => 'login', 'email' => $email], 'Connexion');
        }
        if ($user['email_verified_at'] === null) {
            Database::run('UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = ?', [$user['id']]);
        }
        AuthController::recordLogin((int) $user['id'], $email, $req->ip(), 'navigateur', 'web', true);
        WebAuth::login($user);
        View::redirect('/pro');
    }

    public function logout(Request $req): never
    {
        WebAuth::checkCsrf();
        WebAuth::logout();
        View::redirect('/pro/connexion', 'Vous êtes déconnecté.');
    }

    public function forgotForm(Request $req): never
    {
        View::render('login', ['mode' => 'forgot'], 'Mot de passe oublié');
    }

    public function forgot(Request $req): never
    {
        WebAuth::checkCsrf();
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $user = Users::findByEmail($email);
            EmailCodes::issue($email, 'reset_password', $user !== null && $user['role'] === 'partner' ? $user : null);
        }
        WebAuth::flash('Si un compte partenaire correspond à cette adresse, un code vient d\'y être envoyé.');
        View::render('login', ['mode' => 'reset', 'email' => $email], 'Nouveau mot de passe');
    }

    public function reset(Request $req): never
    {
        WebAuth::checkCsrf();
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        try {
            if (mb_strlen($password) < (int) Config::get('auth.password_min') || mb_strlen($password) > 72) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Le mot de passe doit faire entre 8 et 72 caractères.');
            }
            EmailCodes::consume($email, 'reset_password', (string) ($_POST['code'] ?? ''));
            $user = Users::findByEmail($email) ?? throw new HttpException(422, 'CODE_INVALID', 'Code incorrect.');
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            Auth::revokeAll((int) $user['id']);
        } catch (HttpException $e) {
            WebAuth::flash($e->getMessage(), 'err');
            View::render('login', ['mode' => 'reset', 'email' => $email], 'Nouveau mot de passe');
        }
        View::redirect('/pro/connexion', 'Mot de passe modifié. Connectez-vous.');
    }

    public function account(Request $req): never
    {
        $user = WebAuth::require();
        $logins = Database::all('SELECT * FROM login_events WHERE user_id = ? ORDER BY id DESC LIMIT 15', [$user['id']]);
        $body = '<div class="row2"><div class="card"><h2 style="margin-top:0">Mes informations</h2><dl>'
            . '<dt>Nom</dt><dd>' . View::e($user['name']) . '</dd><dt>Email</dt><dd>' . View::e($user['email']) . '</dd>'
            . '<dt>Organisation</dt><dd>' . View::e($user['org_name']) . ' (' . View::e(Users::PARTNER_TYPES[$user['partner_type']]) . ')</dd></dl></div>'
            . '<div class="card"><h2 style="margin-top:0">Changer mon mot de passe</h2>'
            . Html::form('/pro/mon-compte/mot-de-passe', [
                'current_password' => ['Mot de passe actuel', 'password', '', null],
                'new_password' => ['Nouveau mot de passe (8 caractères minimum)', 'password', '', null],
            ], 'Changer le mot de passe', 'Confirmer le changement de mot de passe ?') . '</div></div>'
            . '<h2>Historique des connexions</h2>'
            . Html::table(['Date', 'Résultat', 'Canal', 'Adresse IP'], array_map(static fn (array $l): array => [
                View::date($l['created_at']), $l['success'] ? View::badge('Réussie', 'ok') : View::badge('Refusée', 'danger'), View::e($l['channel']), View::e($l['ip']),
            ], $logins));
        Html::page('Mon compte', $body);
    }

    public function changePassword(Request $req): never
    {
        $user = WebAuth::require();
        WebAuth::checkCsrf();
        $new = (string) ($_POST['new_password'] ?? '');
        if (!password_verify((string) ($_POST['current_password'] ?? ''), $user['password_hash'])) {
            View::redirect('/pro/mon-compte', 'Mot de passe actuel incorrect.', 'err');
        }
        if (mb_strlen($new) < (int) Config::get('auth.password_min') || mb_strlen($new) > 72) {
            View::redirect('/pro/mon-compte', 'Le nouveau mot de passe doit faire entre 8 et 72 caractères.', 'err');
        }
        Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        View::redirect('/pro/mon-compte', 'Mot de passe modifié.');
    }
}
