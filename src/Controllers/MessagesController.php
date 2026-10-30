<?php
declare(strict_types=1);

namespace Axi\Controllers;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Ids;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Services\Messaging;
use Axi\Services\Push;
use Axi\Services\SignedUrl;
use Axi\Services\Uploads;
use Axi\Support\Stalls;
use Axi\Support\Users;

/**
 * Messagerie client ↔ commerçant dans l'application : texte, photo, message vocal.
 * Le client ouvre la conversation depuis la page de l'étal ; le commerçant depuis la fiche d'un de ses clients
 * qui a un compte Axi. Messages effacés après 24 h sauf si le client suit l'étal (voir Messaging).
 * L'application interroge /v1/conversations/{id}/messages?after= toutes les quelques secondes (ETag : 304 si rien de neuf).
 */
final class MessagesController
{
    private const PER_MINUTE = 20;
    /** Message pas supprimé « pour moi » (paramètre : mon id) */
    private const NOT_HIDDEN = ' AND NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ?)';

    /** Mes conversations (comme client, et comme commerçant si j'ai un étal), la plus récente d'abord */
    public function index(Request $req): never
    {
        $me = $req->userId();
        $stallId = $this->ownStallId($me);
        $rows = Database::all(
            'SELECT c.*, s.name AS stall_name, s.photo_path, s.photo_width, s.photo_height, s.user_id AS merchant_id,
                    u.id AS client_user_id, u.name AS client_name, u.avatar_path, u.avatar_visibility
             FROM conversations c JOIN stalls s ON s.id = c.stall_id JOIN users u ON u.id = c.client_id
             WHERE (c.client_id = ? OR c.stall_id = ?) AND c.last_message_at IS NOT NULL
             ORDER BY c.last_message_at DESC LIMIT 100',
            [$me, $stallId ?? 0],
        );
        $out = [];
        foreach ($rows as $c) {
            $cleared = (int) ((int) $c['client_id'] === $me ? $c['client_cleared_msg_id'] : $c['merchant_cleared_msg_id']);
            $last = Database::one('SELECT m.* FROM messages m WHERE m.conversation_id = ? AND m.id > ? AND ' . Messaging::VISIBLE . self::NOT_HIDDEN . ' ORDER BY m.id DESC LIMIT 1', [$c['id'], $cleared, $me]);
            if ($last === null) {
                continue; // tout a expiré
            }
            $out[] = $this->summary($c, $me, $last);
        }
        Response::ok(['conversations' => $out, 'ttl_hours' => Messaging::ttlHours()]);
    }

    /** Côté client : ouvrir (ou retrouver) la conversation avec un étal */
    public function openWithStall(Request $req): never
    {
        $stallId = $req->id('stl', 'STALL_NOT_FOUND', 'Cet étal n\'existe pas ou n\'est plus actif.');
        $stall = Stalls::findActive($stallId) ?? throw new HttpException(404, 'STALL_NOT_FOUND', 'Cet étal n\'existe pas ou n\'est plus actif.');
        if ((int) $stall['user_id'] === $req->userId()) {
            throw new HttpException(422, 'OWN_STALL', 'C\'est votre propre étal.');
        }
        Response::ok($this->conversation($this->findOrCreate($stallId, $req->userId()), $req->userId()));
    }

    /** Côté commerçant : écrire à un client de son fichier qui a un compte Axi */
    public function openWithClient(Request $req): never
    {
        $stallId = $this->ownStallId($req->userId()) ?? throw new HttpException(404, 'STALL_REQUIRED', 'Votre étal n\'existe pas encore.');
        $clientRow = $req->id('cli', 'CLIENT_NOT_FOUND', 'Client introuvable.');
        $row = Database::one('SELECT user_id, display_name FROM merchant_clients WHERE id = ? AND stall_id = ?', [$clientRow, $stallId]);
        $userId = $row['user_id'] ?? null;
        if ($userId !== null && Database::value('SELECT status FROM users WHERE id = ?', [$userId]) !== 'active') {
            throw new HttpException(403, 'ACCOUNT_UNAVAILABLE', 'Ce compte n\'est plus disponible.');
        }
        if ($userId === null) {
            throw new HttpException(422, 'CLIENT_WITHOUT_ACCOUNT', 'Ce client n\'a pas de compte Axi : écrivez-lui par WhatsApp ou appelez-le.');
        }
        // Ajouté à la main : il doit d'abord accepter (ou suivre l'étal) avant toute discussion
        if (!Messaging::linked((int) $userId, $stallId)) {
            throw new HttpException(403, 'INVITE_PENDING', $row['display_name'] . ' n\'a pas encore accepté votre invitation. Vous pourrez discuter dès qu\'il accepte.');
        }
        Response::ok($this->conversation($this->findOrCreate($stallId, (int) $userId), $req->userId()));
    }

