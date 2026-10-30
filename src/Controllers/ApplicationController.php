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
use Axi\Services\Uploads;
use Axi\Support\Users;

/**
 * « Devenir commerçant » : un client envoie une demande (pièce d'identité recto/verso, selfie, photo de l'étal
 * à son emplacement réel, téléphone, emplacement). Un agent Axi la vérifie dans l'espace web ; s'il valide,
 * le compte devient commerçant, l'étal est créé et vérifié, et un email + un message de félicitations
 * (affiché une seule fois) préviennent le commerçant.
 */
final class ApplicationController
{
    private const LABELS = [
        'phone' => 'Téléphone',
        'id_type' => 'Type de pièce',
        'id_number' => 'Numéro de la pièce',
        'stall_name' => 'Nom de l\'étal',
        'merchant_type' => 'Type de vente',
        'site_id' => 'Marché',
        'site_other' => 'Nom du marché',
        'aisle_id' => 'Allée',
        'aisle_other' => 'Nom de l\'allée',
        'row' => 'Rangée',
        'stand' => 'Numéro de stand',
        'id_front' => 'Pièce d\'identité (recto)',
        'selfie' => 'Photo de vous',
        'stall_photo' => 'Photo de l\'étal',
    ];

    public function show(Request $req): never
    {
        $row = Database::one('SELECT * FROM merchant_applications WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$req->userId()]);
        Response::ok(['application' => $row === null ? null : self::present($row)]);
    }

    /** POST multipart : champs ci-dessus + fichiers id_front, id_back (facultatif), selfie, stall_photo */
    public function create(Request $req): never
    {
        if ($req->user['role'] !== 'client') {
            throw new HttpException(409, 'ALREADY_MERCHANT', 'Votre compte est déjà un compte commerçant.');
        }
        if (Database::value('SELECT 1 FROM merchant_applications WHERE user_id = ? AND status = \'pending\'', [$req->userId()]) !== null) {
            throw new HttpException(409, 'APPLICATION_PENDING', 'Votre demande est déjà en cours de vérification par un agent Axi.');
        }
        $in = Validator::validate($req->all(), [
            'phone' => 'required|phone',
            'id_type' => 'required|in:cni,cip,passport',
            'id_number' => 'required|string|max:40',
            'stall_name' => 'required|string|max:100',
            'merchant_type' => 'in:retail,wholesale',
            'site_id' => 'string|max:30',
            'site_other' => 'string|max:100',
            'aisle_id' => 'string|max:30',
            'aisle_other' => 'string|max:100',
            'row' => 'string|max:30',
            'stand' => 'string|max:20',
        ], self::LABELS);

        // Autres numéros (un par opérateur : MTN, Moov, Celtiis) : 2 au plus, différents du principal
        $others = [];
        foreach (array_slice((array) ($req->all()['other_phones'] ?? []), 0, 3) as $i => $raw) {
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }
            $phone = Validator::phone(trim($raw)) ?? Validator::fail(['other_phones' => 'Le numéro ' . ($i + 2) . ' ne semble pas valide.'], self::LABELS);
            if ($phone !== $in['phone'] && !in_array($phone, $others, true)) {
                $others[] = $phone;
            }
        }
        if (count($others) > 2) {
            Validator::fail(['other_phones' => '3 numéros au plus (un par opérateur).'], self::LABELS);
        }

        [$siteId, $siteOther] = self::choice($in, 'site', 'SELECT id FROM sites WHERE id = ? AND active = 1', 'Choisissez votre marché ou écrivez son nom dans « Autre ».');
        [$aisleId, $aisleOther] = self::choice($in, 'aisle', 'SELECT id FROM aisles WHERE id = ? AND active = 1', 'Choisissez votre allée ou écrivez son nom dans « Autre ».');

        $missing = [];
        foreach (['id_front', 'selfie', 'stall_photo'] as $field) {
            if ($req->file($field) === null) {
                $missing[$field] = 'Ajoutez cette photo.';
            }
        }
        if ($missing !== []) {
            Validator::fail($missing, self::LABELS);
        }
        $front = Uploads::privatePhoto($req->file('id_front'), 'applications');
        $back = $req->file('id_back') === null ? null : Uploads::privatePhoto($req->file('id_back'), 'applications');
        $selfie = Uploads::privatePhoto($req->file('selfie'), 'applications');
        $stallPhoto = Uploads::photo($req->file('stall_photo'))['path'];

        Database::insert(
            'INSERT INTO merchant_applications (user_id, phone, other_phones, id_type, id_number, id_front_path, id_back_path, selfie_path, stall_photo_path, stall_name, merchant_type, site_id, site_other, aisle_id, aisle_other, row_label, stand, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$req->userId(), $in['phone'], $others === [] ? null : implode(',', $others), $in['id_type'], $in['id_number'], $front, $back, $selfie, $stallPhoto, $in['stall_name'], $in['merchant_type'] ?? 'retail',
                $siteId, $siteOther, $aisleId, $aisleOther, $in['row'] ?? null, $in['stand'] ?? null, Clock::now()],
        );
        if ($req->user['phone'] === null && Database::value('SELECT id FROM users WHERE phone = ?', [$in['phone']]) === null) {
            Database::run('UPDATE users SET phone = ? WHERE id = ?', [$in['phone'], $req->userId()]);
        }
        $row = Database::one('SELECT * FROM merchant_applications WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$req->userId()]);
        Response::ok([
            'application' => self::present($row),
            'user' => Users::presentById($req->userId()),
            'message' => 'Demande envoyée. Un agent Axi la vérifie, en général sous 48 h. Vous recevrez un email dès qu\'elle est acceptée.',
        ], 201);
    }

    /** Le commerçant a vu le message de félicitations : il ne s'affichera plus */
    public function welcomeSeen(Request $req): never
    {
        Database::run('UPDATE users SET merchant_welcome_pending = 0 WHERE id = ?', [$req->userId()]);
        Response::ok(['seen' => true]);
    }

    public static function present(array $a): array
    {
        return [
            'id' => Ids::encode('dem', $a['id']),
            'status' => $a['status'],
            'status_label' => ['pending' => 'En cours de vérification', 'approved' => 'Acceptée', 'rejected' => 'Refusée'][$a['status']],
            'reject_reason' => $a['reject_reason'],
            'stall_name' => $a['stall_name'],
            'created_at' => Clock::iso($a['created_at']),
            'reviewed_at' => Clock::iso($a['reviewed_at']),
        ];
    }

    /**
     * Marché ou allée : un identifiant de la liste, OU un nom libre (« Autre ») qu'un agent ajoutera à la liste.
     * @return array{0: ?string, 1: ?string}
     */
    private static function choice(array $in, string $name, string $existsSql, string $message): array
    {
        $id = $in[$name . '_id'] ?? null;
        $other = trim((string) ($in[$name . '_other'] ?? ''));
        if ($id !== null && $id !== '' && Database::value($existsSql, [$id]) !== null && !in_array($id, ['autre', 'autres'], true)) {
            return [$id, null];
        }
        if (mb_strlen($other) >= 2) {
            return [null, $other];
        }
        Validator::fail([$name . '_id' => $message], self::LABELS);
    }
}
