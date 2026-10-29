<?php
declare(strict_types=1);

namespace Axi\Controllers\Admin;

use Axi\Core\Clock;
use Axi\Core\Cursor;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Ids;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Services\AdminLog;
use Axi\Services\Products;
use Axi\Services\StallInput;
use Axi\Support\Stalls;
use Axi\Support\Users;

/**
 * Agents Axi et administrateurs. Un client ne devient commerçant que par un agent, après vérification
 * de l'étal sur place. Chaque action est journalisée dans admin_actions.
 */
final class AdminController
{
    private const PER_PAGE = 30;
    private const MERCHANT_NOT_FOUND = 'Ce commerçant n\'existe pas.';

    /** Recherche d'un compte par email, téléphone ou nom */
    public function users(Request $req): never
    {
        $search = trim((string) $req->query('search', ''));
        if (mb_strlen($search) < 2) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Tapez au moins 2 caractères.', ['fields' => ['search' => 'Tapez au moins 2 caractères.']]);
        }
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $digits = preg_replace('/\D/', '', $search);
        $rows = Database::all(
            'SELECT u.*, s.id AS stall_id FROM users u LEFT JOIN stalls s ON s.user_id = u.id
             WHERE u.email LIKE ? OR u.name LIKE ? OR (? <> \'\' AND REPLACE(u.phone, \'+\', \'\') LIKE ?)
             ORDER BY u.id DESC LIMIT 20',
            [$like, $like, $digits, '%' . $digits . '%'],
        );
        Response::ok(['users' => array_map(static fn (array $u): array => Users::present($u) + [
            'status' => $u['status'],
            'stall_id' => Ids::encode('stl', $u['stall_id']),
            'created_at' => Clock::iso($u['created_at']),
        ], $rows)]);
    }

