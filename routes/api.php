<?php
declare(strict_types=1);

use Axi\Controllers\AgentController;
use Axi\Controllers\AuthController;
use Axi\Controllers\Client\CartController;
use Axi\Controllers\Client\HomeController;
use Axi\Controllers\Client\OrderController;
use Axi\Controllers\Client\StallController;
use Axi\Controllers\Merchant\CustomerController;
use Axi\Controllers\Merchant\DayController;
use Axi\Controllers\Merchant\MoneyController;
use Axi\Controllers\Merchant\ProductController;
use Axi\Controllers\Merchant\StallController as MerchantStallController;
use Axi\Controllers\NotificationController;
use Axi\Controllers\PaymentController;
use Axi\Controllers\Pro\ProController;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Router;

// 3e argument : null = public, Router::OPTIONAL = public avec jeton facultatif,
// [] = connecté, ['merchant'] = rôle exigé
return static function (Router $r): void {
    $user = [];
    $merchant = ['merchant'];
    $pro = ['pro', 'admin'];
    $agent = ['agent', 'admin'];

    $r->get('/v1/health', static fn (Request $req) => Response::ok(['status' => 'up']));

    // Authentification
    $r->post('/v1/auth/otp/request', [AuthController::class, 'requestOtp']);
    $r->post('/v1/auth/otp/verify', [AuthController::class, 'verifyOtp']);
    $r->get('/v1/auth/me', [AuthController::class, 'me'], $user);
    $r->post('/v1/auth/logout', [AuthController::class, 'logout'], $user);

    // Client : accueil, allées, étals
    $r->get('/v1/home', [HomeController::class, 'home'], Router::OPTIONAL);
    $r->get('/v1/markets', [HomeController::class, 'markets']);
    $r->get('/v1/aisles', [HomeController::class, 'aisles']);
    $r->get('/v1/aisles/{id}/stalls', [StallController::class, 'byAisle']);
    $r->get('/v1/search', [HomeController::class, 'search']);
    $r->get('/v1/delivery-options', [HomeController::class, 'deliveryOptions']);
    $r->post('/v1/promotions/{id}/click', [HomeController::class, 'promotionClick']);
    $r->get('/v1/stalls/{id}', [StallController::class, 'show'], Router::OPTIONAL);
    $r->get('/v1/stalls/{id}/products', [StallController::class, 'products']);
    $r->post('/v1/stalls/{id}/follow', [StallController::class, 'follow'], $user);
    $r->delete('/v1/stalls/{id}/follow', [StallController::class, 'unfollow'], $user);

    // Client : panier multi-étals, commandes, paiement séquestre
    $r->get('/v1/cart', [CartController::class, 'show'], $user);
    $r->post('/v1/cart/items', [CartController::class, 'add'], $user);
    $r->patch('/v1/cart/items/{id}', [CartController::class, 'update'], $user);
    $r->delete('/v1/cart/items/{id}', [CartController::class, 'remove'], $user);
    $r->delete('/v1/cart', [CartController::class, 'clear'], $user);
    $r->post('/v1/checkout', [OrderController::class, 'checkout'], $user);
    $r->get('/v1/orders', [OrderController::class, 'index'], $user);
    $r->get('/v1/orders/{id}', [OrderController::class, 'show'], $user);
    $r->post('/v1/orders/{id}/pay', [OrderController::class, 'pay'], $user);
    $r->post('/v1/orders/{id}/confirm-receipt', [OrderController::class, 'confirmReceipt'], $user);

    // Paiements (FedaPay, Kkiapay)
    $r->get('/v1/payments/fedapay/callback', [PaymentController::class, 'fedapayCallback']);
    $r->get('/v1/payments/{id}', [PaymentController::class, 'show'], $user);
    $r->post('/v1/payments/{id}/verify', [PaymentController::class, 'verify'], $user);
    $r->post('/v1/webhooks/fedapay', [PaymentController::class, 'fedapayWebhook']);
    $r->post('/v1/webhooks/kkiapay', [PaymentController::class, 'kkiapayWebhook']);

    // Notifications (clients et commerçants)
    $r->get('/v1/notifications', [NotificationController::class, 'index'], $user);
    $r->post('/v1/notifications/read', [NotificationController::class, 'markRead'], $user);

    // Commerçant : étal et « Ma journée »
    $r->get('/v1/merchant/stall', [MerchantStallController::class, 'show'], $merchant);
    $r->post('/v1/merchant/stall', [MerchantStallController::class, 'save'], $merchant);
    $r->post('/v1/merchant/stall/move', [MerchantStallController::class, 'move'], $merchant);
    $r->get('/v1/merchant/day', [DayController::class, 'day'], $merchant);
    $r->get('/v1/merchant/orders', [DayController::class, 'index'], $merchant);
    $r->patch('/v1/merchant/orders/{id}', [DayController::class, 'update'], $merchant);
    $r->get('/v1/merchant/products', [ProductController::class, 'index'], $merchant);
    $r->post('/v1/merchant/products', [ProductController::class, 'create'], $merchant);
    $r->post('/v1/merchant/products/{id}', [ProductController::class, 'update'], $merchant);
    $r->delete('/v1/merchant/products/{id}', [ProductController::class, 'delete'], $merchant);

    // Commerçant : « Mes clients »
    $r->get('/v1/merchant/customers', [CustomerController::class, 'index'], $merchant);
    $r->post('/v1/merchant/customers', [CustomerController::class, 'create'], $merchant);
    $r->patch('/v1/merchant/customers/{id}', [CustomerController::class, 'update'], $merchant);
    $r->delete('/v1/merchant/customers/{id}', [CustomerController::class, 'delete'], $merchant);
    $r->get('/v1/merchant/broadcasts', [CustomerController::class, 'broadcasts'], $merchant);
    $r->post('/v1/merchant/broadcasts', [CustomerController::class, 'broadcast'], $merchant);

    // Commerçant : « Mon argent »
    $r->get('/v1/merchant/money', [MoneyController::class, 'money'], $merchant);
    $r->get('/v1/merchant/payouts', [MoneyController::class, 'payouts'], $merchant);
    $r->post('/v1/merchant/payouts', [MoneyController::class, 'requestPayout'], $merchant);
    $r->post('/v1/merchant/subscription/renew', [MoneyController::class, 'renewSubscription'], $merchant);
    $r->get('/v1/merchant/credit-requests', [MoneyController::class, 'creditRequestsIndex'], $merchant);
    $r->post('/v1/merchant/credit-requests', [MoneyController::class, 'createCreditRequest'], $merchant);

    // Agents Axi : vérification sur place
    $r->get('/v1/agent/stalls', [AgentController::class, 'stalls'], $agent);
    $r->post('/v1/agent/stalls/{id}/verify', [AgentController::class, 'verify'], $agent);

    // Axi Pro : données agrégées et anonymes
    $r->get('/v1/pro/census', [ProController::class, 'census'], $pro);
    $r->get('/v1/pro/prices', [ProController::class, 'prices'], $pro);
    $r->get('/v1/pro/trends', [ProController::class, 'trends'], $pro);
    $r->get('/v1/pro/credits', [ProController::class, 'credits'], $pro);
    $r->get('/v1/pro/catalog-items', [ProController::class, 'catalogItems'], $pro);
    $r->get('/v1/pro/campaigns', [ProController::class, 'campaigns'], $pro);
    $r->post('/v1/pro/campaigns', [ProController::class, 'createCampaign'], $pro);
};
