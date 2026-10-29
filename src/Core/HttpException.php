<?php
declare(strict_types=1);

namespace Axi\Core;

/** Erreur métier renvoyée au client sous la forme {"ok":false,"error":{...}} */
final class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
