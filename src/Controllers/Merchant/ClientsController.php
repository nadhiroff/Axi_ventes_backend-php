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
        if (!in_array($segment, ['all', 'followers', 'resellers', 'loyal', 'to_follow_up'], true)) {
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
        $followers = (int) Database::value('SELECT COUNT(*) FROM stall_follows WHERE stall_id = ?', [$stall['id']]);

        [$loyalWhere, $loyalParams] = $this->segmentSql('loyal');
        $hasLoyal = Database::value("SELECT 1 FROM merchant_clients WHERE stall_id = ? AND $loyalWhere LIMIT 1", [$stall['id'], ...$loyalParams]) !== null;

        Response::ok([
            'arrival_prompt' => $hasLoyal ? [
                'title' => 'Nouvel arrivage ?',
                'text' => 'Prévenez vos clients fidèles en un message, par Axi ou par WhatsApp.',
                'button_label' => 'Envoyer à mes clients fidèles',
            ] : null,
            'followers_count' => $followers,
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

    /**
     * Recherche d'un compte Axi par email ou téléphone EXACT (jamais par nom : la liste des inscrits reste privée).
     * Renvoie une présentation minimale et le type détecté : un commerçant qui achète chez un autre est un revendeur.
     */
    public function lookup(Request $req): never
    {
        $stall = Merchant::stall($req);
        $contact = trim((string) $req->query('contact', ''));
        $email = Validator::email($contact);
        $phone = $email === null ? Validator::phone($contact) : null;
        if ($email === null && $phone === null) {
            Response::ok(['found' => false, 'valid' => false]);
        }
        $u = Database::one(
            "SELECT * FROM users WHERE status = 'active' AND role IN ('client','merchant') AND id <> ? AND " . ($email !== null ? 'email = ?' : 'phone = ?'),
            [$req->userId(), $email ?? $phone],
        );
        if ($u === null) {
            Response::ok(['found' => false, 'valid' => true, 'contact' => ['email' => $email, 'phone' => $phone]]);
        }
        $existing = Database::value('SELECT id FROM merchant_clients WHERE stall_id = ? AND user_id = ?', [$stall['id'], $u['id']]);
        $theirStall = $u['role'] === 'merchant' ? Database::value('SELECT name FROM stalls WHERE user_id = ?', [$u['id']]) : null;
        Response::ok([
            'found' => true,
            'valid' => true,
            'contact' => ['email' => $email, 'phone' => $phone],
            'user' => [
                'id' => Ids::encode('usr', $u['id']),
                'display_name' => trim($u['first_name'] . ' ' . mb_substr($u['last_name'], 0, 1) . '.'),
                'avatar' => \Axi\Support\Users::avatarFor($u, $req->userId(), false),
                'role' => $u['role'],
                'stall_name' => $theirStall,
            ],
            'kind_detected' => $u['role'] === 'merchant' ? 'reseller' : 'individual',
            'existing_client_id' => $existing === null ? null : Ids::encode('cli', $existing),
        ]);
    }

    /**
     * Ajouter un client : { contact (email ou téléphone), display_name? } ; si un compte Axi correspond, il est
     * rattaché (nom repris) et le type est détecté (commerçant = revendeur). kind / note restent possibles.
     */
    public function create(Request $req): never
    {
        $stall = Merchant::stall($req);
        $body = $req->all();
        // « contact » : un seul champ email ou téléphone, reconnu automatiquement
        if (isset($body['contact']) && is_string($body['contact'])) {
            $contact = trim($body['contact']);
            if (Validator::email($contact) !== null) {
                $body['email'] = $contact;
            } else {
                $body['phone'] = $contact;
            }
        }
        $in = Validator::validate($body, [
            'display_name' => 'string|max:100',
            'phone' => 'phone',
            'email' => 'email',
            'kind' => 'in:individual,reseller',
            'note' => 'string|max:300',
        ], self::LABELS);
        if (!isset($in['phone']) && !isset($in['email'])) {
            Validator::fail(['phone' => 'Indiquez un téléphone ou un email.'], self::LABELS);
        }
        if (isset($in['phone']) && Database::value('SELECT id FROM merchant_clients WHERE stall_id = ? AND phone = ?', [$stall['id'], $in['phone']]) !== null) {
            throw new HttpException(409, 'CLIENT_EXISTS', 'Ce numéro est déjà dans votre fichier clients.');
        }
        // Rattachement au compte Axi du client s'il en a un (même email ou même téléphone)
        $user = Database::one('SELECT * FROM users WHERE status = \'active\' AND role IN (\'client\',\'merchant\') AND id <> ? AND ((email = ?) OR (phone IS NOT NULL AND phone = ?))', [$req->userId(), $in['email'] ?? '', $in['phone'] ?? '']);
        $userId = $user === null ? null : (int) $user['id'];
        if ($userId !== null && Database::value('SELECT id FROM merchant_clients WHERE stall_id = ? AND user_id = ?', [$stall['id'], $userId]) !== null) {
            throw new HttpException(409, 'CLIENT_EXISTS', 'Ce client est déjà dans votre fichier clients.');
        }
        $name = trim((string) ($in['display_name'] ?? ''));
        if ($name === '' && $user !== null) {
            $name = trim($user['first_name'] . ' ' . mb_substr($user['last_name'], 0, 1) . '.');
        }
        if ($name === '') {
            Validator::fail(['display_name' => 'Indiquez le nom du client.'], self::LABELS);
        }
        // Type détecté : un commerçant inscrit sur Axi qui achète chez vous est un revendeur
        $kind = $in['kind'] ?? (($user['role'] ?? null) === 'merchant' ? 'reseller' : 'individual');
        $id = Database::insert(
            'INSERT INTO merchant_clients (stall_id, user_id, display_name, phone, email, kind, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$stall['id'], $userId, $name, $in['phone'] ?? null, $in['email'] ?? ($user['email'] ?? null), $kind, $in['note'] ?? null, Clock::now()],
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
    /** Modifier le texte d'une de mes annonces d'arrivage : { text } (photo et durée inchangées) */
    public function updateAnnouncement(Request $req): never
    {
        $a = $this->ownAnnouncement($req);
        $text = trim((string) (Validator::validate($req->all(), ['text' => 'required|string|max:500'], ['text' => 'Annonce'])['text'] ?? ''));
        if (mb_strlen($text) < 3) {
            Validator::fail(['text' => 'Écrivez votre annonce.'], ['text' => 'Annonce']);
        }
        Database::run('UPDATE stall_announcements SET body = ? WHERE id = ?', [$text, $a['id']]);
        Response::ok(Stalls::announcement(Database::one('SELECT * FROM stall_announcements WHERE id = ?', [$a['id']])));
    }

    /** Supprimer une de mes annonces d'arrivage (elle disparaît de l'étal et de l'accueil) */
    public function deleteAnnouncement(Request $req): never
    {
        $a = $this->ownAnnouncement($req);
        Database::run('DELETE FROM stall_announcements WHERE id = ?', [$a['id']]);
        Response::ok(['deleted' => true]);
    }

    private function ownAnnouncement(Request $req): array
    {
        $stall = Merchant::stall($req);
        $id = $req->id('ann', 'NOT_FOUND', 'Annonce introuvable.');
        return Database::one('SELECT * FROM stall_announcements WHERE id = ? AND stall_id = ?', [$id, $stall['id']])
            ?? throw new HttpException(404, 'NOT_FOUND', 'Annonce introuvable.');
    }

    /**
     * Annonce d'arrivage ÉCRITE (photo facultative), visible 7 jours sur la page de l'étal.
     * Commerçant abonné : aussi en petite annonce sur l'accueil des clients, et envoyée à ses clients fidèles et abonnés.
     */
    public function arrival(Request $req): never
    {
        $stall = Merchant::stall($req);
        $text = trim((string) (Validator::validate($req->all(), ['text' => 'required|string|max:500'], ['text' => 'Annonce'])['text'] ?? ''));
        if (mb_strlen($text) < 3) {
            Validator::fail(['text' => 'Écrivez votre annonce.'], ['text' => 'Annonce']);
        }
        $photo = $req->file('photo') === null ? null : Uploads::photo($req->file('photo'));
        Database::insert(
            'INSERT INTO stall_announcements (stall_id, body, photo_path, photo_width, photo_height, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$stall['id'], $text, $photo['path'] ?? null, $photo['width'] ?? null, $photo['height'] ?? null, Clock::now(), Clock::now(7 * 86400)],
        );
        if (!Subscription::active((int) $stall['id'])) {
            Response::ok([
                'recipients' => 0, 'notified' => 0, 'emailed' => 0, 'whatsapp_url' => null, 'share_text' => $text,
                'message' => 'Annonce publiée sur la page de votre étal pendant 7 jours. Abonnez-vous pour la montrer aussi sur l\'accueil de tous les clients et l\'envoyer à vos clients.',
            ]);
        }
        $clients = array_values(array_merge($this->clients((int) $stall['id'], 'loyal'), $this->clients((int) $stall['id'], 'followers')));
        $unique = [];
        foreach ($clients as $c) {
            $unique[$c['id']] = $c;
        }
        $result = Broadcaster::send($stall, 'arrival', 'loyal', 'Arrivage chez ' . $stall['name'], $text, array_values($unique));
        $result['message'] = 'Annonce publiée sur votre étal et sur l\'accueil des clients. ' . $result['message'];
        Response::ok($result);
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
            'followers' => ['EXISTS (SELECT 1 FROM stall_follows f WHERE f.user_id = merchant_clients.user_id AND f.stall_id = merchant_clients.stall_id)', []],
            'resellers' => ['kind = \'reseller\'', []],
            'loyal' => ['orders_count >= ?', [Settings::int('loyal_min_orders')]],
            'to_follow_up' => ['last_order_at < ?', [Clock::now(-86400 * Settings::int('follow_up_days'))]],
            default => ['1', []],
        };
    }

    private function present(array $c): array
    {
        $user = $c['user_id'] === null ? null : Database::one('SELECT id, avatar_path, avatar_visibility, status FROM users WHERE id = ?', [$c['user_id']]);
        $follows = $c['user_id'] !== null && Database::value('SELECT 1 FROM stall_follows WHERE user_id = ? AND stall_id = ?', [$c['user_id'], $c['stall_id']]) !== null;
        $followUpBefore = Clock::now(-86400 * Settings::int('follow_up_days'));
        $newAfter = Clock::now(-86400 * 7);
        $badge = match (true) {
            $c['last_order_at'] !== null && $c['last_order_at'] < $followUpBefore => ['kind' => 'follow_up', 'label' => 'Relancer'],
            (int) $c['orders_count'] <= 1 && max($c['created_at'], $c['last_order_at'] ?? '') >= $newAfter => ['kind' => 'new', 'label' => 'Nouveau'],
            (int) $c['orders_count'] >= Settings::int('loyal_min_orders') => ['kind' => 'loyal', 'label' => 'Fidèle'],
            $follows && (int) $c['orders_count'] === 0 => ['kind' => 'follower', 'label' => 'Abonné'],
            default => null,
        };
        $kind = $c['kind'] === 'reseller' ? 'Revendeur' : 'Particulier';
        $when = $c['last_order_at'] !== null ? 'dernier achat ' . self::ago($c['last_order_at']) : ($follows ? 'vous suit depuis ' : 'ajouté ') . self::ago($c['created_at']);
        return [
            'id' => Ids::encode('cli', $c['id']),
            'display_name' => $c['display_name'],
            'description' => "$kind · $when",
            'phone' => $c['phone'],
            'email' => $c['email'],
            'badge' => $badge,
            // Compte Axi : profil (présentation) et discussion directe dans l'application
            'user_id' => $user === null || $user['status'] !== 'active' ? null : Ids::encode('usr', $user['id']),
            'avatar' => $user === null ? null : \Axi\Support\Users::avatarFor($user + ['avatar_path' => $user['avatar_path']], null, true),
            'follows' => $follows,
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
