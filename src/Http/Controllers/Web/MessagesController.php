<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\Session;
use Spoome\Core\View;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Messaging\ConversationService;
use Spoome\Domain\Messaging\MessageService;
use Spoome\Domain\Profiles\Profile;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Http\Controllers\Controller;

/**
 * Messaggi diretti lato web (area autenticata). Consentiti solo tra profili connessi (imposto nei service).
 */
final class MessagesController extends Controller
{
    public function inbox(Request $request): void
    {
        $me = $this->me($request);
        if ($me === null) {
            Response::redirect('rivendicazioni');
            return;
        }
        View::render('messaggi/inbox', [
            'title'         => $this->title('nav.messages'),
            'conversations' => (new ConversationService())->inbox($me->id),
            'notice'        => Session::takeFlash(),
        ], 'base');
    }

    public function thread(Request $request): void
    {
        $me = $this->me($request);
        if ($me === null) {
            Response::redirect('rivendicazioni');
            return;
        }
        $repo   = new ProfileRepository();
        $target = $repo->findByHandle((string) $request->param('handle', ''));
        if ($target === null) {
            Response::redirect('messaggi');
            return;
        }

        $result = (new ConversationService())->thread($me->id, $target->id);
        if (!$result->ok) {
            Session::flash($result->error ?? I18n::t('api.error.invalid_data'), 'error');
            Response::redirect('atleti/' . $target->handle);
            return;
        }

        $cards = $repo->cardsByIds([$target->id]);

        View::render('messaggi/thread', [
            'title'    => $this->title('nav.messages') . ' · ' . $target->displayName,
            'other'    => $cards[$target->id] ?? null,
            'handle'   => $target->handle,
            'messages' => $result->data['messages'],
            'notice'   => Session::takeFlash(),
        ], 'base');
    }

    public function send(Request $request): void
    {
        $me = $this->me($request);
        if ($me === null) {
            Response::redirect('rivendicazioni');
            return;
        }
        $target = $this->resolveTargetOr404($request, new ProfileRepository(), 'messaggi');
        if ($target === null) {
            return;
        }
        $result = (new MessageService())->send($me->id, $target->id, $request->body(), $request->ip());
        $this->respond($request, $result, 'messaggi/' . $target->handle);
    }

    /** Polling JSON: nuovi messaggi di una conversazione con id > after. */
    public function poll(Request $request): void
    {
        $me = $this->me($request);
        if ($me === null) {
            Response::error(I18n::t('api.error.unauthorized'), 401);
            return;
        }
        $target = (new ProfileRepository())->findByHandle((string) $request->param('handle', ''));
        if ($target === null) {
            Response::error(I18n::t('atleti.show.not_found_title'), 404);
            return;
        }
        $after  = (int) $request->input('after', 0);
        $result = (new ConversationService())->newMessages($me->id, $target->id, $after);
        if (!$result->ok) {
            Response::error($result->error ?? I18n::t('api.error.invalid_data'), $result->code >= 400 ? $result->code : 422);
            return;
        }
        Response::json($result->data, 200);
    }

    private function me(Request $request): ?Profile
    {
        $user = CurrentUser::resolve($request);
        return (new ProfileRepository())->findByUserId($user->id);
    }
}
