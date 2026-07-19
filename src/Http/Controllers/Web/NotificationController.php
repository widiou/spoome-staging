<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\Request;
use Spoome\Core\View;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Notifications\NotificationRepository;
use Spoome\Http\Controllers\Controller;

/**
 * Centro notifiche in-app. Aprendo la pagina, le notifiche vengono segnate come lette.
 */
final class NotificationController extends Controller
{
    public function index(Request $request): void
    {
        $user = CurrentUser::resolve($request);
        $repo = new NotificationRepository();

        $items = $repo->recent($user->id, 40);
        $repo->markAllRead($user->id); // vista = lette

        View::render('notifiche/index', [
            'title' => $this->title('notif.title'),
            'items' => $items,
        ], 'base');
    }
}
