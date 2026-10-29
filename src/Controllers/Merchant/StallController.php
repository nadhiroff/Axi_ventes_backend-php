<?php
declare(strict_types=1);

namespace Axi\Controllers\Merchant;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Ids;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Services\Products;
use Axi\Services\SignedUrl;
use Axi\Services\StallInput;
use Axi\Services\Uploads;
use Axi\Support\Merchant;
use Axi\Support\Stalls;

/**
 * « Mon étal » : le commerçant crée et gère lui-même sa vitrine (nom, photo, emplacement, contacts),
 * son catalogue et ses prix. Un changement d'emplacement retire le badge « vérifié » : un agent Axi
 * doit repasser sur place (espace web).
 */
final class StallController
{
    /** Listes pour le formulaire : sites et allées actifs */
    public function options(Request $req): never
    {
        Response::ok([
            // « Autre » n'est pas dans les listes : l'application l'ajoute avec un champ libre (id « autre » / « autres »)
            'sites' => Database::all('SELECT id, name FROM sites WHERE active = 1 AND id <> \'autre\' ORDER BY sort_order, name'),
            'aisles' => array_map(static fn (array $a): array => ['id' => $a['id'], 'name' => $a['name'], 'icon' => $a['icon']], Database::all('SELECT * FROM aisles WHERE active = 1 AND id <> \'autres\' ORDER BY sort_order, name')),
            'other_site_id' => 'autre',
            'other_aisle_id' => 'autres',
        ], 200, 3600);
    }

    public function show(Request $req): never
    {
        Response::ok($this->payload($req), 200, 60);
    }

    public function update(Request $req): never
    {
        $stall = Merchant::stall($req);
        $columns = StallInput::validate($req->all(), false);
        if ($columns === []) {
            Response::ok($this->payload($req));
        }
        $moved = false;
        foreach (StallInput::LOCATION_FIELDS as $field) {
            $moved = $moved || (array_key_exists($field, $columns) && (string) $columns[$field] !== (string) $stall[$field]);
        }
        if ($moved) {
            // Nouvel emplacement : badge retiré jusqu'à la visite d'un agent ; encart « nouvelle adresse » affiché
            $columns += ['moved_at' => Clock::now(), 'relocation_sent_at' => null, 'verified_at' => null, 'verified_by' => null];
            if (Database::value('SELECT id FROM agent_visit_requests WHERE user_id = ? AND status IN (\'new\', \'scheduled\')', [$req->userId()]) === null) {
                Database::run('INSERT INTO agent_visit_requests (user_id, created_at) VALUES (?, ?)', [$req->userId(), Clock::now()]);
            }
        }
        Database::updateById('stalls', (int) $stall['id'], $columns + ['updated_at' => Clock::now()]);
        Response::ok($this->payload($req));
    }

    /** Photo de l'étal (multipart « photo ») */
    public function photo(Request $req): never
    {
        $stall = Merchant::stall($req);
        $file = $req->file('photo') ?? Validator::fail(['photo' => 'Choisissez une photo.'], ['photo' => 'Photo']);
        $photo = Uploads::photo($file);
        Database::updateById('stalls', (int) $stall['id'], ['photo_path' => $photo['path'], 'photo_width' => $photo['width'], 'photo_height' => $photo['height'], 'updated_at' => Clock::now()]);
        Response::ok($this->payload($req));
    }

    public function createProduct(Request $req): never
    {
        $stall = Merchant::stall($req);
        Response::ok(Products::create((int) $stall['id'], $req, $req->userId()), 201);
    }

    /** Ajout par vocal : brouillon à compléter (nom, prix, photo) puis à publier */
    public function createVoiceProduct(Request $req): never
    {
        $stall = Merchant::stall($req);
        Response::ok(Products::createVoiceDraft((int) $stall['id'], $req, $req->userId()), 201);
    }

