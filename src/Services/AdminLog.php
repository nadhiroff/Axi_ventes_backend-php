<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\Request;

/** Journal des actions des agents et administrateurs : qui, quoi, quand */
final class AdminLog
{
    public static function record(Request $req, string $action, string $targetType, ?int $targetId, array $details = []): void
    {
        Database::run(
            'INSERT INTO admin_actions (actor_id, action, target_type, target_id, details, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$req->userId(), $action, $targetType, $targetId, $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE), $req->ip(), Clock::now()],
        );
    }
}
