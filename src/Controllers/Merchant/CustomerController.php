<?php
declare(strict_types=1);

namespace Axi\Controllers\Merchant;

use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Page;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Models\Customer;
use Axi\Models\Product;
use Axi\Models\User;
use Axi\Services\BroadcastService;
use Axi\Services\UploadService;

final class CustomerController
{
    use MerchantContext;

    private const FILTERS = [
        'type' => 'string|in:retail,wholesale',
        'segment' => 'string|in:loyal,inactive,new',
    ];

    /** GET /v1/merchant/customers?type=retail|wholesale&segment=loyal|inactive|new&q=&page= */
    public function index(Request $request): never
    {
        $stall = $this->stall($request);
        $filters = Validator::validate($request->queryAll(), self::FILTERS + ['q' => 'string|max:60']);
        $page = Page::from($request);
        [$items, $total] = Customer::list((int) $stall['id'], $filters, $page);
        Response::ok($items, 200, $page->meta($total, ['counts' => Customer::counts((int) $stall['id'])]));
    }

    /** POST /v1/merchant/customers : ajout d'un client rencontré à l'étal */
    public function create(Request $request): never
    {
        $stall = $this->stall($request);
        $in = Validator::validate($request->all(), [
            'name' => 'required|string|max:100',
            'phone' => 'required|string|phone',
            'type' => 'string|in:retail,wholesale',
            'city' => 'string|max:80',
            'note' => 'string|max:300',
        ]);
        if (Customer::findByPhone((int) $stall['id'], $in['phone']) !== null) {
            throw new HttpException(409, 'CUSTOMER_EXISTS', 'Ce numéro est déjà dans votre fichier clients');
        }
        $user = User::findByPhone($in['phone']);
        $id = Customer::create((int) $stall['id'], $user === null ? null : (int) $user['id'], $in['name'], $in['phone'], $in['type'] ?? 'retail', $in['city'] ?? null, $in['note'] ?? null);
        Response::ok(Customer::present(Customer::find($id, (int) $stall['id'])), 201);
    }

    /** PATCH /v1/merchant/customers/{id} {name?, type?, city?, note?} */
    public function update(Request $request): never
    {
        $stall = $this->stall($request);
        $customer = $this->ownCustomer($request, (int) $stall['id']);
        $in = Validator::validate($request->all(), [
            'name' => 'string|max:100',
            'type' => 'string|in:retail,wholesale',
            'city' => 'string|max:80',
            'note' => 'string|max:300',
        ]);
        if (isset($in['type'])) {
            $in['customer_type'] = $in['type'];
        }
        Database::updateById('merchant_customers', (int) $customer['id'], array_intersect_key($in, array_flip(Customer::EDITABLE)));
        Response::ok(Customer::present(Customer::find((int) $customer['id'], (int) $stall['id'])));
    }

    /** DELETE /v1/merchant/customers/{id} */
    public function delete(Request $request): never
    {
        $stall = $this->stall($request);
        $customer = $this->ownCustomer($request, (int) $stall['id']);
        Database::run('DELETE FROM merchant_customers WHERE id = ?', [$customer['id']]);
        Response::noContent();
    }

    /**
     * POST /v1/merchant/broadcasts : relance ou arrivage (JSON ou multipart avec « audio »)
     * {kind: arrival|reminder, message, product_id?, customer_ids? | type? + segment?}
     */
    public function broadcast(Request $request): never
    {
        $stall = $this->stall($request);
        $in = Validator::validate($request->all(), [
            'kind' => 'required|string|in:arrival,reminder',
            'message' => 'string|max:300',
            'product_id' => 'int|min:1',
            'customer_ids' => 'ids',
        ] + self::FILTERS);
        $audioFile = $request->file('audio');
        if (!isset($in['message']) && $audioFile === null) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Données invalides', ['fields' => ['message' => 'requis (ou envoyez un vocal)']]);
        }
        if (isset($in['product_id']) && Product::findForStall($in['product_id'], (int) $stall['id']) === null) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Données invalides', ['fields' => ['product_id' => 'produit inconnu']]);
        }

        $filters = array_intersect_key($in, self::FILTERS);
        if (isset($in['customer_ids'])) {
            $filters['ids'] = $in['customer_ids'];
        }
        [$where, $params] = Customer::where((int) $stall['id'], $filters);
        if ((int) Database::value('SELECT COUNT(*) FROM merchant_customers c' . $where, $params) === 0) {
            throw new HttpException(422, 'NO_RECIPIENTS', 'Aucun client ne correspond à ce filtre');
        }

        $audio = UploadService::store($audioFile, 'audio', 'audio');
        $message = $in['message'] ?? 'Écoutez le message vocal de ' . $stall['name'];
        Response::ok(BroadcastService::send($stall, $in['kind'], $message, $audio, $in['product_id'] ?? null, $filters, false), 201);
    }

    /** GET /v1/merchant/broadcasts */
    public function broadcasts(Request $request): never
    {
        Response::ok(BroadcastService::list((int) $this->stall($request)['id']));
    }

    private function ownCustomer(Request $request, int $stallId): array
    {
        $customer = Customer::find($request->id(), $stallId);
        if ($customer === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Client introuvable');
        }
        return $customer;
    }
}
