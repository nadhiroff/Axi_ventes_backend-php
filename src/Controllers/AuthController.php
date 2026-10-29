<?php
declare(strict_types=1);

namespace Axi\Controllers;

use Axi\Core\Auth;
use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Services\EmailCodes;
use Axi\Support\Users;

/** Connexion par email et mot de passe ; l'adresse est confirmée par un code à 6 chiffres. */
final class AuthController
{
    private const LABELS = [
        'first_name' => 'Prénom',
        'last_name' => 'Nom',
        'email' => 'Email',
        'password' => 'Mot de passe',
        'phone' => 'Téléphone',
        'code' => 'Code',
        'current_password' => 'Mot de passe actuel',
        'new_password' => 'Nouveau mot de passe',
        'purpose' => 'Type de code',
        'lang' => 'Langue',
    ];

    private const CODE_SENT = 'Si un compte correspond à cette adresse, un code vient d\'y être envoyé.';

    public function register(Request $req): never
    {
        $min = (int) Config::get('auth.password_min');
        $in = Validator::validate($req->all(), [
            'first_name' => 'required|string|max:60',
            'last_name' => 'required|string|max:60',
            'email' => 'required|email',
            'password' => "required|string|raw|minlen:$min|max:72",
            'phone' => 'required|phone',
        ], self::LABELS);

        if (Users::findByEmail($in['email']) !== null) {
            throw new HttpException(409, 'EMAIL_TAKEN', 'Un compte existe déjà avec cette adresse email. Connectez-vous ou choisissez « Mot de passe oublié ».');
        }
        if (Database::value('SELECT id FROM users WHERE phone = ?', [$in['phone']]) !== null) {
            throw new HttpException(409, 'PHONE_TAKEN', 'Ce numéro de téléphone est déjà utilisé par un autre compte.');
        }

        try {
            $id = Database::insert(
                'INSERT INTO users (phone, first_name, last_name, name, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$in['phone'], $in['first_name'], $in['last_name'], $in['first_name'] . ' ' . $in['last_name'], $in['email'],
                    password_hash($in['password'], PASSWORD_DEFAULT), 'client', Clock::now()],
            );
        } catch (\PDOException $e) {
            // Deux inscriptions simultanées avec la même adresse ou le même numéro
            if ($e->getCode() === '23000') {
                throw new HttpException(409, 'EMAIL_TAKEN', 'Un compte existe déjà avec cette adresse email ou ce numéro.');
            }
            throw $e;
        }

