<?php
declare(strict_types=1);

namespace Axi\Controllers\Client;

use Axi\Core\Config;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Models\Aisle;
use Axi\Models\DeliveryOption;
use Axi\Models\Follow;
use Axi\Models\Market;
use Axi\Models\Product;
use Axi\Models\Promotion;
use Axi\Models\Stall;

final class HomeController
{
    /** GET /v1/home : écran Accueil en un seul appel */
    public function home(Request $request): never
    {
        $in = Validator::validate($request->queryAll(), ['market_id' => 'int|min:1']);
        $marketId = $in['market_id'] ?? null;

        Response::ok([
            'markets' => Market::all(),
            'aisles' => Aisle::allWithCounts($marketId),
            'followed_stalls' => $request->user === null ? [] : Follow::stallsFor($request->userId()),
            'promotions' => Promotion::live('home', $marketId === null ? [] : ['market_id' => $marketId]),
            'partner_notice' => Config::get('anagem_partnership')
                ? ['partner' => 'ANaGeM', 'text' => 'Service en ligne des marchés, en partenariat avec l\'ANaGeM']
                : null,
        ]);
    }

    /** GET /v1/markets */
    public function markets(Request $request): never
    {
        Response::ok(Market::all());
    }

    /** GET /v1/aisles?market_id= */
    public function aisles(Request $request): never
    {
        $in = Validator::validate($request->queryAll(), ['market_id' => 'int|min:1']);
        Response::ok(Aisle::allWithCounts($in['market_id'] ?? null));
    }

    /** GET /v1/search?q=&market_id= */
    public function search(Request $request): never
    {
        $in = Validator::validate($request->queryAll(), ['q' => 'required|string|max:60', 'market_id' => 'int|min:1']);
        if (!preg_match('/^.{2,}$/u', $in['q'])) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Données invalides', ['fields' => ['q' => '2 caractères minimum']]);
        }
        Response::ok([
            'stalls' => Stall::search($in['q'], $in['market_id'] ?? null, 10),
            'products' => Product::search($in['q'], $in['market_id'] ?? null, 20),
        ]);
    }

    /** GET /v1/delivery-options */
    public function deliveryOptions(Request $request): never
    {
        Response::ok(DeliveryOption::all());
    }

    /** POST /v1/promotions/{id}/click : compte le clic et renvoie la cible */
    public function promotionClick(Request $request): never
    {
        $promo = Promotion::click($request->id());
        if ($promo === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Promotion introuvable');
        }
        Response::ok($promo);
    }
}
