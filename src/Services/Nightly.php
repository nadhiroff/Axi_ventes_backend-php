<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\Database;
use Axi\Services\Payment\Payments;

/** Tâches planifiées : prix médians d'Axi Pro, libération automatique, ménage. */
final class Nightly
{
    /** @return array<string, int> résumé de ce qui a été fait */
    public static function run(): array
    {
        return [
            'median_prices' => self::medianPrices(),
            'released_orders' => self::autoRelease(),
            'cancelled_unpaid' => self::cancelUnpaid(),
            'deleted_codes' => Database::run('DELETE FROM email_codes WHERE created_at < ?', [Clock::now(-2 * 86400)])->rowCount(),
            'deleted_login_failures' => Database::run('DELETE FROM login_failures WHERE created_at < ?', [Clock::now(-86400)])->rowCount(),
        ];
    }

    /** MySQL n'a pas de MEDIAN : pour chaque (référence, site), un prix par étal, trié, valeur du milieu */
    private static function medianPrices(): int
    {
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
        return count($groups);
    }

    /** Commande remise au coursier sans confirmation du client après N jours : réception présumée, argent libéré */
    private static function autoRelease(): int
    {
        $ids = array_column(Database::all(
            "SELECT o.id FROM orders o WHERE o.status = 'on_the_way'
               AND (SELECT MAX(os.picked_up_at) FROM order_stalls os WHERE os.order_id = o.id) < ?",
            [Clock::now(-86400 * (int) Config::get('escrow.auto_release_days', 7))],
        ), 'id');
        foreach ($ids as $id) {
            Payments::release((int) $id);
        }
        return count($ids);
    }

    /** Commandes jamais payées : annulées après 24 h (rien n'a été débité) */
    private static function cancelUnpaid(): int
    {
        $ids = array_column(Database::all(
            "SELECT id FROM orders WHERE status = 'awaiting_payment' AND created_at < ?",
            [Clock::now(-3600 * (int) Config::get('escrow.unpaid_expiry_hours', 24))],
        ), 'id');
        foreach ($ids as $id) {
            Payments::refresh((int) $id);
            Database::transaction(static function () use ($id): void {
                $n = Database::run("UPDATE orders SET status = 'cancelled' WHERE id = ? AND status = 'awaiting_payment'", [$id])->rowCount();
                if ($n > 0) {
                    Database::run("UPDATE order_stalls SET status = 'cancelled' WHERE order_id = ?", [$id]);
                    Database::run("UPDATE payments SET status = 'failed' WHERE order_id = ? AND status = 'pending'", [$id]);
                }
            });
        }
        return count($ids);
    }
}
