<?php
declare(strict_types=1);

namespace Axi\Controllers\Pro;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Url;
use Axi\Core\Validator;
use Axi\Models\Market;
use Axi\Services\ProAnalytics;
use Axi\Services\UploadService;

/** Axi Pro : espace web des partenaires. Données agrégées et anonymes seulement. */
final class ProController
{
    /** GET /v1/pro/census?market_id= */
    public function census(Request $request): never
    {
        $in = Validator::validate($request->queryAll(), ['market_id' => 'int|min:1']);
        Response::ok(ProAnalytics::census($in['market_id'] ?? null));
    }

    /** GET /v1/pro/prices?market_id=&aisle_id=&catalog_item_id= */
    public function prices(Request $request): never
    {
        $in = Validator::validate($request->queryAll(), ['market_id' => 'int|min:1', 'aisle_id' => 'int|min:1', 'catalog_item_id' => 'int|min:1']);
        Response::ok(ProAnalytics::prices($in['market_id'] ?? null, $in['aisle_id'] ?? null, $in['catalog_item_id'] ?? null));
    }

    /** GET /v1/pro/trends?weeks=8&market_id= */
    public function trends(Request $request): never
    {
        $in = Validator::validate($request->queryAll(), ['weeks' => 'int|min:1|maxval:52', 'market_id' => 'int|min:1']);
        Response::ok(ProAnalytics::trends($in['weeks'] ?? 8, $in['market_id'] ?? null));
    }

    /** GET /v1/pro/credits */
    public function credits(Request $request): never
    {
        Response::ok(ProAnalytics::credits());
    }

    /** GET /v1/pro/catalog-items : référentiel des produits comparables */
    public function catalogItems(Request $request): never
    {
        Response::ok(array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'aisle_id' => (int) $r['aisle_id'],
            'name' => $r['name'],
            'unit' => $r['unit'],
        ], Database::all('SELECT id, aisle_id, name, unit FROM catalog_items ORDER BY aisle_id, name')));
    }

    /** GET /v1/pro/campaigns : campagnes du partenaire avec leurs statistiques */
    public function campaigns(Request $request): never
    {
        Response::ok(array_map(
            [self::class, 'presentCampaign'],
            Database::all('SELECT * FROM promotions WHERE owner_id = ? ORDER BY id DESC LIMIT 100', [$request->userId()])
        ));
    }

    /**
     * POST /v1/pro/campaigns (JSON ou multipart avec « image »)
     * Campagne ciblée vers les clients (home) ou les commerçants (merchant). Validée par Axi avant diffusion.
     */
    public function createCampaign(Request $request): never
    {
        $in = Validator::validate($request->all(), [
            'sponsor_name' => 'required|string|max:100',
            'title' => 'required|string|max:120',
            'body' => 'string|max:300',
            'placement' => 'required|string|in:home,merchant',
            'target_type' => 'string|in:none,aisle,stall,product,url',
            'target_id' => 'int|min:1',
            'target_url' => 'string|max:255',
            'audience_market_id' => 'int|min:1',
            'audience_aisle_id' => 'int|min:1',
            'audience_merchant_type' => 'string|in:retail,wholesale',
            'starts_on' => 'required|string|date',
            'ends_on' => 'required|string|date',
            'budget' => 'int|min:0',
        ]);
        $errors = [];
        if ($in['ends_on'] < $in['starts_on']) {
            $errors['ends_on'] = 'doit être après la date de début';
        }
        $targetType = $in['target_type'] ?? 'none';
        if (in_array($targetType, ['aisle', 'stall', 'product'], true) && !isset($in['target_id'])) {
            $errors['target_id'] = 'requis pour ce type de cible';
        }
        if ($targetType === 'url' && !preg_match('#^https://#', $in['target_url'] ?? '')) {
            $errors['target_url'] = 'adresse https:// requise';
        }
        if (isset($in['audience_market_id']) && !Market::exists($in['audience_market_id'])) {
            $errors['audience_market_id'] = 'marché inconnu';
        }
        if ($errors) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Données invalides', ['fields' => $errors]);
        }

        $image = UploadService::store($request->file('image'), 'image', 'image');
        $id = Database::insert(
            'INSERT INTO promotions (owner_id, sponsor_name, title, body, image_path, placement, target_type, target_id, target_url,
                audience_market_id, audience_aisle_id, audience_merchant_type, starts_at, ends_at, budget, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $request->userId(), $in['sponsor_name'], $in['title'], $in['body'] ?? null, $image, $in['placement'], $targetType,
                $in['target_id'] ?? null, $targetType === 'url' ? $in['target_url'] : null,
                $in['audience_market_id'] ?? null, $in['audience_aisle_id'] ?? null, $in['audience_merchant_type'] ?? null,
                Clock::localDateStart($in['starts_on']),
                Clock::addDays(Clock::localDateStart($in['ends_on']), 1),
                $in['budget'] ?? null, Clock::now(),
            ]
        );
        Response::ok(self::presentCampaign(Database::one('SELECT * FROM promotions WHERE id = ?', [$id])), 201);
    }

    public static function presentCampaign(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'sponsor' => $r['sponsor_name'],
            'title' => $r['title'],
            'body' => $r['body'],
            'image_url' => Url::asset($r['image_path']),
            'placement' => $r['placement'],
            'target' => ['type' => $r['target_type'], 'id' => $r['target_id'] === null ? null : (int) $r['target_id'], 'url' => $r['target_url']],
            'audience' => [
                'market_id' => $r['audience_market_id'] === null ? null : (int) $r['audience_market_id'],
                'aisle_id' => $r['audience_aisle_id'] === null ? null : (int) $r['audience_aisle_id'],
                'merchant_type' => $r['audience_merchant_type'],
            ],
            'starts_at' => Clock::iso($r['starts_at']),
            'ends_at' => Clock::iso($r['ends_at']),
            'budget' => $r['budget'] === null ? null : (int) $r['budget'],
            'status' => $r['status'],
            'stats' => ['impressions' => (int) $r['impressions'], 'clicks' => (int) $r['clicks']],
        ];
    }
}
