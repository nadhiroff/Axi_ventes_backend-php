<?php
declare(strict_types=1);

// Données de démonstration (noms fictifs), seulement en APP_ENV=local.
// Usage : php database/seed.php     Mot de passe de tous les comptes : axi-demo-2026
// Les adresses @example.com ne reçoivent jamais d'email (codes dans storage/logs/codes.log).

use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\Database;

require dirname(__DIR__) . '/src/bootstrap.php';

if (!Config::isLocal()) {
    exit("Refusé : seed réservé à APP_ENV=local.\n");
}
if (Database::value('SELECT id FROM users WHERE email = ?', ['admin@example.com']) !== null) {
    exit("Données de démonstration déjà présentes.\n");
}

const PASSWORD = 'axi-demo-2026';
$hash = password_hash(PASSWORD, PASSWORD_DEFAULT);
$now = Clock::now();
$ago = static fn (int $days, int $hours = 0): string => Clock::now(-$days * 86400 - $hours * 3600);

$org = static fn (string $name, string $type): int => Database::insert('INSERT INTO organizations (name, type) VALUES (?, ?)', [$name, $type]);
$anagem = $org('ANaGeM', 'anagem');
$mfi = $org('Crédit Marché (IMF de démonstration)', 'mfi');
$advertiser = $org('Savonnerie du Golfe (annonceur de démonstration)', 'advertiser');

$user = static function (string $first, string $last, string $email, string $phone, string $role = 'client', ?int $orgId = null) use ($hash, $now): int {
    return Database::insert(
        'INSERT INTO users (phone, first_name, last_name, name, email, password_hash, email_verified_at, role, organization_id, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$phone, $first, $last, "$first $last", $email, $hash, $now, $role, $orgId, $now],
    );
};

$admin = $user('Équipe', 'Axi', 'admin@example.com', '+2290190000000', 'admin');
$agent = $user('Rodrigue', 'Agbo', 'agent@example.com', '+2290195000001', 'agent');
$user('Service', 'des marchés', 'anagem@example.com', '+2290194000001', 'pro', $anagem);
$user('Conseiller', 'Crédit', 'imf@example.com', '+2290194000002', 'pro', $mfi);
$user('Marque', 'Partenaire', 'annonceur@example.com', '+2290194000003', 'pro', $advertiser);
$sena = $user('Séna', 'Hounsou', 'sena@example.com', '+2290196000010');
$alassane = $user('Alassane', 'Tanko', 'alassane@example.com', '+2290196000011');
$user('Divine', 'Akakpo', 'divine@example.com', '+2290196000012'); // cliente qui attend la visite d'un agent

// Étals : [prénom, nom, étal, slogan, site, allée, rangée, stand, type, place, déménagé il y a N jours]
$stallsData = [
    ['Afi', 'Houngbo', 'Chez Maman Afi', 'Pagnes wax, bazin et tissus de cérémonie', 'yoyo', 'tissus', 'Allée B', '214', 'retail', 1, 20],
    ['Ayaba', 'Dossou', 'Bijoux Ayaba', 'Perles, parures et sacs tressés', 'yoyo', 'bijoux', 'Allée F', '38', 'retail', 1, null],
    ['Kossi', 'Adjovi', 'Ets Kossi et Fils', 'Riz, maïs et gari en gros', 'daho', 'vivriers', 'Rangée 4', '12', 'wholesale', 1, 60],
    ['Bintou', 'Sanni', 'Céréales Bintou', 'Riz et haricots, détail et gros', 'daho', 'vivriers', 'Rangée 2', '7', 'wholesale', 1, 45],
    ['Marcel', 'Ahouansou', 'Grains du Zou', 'Maïs, riz, soja', 'daho', 'vivriers', 'Rangée 5', '31', 'wholesale', 1, 50],
    ['Rachidatou', 'Bello', 'Vivres Rachida', 'Riz importé et local', 'daho', 'vivriers', null, null, 'retail', 0, null],
    ['Félicité', 'Gbaguidi', 'Félicité Gros', 'Riz, sucre, huile', 'daho', 'vivriers', 'Rangée 1', '3', 'wholesale', 1, 30],
    ['Nadège', 'Tossou', 'Tissus Nadège', 'Wax et bazin', 'yoyo', 'tissus', 'Allée C', '88', 'retail', 1, 25],
];
$riz = Database::insert('INSERT INTO product_refs (aisle_id, name) VALUES (?, ?)', ['vivriers', 'Riz, sac de 25 kg']);
$wax = Database::insert('INSERT INTO product_refs (aisle_id, name) VALUES (?, ?)', ['tissus', 'Wax hollandais, 6 yards']);