        $user = Users::find($id);
        EmailCodes::issue($in['email'], 'verify_email', $user);
        Response::ok(['user' => Users::present($user), 'message' => 'Un code a été envoyé à votre adresse email.'], 201);
    }

    public function verifyEmail(Request $req): never
    {
        $in = Validator::validate($req->all(), [
            'email' => 'required|email',
            'code' => 'required|digits:6',
            'device' => 'string|max:120',
        ], self::LABELS);

        EmailCodes::consume($in['email'], 'verify_email', $in['code']);
        $user = Users::findByEmail($in['email']) ?? throw new HttpException(422, 'CODE_INVALID', 'Code incorrect.');
        if ($user['email_verified_at'] === null) {
            Database::run('UPDATE users SET email_verified_at = ? WHERE id = ?', [Clock::now(), $user['id']]);
        }
        $this->session((int) $user['id'], $in['device'] ?? null);
    }

    public function resendCode(Request $req): never
    {
        $in = Validator::validate($req->all(), [
            'email' => 'required|email',
            'purpose' => 'required|in:verify_email,reset_password',
        ], self::LABELS);

        $user = Users::findByEmail($in['email']);
        // Pas de code de confirmation pour une adresse déjà confirmée (même réponse, pour ne rien révéler)
        if ($user !== null && $in['purpose'] === 'verify_email' && $user['email_verified_at'] !== null) {
            $user = null;
        }
        $wait = EmailCodes::issue($in['email'], $in['purpose'], $user);
        if ($wait > 0) {
            throw new HttpException(429, 'TOO_MANY_REQUESTS', self::waitMessage($wait), ['retry_after' => $wait], ['Retry-After' => (string) $wait]);
        }
        Response::ok(['message' => self::CODE_SENT]);
    }

    public function login(Request $req): never
    {
        $in = Validator::validate($req->all(), [
            'email' => 'required|email',
            'password' => 'required|string|raw|max:200',
            'device' => 'string|max:120',
        ], self::LABELS);

        $window = (int) Config::get('auth.login_window_seconds');
        $max = (int) Config::get('auth.login_max_failures');
        $since = Clock::now(-$window);
        $failures = Database::one(
            'SELECT SUM(email = ?) AS by_email, SUM(ip = ?) AS by_ip FROM login_failures WHERE created_at > ? AND (email = ? OR ip = ?)',
            [$in['email'], $req->ip(), $since, $in['email'], $req->ip()],
        );
        if ((int) $failures['by_email'] >= $max || (int) $failures['by_ip'] >= $max) {
            $minutes = (int) ceil($window / 60);
            throw new HttpException(429, 'TOO_MANY_REQUESTS', "Trop de tentatives de connexion. Réessayez dans $minutes minutes.", ['retry_after' => $window]);
        }

        $user = Users::findByEmail($in['email']);
        // Même durée de calcul que l'adresse existe ou non
        $hash = $user['password_hash'] ?? '$2y$12$PbGIbxhHLQrW3UlJ38pg.uBirWkYqJolzUOK0boAIj/Hic2ZIRfX.';
        if (!password_verify($in['password'], $hash) || $user === null) {
            Database::run('INSERT INTO login_failures (email, ip, created_at) VALUES (?, ?, ?)', [$in['email'], $req->ip(), Clock::now()]);
            throw new HttpException(401, 'INVALID_CREDENTIALS', 'Email ou mot de passe incorrect.');
        }
        if ($user['status'] !== 'active') {
            throw new HttpException(403, 'ACCOUNT_SUSPENDED', 'Votre compte est suspendu. Contactez l\'équipe Axi.');
        }
        if ($user['email_verified_at'] === null) {
            EmailCodes::issue($in['email'], 'verify_email', $user);
            throw new HttpException(403, 'EMAIL_NOT_VERIFIED', 'Confirmez d\'abord votre adresse email : un code vient de vous être envoyé.');
        }

        Database::run('DELETE FROM login_failures WHERE email = ?', [$in['email']]);
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($in['password'], PASSWORD_DEFAULT), $user['id']]);
        }
        $this->session((int) $user['id'], $in['device'] ?? null);
    }

    public function forgotPassword(Request $req): never
    {
        $in = Validator::validate($req->all(), ['email' => 'required|email'], self::LABELS);
        // Toujours la même réponse : on ne révèle ni l'existence du compte ni la limite d'envoi
        EmailCodes::issue($in['email'], 'reset_password', Users::findByEmail($in['email']));
        Response::ok(['message' => self::CODE_SENT]);
    }

    public function resetPassword(Request $req): never
    {
        $min = (int) Config::get('auth.password_min');
        $in = Validator::validate($req->all(), [
            'email' => 'required|email',
            'code' => 'required|digits:6',
            'password' => "required|string|raw|minlen:$min|max:72",
            'device' => 'string|max:120',
        ], self::LABELS);

        EmailCodes::consume($in['email'], 'reset_password', $in['code']);
        $user = Users::findByEmail($in['email']) ?? throw new HttpException(422, 'CODE_INVALID', 'Code incorrect.');
        if ($user['status'] !== 'active') {
            throw new HttpException(403, 'ACCOUNT_SUSPENDED', 'Votre compte est suspendu. Contactez l\'équipe Axi.');
        }

        // Le code reçu par email prouve aussi que l'adresse est la bonne
        Database::run(
            'UPDATE users SET password_hash = ?, email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?',
            [password_hash($in['password'], PASSWORD_DEFAULT), Clock::now(), $user['id']],
        );
        Database::run('DELETE FROM login_failures WHERE email = ?', [$in['email']]);
        Auth::revokeAll((int) $user['id']);
        $this->session((int) $user['id'], $in['device'] ?? null);
    }

    public function changePassword(Request $req): never
    {
        $min = (int) Config::get('auth.password_min');
        $in = Validator::validate($req->all(), [
            'current_password' => 'required|string|raw|max:200',
            'new_password' => "required|string|raw|minlen:$min|max:72",
        ], self::LABELS);

        if (!password_verify($in['current_password'], $req->user['password_hash'])) {
            // 422 et non 401 : un 401 fermerait la session dans l'application
            throw new HttpException(422, 'VALIDATION_ERROR', 'Mot de passe actuel incorrect.', ['fields' => ['current_password' => 'Mot de passe actuel incorrect.']]);
        }
        Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($in['new_password'], PASSWORD_DEFAULT), $req->userId()]);
        // Les autres appareils sont déconnectés ; la session en cours reste ouverte
        Auth::revokeAll($req->userId(), $req->tokenId);
        Response::ok(['changed' => true]);
    }

    public function me(Request $req): never
    {
        Response::ok(Users::present($req->user));
    }

    public function updateMe(Request $req): never
    {
        $in = Validator::validate($req->all(), [
            'first_name' => 'string|max:60',
            'last_name' => 'string|max:60',
            'phone' => 'phone',
            'lang' => 'in:fr,fon,yo,en',
        ], self::LABELS);

        if (isset($in['phone']) && Database::value('SELECT id FROM users WHERE phone = ? AND id <> ?', [$in['phone'], $req->userId()]) !== null) {
            throw new HttpException(409, 'PHONE_TAKEN', 'Ce numéro de téléphone est déjà utilisé par un autre compte.');
        }
        if ($in !== []) {
            $first = $in['first_name'] ?? $req->user['first_name'];
            $last = $in['last_name'] ?? $req->user['last_name'];
            Database::updateById('users', $req->userId(), $in + ['name' => trim($first . ' ' . $last)]);
        }
        Response::ok(Users::presentById($req->userId()));
    }

    public function logout(Request $req): never
    {
        Auth::revoke((int) $req->tokenId);
        Response::ok(['logged_out' => true]);
    }

    private function session(int $userId, ?string $device): never
    {
        if (Users::find($userId)['status'] !== 'active') {
            throw new HttpException(403, 'ACCOUNT_SUSPENDED', 'Votre compte est suspendu. Contactez l\'équipe Axi.');
        }
        Response::ok(['token' => Auth::issueToken($userId, $device), 'user' => Users::presentById($userId)]);
    }

    private static function waitMessage(int $seconds): string
    {
        if ($seconds < 120) {
            return "Patientez $seconds secondes avant de demander un nouveau code.";
        }
        return 'Trop de codes demandés. Réessayez dans ' . (int) ceil($seconds / 60) . ' minutes.';
    }
}
