<?php
declare(strict_types=1);

namespace Axi\Controllers\Merchant;

use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Ids;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Services\Broadcaster;
use Axi\Services\Settings;
use Axi\Services\Uploads;
use Axi\Support\Merchant;
use Axi\Support\Subscription;
use Axi\Support\Stalls;

/**
 * « Mes clients » : le fichier clients appartient au commerçant (issu des commandes et des ajouts manuels).
 * Relances et messages par notification Axi, email et lien WhatsApp ; jamais par SMS.
 */
final class ClientsController
{
    private const LABELS = ['display_name' => 'Nom', 'phone' => 'Téléphone', 'email' => 'Email', 'kind' => 'Type de client', 'segment' => 'Clients', 'note' => 'Note'];

    public function index(Request $req): never
    {
        $stall = Merchant::stall($req);
        $segment = (string) $req->query('segment', 'all');
        if (!in_array($segment, ['all', 'resellers', 'loyal', 'to_follow_up'], true)) {
            Validator::fail(['segment' => 'Valeur non autorisée.'], self::LABELS);
        }
        [$where, $params] = $this->segmentSql($segment);
        $search = trim((string) $req->query('q', ''));
        if ($search !== '') {
            $where .= ' AND (display_name LIKE ? OR phone LIKE ? OR email LIKE ?)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        $rows = Database::all("SELECT * FROM merchant_clients WHERE stall_id = ? AND $where ORDER BY COALESCE(last_order_at, created_at) DESC LIMIT 500", [$stall['id'], ...$params]);

        [$loyalWhere, $loyalParams] = $this->segmentSql('loyal');
        $hasLoyal = Database::value("SELECT 1 FROM merchant_clients WHERE stall_id = ? AND $loyalWhere LIMIT 1", [$stall['id'], ...$loyalParams]) !== null;

        Response::ok([
            'arrival_prompt' => $hasLoyal ? [
                'title' => 'Nouvel arrivage ?',
                'text' => 'Prévenez vos clients fidèles en un message, par Axi ou par WhatsApp.',
                'button_label' => 'Envoyer à mes clients fidèles',
            ] : null,
            'clients' => array_map([$this, 'present'], $rows),
        ], 200, 60);
    }

    /** Fiche client : coordonnées, note, historique de ses commandes chez ce commerçant */
    public function show(Request $req): never
    {
        $stall = Merchant::stall($req);
        $client = $this->find($req, (int) $stall['id']);
        $orders = $client['user_id'] === null ? [] : Database::all(
            'SELECT o.id, o.created_at, os.items_total, os.status, os.escrow_state FROM order_stalls os JOIN orders o ON o.id = os.order_id
             WHERE os.stall_id = ? AND o.client_id = ? AND o.status <> \'awaiting_payment\' ORDER BY o.id DESC LIMIT 100',
            [$stall['id'], $client['user_id']],
        );
        Response::ok($this->present($client) + [
            'email' => $client['email'],
            'kind' => $client['kind'],
            'note' => $client['note'],
            'orders_count' => (int) $client['orders_count'],
            'total_spent' => (int) $client['total_spent'],
            'has_axi_account' => $client['user_id'] !== null,
            'orders' => array_map(static function (array $o): array {
                $items = Database::all('SELECT oi.name, oi.quantity FROM order_items oi JOIN order_stalls os ON os.id = oi.order_stall_id WHERE os.order_id = ?', [$o['id']]);
                return [
                    'id' => Ids::encode('cmd', $o['id']),
                    'date' => Clock::iso($o['created_at']),
                    'amount' => (int) $o['items_total'],
                    'summary' => implode(', ', array_map(static fn (array $i): string => $i['quantity'] . ' × ' . $i['name'], $items)),
                    'status' => $o['status'],
                    'escrow_state' => $o['escrow_state'],
                ];
            }, $orders),
        ]);
    }

    public function create(Request $req): never
    {
        $stall = Merchant::stall($req);
        $in = Validator::validate($req->all(), [
            'display_name' => 'required|string|max:100',
            'phone' => 'phone',
            'email' => 'email',
            'kind' => 'required|in:individual,reseller',
            'note' => 'string|max:300',
        ], self::LABELS);
        if (!isset($in['phone']) && !isset($in['email'])) {
            Validator::fail(['phone' => 'Indiquez un téléphone ou un email.'], self::LABELS);
        }
        if (isset($in['phone']) && Database::value('SELECT id FROM merchant_clients WHERE stall_id = ? AND phone = ?', [$stall['id'], $in['phone']]) !== null) {
            throw new HttpException(409, 'CLIENT_EXISTS', 'Ce numéro est déjà dans votre fichier clients.');
        }
        // Rattachement au compte Axi du client s'il en a un (même email ou même téléphone)
        $userId = Database::value('SELECT id FROM users WHERE status = \'active\' AND ((email = ?) OR (phone IS NOT NULL AND phone = ?))', [$in['email'] ?? '', $in['phone'] ?? '']);
        if ($userId !== null && Database::value('SELECT id FROM merchant_clients WHERE stall_id = ? AND user_id = ?', [$stall['id'], $userId]) !== null) {
            throw new HttpException(409, 'CLIENT_EXISTS', 'Ce client est déjà dans votre fichier clients.');
        }
        $id = Database::insert(
            'INSERT INTO merchant_clients (stall_id, user_id, display_name, phone, email, kind, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$stall['id'], $userId, $in['display_name'], $in['phone'] ?? null, $in['email'] ?? null, $in['kind'], $in['note'] ?? null, Clock::now()],
        );
        Response::ok($this->present(Database::one('SELECT * FROM merchant_clients WHERE id = ?', [$id])), 201);
    }

    public function update(Request $req): never
    {
        $stall = Merchant::stall($req);
        $client = $this->find($req, (int) $stall['id']);
        $body = $req->all();
        $in = Validator::validate($body, [
            'display_name' => 'string|max:100',
            'phone' => 'phone',
            'email' => 'email',
            'kind' => 'in:individual,reseller',
            'note' => 'string|max:300',
        ], self::LABELS);
        foreach (['phone', 'email', 'note'] as $key) {
            if (array_key_exists($key, $body) && ($body[$key] === null || $body[$key] === '')) {
                $in[$key] = null;
            }
        }
        if (isset($in['phone']) && Database::value('SELECT id FROM merchant_clients WHERE stall_id = ? AND phone = ? AND id <> ?', [$stall['id'], $in['phone'], $client['id']]) !== null) {
            throw new HttpException(409, 'CLIENT_EXISTS', 'Ce numéro est déjà dans votre fichier clients.');
        }
        if ($in !== []) {
            Database::updateById('merchant_clients', (int) $client['id'], $in);
        }
        Response::ok($this->present(Database::one('SELECT * FROM merchant_clients WHERE id = ?', [$client['id']])));
    }

    /** Retirer un client de son fichier (confirmation demandée dans l'application) */
    public function delete(Request $req): never
    {
        $stall = Merchant::stall($req);
        $client = $this->find($req, (int) $stall['id']);
        Database::run('DELETE FROM merchant_clients WHERE id = ?', [$client['id']]);
        Response::ok(['deleted' => true]);
    }

    public function followUp(Request $req): never
    {
        $stall = Merchant::stall($req);
        Subscription::require((int) $stall['id'], 'La relance d\'un client');
        $client = $this->find($req, (int) $stall['id']);
        $result = Broadcaster::send($stall, 'follow_up', null, $stall['name'] . ' pense à vous', 'De nouveaux produits vous attendent chez ' . $stall['name'] . ' (' . Stalls::location($stall)['label'] . '). Commandez sur Axi et payez à la réception.', [$client]);
        Database::run('UPDATE merchant_clients SET last_follow_up_at = ? WHERE id = ?', [Clock::now(), $client['id']]);
        Response::ok($result);
    }

    public function broadcast(Request $req): never
    {
        $stall = Merchant::stall($req);
        Subscription::require((int) $stall['id'], 'Le message groupé');
        $in = Validator::validate($req->all(), [
            'segment' => 'required|in:all,resellers,loyal,to_follow_up',
            'channel' => 'in:axi,whatsapp',
            'text' => 'string|max:400',
        ], self::LABELS);
        $text = $in['text'] ?? 'Nouvel arrivage chez ' . $stall['name'] . ' (' . Stalls::location($stall)['label'] . '). Commandez sur Axi.';
        Response::ok(Broadcaster::send($stall, 'segment', $in['segment'], 'Nouvel arrivage chez ' . $stall['name'], $text, $this->clients((int) $stall['id'], $in['segment'])));
    }

    /** « Envoyer ma nouvelle adresse » à tout le fichier clients */
    public function relocation(Request $req): never
    {
        $stall = Merchant::stall($req);
        Subscription::require((int) $stall['id'], 'L\'envoi de votre nouvelle adresse');
        $location = Stalls::location($stall);
        $text = $stall['name'] . ' vous accueille à sa nouvelle adresse : ' . $location['label'] . '.' . ($location['map_url'] !== null ? ' Plan : ' . $location['map_url'] : '');
        $result = Broadcaster::send($stall, 'relocation', 'all', 'Nouvelle adresse de ' . $stall['name'], $text, $this->clients((int) $stall['id'], 'all'));
        if ($result['recipients'] > 0) {
            Database::run('UPDATE stalls SET relocation_sent_at = ? WHERE id = ?', [Clock::now(), $stall['id']]);
        }
        Response::ok($result);
    }

    /** « Annoncer un arrivage en vocal » aux clients fidèles */
    public function arrival(Request $req): never
    {
        $stall = Merchant::stall($req);
        Subscription::require((int) $stall['id'], 'L\'annonce d\'arrivage');
        $audio = $req->file('audio') ?? Validator::fail(['audio' => 'Enregistrez votre message vocal.'], ['audio' => 'Message vocal']);
        Response::ok(Broadcaster::send($stall, 'arrival', 'loyal', 'Arrivage chez ' . $stall['name'], 'Écoutez le message vocal de ' . $stall['name'] . ' sur ses nouveaux produits.', $this->clients((int) $stall['id'], 'loyal'), Uploads::audio($audio)));
    }

    private function find(Request $req, int $stallId): array
    {
        $id = $req->id('cli', 'CLIENT_NOT_FOUND', 'Ce client n\'est pas dans votre fichier.');
        return Database::one('SELECT * FROM merchant_clients WHERE id = ? AND stall_id = ?', [$id, $stallId])
            ?? throw new HttpException(404, 'CLIENT_NOT_FOUND', 'Ce client n\'est pas dans votre fichier.');
    }

    private function clients(int $stallId, string $segment): array
    {
        [$where, $params] = $this->segmentSql($segment);
        return Database::all("SELECT * FROM merchant_clients WHERE stall_id = ? AND $where", [$stallId, ...$params]);
    }

    /** @return array{0: string, 1: array} */
    private function segmentSql(string $segment): array
    {
        return match ($segment) {
            'resellers' => ['kind = \'reseller\'', []],
            'loyal' => ['orders_count >= ?', [Settings::int('loyal_min_orders')]],
            'to_follow_up' => ['last_order_at < ?', [Clock::now(-86400 * Settings::int('follow_up_days'))]],
            default => ['1', []],
        };
    }

    private function present(array $c): array
    {
        $followUpBefore = Clock::now(-86400 * Settings::int('follow_up_days'));
        $newAfter = Clock::now(-86400 * 7);
        $badge = match (true) {
            $c['last_order_at'] !== null && $c['last_order_at'] < $followUpBefore => ['kind' => 'follow_up', 'label' => 'Relancer'],
            (int) $c['orders_count'] <= 1 && max($c['created_at'], $c['last_order_at'] ?? '') >= $newAfter => ['kind' => 'new', 'label' => 'Nouveau'],
            (int) $c['orders_count'] >= Settings::int('loyal_min_orders') => ['kind' => 'loyal', 'label' => 'Fidèle'],
            default => null,
        };
        $kind = $c['kind'] === 'reseller' ? 'Revendeur' : 'Particulier';
        $when = $c['last_order_at'] !== null ? 'dernier achat ' . self::ago($c['last_order_at']) : 'ajouté ' . self::ago($c['created_at']);
        return [
            'id' => Ids::encode('cli', $c['id']),
            'display_name' => $c['display_name'],
            'description' => "$kind · $when",
            'phone' => $c['phone'],
            'email' => $c['email'],
            'badge' => $badge,
        ];
    }

    /** « aujourd'hui », « hier », « il y a 5 jours », « il y a 6 semaines », « il y a 3 mois » (heure du Bénin) */
    private static function ago(string $datetime): string
    {
        $zone = new \DateTimeZone((string) Config::get('timezone'));
        $then = (new \DateTimeImmutable($datetime, new \DateTimeZone('UTC')))->setTimezone($zone)->setTime(0, 0);
        $days = (int) $then->diff(new \DateTimeImmutable('today', $zone))->days;
        return match (true) {
            $days === 0 => 'aujourd\'hui',
            $days === 1 => 'hier',
            $days < 14 => "il y a $days jours",
            $days < 60 => 'il y a ' . intdiv($days, 7) . ' semaines',
            default => 'il y a ' . intdiv($days, 30) . ' mois',
        };
    }
}