$stallIds = [];
foreach ($stallsData as $i => [$first, $last, $name, $tagline, $site, $aisle, $row, $stand, $type, $place, $moved]) {
    $phone = sprintf('+22901970000%02d', $i + 1);
    $email = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $first)) . '@example.com';
    $uid = $user($first, $last, $email, $phone, 'merchant');
    $stallIds[$name] = Database::insert(
        'INSERT INTO stalls (user_id, name, tagline, site_id, aisle_id, row_label, stand, has_physical_place, merchant_type, verified_at, verified_by,
           moved_at, phone, whatsapp, accepts_voice_messages, pay_on_delivery, delivery_note, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$uid, $name, $tagline, $site, $aisle, $row, $stand, $place, $type, $ago(40), $agent, $moved === null ? null : $ago($moved),
            $phone, $phone, 1, (int) ($type === 'retail'), $type === 'retail' ? 'Livraison Cotonou et Calavi' : 'Expédition par car vers le Nord', $ago(40), $ago(1)],
    );
    Database::run('INSERT INTO subscriptions (stall_id, plan, amount, paid_by, starts_on, ends_on, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
        $stallIds[$name], $type, Config::get("pricing.subscription.$type"), $site === 'yoyo' ? 'anagem' : 'merchant',
        gmdate('Y-m-d', time() - 10 * 86400), gmdate('Y-m-d', time() + 20 * 86400), $now,
    ]);
}

$product = static function (int $stallId, string $name, ?int $price, ?int $max = null, bool $today = false, bool $stock = true, ?int $ref = null, int $hoursAgo = 3) use ($ago, $agent): int {
    return Database::insert(
        'INSERT INTO products (stall_id, product_ref_id, name, price_amount, price_max, in_stock, is_today, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$stallId, $ref, $name, $price, $max, (int) $stock, (int) $today, $agent, $ago(0, $hoursAgo), $ago(0, $hoursAgo)],
    );
};
$afi = $stallIds['Chez Maman Afi'];
$p88 = $product($afi, 'Wax hollandais, 6 yards', 18000, null, true, true, $wax);
$product($afi, 'Bazin riche, 5 m', null, null, true);
$product($afi, 'Pagne tissé, la pièce', 12000, 15000, true);
$p93 = $product($afi, 'Lot revendeur, 12 pièces', 195000, null, true);
$product($afi, 'Kente, 2 pagnes', 25000, null, false, false, null, 90);
$product($stallIds['Tissus Nadège'], 'Wax hollandais, 6 yards', 17000, null, true, true, $wax);
$product($stallIds['Bijoux Ayaba'], 'Parure perles', 7500, null, true);
$product($stallIds['Bijoux Ayaba'], 'Sac tressé raphia', 9000);
foreach (['Ets Kossi et Fils' => 17500, 'Céréales Bintou' => 17000, 'Grains du Zou' => 18000, 'Vivres Rachida' => 17250, 'Félicité Gros' => 16500] as $stall => $price) {
    $product($stallIds[$stall], 'Sac de riz, 25 kg', $price, null, true, true, $riz);
}
$product($stallIds['Ets Kossi et Fils'], 'Maïs, sac de 100 kg', 28000, 32000, true);