    /** Messages encore visibles ; ?after=msg_… pour ne recevoir que les nouveaux. Marque la conversation comme lue. */
    public function messages(Request $req): never
    {
        $c = $this->participant($req);
        $after = Ids::tryDecode('msg', (string) $req->query('after', '')) ?? 0;
        // Discussion supprimée pour moi : seuls les messages arrivés depuis sont affichés
        $after = max($after, (int) ((int) $c['client_id'] === $req->userId() ? $c['client_cleared_msg_id'] : $c['merchant_cleared_msg_id']));
        $rows = Database::all(
            'SELECT * FROM (SELECT m.* FROM messages m WHERE m.conversation_id = ? AND m.id > ? AND ' . Messaging::VISIBLE . self::NOT_HIDDEN . ' ORDER BY m.id DESC LIMIT 200) x ORDER BY id',
            [$c['id'], $after, $req->userId()],
        );
        $this->markRead($c, $req->userId());
        $fresh = Database::one('SELECT * FROM conversations WHERE id = ?', [$c['id']]);
        Response::ok($this->conversation($c, $req->userId()) + [
            'messages' => array_map(fn (array $m): array => $this->message($m, $req->userId(), $fresh), $rows),
        ]);
    }

    /** Envoyer : JSON { body, reply_to? } ou multipart { photo | audio, body?, reply_to? } (reply_to : msg_… auquel on répond) */
    public function send(Request $req): never
    {
        $c = $this->participant($req);
        $me = $req->userId();
        // Compte bloqué en face : plus aucun message, comme un compte banni
        if (Database::value('SELECT status FROM users WHERE id = ?', [(int) $c['client_id'] === $me ? $c['merchant_id'] : $c['client_id']]) !== 'active') {
            throw new HttpException(403, 'ACCOUNT_UNAVAILABLE', 'Ce compte n\'est plus disponible.');
        }
        // Discussion seulement entre un étal et un client qui le suit ou a accepté d'être son client
        if (!Messaging::linked((int) $c['client_id'], (int) $c['stall_id'])) {
            throw new HttpException(403, (int) $c['client_id'] === $me ? 'FOLLOW_REQUIRED' : 'INVITE_PENDING', (int) $c['client_id'] === $me
                ? 'Suivez cet étal pour écrire au commerçant.'
                : 'Ce client n\'a pas encore accepté votre invitation.');
        }
        $recent = (int) Database::value('SELECT COUNT(*) FROM messages WHERE sender_id = ? AND created_at > ?', [$me, Clock::now(-60)]);
        if ($recent >= self::PER_MINUTE) {
            throw new HttpException(429, 'TOO_MANY_REQUESTS', 'Vous envoyez beaucoup de messages. Patientez une minute.', ['retry_after' => 60]);
        }
        $in = Validator::validate($req->all(), ['body' => 'string|max:2000', 'reply_to' => 'string'], ['body' => 'Message', 'reply_to' => 'Réponse']);
        $body = trim((string) ($in['body'] ?? ''));
        $replyTo = null;
        if (isset($in['reply_to']) && $in['reply_to'] !== '') {
            $replyTo = Ids::tryDecode('msg', $in['reply_to']);
            if ($replyTo === null || Database::value('SELECT id FROM messages WHERE id = ? AND conversation_id = ?', [$replyTo, $c['id']]) === null) {
                throw new HttpException(422, 'VALIDATION_ERROR', 'Le message auquel vous répondez n\'existe plus.');
            }
        }
        $kind = 'text';
        $path = null;
        $width = $height = null;
        if (($photo = $req->file('photo')) !== null) {
            $saved = Uploads::photo($photo);
            [$kind, $path, $width, $height] = ['photo', $saved['path'], $saved['width'], $saved['height']];
        } elseif (($audio = $req->file('audio')) !== null) {
            [$kind, $path] = ['voice', Uploads::audio($audio)];
        } elseif (($video = $req->file('video')) !== null) {
            [$kind, $path] = ['video', Uploads::video($video)];
        } elseif ($body === '') {
            Validator::fail(['body' => 'Écrivez un message.'], ['body' => 'Message']);
        }
        $now = Clock::now();
        $id = Database::transaction(function () use ($c, $me, $kind, $body, $path, $width, $height, $now, $replyTo): int {
            $id = Database::insert(
                'INSERT INTO messages (conversation_id, sender_id, kind, body, file_path, file_width, file_height, reply_to_id, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$c['id'], $me, $kind, $body === '' ? null : $body, $path, $width, $height, $replyTo, $now, Messaging::expiresAt($c)],
            );
            $mine = (int) $c['client_id'] === $me ? 'client_read_at' : 'merchant_read_at';
            Database::run("UPDATE conversations SET last_message_at = ?, $mine = ? WHERE id = ?", [$now, $now, $c['id']]);
            return $id;
        });
        $this->pushMessage($c, $me, $kind, $body);
        Response::ok($this->message(Database::one('SELECT * FROM messages WHERE id = ?', [$id]), $me, Database::one('SELECT * FROM conversations WHERE id = ?', [$c['id']])), 201);
    }

