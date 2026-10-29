<?php
declare(strict_types=1);

namespace Axi\Controllers;

use Axi\Core\Auth;
use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Ids;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Services\Notifier;
use Axi\Services\SignedUrl;
use Axi\Support\Stalls;

/**
 * Paramètres et vie privée : préférences (thème, notifications, infos mémorisées, plafond de données),
 * sécurité (sessions, historique des connexions), consentements, export et suppression du compte,
 * notifications, favoris et produits récemment vus. Tout est enregistré en base.
 */
final class AccountController
{
    public function settings(Request $req): never
    {
        Response::ok($this->presentSettings($req->userId()));
    }

    public function updateSettings(Request $req): never
    {
        $body = $req->all();
        $in = Validator::validate($body, [
            'theme' => 'in:system,light,dark',
            'currency' => 'in:XOF',
            'date_format' => 'in:dd/mm/yyyy,yyyy-mm-dd',
            'default_address' => 'string|max:255',
            'preferred_point_id' => 'string|max:20',
            'preferred_operator' => 'in:mtn,moov,celtiis',
            'preferred_payment_phone' => 'phone',
            'data_cap_mb' => 'int|min:100|maxval:20000',
        ], ['data_cap_mb' => 'Plafond de données', 'preferred_payment_phone' => 'Téléphone de paiement', 'default_address' => 'Adresse']);

        if (isset($in['preferred_point_id'])) {
            $pointId = Ids::tryDecode('pt', $in['preferred_point_id']);
            if ($pointId === null || Database::value('SELECT id FROM axi_points WHERE id = ? AND active = 1', [$pointId]) === null) {
                Validator::fail(['preferred_point_id' => 'Point Axi inconnu.']);
            }
            $in['preferred_point_id'] = $pointId;
        }
        // Champs vidés volontairement
        foreach (['default_address', 'preferred_point_id', 'preferred_operator', 'preferred_payment_phone'] as $key) {
            if (array_key_exists($key, $body) && ($body[$key] === null || $body[$key] === '')) {
                $in[$key] = null;
            }
        }
        if (isset($body['notifications'])) {
            if (!is_array($body['notifications'])) {
                Validator::fail(['notifications' => 'Format invalide.']);
            }
            $prefs = Notifier::preferences($req->userId());
            foreach ($body['notifications'] as $type => $channels) {
                if (!isset($prefs[$type]) || !is_array($channels)) {
                    Validator::fail(['notifications' => "Type de notification inconnu : $type."]);
                }
                foreach (['app', 'email'] as $channel) {
                    if (array_key_exists($channel, $channels)) {
                        $prefs[$type][$channel] = (bool) $channels[$channel];
                    }
                }
            }
            $in['notifications'] = json_encode($prefs);
        }
        $this->ensureRow($req->userId());
        if ($in !== []) {
            $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", array_keys($in)));
            Database::run("UPDATE user_settings SET $set WHERE user_id = ?", [...array_values($in), $req->userId()]);
        }
        Response::ok($this->presentSettings($req->userId()));
    }

    /** L'application mesure ses propres échanges et les envoie : indicateur de consommation du mois */
    public function dataUsage(Request $req): never
    {
        $bytes = Validator::validate($req->all(), ['bytes' => 'required|int|min:0|maxval:500000000'], ['bytes' => 'Données'])['bytes'];
        $this->ensureRow($req->userId());
        $month = gmdate('Y-m');
        Database::run(
            'UPDATE user_settings SET data_used_bytes = IF(data_month = ?, data_used_bytes, 0) + ?, data_month = ? WHERE user_id = ?',
            [$month, $bytes, $month, $req->userId()],
        );
        Response::ok($this->presentSettings($req->userId())['data']);
    }

    /** Appareils connectés (jetons actifs) */
    public function sessions(Request $req): never
    {
        $rows = Database::all(
            'SELECT id, device, ip, created_at, last_used_at FROM auth_tokens WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ? ORDER BY last_used_at DESC',
            [$req->userId(), Clock::now()],
        );
        Response::ok(['sessions' => array_map(static fn (array $t): array => [
            'id' => Ids::encode('ses', $t['id']),
            'device' => $t['device'] ?? 'Appareil inconnu',
            'ip' => $t['ip'],
            'current' => (int) $t['id'] === $req->tokenId,
            'created_at' => Clock::iso($t['created_at']),
            'last_used_at' => Clock::iso($t['last_used_at']),
        ], $rows)]);
    }

