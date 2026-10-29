<?php
declare(strict_types=1);

namespace Axi\Controllers;

use Axi\Core\Auth;
use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Models\AuthToken;
use Axi\Models\User;
use Axi\Services\OtpService;

final class AuthController
{
    /** POST /v1/auth/otp/request */
    public function requestOtp(Request $request): never
    {
        $in = Validator::validate($request->all(), ['phone' => 'required|string|phone']);
        $code = (new OtpService())->request($in['phone']);

        $data = [
            'expires_in' => (int) Config::get('auth.otp_ttl_seconds'),
            'resend_in' => (int) Config::get('auth.otp_resend_seconds'),
        ];
        // En local seulement : le code est renvoyé pour tester sans SMS
        if (Config::isLocal()) {
            $data['debug_code'] = $code;
        }
        Response::ok($data);
    }

    /** POST /v1/auth/otp/verify */
    public function verifyOtp(Request $request): never
    {
        $in = Validator::validate($request->all(), [
            'phone' => 'required|string|phone',
            'code' => 'required|string|digits:6',
            'role' => 'string|in:client,merchant',
            'name' => 'string|max:100',
            'lang' => 'string|in:fr,fon,yo,en',
        ]);

        // Vérifié avant le code pour ne pas consommer l'OTP si l'inscription est incomplète
        $user = User::findByPhone($in['phone']);
        if ($user === null && !isset($in['name'])) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Données invalides', ['fields' => ['name' => 'requis pour une inscription']]);
        }

        (new OtpService())->verify($in['phone'], $in['code']);

        $isNew = $user === null;
        if ($isNew) {
            $id = User::create($in['phone'], $in['name'], $in['role'] ?? 'client', $in['lang'] ?? 'fr');
            $user = User::findById($id);
        }
        if ($user['status'] !== 'active') {
            throw new HttpException(403, 'ACCOUNT_SUSPENDED', 'Compte suspendu');
        }

        $device = $request->header('user-agent');
        [$token, $expiresAt] = Auth::issueToken((int) $user['id'], $device === null ? null : substr($device, 0, 120));

        Response::ok([
            'token' => $token,
            'expires_at' => Clock::iso($expiresAt),
            'is_new' => $isNew,
            'user' => User::toPublic($user),
        ], $isNew ? 201 : 200);
    }

    /** GET /v1/auth/me */
    public function me(Request $request): never
    {
        Response::ok(['user' => User::toPublic($request->user)]);
    }

    /** POST /v1/auth/logout */
    public function logout(Request $request): never
    {
        AuthToken::revoke($request->tokenId);
        Response::noContent();
    }
}
