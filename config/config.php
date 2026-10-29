<?php
declare(strict_types=1);

// Valeurs par défaut. Les valeurs propres à chaque machine vont dans config.local.php (non versionné).
$config = [
    'env' => 'production',
    'app_secret' => '',
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
    'cors_origin' => '*',
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}

return $config;
