<?php
declare(strict_types=1);

use Axi\Core\Env;

// Les secrets et réglages propres à chaque machine viennent du fichier .env (jamais versionné).
// Les réglages métier ci-dessous sont des hypothèses de la note de cadrage, à confirmer sur le terrain.
return [
    'env' => Env::get('APP_ENV', 'production'),
    'app_key' => Env::get('APP_KEY', ''),
    // URL publique de l'API, ex. https://api.axi.bj (vide = déduite de la requête)
    'public_url' => Env::get('APP_URL', ''),
    'timezone' => 'Africa/Porto-Novo',
    'city' => ['id' => 'cotonou', 'name' => 'Cotonou'],
    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => (int) Env::get('DB_PORT', '3306'),
        'name' => Env::get('DB_NAME', 'axi'),
        'user' => Env::get('DB_USER', ''),
        'pass' => Env::get('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],
    'mail' => [
        'host' => Env::get('MAIL_HOST', ''),
        'port' => (int) Env::get('MAIL_PORT', '587'),
        'encryption' => Env::get('MAIL_ENCRYPTION', 'tls'),
        'username' => Env::get('MAIL_USERNAME', ''),
        'password' => Env::get('MAIL_PASSWORD', ''),
        'from_name' => Env::get('MAIL_FROM_NAME', 'Axi'),
    ],
    'cors_origins' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('CORS_ORIGINS', ''))))),
    'auth' => [
        'token_ttl_days' => (int) Env::get('TOKEN_TTL_DAYS', '90'),
        'password_min' => 8,
        'code_ttl_seconds' => 900,
        'code_max_attempts' => 5,
        'code_resend_seconds' => 60,
        'code_max_per_hour' => 5,
        // Échecs de connexion tolérés sur 15 minutes, par email et par adresse IP
        'login_max_failures' => 5,
        'login_window_seconds' => 900,
    ],
    'pricing' => [
        // « Regroupement de N étals » : N × ce montant, 0 pour un seul étal
        'grouping_fee_per_stall' => 300,
        'reception' => [
            'delivery' => ['fee' => 1500, 'label' => 'Livraison'],
            'bus' => ['fee' => 2500, 'label' => 'Expédition car'],
            'axi_point' => ['fee' => 500, 'label' => 'Retrait au point Axi'],
        ],
        'subscription' => ['retail' => 2000, 'wholesale' => 5000],
    ],
    'customers' => [
        'loyal_min_orders' => 3,
        'follow_up_days' => 30,
        'new_days' => 7,
    ],
    'pro' => [
        // Anonymat : aucun chiffre publié pour un groupe de moins de N étals
        'min_group_size' => 5,
        'min_campaign_budget' => 50000,
    ],
    // Produit « nouveau » pendant N jours (pastille has_new_products)
    'new_product_days' => 3,
    // Mention « Service en ligne des marchés » sur l'accueil, si l'accord ANaGeM est signé
    'anagem_partnership' => false,
];
