<?php
declare(strict_types=1);

namespace Axi\Models;

use Axi\Core\Clock;
use Axi\Core\Database;

final class Payment
{
    public static function create(string $purpose, int $userId, int $amount, string $provider, ?int $orderId, ?int $stallId): int
    {
        return Database::insert(
            'INSERT INTO payments (purpose, order_id, stall_id, user_id, provider, amount, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$purpose, $orderId, $stallId, $userId, $provider, $amount, Clock::now()]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM payments WHERE id = ?', [$id]);
    }

    public static function findByRef(string $provider, string $ref): ?array
    {
        return Database::one('SELECT * FROM payments WHERE provider = ? AND provider_ref = ?', [$provider, $ref]);
    }

    public static function setRef(int $id, string $ref): void
    {
        Database::run('UPDATE payments SET provider_ref = ? WHERE id = ?', [$ref, $id]);
    }

    /** Change le statut seulement depuis $from. Renvoie false si un autre processus l'a déjà fait. */
    public static function transition(int $id, string $from, string $to): bool
    {
        $paidAt = in_array($to, ['held', 'succeeded'], true) ? Clock::now() : null;
        return Database::run(
            'UPDATE payments SET status = ?, paid_at = COALESCE(?, paid_at) WHERE id = ? AND status = ?',
            [$to, $paidAt, $id, $from]
        )->rowCount() === 1;
    }

    public static function present(array $p): array
    {
        return [
            'id' => (int) $p['id'],
            'purpose' => $p['purpose'],
            'provider' => $p['provider'],
            'amount' => (int) $p['amount'],
            'status' => $p['status'],
            'paid_at' => Clock::iso($p['paid_at']),
        ];
    }
}
