<?php
declare(strict_types=1);

namespace Axi\Controllers\Merchant;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Url;
use Axi\Core\Validator;
use Axi\Models\Aisle;
use Axi\Models\Market;
use Axi\Models\Stall;
use Axi\Services\BroadcastService;
use Axi\Services\UploadService;

final class StallController
{
    use MerchantContext;

    /** GET /v1/merchant/stall */
    public function show(Request $request): never
    {
        Response::ok($this->present($this->stall($request)));
    }

    /**
     * POST /v1/merchant/stall (JSON ou multipart avec « photo »)
     * Crée l'étal s'il n'existe pas, sinon modifie les champs envoyés.
     * L'emplacement ne change ensuite que par POST /v1/merchant/stall/move.
     */
    public function save(Request $request): never
    {
        $existing = Stall::findByMerchant($request->userId());
        $required = $existing === null ? 'required|' : '';
        $in = Validator::validate($request->all(), [
            'name' => $required . 'string|max:100',
            'market_id' => $required . 'int|min:1',
            'aisle_id' => $required . 'int|min:1',
            'row_label' => 'string|max:20',
            'stall_number' => 'string|max:20',
            'description' => 'string|max:500',
            'contact_phone' => 'string|phone',
            'whatsapp' => 'string|phone',
            'merchant_type' => 'string|in:retail,wholesale',
            'place_status' => 'string|in:holder,subtenant,no_place',
        ]);
        if (isset($in['aisle_id']) && Aisle::find($in['aisle_id']) === null) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Données invalides', ['fields' => ['aisle_id' => 'allée inconnue']]);
        }
        if (isset($in['market_id']) && !Market::exists($in['market_id'])) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Données invalides', ['fields' => ['market_id' => 'marché inconnu']]);
        }
        if ($existing !== null && (isset($in['market_id']) || isset($in['row_label']) || isset($in['stall_number']))) {
            throw new HttpException(422, 'USE_MOVE_ENDPOINT', 'Pour changer d\'emplacement, utilisez « J\'ai déménagé » (POST /v1/merchant/stall/move)');
        }

        $photo = UploadService::store($request->file('photo'), 'image', 'photo');

        if ($existing === null) {
            Database::insert(
                'INSERT INTO stalls (merchant_id, market_id, aisle_id, name, description, photo_path, row_label, stall_number, contact_phone, whatsapp, merchant_type, place_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $request->userId(), $in['market_id'], $in['aisle_id'], $in['name'], $in['description'] ?? null, $photo,
                    $in['row_label'] ?? null, $in['stall_number'] ?? null, $in['contact_phone'] ?? $request->user['phone'],
                    $in['whatsapp'] ?? null, $in['merchant_type'] ?? 'retail', $in['place_status'] ?? 'holder',
                ]
            );
            Response::ok($this->present(Stall::findByMerchant($request->userId())), 201);
        }

        $fields = array_intersect_key($in, array_flip(Stall::EDITABLE));
        if ($photo !== null) {
            $fields['photo_path'] = $photo;
            UploadService::delete(Stall::findRaw((int) $existing['id'])['photo_path']);
        }
        Database::updateById('stalls', (int) $existing['id'], $fields);
        Response::ok($this->present(Stall::findByMerchant($request->userId())));
    }

    /**
     * POST /v1/merchant/stall/move {market_id, row_label?, stall_number?, message?, notify?}
     * Nouvel emplacement + envoi de la nouvelle adresse à tous les clients et abonnés de l'étal.
     */
    public function move(Request $request): never
    {
        $stall = $this->stall($request);
        $in = Validator::validate($request->all(), [
            'market_id' => 'required|int|min:1',
            'row_label' => 'string|max:20',
            'stall_number' => 'string|max:20',
            'message' => 'string|max:300',
            'notify' => 'bool',
        ]);
        if (!Market::exists($in['market_id'])) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Données invalides', ['fields' => ['market_id' => 'marché inconnu']]);
        }

        $now = Clock::now();
        $moveId = Database::transaction(static function () use ($stall, $in, $now): int {
            Database::updateById('stalls', (int) $stall['id'], [
                'market_id' => $in['market_id'],
                'row_label' => $in['row_label'] ?? null,
                'stall_number' => $in['stall_number'] ?? null,
                'moved_at' => $now,
            ]);
            return Database::insert(
                'INSERT INTO stall_moves (stall_id, from_market_id, from_row, from_number, to_market_id, to_row, to_number, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$stall['id'], $stall['market_id'], $stall['row_label'], $stall['stall_number'], $in['market_id'], $in['row_label'] ?? null, $in['stall_number'] ?? null, $now]
            );
        });

        $updated = Stall::findByMerchant($request->userId());
        $broadcast = null;
        if ($in['notify'] ?? true) {
            $location = Stall::locationLabel($updated['market_name'], $updated['row_label'], $updated['stall_number']);
            $message = $in['message'] ?? "Nous avons déménagé ! Retrouvez-nous désormais à $location. Commandez aussi sur Axi.";
            $broadcast = BroadcastService::send($updated, 'move', $message, null, null, [], true);
            Database::run('UPDATE stall_moves SET recipients_count = ? WHERE id = ?', [$broadcast['app_count'] + $broadcast['sms_count'], $moveId]);
        }

        Response::ok(['stall' => $this->present($updated), 'broadcast' => $broadcast]);
    }

    private function present(array $stall): array
    {
        $raw = Stall::findRaw((int) $stall['id']);
        return Stall::present($stall) + [
            'description' => $stall['description'],
            'contact_phone' => $stall['contact_phone'],
            'whatsapp' => $stall['whatsapp'],
            'place_status' => $stall['place_status'],
            'status' => $stall['status'],
            'verification_photo_url' => Url::asset($raw['verification_photo_path']),
            'followers_count' => Stall::followersCount((int) $stall['id']),
        ];
    }
}
