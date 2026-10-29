<?php
declare(strict_types=1);

namespace Axi\Core;

final class Logger
{
    public static function write(string $channel, string $message): void
    {
        $line = '[' . gmdate('Y-m-d H:i:s') . "Z] $message" . PHP_EOL;
        $file = BASE_PATH . '/storage/logs/' . $channel . '.log';
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log("[axi:$channel] $message");
        }
    }
}
