<?php
declare(strict_types=1);

use Axi\Core\Router;
use Axi\Web\Controllers\Admin;
use Axi\Web\Controllers\Applications;
use Axi\Web\Controllers\AuthPages;
use Axi\Web\Controllers\Campaigns;
use Axi\Web\Controllers\Clients;
use Axi\Web\Controllers\Credits;
use Axi\Web\Controllers\Market;
use Axi\Web\Controllers\Merchants;
use Axi\Web\Controllers\Money;
use Axi\Web\Controllers\Orders;
use Axi\Web\Controllers\Reports;
use Axi\Web\Controllers\Stats;
use Axi\Web\Live;

// Espace web des partenaires « Axi Pro ». Chaque contrôleur vérifie lui-même le sous-type (WebAuth::require)
// et le jeton CSRF de chaque formulaire POST.
return static function (Router $r): void {
    $r->get('/', [AuthPages::class, 'home']);
    $r->get('/pro/connexion', [AuthPages::class, 'loginForm']);
    $r->post('/pro/connexion', [AuthPages::class, 'login']);
    $r->post('/pro/deconnexion', [AuthPages::class, 'logout']);
    $r->get('/pro/mot-de-passe-oublie', [AuthPages::class, 'forgotForm']);
    $r->post('/pro/mot-de-passe-oublie', [AuthPages::class, 'forgot']);
    $r->post('/pro/reinitialiser', [AuthPages::class, 'reset']);
    $r->get('/pro/mon-compte', [AuthPages::class, 'account']);
    $r->post('/pro/mon-compte/mot-de-passe', [AuthPages::class, 'changePassword']);

    $r->get('/pro/pulse', [Live::class, 'pulse']);

    $r->get('/pro', [Stats::class, 'dashboard']);
    $r->get('/pro/recensement', [Stats::class, 'census']);
    $r->get('/pro/activite', [Stats::class, 'activity']);
    $r->get('/pro/prix', [Stats::class, 'prices']);
    $r->get('/pro/ventes', [Stats::class, 'sales']);

    $r->get('/pro/demandes', [Applications::class, 'index']);
    $r->get('/pro/demandes/{id}', [Applications::class, 'show']);
    $r->post('/pro/demandes/{id}/accepter', [Applications::class, 'approve']);
    $r->post('/pro/demandes/{id}/refuser', [Applications::class, 'reject']);
    $r->get('/pro/fichier', [Applications::class, 'file']);

    $r->get('/pro/commercants', [Merchants::class, 'index']);
    $r->get('/pro/commercants/{id}', [Merchants::class, 'show']);
    $r->post('/pro/commercants/{id}/verifier', [Merchants::class, 'verify']);
    $r->post('/pro/commercants/{id}/retirer-badge', [Merchants::class, 'unverify']);
    $r->post('/pro/commercants/{id}/suspendre', [Merchants::class, 'suspend']);
    $r->post('/pro/commercants/{id}/activer', [Merchants::class, 'activate']);
    $r->post('/pro/commercants/{id}/modifier', [Merchants::class, 'update']);
    $r->post('/pro/commercants/{id}/abonnement', [Merchants::class, 'subscription']);
    $r->post('/pro/commercants/{id}/verser', [Merchants::class, 'payout']);
    $r->get('/pro/verifications', [Merchants::class, 'visits']);

    $r->get('/pro/signalements', [Reports::class, 'index']);
    $r->post('/pro/signalements/{id}/bloquer', [Reports::class, 'block']);
    $r->post('/pro/signalements/{id}/classer', [Reports::class, 'dismiss']);

    $r->get('/pro/clients', [Clients::class, 'index']);
    $r->get('/pro/clients/{id}', [Clients::class, 'show']);
    $r->post('/pro/clients/{id}/suspendre', [Clients::class, 'suspend']);
    $r->post('/pro/clients/{id}/activer', [Clients::class, 'activate']);

    $r->get('/pro/commandes', [Orders::class, 'index']);
    $r->get('/pro/commandes/{id}', [Orders::class, 'show']);
    $r->post('/pro/commandes/{id}/enlevement', [Orders::class, 'pickup']);
    $r->post('/pro/commandes/{id}/liberer', [Orders::class, 'release']);
    $r->post('/pro/commandes/{id}/rembourser', [Orders::class, 'refund']);
    $r->get('/pro/litiges', [Orders::class, 'disputes']);
    $r->post('/pro/litiges/{id}', [Orders::class, 'resolve']);

    $r->get('/pro/paiements', [Money::class, 'payments']);
    $r->post('/pro/paiements/reconcilier', [Money::class, 'reconcile']);
    $r->post('/pro/paiements/{id}/verifier', [Money::class, 'verify']);
    $r->get('/pro/versements', [Money::class, 'payouts']);

    $r->get('/pro/allees', [Market::class, 'index']);
    $r->post('/pro/allees/allee', [Market::class, 'saveAisle']);
    $r->post('/pro/allees/site', [Market::class, 'saveSite']);
    $r->post('/pro/allees/proposition/{id}', [Market::class, 'resolveProposal']);

    $r->get('/pro/campagnes', [Campaigns::class, 'index']);
    $r->post('/pro/campagnes', [Campaigns::class, 'create']);
    $r->post('/pro/campagnes/{id}/statut', [Campaigns::class, 'setStatus']);

    $r->get('/pro/credits', [Credits::class, 'index']);
    $r->get('/pro/credits/dossier/{id}', [Credits::class, 'file']);
    $r->post('/pro/credits/{id}', [Credits::class, 'update']);

    $r->get('/pro/partenaires', [Admin::class, 'partners']);
    $r->post('/pro/partenaires/organisation', [Admin::class, 'createOrganization']);
    $r->post('/pro/partenaires/organisation/{id}', [Admin::class, 'toggleOrganization']);
    $r->post('/pro/partenaires/compte', [Admin::class, 'createPartner']);
    $r->post('/pro/partenaires/compte/{id}', [Admin::class, 'togglePartner']);
    $r->get('/pro/reglages', [Admin::class, 'settings']);
    $r->post('/pro/reglages', [Admin::class, 'saveSettings']);
    $r->get('/pro/journal', [Admin::class, 'journal']);
};
