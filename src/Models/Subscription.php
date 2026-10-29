<?php
declare(strict_types=1);

namespace Axi\Models;

use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\Database;

final class Subscription
{
    /** Dernière période payée (les renouvellements s'enchaînent sans trou), ou null */
    public static function latest(int $stallId): ?array
    {
        return Database::one('SELECT * FROM subscriptions WHERE stall_id = ? ORDER BY ends_at DESC LIMIT 1', [$stallId]);
    }

    /** Prolonge d'une période à partir de la fin de l'abonnement en cours (ou de maintenant) */
    public static function extend(int $stallId, string $plan, int $amount, ?int $paymentId): void
    {
        $lastEnd = Database::value('SELECT MAX(ends_at) FROM subscriptions WHERE stall_id = ?', [$stallId]);
        $start = $lastEnd !== null && $lastEnd > Clock::now() ? $lastEnd : Clock::now();
        Database::insert(
            'INSERT INTO subscriptions (stall_id, plan, amount, paid_by, payment_id, starts_at, ends_at, created_at) VALUES (?, ?, ?, \'merchant\', ?, ?, ?, ?)',
            [$stallId, $plan, $amount, $paymentId, $start, Clock::addDays($start, (int) Config::get('pricing.subscription_days')), Clock::now()]
        );
    }

    public static function priceFor(string $merchantType): int
    {
        return (int) Config::get('pricing.subscription.' . $merchantType);
    }

    public static function present(?array $s, string $merchantType): array
    {
        $active = $s !== null && $s['ends_at'] > Clock::now();
        return [
            'active' => $active,
            'plan' => $s['plan'] ?? null,
            'amount' => $s === null ? null : (int) $s['amount'],
            'paid_by' => $s['paid_by'] ?? null,
            'data_included' => $active && (bool) $s['data_included'],
            'starts_at' => Clock::iso($s['starts_at'] ?? null),
            'ends_at' => Clock::iso($s['ends_at'] ?? null),
            'days_left' => $active ? Clock::daysUntil($s['ends_at']) : 0,
            'renew_price' => self::priceFor($merchantType),
        ];
    }
}