// Commandes payées chez Maman Afi (une à préparer aujourd'hui, une ancienne livrée)
$order = static function (int $clientId, array $lines, string $status, string $stallStatus, string $createdAt, string $detail) use ($afi): void {
    $total = array_sum(array_map(static fn (array $l): int => $l[2] * $l[3], $lines));
    $orderId = Database::insert(
        'INSERT INTO orders (client_id, status, items_total, grouping_fee, reception_fee, total, reception_mode, reception_detail, payment_operator, payment_phone, paid_at, created_at)
         VALUES (?, ?, ?, 0, 1500, ?, \'delivery\', ?, \'mtn\', (SELECT phone FROM users WHERE id = ?), ?, ?)',
        [$clientId, $status, $total, $total + 1500, $detail, $clientId, $createdAt, $createdAt],
    );
    $osId = Database::insert('INSERT INTO order_stalls (order_id, stall_id, items_total, status, created_at) VALUES (?, ?, ?, ?, ?)', [$orderId, $afi, $total, $stallStatus, $createdAt]);
    foreach ($lines as [$productId, $name, $price, $qty]) {
        Database::run('INSERT INTO order_items (order_stall_id, product_id, name, unit_price, quantity, line_total) VALUES (?, ?, ?, ?, ?, ?)', [$osId, $productId, $name, $price, $qty, $price * $qty]);
    }
    Database::run('INSERT INTO payments (order_id, provider, operator, phone, amount, status, created_at) VALUES (?, \'fake\', \'mtn\', \'+2290196000010\', ?, ?, ?)', [$orderId, $total + 1500, $status === 'delivered' ? 'released' : 'held', $createdAt]);
};
$order($sena, [[$p88, 'Wax hollandais, 6 yards', 18000, 2]], 'paid', 'to_prepare', $ago(0, 2), 'Akpakpa, près de la pharmacie');
$order($alassane, [[$p93, 'Lot revendeur, 12 pièces', 195000, 1]], 'delivered', 'delivered', $ago(45), 'Parakou');

$client = static fn (string $name, string $phone, string $kind, int $orders, ?string $last, ?int $userId = null) => Database::run(
    'INSERT INTO merchant_clients (stall_id, user_id, display_name, phone, kind, orders_count, last_order_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
    [$afi, $userId, $name, $phone, $kind, $orders, $last, $ago(60)],
);
$client('Boutique Sena, Lomé', '+22890000011', 'reseller', 5, $ago(1));
$client('Madame Hounsou', '+2290196000010', 'individual', 1, $ago(0, 2), $sena);
$client('Alhaji Tanko, Kano', '+2348030000012', 'reseller', 4, $ago(45), $alassane);
$client('Famille Dossou, Paris', '+33600000013', 'individual', 3, $ago(10));

Database::run('INSERT INTO stall_follows (user_id, stall_id, created_at) VALUES (?, ?, ?), (?, ?, ?)', [$sena, $afi, $now, $sena, $stallIds['Bijoux Ayaba'], $now]);
Database::run('INSERT INTO agent_visit_requests (user_id, created_at) SELECT id, ? FROM users WHERE email = ?', [$now, 'divine@example.com']);

Database::run(
    'INSERT INTO campaigns (organization_id, created_by, audience_type, aisle_id, site_id, format, title, body, target_type, target_id, budget, status, reviewed_by, starts_at, impressions, clicks, created_at)
     VALUES (?, ?, \'clients\', \'cosmetiques\', \'yoyo\', \'sponsored_home\', ?, ?, \'aisle\', \'cosmetiques\', 150000, \'active\', ?, ?, 3120, 214, ?),
            (?, ?, \'merchants\', \'tissus\', \'yoyo\', \'supplier_offer\', ?, ?, NULL, NULL, 250000, \'active\', ?, ?, 0, 0, ?)',
    [$advertiser, $admin, 'Savons au karité : la promo de la semaine', 'Chez les étals cosmétiques de Tokpa Yôyô', $admin, $ago(5), $ago(6),
        $advertiser, $admin, '20 pièces de wax livrées à votre stand', 'Payables en 4 semaines avec le crédit stock de notre IMF partenaire, prélevé sur vos ventes Axi.', $admin, $ago(5), $ago(6)],
);

echo "Données de démonstration créées. Mot de passe de tous les comptes : " . PASSWORD . "\n";
echo "Comptes : admin@, agent@, anagem@, imf@, annonceur@, sena@, alassane@, divine@, afi@ (commerçante)… @example.com\n";
