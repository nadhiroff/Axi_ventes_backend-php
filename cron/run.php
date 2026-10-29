<?php
declare(strict_types=1);

// Tâche planifiée, une fois par nuit (crontab : 30 2 * * * php /chemin/cron/run.php) :
// prix médians du jour pour Axi Pro, puis ménage des codes et des échecs de connexion.

use Axi\Core\Clock;
use Axi\Core\Database;

require dirname(__DIR__) . '/src/bootstrap.php';

// MySQL n'a pas de fonction MEDIAN : pour chaque (référence, site), un prix par étal, trié, valeur du milieu
$rows = Database::all(
    "SELECT p.product_ref_id, s.site_id, s.id AS stall_id, MIN(p.price_amount) AS price
     FROM products p JOIN stalls s ON s.id = p.stall_id
     WHERE p.product_ref_id IS NOT NULL AND p.price_amount IS NOT NULL AND p.status = 'active' AND p.in_stock = 1 AND s.status = 'active'
     GROUP BY p.product_ref_id, s.site_id, s.id",
);
$groups = [];
foreach ($rows as $row) {
    $groups[$row['product_ref_id'] . '|' . $row['site_id']][] = (int) $row['price'];
}
$day = Clock::localDate();
foreach ($groups as $key => $prices) {
    [$refId, $siteId] = explode('|', $key);
    sort($prices);
    $n = count($prices);
    $median = $n % 2 === 1 ? $prices[intdiv($n, 2)] : intdiv($prices[$n / 2 - 1] + $prices[$n / 2], 2);
    Database::run(
        'INSERT INTO price_stats_daily (product_ref_id, site_id, day, median_price, sample_size) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE median_price = VALUES(median_price), sample_size = VALUES(sample_size)',
        [$refId, $siteId, $day, $median, $n],
    );
}

Database::run('DELETE FROM email_codes WHERE created_at < ?', [Clock::now(-2 * 86400)]);
Database::run('DELETE FROM login_failures WHERE created_at < ?', [Clock::now(-86400)]);

echo gmdate('c') . ' : ' . count($groups) . " prix médian(s) calculé(s).\n";
