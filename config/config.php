<?php
declare(strict_types=1);

// Valeurs par défaut. Les valeurs propres à chaque machine vont dans config.local.php (non versionné).
// Pas de fichier .env : tout est en PHP, compatible avec tout hébergement mutualisé.
$config = [
    'env' => 'production',
    'app_secret' => '',
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
    'auth' => [
        'token_ttl_days' => 30,
        'otp_ttl_seconds' => 300,
        'otp_resend_seconds' => 60,
        'otp_max_attempts' => 5,
        'otp_max_per_hour' => 5,
    ],
    'sms' => [
        'driver' => 'log',
    ],
    // Hypothèses de la note de cadrage, à confirmer sur le terrain
    'pricing' => [
        'grouping_fee_per_extra_stall' => 250,
        'commission_rate' => 0.0,
        'subscription' => ['retail' => 2000, 'wholesale' => 5000],
        'subscription_days' => 30,
        'min_payout' => 500,
    ],
    'escrow' => [
        // Libération automatique si le client ne confirme pas la réception (cron/run.php)
        'auto_release_days' => 7,
        // Annulation des commandes jamais payées
        'unpaid_expiry_hours' => 24,
    ],
    'payments' => [
        // sandbox ou live
        'mode' => 'sandbox',
        'fedapay' => ['secret_key' => ''],
        'kkiapay' => ['public_key' => '', 'private_key' => '', 'secret' => ''],
    ],
    'customers' => [
        'loyal_min_orders' => 3,
        'inactive_days' => 30,
    ],
    'pro' => [
        // Anonymat : aucun chiffre publié pour un groupe de moins de N étals
        'min_group_size' => 3,
    ],
    // Mention « service en ligne des marchés » sur l'accueil, si l'accord ANaGeM est signé
    'anagem_partnership' => false,
    'cors_origin' => '*',
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}

return $config;