    /** Alerte sur le téléphone du destinataire : « Nom » et le début du message (ou « Photo », « Message vocal ») */
    private function pushMessage(array $c, int $me, string $kind, string $body): void
    {
        $stall = Database::one('SELECT user_id, name FROM stalls WHERE id = ?', [$c['stall_id']]);
        if ($stall === null) {
            return;
        }
        $fromClient = (int) $c['client_id'] === $me;
        $to = $fromClient ? (int) $stall['user_id'] : (int) $c['client_id'];
        $from = $fromClient ? (string) Database::value('SELECT name FROM users WHERE id = ?', [$me]) : (string) $stall['name'];
        $text = match ($kind) {
            'photo' => $body !== '' ? "📷 $body" : '📷 Photo',
            'voice' => '🎤 Message vocal',
            'video' => $body !== '' ? "🎥 $body" : '🎥 Vidéo',
            default => $body,
        };
        Push::toUser($to, $from, $text, ['url' => '/messages/' . Ids::encode('conv', (int) $c['id'])]);
    }

    /** Modifier son propre message texte : { body } ; « modifié » est affiché à côté de l'heure */
    public function edit(Request $req): never
    {
        [$c, $m] = $this->ownMessage($req);
        if ($m['kind'] !== 'text') {
            throw new HttpException(422, 'NOT_EDITABLE', 'Seuls les messages écrits peuvent être modifiés.');
        }
        $body = trim((string) (Validator::validate($req->all(), ['body' => 'required|string|max:2000'], ['body' => 'Message'])['body'] ?? ''));
        if ($body === '') {
            Validator::fail(['body' => 'Écrivez un message.'], ['body' => 'Message']);
        }
        Database::run('UPDATE messages SET body = ?, edited_at = ? WHERE id = ?', [$body, Clock::now(), $m['id']]);
        Response::ok($this->message(Database::one('SELECT * FROM messages WHERE id = ?', [$m['id']]), $req->userId(), Database::one('SELECT * FROM conversations WHERE id = ?', [$c['id']])));
    }

    /** Supprimer son propre message pour tout le monde : « Ce message a été supprimé » reste affiché */
    public function delete(Request $req): never
    {
        [$c, $m] = $this->ownMessage($req);
        if (in_array($m['kind'], ['voice', 'video'], true) && $m['file_path'] !== null) {
            @unlink(BASE_PATH . '/storage/' . $m['file_path']);
        }
        Database::run('UPDATE messages SET body = NULL, file_path = NULL, deleted_at = ? WHERE id = ?', [Clock::now(), $m['id']]);
        Response::ok($this->message(Database::one('SELECT * FROM messages WHERE id = ?', [$m['id']]), $req->userId(), Database::one('SELECT * FROM conversations WHERE id = ?', [$c['id']])));
    }

