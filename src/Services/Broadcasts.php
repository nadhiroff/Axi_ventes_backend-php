<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\Logger;

/**
 * Messages groupés du commerçant (nouvelle adresse, arrivage, relance).
 * Le message est enregistré ; l'envoi réel (SMS, WhatsApp, notification) n'est pas encore branché :
 * en attendant il est écrit dans storage/logs/broadcasts.log.
 */
final class Broadcasts
{
    public static function record(int $stallId, string $kind, string $channel, ?string $segment, string $message, array $phones, ?string $audioPath = null): int
    {
        Database::run(
            'INSERT INTO broadcasts (stall_id, kind, channel, segment, message, audio_path, recipients, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$stallId, $kind, $channel, $segment, mb_substr($message, 0, 500), $audioPath, count($phones), Clock::now()],
        );
        Logger::write('broadcasts', "étal $stallId $kind/$channel → " . count($phones) . ' destinataire(s) : ' . $message . ' [' . implode(', ', $phones) . ']');
        return count($phones);
    }
}
