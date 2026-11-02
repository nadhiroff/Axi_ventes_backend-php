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
use Axi\Support\Blocks;
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
        // Compte bloqué : comme un compte banni, plus aucun profil visible (il revient tel quel au déblocage)
        if ($id !== $me && $u['status'] !== 'active') {
            throw new HttpException(404, 'ACCOUNT_UNAVAILABLE', 'Ce compte n\'est plus disponible.');
        }
        if ($id !== $me && !$this->related($me, $id) && !$this->foundByContact($me, $u, (string) $req->query('contact', ''))) {
            throw new HttpException(404, 'USER_NOT_FOUND', 'Profil introuvable.');
        }
        // Blocage dans un sens ou dans l'autre : aucun profil, comme s'il n'existait pas
        if (Blocks::between($me, $id)) {
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

    /**
     * Bloquer quelqu'un qui me dérange (sans passer par l'équipe Axi). Les deux ne se voient plus nulle part et
     * ne peuvent plus s'écrire ni se commander quoi que ce soit ; la personne bloquée n'est pas prévenue.
     */
    public function block(Request $req): never
    {
        $me = $req->userId();
        $id = $this->otherUser($req);
        Blocks::block($me, $id);
        Response::ok(['blocked' => true, 'message' => 'Personne bloquée. Vous ne verrez plus rien d\'elle, et elle ne pourra plus vous écrire ni voir votre profil. Débloquez-la quand vous voulez dans Paramètres > Utilisateurs bloqués.']);
    }

    public function unblock(Request $req): never
    {
        Blocks::unblock($req->userId(), $req->id('usr', 'USER_NOT_FOUND', 'Profil introuvable.'));
        Response::ok(['blocked' => false, 'message' => 'Personne débloquée. Vos discussions et son étal réapparaissent.']);
    }

    /** Les personnes que j'ai bloquées (pas celles qui m'ont bloqué, qui ne doivent pas le savoir) */
    public function blocked(Request $req): never
    {
        $rows = Database::all(
            'SELECT u.*, b.created_at AS blocked_at, s.name AS stall_name FROM user_blocks b JOIN users u ON u.id = b.blocked_id
             LEFT JOIN stalls s ON s.user_id = u.id WHERE b.blocker_id = ? ORDER BY b.created_at DESC',
            [$req->userId()],
        );
        Response::ok(['users' => array_map(static fn (array $u): array => [
            'id' => Ids::encode('usr', $u['id']),
            'display_name' => $u['status'] === 'deleted' ? 'Compte supprimé' : trim($u['first_name'] . ' ' . mb_substr($u['last_name'], 0, 1) . '.'),
            'avatar' => Users::avatarFor($u, $req->userId(), true),
            'role' => $u['role'],
            'stall_name' => $u['stall_name'],
            'blocked_at' => Clock::iso($u['blocked_at']),
        ], $rows)]);
    }

    /** Un autre compte client ou commerçant qui existe (moi exclu) */
    private function otherUser(Request $req): int
    {
        $id = $req->id('usr', 'USER_NOT_FOUND', 'Profil introuvable.');
        if ($id === $req->userId()) {
            throw new HttpException(422, 'CANNOT_BLOCK_SELF', 'Vous ne pouvez pas vous bloquer vous-même.');
        }
        if (Database::value("SELECT id FROM users WHERE id = ? AND role IN ('client','merchant') AND status <> 'deleted'", [$id]) === null) {
            throw new HttpException(404, 'USER_NOT_FOUND', 'Profil introuvable.');
        }
        return $id;
    }

    public static function reasonLabel(string $reason): string
    {
        return self::REASONS[$reason] ?? $reason;
    }

    /**
     * Commerçant qui vient de retrouver cette personne par son email ou son téléphone EXACT (« Ajouter un client ») :
     * il peut voir sa présentation avant de l'ajouter.
     */
    private function foundByContact(int $me, array $u, string $contact): bool
    {
        if ($contact === '' || Database::value('SELECT id FROM stalls WHERE user_id = ?', [$me]) === null) {
            return false;
        }
        $email = Validator::email($contact);
        $phone = $email === null ? Validator::phone($contact) : null;
        return ($email !== null && $email === $u['email']) || ($phone !== null && $phone === $u['phone']);
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