    /** Réglages de la discussion (commerçant) : { retention_hours: 24 | 48 | 720 | 2160 } ; 30 et 90 jours réservés aux abonnés */
    public function settings(Request $req): never
    {
        $c = $this->participant($req);
        if ((int) $c['merchant_id'] !== $req->userId()) {
            throw new HttpException(403, 'FORBIDDEN', 'Seul le commerçant règle la durée des messages.');
        }
        $hours = Validator::validate($req->all(), ['retention_hours' => 'required|int'], ['retention_hours' => 'Durée'])['retention_hours'];
        $hours = (int) $hours;
        if (!array_key_exists($hours, Messaging::RETENTION)) {
            Validator::fail(['retention_hours' => 'Durée non proposée.']);
        }
        if (Messaging::RETENTION[$hours]) {
            \Axi\Support\Subscription::require((int) $c['stall_id'], 'Garder les messages ' . Messaging::label($hours));
        }
        Messaging::setRetention((int) $c['id'], $hours);
        $row = Database::one(
            'SELECT c.*, s.name AS stall_name, s.photo_path, s.photo_width, s.photo_height, s.user_id AS merchant_id,
                    u.id AS client_user_id, u.name AS client_name, u.avatar_path, u.avatar_visibility
             FROM conversations c JOIN stalls s ON s.id = c.stall_id JOIN users u ON u.id = c.client_id WHERE c.id = ?',
            [$c['id']],
        );
        Response::ok(['conversation' => $this->summary($row, $req->userId(), null)]);
    }

    /**
     * Supprimer la discussion « pour moi » : elle disparaît de ma liste et ses messages de mon écran ;
     * l'autre personne la garde. Si un nouveau message arrive, la discussion réapparaît (avec lui seul).
     */
    public function clear(Request $req): never
    {
        $c = $this->participant($req);
        $side = (int) $c['client_id'] === $req->userId() ? 'client' : 'merchant';
        $max = (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM messages WHERE conversation_id = ?', [$c['id']]);
        Database::run("UPDATE conversations SET {$side}_cleared_msg_id = ?, {$side}_read_msg_id = GREATEST({$side}_read_msg_id, ?), {$side}_read_at = ? WHERE id = ?", [$max, $max, Clock::now(), $c['id']]);
        Response::ok(['cleared' => true]);
    }

    /** Supprimer un message « pour moi » (le mien ou celui de l'autre) : il reste chez l'autre personne */
    public function hide(Request $req): never
    {
        $c = $this->participant($req);
        $msgId = Ids::decode('msg', $req->params['message'] ?? null, 'MESSAGE_NOT_FOUND', 'Message introuvable.');
        if (Database::value('SELECT id FROM messages WHERE id = ? AND conversation_id = ?', [$msgId, $c['id']]) === null) {
            throw new HttpException(404, 'MESSAGE_NOT_FOUND', 'Message introuvable.');
        }
        Database::run('INSERT IGNORE INTO message_hidden (user_id, message_id, created_at) VALUES (?, ?, ?)', [$req->userId(), $msgId, Clock::now()]);
        Response::ok(['hidden' => true, 'id' => Ids::encode('msg', $msgId)]);
    }

    /** @return array{0: array, 1: array} conversation et message envoyé par moi, encore visible et non supprimé */
    private function ownMessage(Request $req): array
    {
        $c = $this->participant($req);
        $msgId = Ids::decode('msg', $req->params['message'] ?? null, 'MESSAGE_NOT_FOUND', 'Message introuvable.');
        $m = Database::one('SELECT m.* FROM messages m WHERE m.id = ? AND m.conversation_id = ? AND ' . Messaging::VISIBLE, [$msgId, $c['id']]);
        if ($m === null || $m['deleted_at'] !== null) {
            throw new HttpException(404, 'MESSAGE_NOT_FOUND', 'Message introuvable.');
        }
        if ((int) $m['sender_id'] !== $req->userId()) {
            throw new HttpException(403, 'FORBIDDEN', 'Vous ne pouvez modifier ou supprimer que vos propres messages.');
        }
        return [$c, $m];
    }

    public function read(Request $req): never
    {
        $c = $this->participant($req);
        $this->markRead($c, $req->userId());
        Response::ok(['read' => true]);
    }

    /** Pastilles de l'application (interrogées régulièrement) : messages et notifications non lus */
    public function badges(Request $req): never
    {
        $me = $req->userId();
        $stallId = $this->ownStallId($me) ?? 0;
        // L'application est ouverte : « en ligne », et les messages reçus passent à ✓✓ chez l'expéditeur
        $now = Clock::now();
        Database::run('UPDATE users SET last_seen_at = ? WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at < ?)', [$now, $me, Clock::now(-20)]);
        $max = '(SELECT COALESCE(MAX(m.id), 0) FROM messages m WHERE m.conversation_id = c.id)';
        Database::run("UPDATE conversations c SET c.client_delivered_at = ?, c.client_delivered_msg_id = $max WHERE c.client_id = ? AND c.last_message_at IS NOT NULL AND (c.client_delivered_at IS NULL OR c.client_delivered_at <= c.last_message_at)", [$now, $me]);
        if ($stallId > 0) {
            Database::run("UPDATE conversations c SET c.merchant_delivered_at = ?, c.merchant_delivered_msg_id = $max WHERE c.stall_id = ? AND c.last_message_at IS NOT NULL AND (c.merchant_delivered_at IS NULL OR c.merchant_delivered_at <= c.last_message_at)", [$now, $stallId]);
        }
        // Comptés à part : les discussions où je suis client et celles de mon commerce ne se mélangent pas
        $unread = Database::one(
            'SELECT COALESCE(SUM(c.client_id = ? AND m.id > c.client_cleared_msg_id AND (c.client_read_at IS NULL OR m.created_at > c.client_read_at)), 0) AS client,
                    COALESCE(SUM(c.stall_id = ? AND m.id > c.merchant_cleared_msg_id AND (c.merchant_read_at IS NULL OR m.created_at > c.merchant_read_at)), 0) AS merchant
             FROM messages m JOIN conversations c ON c.id = m.conversation_id
             WHERE ' . Messaging::VISIBLE . self::NOT_HIDDEN . ' AND m.sender_id <> ?',
            [$me, $stallId, $me, $me],
        );
        $user = Database::one('SELECT role, merchant_welcome_pending FROM users WHERE id = ?', [$me]);
        Response::ok([
            'unread_messages' => (int) $unread['client'] + (int) $unread['merchant'],
            'unread_as_client' => (int) $unread['client'],
            'unread_as_merchant' => (int) $unread['merchant'],
            'unread_notifications' => (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$me]),
            // L'application recharge le profil quand le rôle change (demande commerçant acceptée)
            'role' => $user['role'],
            'merchant_welcome' => (bool) $user['merchant_welcome_pending'],
        ]);
    }

