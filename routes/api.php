<?php
declare(strict_types=1);

use Axi\Controllers\Admin\AdminController;
use Axi\Controllers\AgentVisitController;
use Axi\Controllers\AuthController;
use Axi\Controllers\CatalogController;
use Axi\Controllers\CronController;
use Axi\Controllers\Merchant\ClientsController;
use Axi\Controllers\Merchant\MoneyController;
use Axi\Controllers\Merchant\ProductsController;
use Axi\Controllers\Merchant\TodayController;
use Axi\Controllers\OrderController;
use Axi\Controllers\Pro\ProController;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Router;

// 3e argument : null = public, Router::OPTIONAL = public avec jeton facultatif,
// [] = connecté, ['merchant'] = rôles exigés. Voir docs/POUR_LE_FRONTEND.md.
return static function (Router $r): void {
    $user = [];
    $merchant = ['merchant'];
    $staff = ['agent', 'admin'];
    $pro = ['pro', 'admin'];

    $r->get('/v1/health', static fn (Request $req) => Response::ok(['status' => 'up']));

    // Authentification par email et mot de passe
    $r->post('/v1/auth/register', [AuthController::class, 'register']);
    $r->post('/v1/auth/verify-email', [AuthController::class, 'verifyEmail']);
    $r->post('/v1/auth/resend-code', [AuthController::class, 'resendCode']);
    $r->post('/v1/auth/login', [AuthController::class, 'login']);
    $r->post('/v1/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    $r->post('/v1/auth/reset-password', [AuthController::class, 'resetPassword']);
    $r->post('/v1/auth/change-password', [AuthController::class, 'changePassword'], $user);
    $r->post('/v1/auth/logout', [AuthController::class, 'logout'], $user);
    $r->get('/v1/me', [AuthController::class, 'me'], $user);
    $r->patch('/v1/me', [AuthController::class, 'updateMe'], $user);
    $r->post('/v1/agent-visits', [AgentVisitController::class, 'create'], $user);

    // Client : accueil, allées, recherche, étals (visite libre)
    $r->get('/v1/home', [CatalogController::class, 'home'], Router::OPTIONAL);
    $r->get('/v1/aisles/{id}/stalls', [CatalogController::class, 'aisle'], Router::OPTIONAL);
    $r->get('/v1/search', [CatalogController::class, 'search'], Router::OPTIONAL);
    $r->post('/v1/search/voice', [CatalogController::class, 'voiceSearch'], Router::OPTIONAL);
    $r->get('/v1/stalls/{id}', [CatalogController::class, 'stall'], Router::OPTIONAL);
    $r->post('/v1/stalls/{id}/follow', [CatalogController::class, 'follow'], $user);
    $r->delete('/v1/stalls/{id}/follow', [CatalogController::class, 'unfollow'], $user);
    $r->post('/v1/stalls/{id}/voice-messages', [CatalogController::class, 'voiceMessage'], $user);

    // Client : panier multi-étals (gardé sur le téléphone) et paiement Mobile Money
    $r->post('/v1/orders/quote', [OrderController::class, 'quote'], Router::OPTIONAL);
    $r->post('/v1/orders', [OrderController::class, 'create'], $user);
    $r->get('/v1/orders/{id}', [OrderController::class, 'show'], $user);
    $r->post('/v1/orders/{id}/confirm-receipt', [OrderController::class, 'confirmReceipt'], $user);

    // Commerçant
    $r->get('/v1/merchant/today', [TodayController::class, 'today'], $merchant);
    $r->post('/v1/merchant/relocation/broadcast', [ClientsController::class, 'relocation'], $merchant);
    $r->post('/v1/merchant/orders/{id}/ready', [TodayController::class, 'ready'], $merchant);
    $r->post('/v1/merchant/arrivals', [ClientsController::class, 'arrival'], $merchant);
    $r->get('/v1/merchant/stall', [ProductsController::class, 'stall'], $merchant);
    $r->patch('/v1/merchant/stall', [ProductsController::class, 'updateStall'], $merchant);
    $r->post('/v1/merchant/products', [ProductsController::class, 'create'], $merchant);
    $r->patch('/v1/merchant/products/{id}', [ProductsController::class, 'update'], $merchant);
    $r->delete('/v1/merchant/products/{id}', [ProductsController::class, 'delete'], $merchant);
    $r->get('/v1/merchant/clients', [ClientsController::class, 'index'], $merchant);
    $r->post('/v1/merchant/clients', [ClientsController::class, 'create'], $merchant);
    $r->post('/v1/merchant/clients/{id}/follow-up', [ClientsController::class, 'followUp'], $merchant);
    $r->post('/v1/merchant/broadcasts', [ClientsController::class, 'broadcast'], $merchant);
    $r->get('/v1/merchant/money', [MoneyController::class, 'money'], $merchant);
    $r->post('/v1/merchant/credit-requests', [MoneyController::class, 'requestCredit'], $merchant);
    $r->put('/v1/merchant/data-sharing', [MoneyController::class, 'dataSharing'], $merchant);

    // Agents et administrateurs : enrôlement des commerçants (toutes les actions sont journalisées)
    $r->get('/v1/admin/users', [AdminController::class, 'users'], $staff);
    $r->get('/v1/admin/agent-visits', [AdminController::class, 'visits'], $staff);
    $r->post('/v1/admin/merchants', [AdminController::class, 'createMerchant'], $staff);
    $r->get('/v1/admin/merchants', [AdminController::class, 'merchants'], $staff);
    $r->get('/v1/admin/merchants/{id}', [AdminController::class, 'merchant'], $staff);
    $r->patch('/v1/admin/merchants/{id}', [AdminController::class, 'updateMerchant'], $staff);
    $r->post('/v1/admin/stalls/{id}/products', [AdminController::class, 'createProduct'], $staff);
    $r->post('/v1/admin/orders/{id}/pickup', [AdminController::class, 'pickup'], $staff);

    // Tâche planifiée appelée par URL (hébergement sans SSH) : /v1/internal/cron?key=<cron_key>
    $r->get('/v1/internal/cron', [CronController::class, 'run']);

    // Axi Pro : données agrégées et anonymes (le type d'organisation est vérifié dans le contrôleur)
    $r->get('/v1/pro/overview', [ProController::class, 'overview'], $pro);
    $r->get('/v1/pro/campaigns', [ProController::class, 'campaigns'], $pro);
    $r->post('/v1/pro/campaigns', [ProController::class, 'createCampaign'], $pro);
    $r->get('/v1/pro/census', [ProController::class, 'census'], $pro);
    $r->get('/v1/pro/financing', [ProController::class, 'financing'], $pro);
};
