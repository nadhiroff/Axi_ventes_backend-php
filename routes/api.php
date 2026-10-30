<?php
declare(strict_types=1);

use Axi\Controllers\AccountController;
use Axi\Controllers\ApplicationController;
use Axi\Controllers\AuthController;
use Axi\Controllers\CatalogController;
use Axi\Controllers\CronController;
use Axi\Controllers\MediaController;
use Axi\Controllers\MessagesController;
use Axi\Controllers\Merchant\ClientsController;
use Axi\Controllers\Merchant\MoneyController;
use Axi\Controllers\Merchant\StallController;
use Axi\Controllers\Merchant\TodayController;
use Axi\Controllers\OrderController;
use Axi\Controllers\PaymentController;
use Axi\Controllers\ProfileController;
use Axi\Controllers\SalesController;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Router;

// API de l'application mobile : rôles client et merchant. Les partenaires utilisent l'espace web (routes/web.php).
// 3e argument : null = public, Router::OPTIONAL = public avec jeton facultatif,
// [] = connecté (client ou commerçant), ['merchant'] = rôle exigé. Voir docs/POUR_LE_FRONTEND.md.
return static function (Router $r): void {
    $user = ['client', 'merchant'];
    $merchant = ['merchant'];

    $r->get('/v1/health', static fn (Request $req) => Response::ok(['status' => 'up']));

    // Authentification : email et mot de passe uniquement
    $r->post('/v1/auth/register', [AuthController::class, 'register']);
    $r->post('/v1/auth/verify-email', [AuthController::class, 'verifyEmail']);
    $r->post('/v1/auth/resend-code', [AuthController::class, 'resendCode']);
    $r->post('/v1/auth/login', [AuthController::class, 'login']);
    $r->post('/v1/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    $r->post('/v1/auth/reset-password', [AuthController::class, 'resetPassword']);
    $r->post('/v1/auth/change-password', [AuthController::class, 'changePassword'], $user);
    $r->post('/v1/auth/logout', [AuthController::class, 'logout'], $user);
    $r->post('/v1/auth/logout-all', [AuthController::class, 'logoutAll'], $user);
    $r->get('/v1/me', [AuthController::class, 'me'], $user);
    $r->patch('/v1/me', [AuthController::class, 'updateMe'], $user);

    // Paramètres, sécurité, vie privée
    $r->get('/v1/me/settings', [AccountController::class, 'settings'], $user);
    $r->patch('/v1/me/settings', [AccountController::class, 'updateSettings'], $user);
    $r->post('/v1/me/data-usage', [AccountController::class, 'dataUsage'], $user);
    $r->get('/v1/me/sessions', [AccountController::class, 'sessions'], $user);
    $r->delete('/v1/me/sessions/{id}', [AccountController::class, 'revokeSession'], $user);
    $r->get('/v1/me/login-history', [AccountController::class, 'loginHistory'], $user);
    $r->get('/v1/me/consents', [AccountController::class, 'consents'], $user);
    $r->delete('/v1/me/consents/{id}', [AccountController::class, 'revokeConsent'], $user);
    $r->post('/v1/me/export', [AccountController::class, 'exportLink'], $user);
    $r->get('/v1/me/export.json', [AccountController::class, 'export']);
    $r->post('/v1/me/delete', [AccountController::class, 'deleteAccount'], $user);
    $r->post('/v1/me/avatar', [AccountController::class, 'avatar'], $user);
    $r->delete('/v1/me/avatar', [AccountController::class, 'deleteAvatar'], $user);
    $r->get('/v1/me/badges', [MessagesController::class, 'badges'], $user);

    // Devenir commerçant : demande vérifiée par un agent Axi dans l'espace web
    $r->get('/v1/me/merchant-application', [ApplicationController::class, 'show'], $user);
    $r->post('/v1/me/merchant-application', [ApplicationController::class, 'create'], $user);
    $r->post('/v1/me/merchant-welcome/seen', [ApplicationController::class, 'welcomeSeen'], $user);

    // Messagerie client ↔ commerçant (effacée après 24 h sauf si le client suit l'étal)
    $r->get('/v1/conversations', [MessagesController::class, 'index'], $user);
    $r->get('/v1/conversations/{id}/messages', [MessagesController::class, 'messages'], $user);
    $r->post('/v1/conversations/{id}/messages', [MessagesController::class, 'send'], $user);
    $r->patch('/v1/conversations/{id}/messages/{message}', [MessagesController::class, 'edit'], $user);
    $r->delete('/v1/conversations/{id}/messages/{message}', [MessagesController::class, 'delete'], $user);
    $r->post('/v1/conversations/{id}/read', [MessagesController::class, 'read'], $user);
    $r->post('/v1/conversations/{id}/messages/{message}/hide', [MessagesController::class, 'hide'], $user);
    $r->get('/v1/users/{id}/profile', [ProfileController::class, 'show'], $user);
    $r->post('/v1/users/{id}/report', [ProfileController::class, 'report'], $user);
    $r->post('/v1/stalls/{id}/conversation', [MessagesController::class, 'openWithStall'], $user);
    $r->post('/v1/merchant/clients/{id}/conversation', [MessagesController::class, 'openWithClient'], $merchant);
    $r->get('/v1/notifications', [AccountController::class, 'notifications'], $user);
    $r->post('/v1/notifications/read', [AccountController::class, 'readNotifications'], $user);
    $r->get('/v1/favorites', [AccountController::class, 'favorites'], $user);
    $r->post('/v1/favorites/{id}', [AccountController::class, 'addFavorite'], $user);
    $r->delete('/v1/favorites/{id}', [AccountController::class, 'removeFavorite'], $user);
    $r->get('/v1/me/recently-viewed', [AccountController::class, 'recentlyViewed'], $user);

    // Client : accueil, allées, recherche, étals, produits (visite libre)
    $r->get('/v1/home', [CatalogController::class, 'home'], Router::OPTIONAL);
    $r->get('/v1/rates', [CatalogController::class, 'rates'], Router::OPTIONAL);
    $r->get('/v1/aisles/{id}/stalls', [CatalogController::class, 'aisle'], Router::OPTIONAL);
    $r->get('/v1/search', [CatalogController::class, 'search'], Router::OPTIONAL);
    $r->post('/v1/search/voice', [CatalogController::class, 'voiceSearch'], Router::OPTIONAL);
    $r->get('/v1/stalls/{id}', [CatalogController::class, 'stall'], Router::OPTIONAL);
    $r->get('/v1/products/{id}', [CatalogController::class, 'product'], Router::OPTIONAL);
    $r->post('/v1/promotions/{id}/click', [CatalogController::class, 'promotionClick']);
    $r->post('/v1/stalls/{id}/follow', [CatalogController::class, 'follow'], $user);
    $r->delete('/v1/stalls/{id}/follow', [CatalogController::class, 'unfollow'], $user);
    $r->post('/v1/stalls/{id}/voice-messages', [CatalogController::class, 'voiceMessage'], $user);
    $r->post('/v1/stalls/{id}/email', [CatalogController::class, 'emailStall'], $user);

    // Client : panier multi-étals, paiement unique, suivi, réception, litige
    $r->post('/v1/orders/quote', [OrderController::class, 'quote'], Router::OPTIONAL);
    $r->post('/v1/orders', [OrderController::class, 'create'], $user);
    $r->get('/v1/orders', [OrderController::class, 'index'], $user);
    $r->get('/v1/orders/{id}', [OrderController::class, 'show'], $user);
    $r->post('/v1/orders/{id}/verify-payment', [OrderController::class, 'verifyPayment'], $user);
    $r->post('/v1/orders/{id}/cancel', [OrderController::class, 'cancel'], $user);
    $r->post('/v1/orders/{id}/confirm-receipt', [OrderController::class, 'confirmReceipt'], $user);
    $r->post('/v1/orders/{id}/dispute', [OrderController::class, 'dispute'], $user);
    $r->get('/v1/sales', [SalesController::class, 'index'], $user);
    $r->post('/v1/sales/export', [SalesController::class, 'exportLink'], $user);
    $r->get('/v1/sales/export.csv', [SalesController::class, 'export']);

    // Paiement : pages ouvertes dans l'application, retour, webhooks
    $r->get('/v1/payments/kkiapay', [PaymentController::class, 'kkiapay']);
    $r->get('/v1/payments/return', [PaymentController::class, 'return']);
    $r->post('/v1/webhooks/fedapay', [PaymentController::class, 'fedapayWebhook']);
    $r->post('/v1/webhooks/kkiapay', [PaymentController::class, 'kkiapayWebhook']);
    $r->get('/v1/media/audio', [MediaController::class, 'audio']);

    // Commerçant
    $r->get('/v1/merchant/stall-options', [StallController::class, 'options'], $user);
    $r->get('/v1/merchant/stall', [StallController::class, 'show'], $merchant);
    $r->patch('/v1/merchant/stall', [StallController::class, 'update'], $merchant);
    $r->post('/v1/merchant/stall/photo', [StallController::class, 'photo'], $merchant);
    $r->post('/v1/merchant/stall/photos', [StallController::class, 'addGalleryPhotos'], $merchant);
    $r->delete('/v1/merchant/stall/photos/{index}', [StallController::class, 'removeGalleryPhoto'], $merchant);
    $r->post('/v1/merchant/verification-request', [StallController::class, 'requestVisit'], $merchant);
    $r->post('/v1/merchant/products', [StallController::class, 'createProduct'], $merchant);
    $r->post('/v1/merchant/products/voice', [StallController::class, 'createVoiceProduct'], $merchant);
    $r->patch('/v1/merchant/products/{id}', [StallController::class, 'updateProduct'], $merchant);
    $r->post('/v1/merchant/products/{id}/photos', [StallController::class, 'addPhotos'], $merchant);
    $r->delete('/v1/merchant/products/{id}/photos/{index}', [StallController::class, 'removePhoto'], $merchant);
    $r->delete('/v1/merchant/products/{id}', [StallController::class, 'deleteProduct'], $merchant);
    $r->get('/v1/merchant/voice-messages', [StallController::class, 'voiceMessages'], $merchant);
    $r->post('/v1/merchant/voice-messages/{id}/listened', [StallController::class, 'markListened'], $merchant);
    $r->delete('/v1/merchant/voice-messages/{id}', [StallController::class, 'deleteVoiceMessage'], $merchant);
    $r->get('/v1/merchant/today', [TodayController::class, 'today'], $merchant);
    $r->post('/v1/merchant/orders/{id}/ready', [TodayController::class, 'ready'], $merchant);
    $r->post('/v1/merchant/relocation/broadcast', [ClientsController::class, 'relocation'], $merchant);
    $r->post('/v1/merchant/arrivals', [ClientsController::class, 'arrival'], $merchant);
    $r->patch('/v1/merchant/announcements/{id}', [ClientsController::class, 'updateAnnouncement'], $merchant);
    $r->delete('/v1/merchant/announcements/{id}', [ClientsController::class, 'deleteAnnouncement'], $merchant);
    $r->get('/v1/merchant/clients', [ClientsController::class, 'index'], $merchant);
    $r->post('/v1/merchant/clients', [ClientsController::class, 'create'], $merchant);
    $r->get('/v1/merchant/clients/lookup', [ClientsController::class, 'lookup'], $merchant);
    $r->get('/v1/merchant/clients/{id}', [ClientsController::class, 'show'], $merchant);
    $r->patch('/v1/merchant/clients/{id}', [ClientsController::class, 'update'], $merchant);
    $r->delete('/v1/merchant/clients/{id}', [ClientsController::class, 'delete'], $merchant);
    $r->post('/v1/merchant/clients/{id}/follow-up', [ClientsController::class, 'followUp'], $merchant);
    $r->post('/v1/merchant/broadcasts', [ClientsController::class, 'broadcast'], $merchant);
    $r->get('/v1/merchant/money', [MoneyController::class, 'money'], $merchant);
    $r->post('/v1/merchant/subscription/pay', [MoneyController::class, 'paySubscription'], $merchant);
    $r->post('/v1/merchant/subscription/verify', [MoneyController::class, 'verifySubscription'], $merchant);
    $r->post('/v1/merchant/credit-requests', [MoneyController::class, 'requestCredit'], $merchant);
    $r->put('/v1/merchant/data-sharing', [MoneyController::class, 'dataSharing'], $merchant);

    // Tâche planifiée appelée par URL (hébergement sans SSH) : /v1/internal/cron?key=<cron_key>
    $r->get('/v1/internal/cron', [CronController::class, 'run']);
};
