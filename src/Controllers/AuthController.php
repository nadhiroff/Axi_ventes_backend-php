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
use Axi\Services\Notifier;
use Axi\Support\Users;

/**
 * Connexion par EMAIL et MOT DE PASSE uniquement ; l'adresse est confirmée par un code envoyé par email.
 * Le téléphone n'est qu'une information de contact (Mobile Money, WhatsApp), jamais un moyen de connexion.
 * Les partenaires se connectent sur l'espace web (/pro), pas dans l'application.
 */
final class AuthController
{
    public const LABELS = [
        'first_name' => 'Prénom',
        'last_name' => 'Nom',
        'email' => 'Email',
        'password' => 'Mot de passe',
        'phone' => 'Téléphone',
        'password_confirmation' => 'Confirmation du mot de passe',
        'avatar_visibility' => 'Qui voit ma photo',
        'code' => 'Code',
        'current_password' => 'Mot de passe actuel',
        'new_password' => 'Nouveau mot de passe',
        'purpose' => 'Type de code',
        'lang' => 'Langue',
    ];

    private const CODE_SENT = 'Si un compte correspond à cette adresse, un code vient d\'y être envoyé.';

    /**
     * Créer un compte : toujours CLIENT. Pour vendre, le client envoie ensuite une demande (pièce d'identité,
     * photos) validée par un agent Axi ; le partenaire est créé sur le web.
     */
    public function register(Request $req): never
    {
        $min = (int) Config::get('auth.password_min');
        $in = Validator::validate($req->all(), [
            'first_name' => 'required|string|max:60',
            'last_name' => 'required|string|max:60',
            'email' => 'required|email',
            'password' => "required|string|raw|minlen:$min|max:72",
            'password_confirmation' => 'string|raw|max:72',
            'phone' => 'phone',
            'lang' => 'in:fr,en,fon,yo',
        ], self::LABELS);
        if (isset($in['password_confirmation']) && !hash_equals($in['password'], $in['password_confirmation'])) {
            Validator::fail(['password_confirmation' => 'Les deux mots de passe ne sont pas identiques.'], self::LABELS);
        }

        if (Database::value('SELECT id FROM users WHERE email = ?', [$in['email']]) !== null) {
            throw new HttpException(409, 'EMAIL_TAKEN', 'Un compte existe déjà avec cette adresse email. Connectez-vous ou choisissez « Mot de passe oublié ».');
        }
        if (isset($in['phone']) && Database::value('SELECT id FROM users WHERE phone = ?', [$in['phone']]) !== null) {
            throw new HttpException(409, 'PHONE_TAKEN', 'Ce numéro de téléphone est déjà indiqué sur un autre compte.');
        }

        try {
            $id = Database::insert(
                'INSERT INTO users (first_name, last_name, name, email, password_hash, phone, role, lang, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$in['first_name'], $in['last_name'], $in['first_name'] . ' ' . $in['last_name'], $in['email'],
                    password_hash($in['password'], PASSWORD_DEFAULT), $in['phone'] ?? null, 'client', $in['lang'] ?? 'fr', Clock::now()],
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new HttpException(409, 'EMAIL_TAKEN', 'Un compte existe déjà avec cette adresse email ou ce numéro.');
            }
            throw $e;
        }
        Database::run('INSERT INTO user_settings (user_id) VALUES (?)', [$id]);

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
        $this->session($req, (int) $user['id'], $in['device'] ?? null, $user['email']);
    }

    public function resendCode(Request $req): never
    {
        $in = Validator::validate($req->all(), [
            'email' => 'required|email',
            'purpose' => 'required|in:verify_email,reset_password',
        ], self::LABELS);

        $user = Users::findByEmail($in['email']);
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

        $user = self::checkCredentials($in['email'], $in['password'], $req->ip(), $in['device'] ?? null, 'app');
        if ($user['role'] === 'partner') {
            self::recordLogin((int) $user['id'], $in['email'], $req->ip(), $in['device'] ?? null, 'app', false, 'partner_web_only');
            throw new HttpException(403, 'PARTNER_USE_WEB', 'Les partenaires (agents Axi, ANaGeM, annonceurs, IMF) se connectent sur l\'espace web Axi Pro.');
        }
        if ($user['email_verified_at'] === null) {
            EmailCodes::issue($in['email'], 'verify_email', $user);
            throw new HttpException(403, 'EMAIL_NOT_VERIFIED', 'Confirmez d\'abord votre adresse email : un code vient de vous être envoyé.');
        }
        $this->session($req, (int) $user['id'], $in['device'] ?? null, $in['email']);
    }

    /**
     * Vérifie email et mot de passe avec la limite de 5 échecs par 15 minutes (par email et par IP).
     * Utilisé par l'application et par l'espace web partenaire.
     */
    public static function checkCredentials(string $email, string $password, string $ip, ?string $device, string $channel): array
    {
        $window = (int) Config::get('auth.login_window_seconds');
        $max = (int) Config::get('auth.login_max_failures');
        $failures = Database::one(
            'SELECT SUM(email = ?) AS by_email, SUM(ip = ?) AS by_ip FROM login_events WHERE success = 0 AND created_at > ? AND (email = ? OR ip = ?)',
            [$email, $ip, Clock::now(-$window), $email, $ip],
        );
        // En local, la machine elle-même (tests automatiques) n'est pas limitée par adresse IP
        $ipLimited = !(Config::isLocal() && in_array($ip, ['127.0.0.1', '::1'], true));
        if ((int) $failures['by_email'] >= $max || ($ipLimited && (int) $failures['by_ip'] >= $max)) {
            throw new HttpException(429, 'TOO_MANY_REQUESTS', 'Trop de tentatives de connexion. Réessayez dans ' . (int) ceil($window / 60) . ' minutes.', ['retry_after' => $window]);
        }

        $user = Users::findByEmail($email);
        // Même durée de calcul que l'adresse existe ou non
        $hash = $user['password_hash'] ?? '$2y$10$AxiTimingDummySaltValur672mAp6JEeTYYXi3Bxnq2vkKnkeYvC';
        if (!password_verify($password, $hash) || $user === null) {
            self::recordLogin($user === null ? null : (int) $user['id'], $email, $ip, $device, $channel, false, 'bad_credentials');
            throw new HttpException(401, 'INVALID_CREDENTIALS', 'Email ou mot de passe incorrect.');
        }
        if ($user['status'] !== 'active') {
            self::recordLogin((int) $user['id'], $email, $ip, $device, $channel, false, 'suspended');
            throw new HttpException(403, 'ACCOUNT_SUSPENDED', 'Votre compte est suspendu. Contactez l\'équipe Axi.');
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        return $user;
    }

    public static function recordLogin(?int $userId, string $email, string $ip, ?string $device, string $channel, bool $success, ?string $reason = null): void
    {
        Database::run(
            'INSERT INTO login_events (user_id, email, ip, device, channel, success, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $email, $ip, $device === null ? null : mb_substr($device, 0, 120), $channel, (int) $success, $reason, Clock::now()],
        );
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
        Database::run(
            'UPDATE users SET password_hash = ?, email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?',
            [password_hash($in['password'], PASSWORD_DEFAULT), Clock::now(), $user['id']],
        );
        Auth::revokeAll((int) $user['id']);
        Notifier::send((int) $user['id'], 'account', 'Mot de passe modifié', 'Le mot de passe de votre compte Axi vient d\'être changé. Si ce n\'est pas vous, contactez l\'équipe Axi.', [], [], true);
        if ($user['role'] === 'partner') {
            Response::ok(['message' => 'Mot de passe modifié. Connectez-vous sur l\'espace web Axi Pro.', 'token' => null, 'user' => Users::presentById((int) $user['id'])]);
        }
        $this->session($req, (int) $user['id'], $in['device'] ?? null, $in['email']);
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
        Auth::revokeAll($req->userId(), $req->tokenId);
        Notifier::send($req->userId(), 'account', 'Mot de passe modifié', 'Le mot de passe de votre compte Axi vient d\'être changé. Les autres appareils ont été déconnectés.', [], [], true);
        Response::ok(['changed' => true]);
    }

    public function me(Request $req): never
    {
        Response::ok(Users::present($req->user));
    }

    public function updateMe(Request $req): never
    {
        $body = $req->all();
        $in = Validator::validate($body, [
            'first_name' => 'string|max:60',
            'last_name' => 'string|max:60',
            'phone' => 'phone',
            'lang' => 'in:fr,en,fon,yo',
            'avatar_visibility' => 'in:everyone,contacts,nobody',
        ], self::LABELS);

        if (isset($in['phone']) && Database::value('SELECT id FROM users WHERE phone = ? AND id <> ?', [$in['phone'], $req->userId()]) !== null) {
            throw new HttpException(409, 'PHONE_TAKEN', 'Ce numéro de téléphone est déjà indiqué sur un autre compte.');
        }
        if (array_key_exists('phone', $body) && ($body['phone'] === null || $body['phone'] === '')) {
            $in['phone'] = null;
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

    /** Déconnexion de tous les appareils (y compris celui-ci) */
    public function logoutAll(Request $req): never
    {
        Auth::revokeAll($req->userId());
        Response::ok(['logged_out' => true]);
    }

    private function session(Request $req, int $userId, ?string $device, string $email): never
    {
        $user = Users::find($userId);
        if ($user['status'] !== 'active') {
            throw new HttpException(403, 'ACCOUNT_SUSPENDED', 'Votre compte est suspendu. Contactez l\'équipe Axi.');
        }
        if ($user['role'] === 'partner') {
            throw new HttpException(403, 'PARTNER_USE_WEB', 'Les partenaires se connectent sur l\'espace web Axi Pro.');
        }
        self::recordLogin($userId, $email, $req->ip(), $device, 'app', true);
        Response::ok(['token' => Auth::issueToken($userId, $device, $req->ip()), 'user' => Users::present($user)]);
    }

    private static function waitMessage(int $seconds): string
    {
        if ($seconds < 120) {
            return "Patientez $seconds secondes avant de demander un nouveau code.";
        }
        return 'Trop de codes demandés. Réessayez dans ' . (int) ceil($seconds / 60) . ' minutes.';
    }
}
