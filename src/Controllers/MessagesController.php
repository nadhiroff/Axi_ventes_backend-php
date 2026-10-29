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
            $last = Database::one('SELECT m.* FROM messages m WHERE m.conversation_id = ? AND ' . Messaging::VISIBLE . ' ORDER BY m.id DESC LIMIT 1', [$c['id']]);
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
        $userId = Database::value('SELECT user_id FROM merchant_clients WHERE id = ? AND stall_id = ?', [$clientRow, $stallId]);
        if ($userId === null) {
            throw new HttpException(422, 'CLIENT_WITHOUT_ACCOUNT', 'Ce client n\'a pas de compte Axi : écrivez-lui par WhatsApp ou appelez-le.');
        }
        Response::ok($this->conversation($this->findOrCreate($stallId, (int) $userId), $req->userId()));
    }

    /** Messages encore visibles ; ?after=msg_… pour ne recevoir que les nouveaux. Marque la conversation comme lue. */
    public function messages(Request $req): never
    {
        $c = $this->participant($req);
        $after = Ids::tryDecode('msg', (string) $req->query('after', '')) ?? 0;
        $rows = Database::all(
            'SELECT * FROM (SELECT m.* FROM messages m WHERE m.conversation_id = ? AND m.id > ? AND ' . Messaging::VISIBLE . ' ORDER BY m.id DESC LIMIT 200) x ORDER BY id',
            [$c['id'], $after],
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
        } elseif ($body === '') {
            Validator::fail(['body' => 'Écrivez un message.'], ['body' => 'Message']);
        }
        $now = Clock::now();
        $id = Database::transaction(function () use ($c, $me, $kind, $body, $path, $width, $height, $now, $replyTo): int {
            $id = Database::insert(
                'INSERT INTO messages (conversation_id, sender_id, kind, body, file_path, file_width, file_height, reply_to_id, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$c['id'], $me, $kind, $body === '' ? null : $body, $path, $width, $height, $replyTo, $now, Messaging::expiresAt((int) $c['client_id'], (int) $c['stall_id'])],
            );
            $mine = (int) $c['client_id'] === $me ? 'client_read_at' : 'merchant_read_at';
            Database::run("UPDATE conversations SET last_message_at = ?, $mine = ? WHERE id = ?", [$now, $now, $c['id']]);
            return $id;
        });
        Response::ok($this->message(Database::one('SELECT * FROM messages WHERE id = ?', [$id]), $me, Database::one('SELECT * FROM conversations WHERE id = ?', [$c['id']])), 201);
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
        if ($m['kind'] === 'voice' && $m['file_path'] !== null) {
            @unlink(BASE_PATH . '/storage/' . $m['file_path']);
        }
        Database::run('UPDATE messages SET body = NULL, file_path = NULL, deleted_at = ? WHERE id = ?', [Clock::now(), $m['id']]);
        Response::ok($this->message(Database::one('SELECT * FROM messages WHERE id = ?', [$m['id']]), $req->userId(), Database::one('SELECT * FROM conversations WHERE id = ?', [$c['id']])));
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
        $unread = (int) Database::value(
            'SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id
             WHERE ' . Messaging::VISIBLE . ' AND m.sender_id <> ?
               AND ((c.client_id = ? AND (c.client_read_at IS NULL OR m.created_at > c.client_read_at))
                 OR (c.stall_id = ? AND (c.merchant_read_at IS NULL OR m.created_at > c.merchant_read_at)))',
            [$me, $me, $stallId],
        );
        $user = Database::one('SELECT role, merchant_welcome_pending FROM users WHERE id = ?', [$me]);
        Response::ok([
            'unread_messages' => $unread,
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
        $kept = Messaging::follows((int) $c['client_id'], (int) $c['stall_id']);
        $readAt = $asClient ? $c['client_read_at'] : $c['merchant_read_at'];
        $unread = (int) Database::value(
            'SELECT COUNT(*) FROM messages m WHERE m.conversation_id = ? AND m.sender_id <> ? AND ' . Messaging::VISIBLE . ($readAt !== null ? ' AND m.created_at > ?' : ''),
            $readAt !== null ? [$c['id'], $me, $readAt] : [$c['id'], $me],
        );
        $stallImage = Stalls::image($c['photo_path'], $c['photo_width'] === null ? null : (int) $c['photo_width'], $c['photo_height'] === null ? null : (int) $c['photo_height']);
        $merchant = $asClient ? null : Users::find((int) $c['merchant_id']);
        $otherSeen = Database::value('SELECT last_seen_at FROM users WHERE id = ?', [$asClient ? $c['merchant_id'] : $c['client_id']]);
        return [
            'id' => Ids::encode('conv', $c['id']),
            'my_role' => $asClient ? 'client' : 'merchant',
            'stall' => ['id' => Ids::encode('stl', $c['stall_id']), 'name' => $c['stall_name'], 'avatar' => $stallImage],
            // L'autre personne : l'étal pour le client, le client pour le commerçant (photo selon son choix)
            'other' => $asClient
                ? ['name' => $c['stall_name'], 'avatar' => $stallImage]
                : ['name' => $c['client_name'], 'avatar' => Users::avatarFor(['id' => $c['client_user_id'], 'avatar_path' => $c['avatar_path'], 'avatar_visibility' => $c['avatar_visibility']], $merchant === null ? null : (int) $merchant['id'], true)],
            // « En ligne » : application ouverte il y a moins d'une minute ; sinon « vu à … »
            'other_online' => $otherSeen !== null && strtotime($otherSeen . ' UTC') > time() - 60,
            'other_last_seen_at' => Clock::iso($otherSeen),
            'kept' => $kept,
            'retention_note' => $kept
                ? 'Messages gardés : ' . ($asClient ? 'vous suivez' : 'le client suit') . ' cet étal.'
                : 'Les messages disparaissent ' . Messaging::ttlHours() . ' h après leur envoi' . ($asClient ? ', sauf si vous suivez l\'étal.' : ' (le client ne suit pas votre étal).'),
            'unread_count' => $unread,
            'last_message' => $last === null ? null : [
                'kind' => $last['kind'],
                'preview' => $last['deleted_at'] !== null ? '🚫 Message supprimé' : match ($last['kind']) { 'photo' => '📷 Photo', 'voice' => '🎤 Message vocal', default => mb_substr((string) $last['body'], 0, 80) },
                'mine' => (int) $last['sender_id'] === $me,
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
            'preview' => $r['deleted_at'] !== null ? 'Message supprimé' : match ($r['kind']) { 'photo' => '📷 Photo', 'voice' => '🎤 Message vocal', default => mb_substr((string) $r['body'], 0, 120) },
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
            'created_at' => Clock::iso($m['created_at']),
            'expires_at' => Clock::iso($m['expires_at']),
        ];
    }
}
