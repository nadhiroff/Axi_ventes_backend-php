<?php
declare(strict_types=1);

namespace Axi\Controllers;

use Axi\Core\Page;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Models\Notification;

final class NotificationController
{
    /** GET /v1/notifications?page= */
    public function index(Request $request): never
    {
        $page = Page::from($request);
        [$items, $total, $unread] = Notification::list($request->userId(), $page);
        Response::ok($items, 200, $page->meta($total, ['unread' => $unread]));
    }

    /** POST /v1/notifications/read {ids?} : sans ids, tout est marqué comme lu */
    public function markRead(Request $request): never
    {
        $in = Validator::validate($request->all(), ['ids' => 'ids']);
        Notification::markRead($request->userId(), $in['ids'] ?? null);
        Response::noContent();
    }
}