    public function updateProduct(Request $req): never
    {
        $stall = Merchant::stall($req);
        Response::ok(Products::update($this->productId($req), (int) $stall['id'], $req));
    }

    public function addPhotos(Request $req): never
    {
        $stall = Merchant::stall($req);
        Response::ok(Products::addPhotos($this->productId($req), (int) $stall['id'], $req));
    }

    public function removePhoto(Request $req): never
    {
        $stall = Merchant::stall($req);
        $index = (int) ($req->params['index'] ?? -1);
        Response::ok(Products::removePhoto($this->productId($req), (int) $stall['id'], $index));
    }

    public function deleteProduct(Request $req): never
    {
        $stall = Merchant::stall($req);
        Products::delete($this->productId($req), (int) $stall['id']);
        Response::ok(['deleted' => true]);
    }

    /** Messages vocaux laissés par les clients (lien d'écoute signé, valable 1 jour) */
    public function voiceMessages(Request $req): never
    {
        $stall = Merchant::stall($req);
        $rows = Database::all(
            'SELECT v.*, u.name, u.phone FROM voice_messages v JOIN users u ON u.id = v.user_id WHERE v.stall_id = ? ORDER BY v.id DESC LIMIT 100',
            [$stall['id']],
        );
        Response::ok(['messages' => array_map(static fn (array $m): array => [
            'id' => Ids::encode('vm', $m['id']),
            'client_name' => $m['name'],
            'client_phone' => $m['phone'],
            'audio_url' => SignedUrl::make('/v1/media/audio', ['p' => $m['audio_path']], 86400),
            'listened' => $m['listened_at'] !== null,
            'created_at' => Clock::iso($m['created_at']),
        ], $rows)]);
    }

    public function markListened(Request $req): never
    {
        $stall = Merchant::stall($req);
        Database::run('UPDATE voice_messages SET listened_at = COALESCE(listened_at, ?) WHERE id = ? AND stall_id = ?', [Clock::now(), $req->id('vm'), $stall['id']]);
        Response::ok(['listened' => true]);
    }

    /** Demande de visite d'un agent (vérification sur place, badge « vérifié ») */
    public function requestVisit(Request $req): never
    {
        Merchant::stall($req);
        if (Database::value('SELECT id FROM agent_visit_requests WHERE user_id = ? AND status IN (\'new\', \'scheduled\')', [$req->userId()]) === null) {
            Database::run('INSERT INTO agent_visit_requests (user_id, created_at) VALUES (?, ?)', [$req->userId(), Clock::now()]);
        }
        Response::ok(['message' => 'Demande enregistrée. Un agent Axi passera vérifier votre étal sur place.']);
    }

    private function productId(Request $req): int
    {
        return $req->id('prd', 'PRODUCT_NOT_FOUND', 'Ce produit n\'existe pas ou a été supprimé.');
    }

    /** Tous les produits (brouillons vocaux en premier, épuisés compris) et l'état de vérification */
    private function payload(Request $req): array
    {
        $stall = Merchant::stall($req);
        $rows = Stalls::products((int) $stall['id'], 0, null, true);
        $products = Stalls::productList($rows);
        foreach ($rows as $i => $p) {
            $products[$i]['voice_note_url'] = $p['audio_path'] === null ? null : SignedUrl::make('/v1/media/audio', ['p' => $p['audio_path']], 86400);
        }
        $visit = Database::one('SELECT status, created_at FROM agent_visit_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$req->userId()]);
        return [
            'stall' => Stalls::detail($stall, null) + [
                'status' => $stall['status'],
                'has_physical_place' => (bool) $stall['has_physical_place'],
                'site_other' => $stall['site_other'],
                'aisle_other' => $stall['aisle_other'],
                'subscription' => \Axi\Support\Subscription::present($stall),
                'verification_requested' => $visit !== null && in_array($visit['status'], ['new', 'scheduled'], true),
            ],
            'products' => $products,
        ];
    }
}
