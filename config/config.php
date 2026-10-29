<?php
declare(strict_types=1);

// Valeurs par défaut, sans aucun secret. Les secrets et réglages propres à chaque machine
// (base, SMTP, clés) sont dans config/config.local.php : jamais versionné, jamais servi par le serveur web.
// Les réglages métier ci-dessous sont des hypothèses de la note de cadrage, à confirmer sur le terrain.
defined('BASE_PATH') || exit;

$config = [
    'env' => 'production',
    'app_key' => '',
    // URL publique de l'API, ex. https://api.axi.bj (vide = déduite de la requête)
    'public_url' => '',
    'timezone' => 'Africa/Porto-Novo',
    'city' => ['id' => 'cotonou', 'name' => 'Cotonou'],
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
        'from_name' => 'Axi',
    ],
    // Prestataire Mobile Money : « fake » (APP_ENV=local seulement) ; le vrai prestataire viendra plus tard
    'payment_provider' => '',
    // Origines web autorisées (Expo web, Axi Pro), ex. ['https://pro.axi.bj']
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
    'escrow' => [
        // Remise au coursier sans confirmation du client : argent libéré après N jours
        'auto_release_days' => 7,
        // Commande jamais payée : annulée après N heures
        'unpaid_expiry_hours' => 24,
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

$local = __DIR__ . '/config.local.php';
if (!is_file($local)) {
    throw new RuntimeException('config/config.local.php manquant : copiez config/config.local.php.example et complétez-le');
}
return array_replace_recursive($config, require $local);