    /** Demandes « Demander la visite d'un agent » encore ouvertes */
    public function visits(Request $req): never
    {
        $rows = Database::all(
            'SELECT v.*, u.name, u.phone, u.email FROM agent_visit_requests v JOIN users u ON u.id = v.user_id
             WHERE v.status IN (\'new\', \'scheduled\') ORDER BY v.id LIMIT 100',
        );
        Response::ok(['visits' => array_map(static fn (array $v): array => [
            'id' => Ids::encode('vis', $v['id']),
            'user_id' => Ids::encode('usr', $v['user_id']),
            'name' => $v['name'],
            'phone' => $v['phone'],
            'email' => $v['email'],
            'status' => $v['status'],
            'created_at' => Clock::iso($v['created_at']),
        ], $rows)]);
    }

    /** Passe un client en commerçant et crée son étal, vérifié par l'agent connecté */
    public function createMerchant(Request $req): never
    {
        $userId = Ids::tryDecode('usr', $req->input('user_id'))
            ?? Validator::fail(['user_id' => 'Choisissez le compte du commerçant.'], ['user_id' => 'Compte']);
        $stallData = $req->input('stall');
        if (!is_array($stallData)) {
            Validator::fail(['stall' => 'Les informations de l\'étal sont obligatoires.'], ['stall' => 'Étal']);
        }
        $columns = StallInput::validate($stallData, true);

        $user = Users::find($userId) ?? throw new HttpException(404, 'USER_NOT_FOUND', 'Ce compte n\'existe pas.');
        if ($user['role'] === 'merchant') {
            throw new HttpException(409, 'ALREADY_MERCHANT', 'Ce compte est déjà commerçant.');
        }
        if ($user['role'] !== 'client') {
            throw new HttpException(409, 'ROLE_CONFLICT', 'Seul un compte client peut devenir commerçant.');
        }
        if ($user['status'] !== 'active') {
            throw new HttpException(409, 'ACCOUNT_SUSPENDED', 'Ce compte est suspendu.');
        }

        $stallId = Database::transaction(function () use ($req, $userId, $user, $columns): int {
            $now = Clock::now();
            $columns += ['phone' => $user['phone'], 'whatsapp' => $user['phone']];
            $columns += ['user_id' => $userId, 'verified_at' => $now, 'verified_by' => $req->userId(), 'created_at' => $now, 'updated_at' => $now];
            $stallId = Database::insert(
                'INSERT INTO stalls (' . implode(', ', array_keys($columns)) . ') VALUES (' . Database::in($columns) . ')',
                array_values($columns),
            );
            Database::run('UPDATE users SET role = \'merchant\' WHERE id = ?', [$userId]);
            Database::run('UPDATE agent_visit_requests SET status = \'done\', assigned_agent_id = ? WHERE user_id = ? AND status IN (\'new\', \'scheduled\')', [$req->userId(), $userId]);
            AdminLog::record($req, 'merchant.create', 'stall', $stallId, ['user_id' => $userId] + $columns);
            return $stallId;
        });

        Response::ok($this->merchantPayload($stallId), 201);
    }

    public function merchants(Request $req): never
    {
        $where = ['1'];
        $params = [];
        foreach (['site_id' => 's.site_id', 'aisle_id' => 's.aisle_id'] as $key => $column) {
            $value = trim((string) $req->query($key, ''));
            if ($value !== '') {
                $where[] = "$column = ?";
                $params[] = $value;
            }
        }
        $search = trim((string) $req->query('search', ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(s.name LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR s.stand LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $offset = Cursor::offset($req->query('cursor'));
        $rows = Stalls::query(implode(' AND ', $where), $params, 'ORDER BY s.id DESC LIMIT ' . (self::PER_PAGE + 1) . ' OFFSET ' . $offset);

        Response::ok([
            'merchants' => array_map(fn (array $s): array => [
                'user' => Users::presentById((int) $s['user_id']),
                'stall' => Stalls::detail($s, null),
            ], array_slice($rows, 0, self::PER_PAGE)),
            'next_cursor' => Cursor::next($offset, self::PER_PAGE, count($rows)),
        ]);
    }

    public function merchant(Request $req): never
    {
        Response::ok($this->merchantPayload($this->stallIdFromParam($req)));
    }

    /**
     * { stall: {...}, status?: 'active'|'suspended' }. Un changement de site, rangée ou stand vaut
     * déménagement (moved_at) et nouvelle vérification par l'agent connecté. La suspension est réservée aux admins.
     */
    public function updateMerchant(Request $req): never
    {
        $stallId = $this->stallIdFromParam($req);
        $stall = Database::one('SELECT * FROM stalls WHERE id = ?', [$stallId]);
        $stallData = $req->input('stall', []);
        $columns = is_array($stallData) ? StallInput::validate($stallData, false) : [];
        $status = Validator::validate($req->all(), ['status' => 'in:active,suspended,closed'], ['status' => 'Statut'])['status'] ?? null;
        if ($status !== null && $req->role() !== 'admin') {
            throw new HttpException(403, 'FORBIDDEN', 'Seul un administrateur peut suspendre ou fermer un compte.');
        }

        Database::transaction(function () use ($req, $stall, $columns, $status): void {
            $now = Clock::now();
            $moved = false;
            foreach (['site_id', 'row_label', 'stand'] as $key) {
                $moved = $moved || (array_key_exists($key, $columns) && $columns[$key] !== $stall[$key]);
            }
            if ($moved) {
                $columns += ['moved_at' => $now, 'relocation_sent_at' => null];
            }
            if ($columns !== []) {
                Database::updateById('stalls', (int) $stall['id'], $columns + ['verified_at' => $now, 'verified_by' => $req->userId(), 'updated_at' => $now]);
            }
            if ($status === 'closed') {
                Database::updateById('stalls', (int) $stall['id'], ['status' => 'closed']);
            } elseif ($status !== null) {
                Database::updateById('users', (int) $stall['user_id'], ['status' => $status]);
                Database::updateById('stalls', (int) $stall['id'], ['status' => 'active']);
            }
            AdminLog::record($req, 'merchant.update', 'stall', (int) $stall['id'], $columns + ($status === null ? [] : ['status' => $status]));
        });

        Response::ok($this->merchantPayload($stallId));
    }

    /** L'agent saisit les premiers produits à la place du commerçant (même format que /v1/merchant/products) */
    public function createProduct(Request $req): never
    {
        $stallId = $req->id('stl', 'STALL_NOT_FOUND', 'Cet étal n\'existe pas.');
        if (Database::value('SELECT id FROM stalls WHERE id = ?', [$stallId]) === null) {
            throw new HttpException(404, 'STALL_NOT_FOUND', 'Cet étal n\'existe pas.');
        }
        $product = Products::create($stallId, $req, $req->userId());
        AdminLog::record($req, 'product.create', 'product', Ids::tryDecode('prd', $product['id']), ['stall_id' => $stallId, 'name' => $product['name'], 'price' => $product['price']]);
        Response::ok($product, 201);
    }

    /**
     * L'agent ou le coursier a récupéré la part d'un étal : { "stall_id": "stl_1" }.
     * Sans stall_id, toutes les parts prêtes de la commande sont marquées récupérées.
     */
    public function pickup(Request $req): never
    {
        $orderId = $req->id('cmd', 'ORDER_NOT_FOUND', 'Commande introuvable.');
        $order = Database::one('SELECT status FROM orders WHERE id = ?', [$orderId])
            ?? throw new HttpException(404, 'ORDER_NOT_FOUND', 'Commande introuvable.');
        if (in_array($order['status'], ['awaiting_payment', 'cancelled', 'delivered'], true)) {
            throw new HttpException(409, 'ORDER_NOT_PREPARABLE', 'Cette commande ne peut pas être récupérée.');
        }
        $stallId = $req->input('stall_id') === null ? null : Ids::decode('stl', $req->input('stall_id'), 'STALL_NOT_FOUND', 'Cet étal ne fait pas partie de la commande.');
        $updated = Database::run(
            'UPDATE order_stalls SET status = \'picked_up\', picked_up_at = ? WHERE order_id = ? AND status IN (\'to_prepare\', \'ready\')'
            . ($stallId === null ? ' AND status = \'ready\'' : ' AND stall_id = ?'),
            $stallId === null ? [Clock::now(), $orderId] : [Clock::now(), $orderId, $stallId],
        )->rowCount();
        \Axi\Services\Orders::syncStatus($orderId);
        AdminLog::record($req, 'order.pickup', 'order', $orderId, ['stall_id' => $stallId, 'parts' => $updated]);
        Response::ok([
            'status' => Database::value('SELECT status FROM orders WHERE id = ?', [$orderId]),
            'picked_up' => $updated,
        ]);
    }

    /** {id} accepte l'étal (stl_…) ou le compte du commerçant (usr_…) */
    private function stallIdFromParam(Request $req): int
    {
        $param = $req->params['id'] ?? '';
        $stallId = Ids::tryDecode('stl', $param);
        if ($stallId === null && ($userId = Ids::tryDecode('usr', $param)) !== null) {
            $stallId = (int) Database::value('SELECT id FROM stalls WHERE user_id = ?', [$userId]) ?: null;
        }
        if ($stallId === null || Database::value('SELECT id FROM stalls WHERE id = ?', [$stallId]) === null) {
            throw new HttpException(404, 'MERCHANT_NOT_FOUND', self::MERCHANT_NOT_FOUND);
        }
        return $stallId;
    }

    /** L'agent voit aussi les étals fermés */
    private function merchantPayload(int $stallId): array
    {
        $stall = Stalls::findActive($stallId) ?? Database::one(
            'SELECT s.*, si.name AS site_name, si.map_url AS site_map_url, u.name AS owner_name, 0 AS has_new
             FROM stalls s JOIN sites si ON si.id = s.site_id JOIN users u ON u.id = s.user_id WHERE s.id = ?',
            [$stallId],
        );
        $user = Users::find((int) $stall['user_id']);
        return [
            'user' => Users::present($user) + ['status' => $user['status']],
            'stall' => Stalls::detail($stall, null) + ['status' => $stall['status'], 'merchant_type' => $stall['merchant_type'], 'site_id' => $stall['site_id'], 'has_physical_place' => (bool) $stall['has_physical_place']],
            'products' => array_map([Stalls::class, 'product'], Stalls::products($stallId)),
        ];
    }
}
