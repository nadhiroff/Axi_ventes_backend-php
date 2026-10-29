<?php
declare(strict_types=1);

namespace Axi\Services\Sms;

interface SmsSender
{
    public function send(string $phone, string $message): void;
}
