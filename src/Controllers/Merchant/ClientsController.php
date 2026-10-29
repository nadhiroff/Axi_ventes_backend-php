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
use Axi\Services\Broadcasts;
use Axi\Services\Uploads;
use Axi\Support\Merchant;

/** « Mes clients » : le fichier clients appartient au commerçant ; relances et messages groupés */
final class ClientsController
{
    private const LABELS = ['display_name' => 'Nom', 'phone' => 'Téléphone', 'kind' => 'Type de client', 'segment' => 'Clients', 'channel' => 'Canal'];

    public function index(Request $req): never
    {
        $stall = Merchant::stall($req);
        $segment = (string) $req->query('segment', 'all');
        if (!in_array($segment, ['all', 'resellers', 'loyal', 'to_follow_up'], true)) {
            Validator::fail(['segment' => 'Valeur non autorisée.'], self::LABELS);
        }
        [$where, $params] = $this->segmentSql($segment);
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

    public function create(Request $req): never
    {
        $stall = Merchant::stall($req);
        $in = Validator::validate($req->all(), [
            'display_name' => 'required|string|max:100',
            'phone' => 'required|phone',
            'kind' => 'required|in:individual,reseller',
        ], self::LABELS);
        if (Database::value('SELECT id FROM merchant_clients WHERE stall_id = ? AND phone = ?', [$stall['id'], $in['phone']]) !== null) {
            throw new HttpException(409, 'CLIENT_EXISTS', 'Ce numéro est déjà dans votre fichier clients.');
        }
        $userId = Database::value('SELECT id FROM users WHERE phone = ?', [$in['phone']]);
        $id = Database::insert(
            'INSERT INTO merchant_clients (stall_id, user_id, display_name, phone, kind, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$stall['id'], $userId, $in['display_name'], $in['phone'], $in['kind'], Clock::now()],
        );
        Response::ok($this->present(Database::one('SELECT * FROM merchant_clients WHERE id = ?', [$id])), 201);
    }

    public function followUp(Request $req): never
    {
        $stall = Merchant::stall($req);
        $id = $req->id('cli', 'CLIENT_NOT_FOUND', 'Ce client n\'est pas dans votre fichier.');
        $client = Database::one('SELECT * FROM merchant_clients WHERE id = ? AND stall_id = ?', [$id, $stall['id']])
            ?? throw new HttpException(404, 'CLIENT_NOT_FOUND', 'Ce client n\'est pas dans votre fichier.');
        Broadcasts::record((int) $stall['id'], 'follow_up', 'axi', null, $stall['name'] . ' pense à vous : de nouveaux produits vous attendent sur Axi.', [$client['phone']]);
        Database::run('UPDATE merchant_clients SET last_follow_up_at = ? WHERE id = ?', [Clock::now(), $id]);
        Response::ok(['recipients' => 1, 'message' => 'Relance envoyée à ' . $client['display_name'] . '.']);
    }

    public function broadcast(Request $req): never
    {
        $stall = Merchant::stall($req);
        $in = Validator::validate($req->all(), [
            'segment' => 'required|in:all,resellers,loyal,to_follow_up',
            'channel' => 'required|in:axi,whatsapp',
        ], self::LABELS);
        $phones = $this->phones((int) $stall['id'], $in['segment']);
        $n = Broadcasts::record((int) $stall['id'], 'segment', $in['channel'], $in['segment'], 'Nouvel arrivage chez ' . $stall['name'] . ' : ' . Config::get('city.name') . ', ' . $stall['site_name'] . '.', $phones);
        $who = $in['segment'] === 'loyal' ? Merchant::plural($n, 'client fidèle', 'clients fidèles') : Merchant::plural($n, 'client');
        Response::ok([
            'recipients' => $n,
            'message' => $n === 0 ? 'Aucun client dans cette liste pour l\'instant.'
                : ($in['channel'] === 'whatsapp' ? "Message prêt à partager sur WhatsApp avec vos $who." : "Message envoyé à vos $who."),
        ]);
    }

    /** « Envoyer ma nouvelle adresse » à tout le fichier clients */
    public function relocation(Request $req): never
    {
        $stall = Merchant::stall($req);
        $location = \Axi\Support\Stalls::location($stall);
        $phones = $this->phones((int) $stall['id'], 'all');
        $text = $stall['name'] . ' a déménagé. Nouvelle adresse : ' . $location['label'] . '.' . ($location['map_url'] !== null ? ' Plan : ' . $location['map_url'] : '');
        $n = Broadcasts::record((int) $stall['id'], 'relocation', 'axi', 'all', $text, $phones);
        if ($n > 0) {
            Database::run('UPDATE stalls SET relocation_sent_at = ? WHERE id = ?', [Clock::now(), $stall['id']]);
        }
        Response::ok([
            'recipients' => $n,
            'message' => $n === 0 ? 'Ajoutez d\'abord vos clients pour leur envoyer votre nouvelle adresse.' : 'Nouvelle adresse envoyée à ' . Merchant::plural($n, 'client') . '.',
        ]);
    }

    /** « Annoncer un arrivage en vocal » aux clients fidèles */
    public function arrival(Request $req): never
    {
        $stall = Merchant::stall($req);
        $audio = $req->file('audio') ?? Validator::fail(['audio' => 'Enregistrez votre message vocal.'], ['audio' => 'Message vocal']);
        $phones = $this->phones((int) $stall['id'], 'loyal');
        $n = Broadcasts::record((int) $stall['id'], 'arrival', 'axi', 'loyal', 'Arrivage chez ' . $stall['name'] . ' (message vocal)', $phones, Uploads::audio($audio));
        Response::ok([
            'recipients' => $n,
            'message' => $n === 0 ? 'Message enregistré. Vous n\'avez pas encore de clients fidèles.' : 'Arrivage annoncé à vos ' . Merchant::plural($n, 'client fidèle', 'clients fidèles') . '.',
        ]);
    }

    private function phones(int $stallId, string $segment): array
    {
        [$where, $params] = $this->segmentSql($segment);
        return array_column(Database::all("SELECT phone FROM merchant_clients WHERE stall_id = ? AND $where", [$stallId, ...$params]), 'phone');
    }

    /** @return array{0: string, 1: array} */
    private function segmentSql(string $segment): array
    {
        return match ($segment) {
            'resellers' => ['kind = \'reseller\'', []],
            'loyal' => ['orders_count >= ?', [(int) Config::get('customers.loyal_min_orders')]],
            'to_follow_up' => ['last_order_at < ?', [Clock::now(-86400 * (int) Config::get('customers.follow_up_days'))]],
            default => ['1', []],
        };
    }

    private function present(array $c): array
    {
        $followUpBefore = Clock::now(-86400 * (int) Config::get('customers.follow_up_days'));
        $newAfter = Clock::now(-86400 * (int) Config::get('customers.new_days'));
        $badge = match (true) {
            $c['last_order_at'] !== null && $c['last_order_at'] < $followUpBefore => ['kind' => 'follow_up', 'label' => 'Relancer'],
            (int) $c['orders_count'] <= 1 && max($c['created_at'], $c['last_order_at'] ?? '') >= $newAfter => ['kind' => 'new', 'label' => 'Nouveau'],
            (int) $c['orders_count'] >= (int) Config::get('customers.loyal_min_orders') => ['kind' => 'loyal', 'label' => 'Fidèle'],
            default => null,
        };
        $kind = $c['kind'] === 'reseller' ? 'Revendeur' : 'Particulier';
        $when = $c['last_order_at'] !== null ? 'dernier achat ' . self::ago($c['last_order_at']) : 'ajouté ' . self::ago($c['created_at']);
        return [
            'id' => Ids::encode('cli', $c['id']),
            'display_name' => $c['display_name'],
            'description' => "$kind · $when",
            'phone' => $c['phone'],
            'badge' => $badge,
        ];
    }

    /** « aujourd'hui », « hier », « il y a 5 jours », « il y a 6 semaines », « il y a 3 mois » (jours à l'heure du Bénin) */
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
