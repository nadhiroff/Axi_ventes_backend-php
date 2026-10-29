<?php
declare(strict_types=1);

namespace Axi\Web;

use Axi\Core\Config;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Url;

/**
 * Session de l'espace web partenaire : cookie HttpOnly + SameSite=Strict (+ Secure en HTTPS),
 * identifiant régénéré à la connexion, expiration après inactivité, jeton CSRF sur chaque formulaire.
 * Seuls les comptes « partner » d'une organisation active y ont accès.
 */
final class WebAuth
{
    public const MENUS = [
        // [chemin, libellé, sous-types autorisés]
        ['/pro', 'Tableau de bord', ['axi', 'anagem', 'advertiser', 'mfi']],
        ['/pro/commercants', 'Commerçants', ['axi']],
        ['/pro/verifications', 'Vérifications sur place', ['axi']],
        ['/pro/clients', 'Clients', ['axi']],
        ['/pro/commandes', 'Commandes', ['axi']],
        ['/pro/litiges', 'Litiges', ['axi']],
        ['/pro/paiements', 'Paiements et séquestre', ['axi']],
        ['/pro/versements', 'Versements', ['axi']],
        ['/pro/allees', 'Sites et allées', ['axi']],
        ['/pro/recensement', 'Recensement des étals', ['axi', 'anagem']],
        ['/pro/activite', 'Suivi d\'activité', ['axi', 'anagem']],
        ['/pro/campagnes', 'Campagnes', ['axi', 'advertiser']],
        ['/pro/credits', 'Crédits', ['axi', 'mfi']],
        ['/pro/prix', 'Prix médians', ['axi', 'anagem', 'advertiser', 'mfi']],
        ['/pro/ventes', 'Ventes et paiements', ['axi', 'anagem', 'advertiser', 'mfi']],
        ['/pro/partenaires', 'Comptes partenaires', ['axi']],
        ['/pro/reglages', 'Réglages', ['axi']],
        ['/pro/journal', 'Journal', ['axi']],
    ];

    private static ?array $user = null;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        session_name('AXIPRO');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
        ini_set('session.use_strict_mode', '1');
        session_start();
        $ttl = 60 * (int) Config::get('auth.web_session_minutes', 120);
        if (isset($_SESSION['last']) && time() - (int) $_SESSION['last'] > $ttl) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['last'] = time();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function login(array $user): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_regenerate_id(true);
    }

    /** Partenaire connecté (avec son organisation), ou null */
    public static function user(): ?array
    {
        self::start();
        if (self::$user === null && isset($_SESSION['uid'])) {
            self::$user = Database::one(
                "SELECT u.*, o.type AS partner_type, o.name AS org_name FROM users u JOIN organizations o ON o.id = u.organization_id
                 WHERE u.id = ? AND u.role = 'partner' AND u.status = 'active' AND o.status = 'active'",
                [$_SESSION['uid']],
            );
            if (self::$user === null) {
                unset($_SESSION['uid']);
            }
        }
        return self::$user;
    }

    /** Exige un partenaire connecté de l'un de ces sous-types ; sinon page de connexion ou 403 */
    public static function require(array $types = ['axi', 'anagem', 'advertiser', 'mfi']): array
    {
        $user = self::user();
        if ($user === null) {
            header('Location: ' . Url::to('/pro/connexion'));
            exit;
        }
        if (!in_array($user['partner_type'], $types, true)) {
            throw new HttpException(403, 'FORBIDDEN', 'Cette page n\'est pas ouverte à votre type de compte partenaire.');
        }
        return $user;
    }

    public static function csrf(): string
    {
        self::start();
        return (string) $_SESSION['csrf'];
    }

    /** Toute requête POST doit porter le jeton CSRF de la session */
    public static function checkCsrf(): void
    {
        self::start();
        if (!hash_equals((string) $_SESSION['csrf'], (string) ($_POST['_csrf'] ?? ''))) {
            throw new HttpException(419, 'CSRF', 'La page a expiré. Rechargez-la puis recommencez.');
        }
    }

    public static function flash(?string $message = null, string $type = 'ok'): ?array
    {
        self::start();
        if ($message !== null) {
            $_SESSION['flash'] = ['message' => $message, 'type' => $type];
            return null;
        }
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $flash;
    }
}
