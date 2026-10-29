<?php
declare(strict_types=1);

namespace Axi\Services\Sms;

use Axi\Core\Logger;

/** Développement local : les SMS sont écrits dans storage/logs/sms.log au lieu d'être envoyés. */
final class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        Logger::write('sms', "$phone : $message");
    }
}