    public function revokeSession(Request $req): never
    {
        $id = $req->id('ses');
        $n = Database::run('UPDATE auth_tokens SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [Clock::now(), $id, $req->userId()])->rowCount();
        if ($n === 0) {
            throw new HttpException(404, 'NOT_FOUND', 'Session introuvable.');
        }
        Response::ok(['revoked' => true]);
    }

    /** Historique des connexions (30 dernières), réussies et refusées */
    public function loginHistory(Request $req): never
    {
        $rows = Database::all('SELECT * FROM login_events WHERE user_id = ? ORDER BY id DESC LIMIT 30', [$req->userId()]);
        Response::ok(['events' => array_map(static fn (array $e): array => [
            'success' => (bool) $e['success'],
            'label' => $e['success'] ? 'Connexion réussie' : match ($e['reason']) {
                'bad_credentials' => 'Mot de passe incorrect',
                'suspended' => 'Compte suspendu',
                default => 'Connexion refusée',
            },
            'device' => $e['device'],
            'ip' => $e['ip'],
            'channel' => $e['channel'],
            'created_at' => Clock::iso($e['created_at']),
        ], $rows)]);
    }

    /** Consentements donnés (partage d'historique avec une IMF) : voir et révoquer */
    public function consents(Request $req): never
    {
        $rows = Database::all(
            'SELECT c.*, o.name AS org_name FROM data_sharing_consents c JOIN organizations o ON o.id = c.organization_id WHERE c.merchant_user_id = ? ORDER BY c.id DESC',
            [$req->userId()],
        );
        Response::ok(['consents' => array_map(static fn (array $c): array => [
            'id' => Ids::encode('cst', $c['id']),
            'partner_name' => $c['org_name'],
            'scope' => $c['scope'],
            'granted_at' => Clock::iso($c['granted_at']),
            'revoked_at' => Clock::iso($c['revoked_at']),
            'active' => $c['revoked_at'] === null,
        ], $rows)]);
    }

    public function revokeConsent(Request $req): never
    {
        $id = $req->id('cst');
        $n = Database::run('UPDATE data_sharing_consents SET revoked_at = ? WHERE id = ? AND merchant_user_id = ? AND revoked_at IS NULL', [Clock::now(), $id, $req->userId()])->rowCount();
        if ($n === 0) {
            throw new HttpException(404, 'NOT_FOUND', 'Consentement introuvable ou déjà retiré.');
        }
        Response::ok(['revoked' => true]);
    }

    /** Export de mes données : lien signé valable 10 minutes (ouvert dans le navigateur) */
    public function exportLink(Request $req): never
    {
        Response::ok(['url' => SignedUrl::make('/v1/me/export.json', ['u' => $req->userId()], 600)]);
    }

    public function export(Request $req): never
    {
        $userId = (int) SignedUrl::verify('/v1/me/export.json', $req->queryAll())['u'];
        $data = [
            'exported_at' => gmdate('c'),
            'account' => Database::one('SELECT first_name, last_name, email, phone, role, lang, created_at FROM users WHERE id = ?', [$userId]),
            'settings' => Database::one('SELECT theme, currency, date_format, notifications, default_address, preferred_operator, preferred_payment_phone FROM user_settings WHERE user_id = ?', [$userId]),
            'orders' => Database::all('SELECT id, status, total, reception_mode, reception_detail, created_at, paid_at, delivered_at FROM orders WHERE client_id = ? ORDER BY id', [$userId]),
            'payments' => Database::all('SELECT reference, purpose, provider, amount, status, created_at, paid_at FROM payments WHERE user_id = ? ORDER BY id', [$userId]),
            'followed_stalls' => Database::all('SELECT s.name, f.created_at FROM stall_follows f JOIN stalls s ON s.id = f.stall_id WHERE f.user_id = ?', [$userId]),
            'favorites' => Database::all('SELECT p.name, f.created_at FROM favorites f JOIN products p ON p.id = f.product_id WHERE f.user_id = ?', [$userId]),
            'consents' => Database::all('SELECT scope, granted_at, revoked_at FROM data_sharing_consents WHERE merchant_user_id = ?', [$userId]),
            'logins' => Database::all('SELECT success, device, ip, created_at FROM login_events WHERE user_id = ? ORDER BY id DESC LIMIT 200', [$userId]),
        ];
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="mes-donnees-axi.json"');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Suppression du compte (le mot de passe est redemandé ; l'application demande deux confirmations).
     * Les données personnelles sont effacées ; les commandes restent, anonymes, pour la comptabilité.
     */
    public function deleteAccount(Request $req): never
    {
        $in = Validator::validate($req->all(), ['password' => 'required|string|raw|max:200'], ['password' => 'Mot de passe']);
        if (!password_verify($in['password'], $req->user['password_hash'])) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Mot de passe incorrect.', ['fields' => ['password' => 'Mot de passe incorrect.']]);
        }
        $userId = $req->userId();
        $open = Database::value("SELECT COUNT(*) FROM orders WHERE client_id = ? AND status IN ('paid','preparing','on_the_way','disputed')", [$userId]);
        $merchantOpen = Database::value("SELECT COUNT(*) FROM order_stalls os JOIN stalls s ON s.id = os.stall_id WHERE s.user_id = ? AND os.escrow_state IN ('held','disputed')", [$userId]);
        if ((int) $open > 0 || (int) $merchantOpen > 0) {
            throw new HttpException(409, 'OPEN_ORDERS', 'Vous avez des commandes en cours. Attendez leur livraison avant de supprimer votre compte.');
        }
        Database::transaction(static function () use ($userId): void {
            Database::run(
                'UPDATE users SET first_name = \'Compte\', last_name = \'supprimé\', name = \'Compte supprimé\', email = ?, phone = NULL, password_hash = ?, status = \'deleted\', deleted_at = ? WHERE id = ?',
                ['supprime-' . $userId . '@deleted.invalid', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), Clock::now(), $userId],
            );
            foreach (['user_settings', 'favorites', 'product_views', 'stall_follows', 'notifications'] as $table) {
                Database::run("DELETE FROM $table WHERE user_id = ?", [$userId]);
            }
            Database::run('UPDATE data_sharing_consents SET revoked_at = ? WHERE merchant_user_id = ? AND revoked_at IS NULL', [Clock::now(), $userId]);
            Database::run('UPDATE stalls SET status = \'closed\', phone = NULL, whatsapp = NULL WHERE user_id = ?', [$userId]);
            Database::run('UPDATE merchant_clients SET user_id = NULL WHERE user_id = ?', [$userId]);
        });
        Auth::revokeAll($userId);
        Response::ok(['deleted' => true]);
    }

    public function notifications(Request $req): never
    {
        $rows = Database::all('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$req->userId()]);
        Response::ok([
            'unread' => (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$req->userId()]),
            'notifications' => array_map(static fn (array $n): array => [
                'id' => Ids::encode('ntf', $n['id']),
                'type' => $n['type'],
                'title' => $n['title'],
                'body' => $n['body'],
                'data' => $n['data'] === null ? (object) [] : json_decode($n['data'], true),
                'read' => $n['read_at'] !== null,
                'created_at' => Clock::iso($n['created_at']),
            ], $rows),
        ]);
    }

    /** { "ids": ["ntf_1"] } ou { "all": true } */
    public function readNotifications(Request $req): never
    {
        if ($req->input('all') === true) {
            Database::run('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [Clock::now(), $req->userId()]);
        } else {
            $ids = array_filter(array_map(static fn ($id) => Ids::tryDecode('ntf', $id), (array) $req->input('ids', [])));
            if ($ids !== []) {
                Database::run('UPDATE notifications SET read_at = ? WHERE user_id = ? AND id IN (' . Database::in($ids) . ')', [Clock::now(), $req->userId(), ...$ids]);
            }
        }
        Response::ok(['unread' => (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$req->userId()])]);
    }

    public function favorites(Request $req): never
    {
        $rows = Database::all(
            'SELECT p.*, s.name AS stall_name, s.verified_at AS stall_verified_at FROM favorites f
             JOIN products p ON p.id = f.product_id JOIN stalls s ON s.id = p.stall_id
             WHERE f.user_id = ? AND ' . Stalls::VISIBLE . ' AND s.status = \'active\' ORDER BY f.created_at DESC LIMIT 100',
            [$req->userId()],
        );
        Response::ok(['products' => $this->withStall($rows)]);
    }

    public function addFavorite(Request $req): never
    {
        $id = $this->activeProduct($req);
        Database::run('INSERT IGNORE INTO favorites (user_id, product_id, created_at) VALUES (?, ?, ?)', [$req->userId(), $id, Clock::now()]);
        Response::ok(['is_favorite' => true]);
    }

    public function removeFavorite(Request $req): never
    {
        $id = $req->id('prd', 'PRODUCT_NOT_FOUND', 'Produit introuvable.');
        Database::run('DELETE FROM favorites WHERE user_id = ? AND product_id = ?', [$req->userId(), $id]);
        Response::ok(['is_favorite' => false]);
    }

    public function recentlyViewed(Request $req): never
    {
        $rows = Database::all(
            'SELECT p.*, s.name AS stall_name, s.verified_at AS stall_verified_at FROM product_views v
             JOIN products p ON p.id = v.product_id JOIN stalls s ON s.id = p.stall_id
             WHERE v.user_id = ? AND ' . Stalls::VISIBLE . ' AND s.status = \'active\' ORDER BY v.viewed_at DESC LIMIT 30',
            [$req->userId()],
        );
        Response::ok(['products' => $this->withStall($rows)]);
    }

    private function withStall(array $rows): array
    {
        $list = Stalls::productList($rows);
        foreach ($rows as $i => $p) {
            $list[$i]['stall'] = ['id' => Ids::encode('stl', $p['stall_id']), 'name' => $p['stall_name'], 'verified' => $p['stall_verified_at'] !== null];
        }
        return $list;
    }

    private function activeProduct(Request $req): int
    {
        $id = $req->id('prd', 'PRODUCT_NOT_FOUND', 'Produit introuvable.');
        if (Database::value('SELECT id FROM products WHERE id = ? AND status = \'active\'', [$id]) === null) {
            throw new HttpException(404, 'PRODUCT_NOT_FOUND', 'Ce produit n\'existe pas ou a été supprimé.');
        }
        return $id;
    }

    private function ensureRow(int $userId): void
    {
        Database::run('INSERT IGNORE INTO user_settings (user_id) VALUES (?)', [$userId]);
    }

    private function presentSettings(int $userId): array
    {
        $this->ensureRow($userId);
        $s = Database::one('SELECT * FROM user_settings WHERE user_id = ?', [$userId]);
        $used = $s['data_month'] === gmdate('Y-m') ? (int) $s['data_used_bytes'] : 0;
        return [
            'theme' => $s['theme'],
            'lang' => Database::value('SELECT lang FROM users WHERE id = ?', [$userId]),
            'currency' => $s['currency'],
            'date_format' => $s['date_format'],
            'notifications' => Notifier::preferences($userId),
            'default_address' => $s['default_address'],
            'preferred_point_id' => Ids::encode('pt', $s['preferred_point_id']),
            'preferred_operator' => $s['preferred_operator'],
            'preferred_payment_phone' => $s['preferred_payment_phone'],
            // Points de retrait disponibles, pour choisir le point préféré
            'axi_points' => array_map(static fn (array $pt): array => ['id' => Ids::encode('pt', $pt['id']), 'name' => $pt['name'], 'address' => $pt['address']], Database::all('SELECT * FROM axi_points WHERE active = 1 ORDER BY sort_order, id')),
            'data' => [
                'cap_mb' => (int) $s['data_cap_mb'],
                'used_bytes' => $used,
                'used_pct' => (int) min(100, round(100 * $used / max(1, (int) $s['data_cap_mb'] * 1048576))),
                'month' => gmdate('Y-m'),
            ],
        ];
    }

    /** Photo de profil (facultative) : multipart « photo ». Qui la voit : PATCH /v1/me { avatar_visibility } */
    public function avatar(Request $req): never
    {
        $photo = $req->file('photo') ?? \Axi\Core\Validator::fail(['photo' => 'Choisissez une photo.'], ['photo' => 'Photo de profil']);
        $saved = \Axi\Services\Uploads::photo($photo);
        Database::run('UPDATE users SET avatar_path = ? WHERE id = ?', [$saved['path'], $req->userId()]);
        Response::ok(\Axi\Support\Users::presentById($req->userId()));
    }

    public function deleteAvatar(Request $req): never
    {
        Database::run('UPDATE users SET avatar_path = NULL WHERE id = ?', [$req->userId()]);
        Response::ok(\Axi\Support\Users::presentById($req->userId()));
    }
}