    private function ownStallId(int $userId): ?int
    {
        $id = Database::value('SELECT id FROM stalls WHERE user_id = ?', [$userId]);
        return $id === null ? null : (int) $id;
    }

    private function findOrCreate(int $stallId, int $clientId): array
    {
        Database::run('INSERT IGNORE INTO conversations (stall_id, client_id, created_at) VALUES (?, ?, ?)', [$stallId, $clientId, Clock::now()]);
        return Database::one('SELECT * FROM conversations WHERE stall_id = ? AND client_id = ?', [$stallId, $clientId]);
    }

    /** Conversation dont je suis le client ou le commerçant ; sinon 404 */
    private function participant(Request $req): array
    {
        $id = $req->id('conv', 'CONVERSATION_NOT_FOUND', 'Conversation introuvable.');
        $c = Database::one('SELECT c.*, s.user_id AS merchant_id FROM conversations c JOIN stalls s ON s.id = c.stall_id WHERE c.id = ?', [$id]);
        if ($c === null || ((int) $c['client_id'] !== $req->userId() && (int) $c['merchant_id'] !== $req->userId())) {
            throw new HttpException(404, 'CONVERSATION_NOT_FOUND', 'Conversation introuvable.');
        }
        return $c;
    }

    private function markRead(array $c, int $me): void
    {
        $side = (int) $c['client_id'] === $me ? 'client' : 'merchant';
        $now = Clock::now();
        $max = (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM messages WHERE conversation_id = ?', [$c['id']]);
        Database::run("UPDATE conversations SET {$side}_read_at = ?, {$side}_delivered_at = ?, {$side}_read_msg_id = GREATEST({$side}_read_msg_id, ?), {$side}_delivered_msg_id = GREATEST({$side}_delivered_msg_id, ?) WHERE id = ?", [$now, $now, $max, $max, $c['id']]);
        Database::run('UPDATE users SET last_seen_at = ? WHERE id = ?', [$now, $me]);
    }

    /** En-tête d'une conversation, vu par $me */
    private function conversation(array $c, int $me): array
    {
        $row = Database::one(
            'SELECT c.*, s.name AS stall_name, s.photo_path, s.photo_width, s.photo_height, s.user_id AS merchant_id,
                    u.id AS client_user_id, u.name AS client_name, u.avatar_path, u.avatar_visibility
             FROM conversations c JOIN stalls s ON s.id = c.stall_id JOIN users u ON u.id = c.client_id WHERE c.id = ?',
            [$c['id']],
        );
        return ['conversation' => $this->summary($row, $me, null)];
    }

    private function summary(array $c, int $me, ?array $last): array
    {
        $asClient = (int) $c['client_id'] === $me;
        $hours = Messaging::effectiveHours($c);
        $subscribed = \Axi\Support\Subscription::active((int) $c['stall_id']);
        $readAt = $asClient ? $c['client_read_at'] : $c['merchant_read_at'];
        $cleared = (int) ($asClient ? ($c['client_cleared_msg_id'] ?? 0) : ($c['merchant_cleared_msg_id'] ?? 0));
        $unread = (int) Database::value(
            'SELECT COUNT(*) FROM messages m WHERE m.conversation_id = ? AND m.id > ? AND m.sender_id <> ? AND ' . Messaging::VISIBLE . self::NOT_HIDDEN . ($readAt !== null ? ' AND m.created_at > ?' : ''),
            $readAt !== null ? [$c['id'], $cleared, $me, $me, $readAt] : [$c['id'], $cleared, $me, $me],
        );
        $stallImage = Stalls::image($c['photo_path'], $c['photo_width'] === null ? null : (int) $c['photo_width'], $c['photo_height'] === null ? null : (int) $c['photo_height']);
        $merchant = $asClient ? null : Users::find((int) $c['merchant_id']);
        $otherUser = Database::one('SELECT status, last_seen_at FROM users WHERE id = ?', [$asClient ? $c['merchant_id'] : $c['client_id']]);
        // Compte bloqué : ni nom, ni photo, ni présence, ni réponse possible. Rien n'est effacé : tout revient au déblocage.
        $gone = ($otherUser['status'] ?? '') !== 'active';
        $otherSeen = $gone ? null : ($otherUser['last_seen_at'] ?? null);
        if ($gone) {
            $stallImage = null;
            $c['client_name'] = $c['stall_name'] = 'Compte indisponible';
            $c['avatar_path'] = null;
        }
        return [
            'id' => Ids::encode('conv', $c['id']),
            'my_role' => $asClient ? 'client' : 'merchant',
            // subscribed : commerçant abonné (badge « Abonné » dans la discussion)
            'stall' => ['id' => Ids::encode('stl', $c['stall_id']), 'name' => $c['stall_name'], 'avatar' => $stallImage, 'subscribed' => $subscribed],
            // L'autre personne : l'étal pour le client, le client pour le commerçant (photo selon son choix)
            // Profil de l'autre personne (présentation publique, sans email ni téléphone)
            'other_user_id' => Ids::encode('usr', $asClient ? $c['merchant_id'] : $c['client_user_id']),
            'other' => $asClient
                ? ['name' => $c['stall_name'], 'avatar' => $stallImage]
                : ['name' => $c['client_name'], 'avatar' => Users::avatarFor(['id' => $c['client_user_id'], 'avatar_path' => $c['avatar_path'], 'avatar_visibility' => $c['avatar_visibility']], $merchant === null ? null : (int) $merchant['id'], true)],
            // « En ligne » : application ouverte il y a moins d'une minute ; sinon « vu à … »
            'other_online' => $otherSeen !== null && strtotime($otherSeen . ' UTC') > time() - 60,
            'other_last_seen_at' => Clock::iso($otherSeen),
            // Durée des messages (réglée par le commerçant) : 24 h, 48 h ; 30 ou 90 jours s'il est abonné
            'retention_hours' => $hours,
            // Écrire : le client suit l'étal ou a accepté l'invitation du commerçant (dans les deux sens)
            'other_unavailable' => $gone,
            'can_write' => !$gone && Messaging::linked((int) $c['client_id'], (int) $c['stall_id']),
            'retention_note' => 'Les messages disparaissent ' . Messaging::label($hours) . ' après leur envoi.',
            'retention_options' => $asClient || $gone ? null : array_map(
                static fn (int $h, bool $pro): array => ['hours' => $h, 'label' => Messaging::label($h), 'locked' => $pro && !$subscribed],
                array_keys(Messaging::RETENTION),
                array_values(Messaging::RETENTION),
            ),
            'unread_count' => $unread,
            'last_message' => $last === null ? null : [
                'kind' => $last['kind'],
                'preview' => $last['deleted_at'] !== null ? '🚫 Message supprimé' : match ($last['kind']) { 'photo' => '📷 Photo', 'voice' => '🎤 Message vocal', 'video' => '🎥 Vidéo', default => mb_substr((string) $last['body'], 0, 80) },
                'mine' => (int) $last['sender_id'] === $me,
                // Coches visibles dans la liste : ✓ envoyé, ✓✓ reçu, ✓✓ bleu vu (mes messages seulement)
                'status' => (int) $last['sender_id'] !== $me ? null : match (true) {
                    (int) $c[($asClient ? 'merchant' : 'client') . '_read_msg_id'] >= (int) $last['id'] => 'read',
                    (int) $c[($asClient ? 'merchant' : 'client') . '_delivered_msg_id'] >= (int) $last['id'] => 'delivered',
                    default => 'sent',
                },
                'created_at' => Clock::iso($last['created_at']),
            ],
        ];
    }

    /**
     * Message vu par $me. Pour ses propres messages, « status » comme WhatsApp :
     * sent (✓ reçu par Axi), delivered (✓✓ arrivé sur le téléphone de l'autre), read (✓✓ bleu, conversation ouverte).
     */
    /** Aperçu du message auquel on répond (« Message supprimé » ou « effacé » s'il n'est plus visible) */
    private function reply(?int $id, int $me): ?array
    {
        if ($id === null) {
            return null;
        }
        $r = Database::one('SELECT m.* FROM messages m WHERE m.id = ? AND ' . Messaging::VISIBLE, [$id]);
        if ($r === null) {
            return ['id' => Ids::encode('msg', $id), 'mine' => false, 'kind' => 'text', 'preview' => 'Message effacé'];
        }
        return [
            'id' => Ids::encode('msg', $r['id']),
            'mine' => (int) $r['sender_id'] === $me,
            'kind' => $r['kind'],
            'preview' => $r['deleted_at'] !== null ? 'Message supprimé' : match ($r['kind']) { 'photo' => '📷 Photo', 'voice' => '🎤 Message vocal', 'video' => '🎥 Vidéo', default => mb_substr((string) $r['body'], 0, 120) },
        ];
    }

    private function message(array $m, int $me, array $c): array
    {
        $mine = (int) $m['sender_id'] === $me;
        $otherSide = (int) $c['client_id'] === $me ? 'merchant' : 'client';
        $readId = (int) $c[$otherSide . '_read_msg_id'];
        $deliveredId = (int) $c[$otherSide . '_delivered_msg_id'];
        $deleted = $m['deleted_at'] !== null;
        return [
            'id' => Ids::encode('msg', $m['id']),
            'mine' => $mine,
            'status' => !$mine ? null : match (true) {
                $readId >= (int) $m['id'] => 'read',
                max($readId, $deliveredId) >= (int) $m['id'] => 'delivered',
                default => 'sent',
            },
            'edited' => $m['edited_at'] !== null && !$deleted,
            'reply' => $this->reply($m['reply_to_id'] === null ? null : (int) $m['reply_to_id'], $me),
            'deleted' => $deleted,
            'kind' => $m['kind'],
            'body' => $m['body'],
            'image' => $deleted ? null : ($m['kind'] === 'photo' ? Stalls::image($m['file_path'], $m['file_width'] === null ? null : (int) $m['file_width'], $m['file_height'] === null ? null : (int) $m['file_height']) : null),
            'audio_url' => !$deleted && $m['kind'] === 'voice' ? SignedUrl::make('/v1/media/audio', ['p' => $m['file_path']], 86400) : null,
            'video_url' => !$deleted && $m['kind'] === 'video' ? SignedUrl::make('/v1/media/video', ['p' => $m['file_path']], 86400) : null,
            'created_at' => Clock::iso($m['created_at']),
            'expires_at' => Clock::iso($m['expires_at']),
        ];
    }
}
