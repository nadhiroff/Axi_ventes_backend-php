<?php
declare(strict_types=1);

namespace Axi\Controllers;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Page;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Models\Notification;
use Axi\Models\Stall;
use Axi\Services\UploadService;

/** Agents Axi dans les allées : vérification des étals sur place (badge « vérifié ») */
final class AgentController
{
    /** GET /v1/agent/stalls?market_id=&todo=1&page= : étals à visiter (jamais vérifiés ou déménagés depuis) */
    public function stalls(Request $request): never
    {
        $in = Validator::validate($request->queryAll(), ['market_id' => 'int|min:1', 'todo' => 'bool']);
        $where = ["s.status = 'active'"];
        $params = [];
        if (isset($in['market_id'])) {
            $where[] = 's.market_id = ?';
            $params[] = $in['market_id'];
        }
        if ($in['todo'] ?? true) {
            $where[] = '(s.verified_at IS NULL OR s.verified_at < s.moved_at)';
        }
        $sqlWhere = ' WHERE ' . implode(' AND ', $where);
        $page = Page::from($request);
        $total = (int) Database::value('SELECT COUNT(*) FROM stalls s' . $sqlWhere, $params);
        $rows = Database::all('SELECT ' . Stall::SUMMARY . ' ' . Stall::JOINS . $sqlWhere . ' ORDER BY m.name, s.row_label, s.stall_number' . $page->limitSql(), $params);
        Response::ok(array_map([Stall::class, 'present'], $rows), 200, $page->meta($total));
    }

    /** POST /v1/agent/stalls/{id}/verify (multipart, « photo » obligatoire : l'étal à son emplacement réel) */
    public function verify(Request $request): never
    {
        $stall = Stall::findRaw($request->id());
        if ($stall === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Étal introuvable');
        }
        $photo = UploadService::store($request->file('photo'), 'image', 'photo');
        if ($photo === null) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Données invalides', ['fields' => ['photo' => 'photo de l\'étal obligatoire']]);
        }
        UploadService::delete($stall['verification_photo_path']);
        Database::updateById('stalls', (int) $stall['id'], [
            'verified_at' => Clock::now(),
            'verified_by' => $request->userId(),
            'verification_photo_path' => $photo,
        ]);
        Notification::send((int) $stall['merchant_id'], 'stall_verified', 'Étal vérifié', 'Un agent Axi a vérifié votre étal : le badge « vérifié » est affiché.');

        $row = Database::one('SELECT ' . Stall::SUMMARY . ' ' . Stall::JOINS . ' WHERE s.id = ?', [$stall['id']]);
        Response::ok(Stall::present($row));
    }
}
