<?php
declare(strict_types=1);

// Valeurs par défaut, sans aucun secret. Les secrets et réglages propres à chaque machine
// (base, SMTP, clés des passerelles) sont dans config/config.local.php : jamais versionné, jamais servi.
// Les montants métier (frais, abonnements, commission, délais) sont en BASE, table settings,
// modifiables par un agent Axi dans l'espace web : voir src/Services/Settings.php.
defined('BASE_PATH') || exit;

$config = [
    'env' => 'production',
    // Clé des codes email et des liens signés (64 caractères hexadécimaux)
    'app_key' => '',
    // URL publique de l'API, ex. https://api.axi.bj (vide = déduite de la requête)
    'public_url' => '',
    'timezone' => 'Africa/Porto-Novo',
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'axi',
        'user' => '',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'mail' => [
        'host' => '',
        'port' => 587,
        'encryption' => 'tls',
        'username' => '',
        'password' => '',
        'from_address' => '',
        'from_name' => 'Axi',
    ],
    'payments' => [
        // Passerelle active : fedapay | kkiapay (« fake » : tests automatiques, env local seulement)
        'provider' => 'fedapay',
        'fedapay' => ['env' => 'sandbox', 'public_key' => '', 'secret_key' => '', 'webhook_secret' => ''],
        'kkiapay' => ['sandbox' => true, 'public_key' => '', 'private_key' => '', 'secret' => ''],
    ],
    // Origines web autorisées (Expo web), ex. ['https://app.axi.bj']
    'cors_origins' => [],
    // Clé de l'URL de la tâche planifiée (/v1/internal/cron?key=…), pour les hébergements sans SSH
    'cron_key' => '',
    'auth' => [
        'token_ttl_days' => 90,
        'password_min' => 8,
        'code_ttl_seconds' => 900,
        'code_max_attempts' => 5,
        'code_resend_seconds' => 60,
        'code_max_per_hour' => 5,
        // Échecs de connexion tolérés sur 15 minutes, par email et par adresse IP
        'login_max_failures' => 5,
        'login_window_seconds' => 900,
        // Espace web partenaire : session fermée après N minutes d'inactivité
        'web_session_minutes' => 120,
    ],
];

$local = __DIR__ . '/config.local.php';
if (!is_file($local)) {
    throw new RuntimeException('config/config.local.php manquant : copiez config/config.local.php.example et complétez-le');
}
return array_replace_recursive($config, require $local);
