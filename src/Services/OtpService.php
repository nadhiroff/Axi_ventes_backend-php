<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\HttpException;
use Axi\Models\OtpCode;
use Axi\Services\Sms\LogSmsSender;
use Axi\Services\Sms\SmsSender;

final class OtpService
{
    private SmsSender $sms;

    public function __construct(?SmsSender $sms = null)
    {
        $this->sms = $sms ?? match (Config::get('sms.driver')) {
            'log' => new LogSmsSender(),
            default => throw new \RuntimeException('Fournisseur SMS non configuré'),
        };
    }

    /** Génère et envoie un code. Renvoie le code en clair (utilisé seulement en local). */
    public function request(string $phone): string
    {
        $resend = (int) Config::get('auth.otp_resend_seconds');
        $last = OtpCode::latestForPhone($phone);
        if ($last !== null && $last['created_at'] > Clock::now(-$resend)) {
            $wait = max(1, strtotime($last['created_at'] . ' UTC') + $resend - time());
            throw new HttpException(429, 'OTP_TOO_MANY', "Réessayez dans $wait s", ['retry_in' => $wait], ['Retry-After' => (string) $wait]);
        }
        if (OtpCode::countSince($phone, Clock::now(-3600)) >= (int) Config::get('auth.otp_max_per_hour')) {
            throw new HttpException(429, 'OTP_TOO_MANY', 'Trop de demandes, réessayez dans une heure', ['retry_in' => 3600], ['Retry-After' => '3600']);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        OtpCode::create($phone, $this->hash($phone, $code), Clock::now(), Clock::now((int) Config::get('auth.otp_ttl_seconds')));
        $this->sms->send($phone, "Axi : votre code est $code. Ne le partagez avec personne.");

        return $code;
    }

    public function verify(string $phone, string $code): void
    {
        $otp = OtpCode::latestForPhone($phone);
        if ($otp === null || $otp['used_at'] !== null || $otp['expires_at'] <= Clock::now()) {
            throw new HttpException(401, 'OTP_INVALID', 'Code incorrect ou expiré');
        }
        if ((int) $otp['attempts'] >= (int) Config::get('auth.otp_max_attempts')) {
            throw new HttpException(429, 'OTP_LOCKED', 'Trop d\'essais, demandez un nouveau code');
        }
        if (!hash_equals($otp['code_hash'], $this->hash($phone, $code))) {
            OtpCode::incrementAttempts((int) $otp['id']);
            throw new HttpException(401, 'OTP_INVALID', 'Code incorrect ou expiré');
        }
        if (!OtpCode::consume((int) $otp['id'], Clock::now())) {
            throw new HttpException(401, 'OTP_INVALID', 'Code incorrect ou expiré');
        }
    }

    private function hash(string $phone, string $code): string
    {
        $secret = (string) Config::get('app_secret');
        if ($secret === '') {
            throw new \RuntimeException('app_secret manquant dans config/config.local.php');
        }
        return hash_hmac('sha256', $phone . '|' . $code, $secret);
    }
}
