<?php
declare(strict_types=1);

use Axi\Controllers\AuthController;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Router;

// 3e argument : null = public, [] = connecté, ['merchant'] = rôle exigé
return static function (Router $r): void {
    $r->get('/v1/health', static fn (Request $req) => Response::ok(['status' => 'up']));

    // Authentification
    $r->post('/v1/auth/otp/request', [AuthController::class, 'requestOtp']);
    $r->post('/v1/auth/otp/verify', [AuthController::class, 'verifyOtp']);
    $r->get('/v1/auth/me', [AuthController::class, 'me'], []);
    $r->post('/v1/auth/logout', [AuthController::class, 'logout'], []);
};
