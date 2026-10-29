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
use Axi\Support\Stalls;
use Axi\Support\Users;

/**
 * Présentation publique d'une personne (jamais d'email ni de téléphone), visible seulement de quelqu'un
 * qui échange avec elle (conversation) ou d'un commerçant dont elle est cliente ; signalement d'un profil.
 */
final class ProfileController
{
    private const REASONS = ['spam' => 'Messages indésirables', 'fraud' => 'Arnaque ou fraude', 'insult' => 'Insultes ou harcèlement', 'fake' => 'Faux profil', 'other' => 'Autre'];

    public function show(Request $req): never
    {
        $me = $req->userId();
        $id = $req->id('usr', 'USER_NOT_FOUND', 'Profil introuvable.');
        $u = Database::one("SELECT * FROM users WHERE id = ? AND status <> 'deleted' AND role IN ('client','merchant')", [$id])
            ?? throw new HttpException(404, 'USER_NOT_FOUND', 'Profil introuvable.');
        if ($id !== $me && !$this->related($me, $id)) {
            throw new HttpException(404, 'USER_NOT_FOUND', 'Profil introuvable.');
        }
        $stall = $u['role'] === 'merchant' ? Stalls::findByUser($id) : null;
        $myStall = Database::value('SELECT id FROM stalls WHERE user_id = ?', [$me]);
        $asClient = $myStall === null ? null : Database::one('SELECT orders_count, total_spent FROM merchant_clients WHERE stall_id = ? AND user_id = ?', [$myStall, $id]);
        Response::ok([
            'id' => Ids::encode('usr', $id),
            // Prénom et initiale du nom seulement
            'display_name' => trim($u['first_name'] . ' ' . mb_substr($u['last_name'], 0, 1) . '.'),
            'avatar' => Users::avatarFor($u, $me, true),
            'bio' => $u['bio'],
            'member_since' => Clock::iso($u['created_at']),
            'role' => $u['role'],
            'stall' => $stall === null || $stall['status'] !== 'active' ? null : [
                'id' => Ids::encode('stl', $stall['id']), 'name' => $stall['name'], 'verified' => $stall['verified_at'] !== null, 'subscribed' => (bool) $stall['subscribed'],
            ],
            // Pour le commerçant : ce que ce client a acheté chez lui
            'with_me' => $asClient === null ? null : ['orders_count' => (int) $asClient['orders_count'], 'total_spent' => (int) $asClient['total_spent']],
            'reported' => Database::value("SELECT 1 FROM reports WHERE reporter_id = ? AND target_user_id = ? AND status = 'open'", [$me, $id]) !== null,
        ]);
    }

    /** Signaler un profil : { reason, details?, conversation_id? } ; un agent Axi examine et peut bloquer l'accès */
    public function report(Request $req): never
    {
        $me = $req->userId();
        $id = $req->id('usr', 'USER_NOT_FOUND', 'Profil introuvable.');
        if ($id === $me || Database::value("SELECT id FROM users WHERE id = ? AND role IN ('client','merchant')", [$id]) === null || !$this->related($me, $id)) {
            throw new HttpException(404, 'USER_NOT_FOUND', 'Profil introuvable.');
        }
        $in = Validator::validate($req->all(), ['reason' => 'required|in:spam,fraud,insult,fake,other', 'details' => 'string|max:1000', 'conversation_id' => 'string'], ['reason' => 'Motif', 'details' => 'Détails']);
        if (Database::value("SELECT 1 FROM reports WHERE reporter_id = ? AND target_user_id = ? AND status = 'open'", [$me, $id]) !== null) {
            throw new HttpException(409, 'ALREADY_REPORTED', 'Vous avez déjà signalé ce profil : l\'équipe Axi l\'examine.');
        }
        $conv = isset($in['conversation_id']) ? Ids::tryDecode('conv', $in['conversation_id']) : null;
        Database::insert(
            'INSERT INTO reports (reporter_id, target_user_id, conversation_id, reason, details, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$me, $id, $conv, $in['reason'], $in['details'] ?? null, Clock::now()],
        );
        Response::ok(['message' => 'Merci. L\'équipe Axi examine ce signalement et bloquera ce compte s\'il ne respecte pas les règles.'], 201);
    }

    public static function reasonLabel(string $reason): string
    {
        return self::REASONS[$reason] ?? $reason;
    }

    /** Conversation entre les deux, ou l'un est client du commerçant de l'autre */
    private function related(int $a, int $b): bool
    {
        return Database::value(
            'SELECT 1 FROM conversations c JOIN stalls s ON s.id = c.stall_id
             WHERE (c.client_id = ? AND s.user_id = ?) OR (c.client_id = ? AND s.user_id = ?)
             UNION SELECT 1 FROM merchant_clients mc JOIN stalls s ON s.id = mc.stall_id
             WHERE (mc.user_id = ? AND s.user_id = ?) OR (mc.user_id = ? AND s.user_id = ?) LIMIT 1',
            [$a, $b, $b, $a, $a, $b, $b, $a],
        ) !== null;
    }
}
