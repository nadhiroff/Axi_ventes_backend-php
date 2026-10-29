<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\Url;
use Axi\Models\Customer;
use Axi\Models\Notification;

/**
 * Messages groupés d'un commerçant : nouvelle adresse, arrivage, relance.
 * Clients ayant l'application => notification (gratuit, zero-rating) ; autres => SMS en file d'attente.
 */
final class BroadcastService
{
    /**
     * $filters : filtres de Customer::where (type, segment, ids)
     * $includeFollowers : ajoute les clients Axi qui suivent l'étal (déménagement)
     */
    public static function send(array $stall, string $kind, string $message, ?string $audioPath, ?int $productId, array $filters, bool $includeFollowers): array
    {
        [$where, $params] = Customer::where((int) $stall['id'], $filters);
        $customers = Database::all('SELECT c.user_id, c.phone FROM merchant_customers c' . $where, $params);

        $userIds = [];
        $phones = [];
        foreach ($customers as $c) {
            if ($c['user_id'] !== null) {
                $userIds[(int) $c['user_id']] = (int) $c['user_id'];
            } else {
                $phones[$c['phone']] = $c['phone'];
            }
        }
        if ($includeFollowers) {
            foreach (Database::all('SELECT user_id FROM follows WHERE stall_id = ?', [$stall['id']]) as $f) {
                $userIds[(int) $f['user_id']] = (int) $f['user_id'];
            }
        }
        unset($userIds[(int) $stall['merchant_id']]);

        return Database::transaction(static function () use ($stall, $kind, $message, $audioPath, $productId, $userIds, $phones): array {
            $broadcastId = Database::insert(
                'INSERT INTO broadcasts (stall_id, kind, message, audio_path, product_id, app_count, sms_count, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$stall['id'], $kind, $message, $audioPath, $productId, count($userIds), count($phones), Clock::now()]
            );

            $title = match ($kind) {
                'move' => $stall['name'] . ' a déménagé',
                'arrival' => 'Nouvel arrivage chez ' . $stall['name'],
                default => $stall['name'] . ' vous écrit',
            };
            Notification::sendMany(array_values($userIds), 'stall_' . $kind, $title, $message, array_filter([
                'stall_id' => (int) $stall['id'],
                'product_id' => $productId,
                'audio_url' => Url::asset($audioPath),
            ], static fn ($v): bool => $v !== null));

            // Tronqué à 480 caractères (UTF-8) sans dépendre de l'extension mbstring
            preg_match('/^.{0,480}/us', $stall['name'] . ' : ' . $message, $m);
            $sms = $m[0];
            $now = Clock::now();
            Database::insertMany(
                'sms_queue',
                ['phone', 'message', 'broadcast_id', 'created_at'],
                array_map(static fn (string $phone): array => [$phone, $sms, $broadcastId, $now], array_values($phones))
            );

            return ['id' => $broadcastId, 'app_count' => count($userIds), 'sms_count' => count($phones)];
        });
    }

    public static function list(int $stallId, int $limit = 20): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'kind' => $r['kind'],
            'message' => $r['message'],
            'audio_url' => Url::asset($r['audio_path']),
            'product_id' => $r['product_id'] === null ? null : (int) $r['product_id'],
            'app_count' => (int) $r['app_count'],
            'sms_count' => (int) $r['sms_count'],
            'created_at' => Clock::iso($r['created_at']),
        ], Database::all("SELECT * FROM broadcasts WHERE stall_id = ? ORDER BY id DESC LIMIT $limit", [$stallId]));
    }
}
