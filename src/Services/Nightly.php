<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Services\Payment\Escrow;

/**
 * Tâches planifiées (cron/run.php ou /v1/internal/cron?key=) : vérification des paiements en attente,
 * relance de paiement, annulation des commandes jamais payées, libération automatique, rappels
 * (commande en cours, abonnement à renouveler), prix médians d'Axi Pro, ménage.
 */
final class Nightly
{
    /** @return array<string, int> résumé de ce qui a été fait */
    public static function run(): array
    {
        return [
            'checked_payments' => self::checkPending(),
            'payment_reminders' => self::paymentReminders(),
            'cancelled_unpaid' => self::cancelUnpaid(),
            'released_orders' => self::autoRelease(),
            'subscription_reminders' => self::subscriptionReminders(),
            'median_prices' => self::medianPrices(),
            'deleted_codes' => Database::run('DELETE FROM email_codes WHERE created_at < ?', [Clock::now(-2 * 86400)])->rowCount(),
        ];
    }

    /** Paiements en attente : statut redemandé à la passerelle (si un webhook s'est perdu) */
    private static function checkPending(): int
    {
        $ids = array_column(Database::all("SELECT id FROM payments WHERE status = 'pending' AND payment_url IS NOT NULL AND created_at > ? LIMIT 200", [Clock::now(-48 * 3600)]), 'id');
        foreach ($ids as $id) {
            Escrow::refresh((int) $id);
        }
        return count($ids);
    }

    /** Relance par email (et notification) d'une commande non payée, une seule fois */
    private static function paymentReminders(): int
    {
        $rows = Database::all(
            "SELECT id, client_id, total FROM orders WHERE status = 'awaiting_payment' AND reminder_sent_at IS NULL AND created_at < ? AND created_at > ?",
            [Clock::now(-60 * Settings::int('payment_reminder_minutes')), Clock::now(-3600 * Settings::int('unpaid_expiry_hours'))],
        );
        foreach ($rows as $o) {
            Database::run('UPDATE orders SET reminder_sent_at = ? WHERE id = ?', [Clock::now(), $o['id']]);
            Notifier::send((int) $o['client_id'], 'reminder', 'Votre commande attend son paiement', 'La commande cmd_' . $o['id'] . ' (' . Escrow::fcfa((int) $o['total']) . ') n\'est pas encore payée. Ouvrez « Mes commandes » pour la payer ou l\'annuler.', ['order_id' => 'cmd_' . $o['id']]);
        }
        return count($rows);
    }

    /** Commandes jamais payées : annulées après le délai configuré (rien n'a été débité) */
    private static function cancelUnpaid(): int
    {
        $rows = Database::all("SELECT o.id, p.id AS payment_id FROM orders o JOIN payments p ON p.order_id = o.id WHERE o.status = 'awaiting_payment' AND o.created_at < ?", [Clock::now(-3600 * Settings::int('unpaid_expiry_hours'))]);
        $n = 0;
        foreach ($rows as $row) {
            // Dernière vérification : un paiement validé entre-temps n'est jamais annulé
            Escrow::refresh((int) $row['payment_id']);
            $done = Database::transaction(static function () use ($row): bool {
                if (Database::run("UPDATE orders SET status = 'cancelled', cancelled_at = ? WHERE id = ? AND status = 'awaiting_payment'", [Clock::now(), $row['id']])->rowCount() === 0) {
                    return false;
                }
                Database::run("UPDATE order_stalls SET status = 'cancelled' WHERE order_id = ?", [$row['id']]);
                Database::run("UPDATE payments SET status = 'expired' WHERE id = ? AND status = 'pending'", [$row['payment_id']]);
                Escrow::log('order_expired', ['payment' => $row['payment_id'], 'order' => $row['id']], 'system', null, 'pending', 'expired');
                return true;
            });
            $n += (int) $done;
        }
        return $n;
    }

    /** Remise au coursier sans confirmation ni litige après N jours : réception présumée, argent libéré */
    private static function autoRelease(): int
    {
        $ids = array_column(Database::all(
            "SELECT o.id FROM orders o WHERE o.status = 'on_the_way'
               AND (SELECT MAX(os.picked_up_at) FROM order_stalls os WHERE os.order_id = o.id) < ?",
            [Clock::now(-86400 * Settings::int('auto_release_days'))],
        ), 'id');
        foreach ($ids as $id) {
            Escrow::confirmReceipt((int) $id, 'system', null);
        }
        return count($ids);
    }

    /** Abonnement qui expire dans 3 jours : un rappel par jour au commerçant */
    private static function subscriptionReminders(): int
    {
        $rows = Database::all(
            "SELECT s.stall_id, MAX(s.ends_on) AS ends_on, st.user_id FROM subscriptions s JOIN stalls st ON st.id = s.stall_id
             WHERE s.status = 'active' AND s.paid_by = 'merchant' GROUP BY s.stall_id, st.user_id HAVING ends_on BETWEEN ? AND ?",
            [Clock::localDate(), date('Y-m-d', strtotime(Clock::localDate() . ' +3 days'))],
        );
        $n = 0;
        foreach ($rows as $r) {
            $already = Database::value("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'reminder' AND title = 'Abonnement à renouveler' AND created_at > ?", [$r['user_id'], Clock::now(-20 * 3600)]);
            if ($already === null) {
                Notifier::send((int) $r['user_id'], 'reminder', 'Abonnement à renouveler', 'Votre abonnement Axi se termine le ' . date('d/m/Y', strtotime($r['ends_on'])) . '. Renouvelez-le dans « Mon argent » pour garder votre étal en ligne.');
                $n++;
            }
        }
        return $n;
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
}
