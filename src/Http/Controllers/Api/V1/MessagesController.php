<?php

namespace Spoome\Http\Controllers\Api\V1;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Domain\Messaging\ConversationService;
use Spoome\Domain\Messaging\MessageService;
use Spoome\Domain\Profiles\Profile;
use Spoome\Domain\Profiles\ProfilePresenter;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Http\Controllers\ApiController;

/**
 * API messaggi diretti (JSON, solo-Bearer). Consentiti solo tra profili connessi.
 */
final class MessagesController extends ApiController
{
    /** GET /me/conversations — inbox. */
    public function inbox(Request $request): void
    {
        $me = $this->meProfile($request);
        if ($me === null) {
            return;
        }
        Response::json((new ConversationService())->inbox($me->id));
    }

    /** GET /me/conversations/{handle} — thread con un profilo (marca letti). */
    public function thread(Request $request): void
    {
        $me = $this->meProfile($request);
        if ($me === null) {
            return;
        }
        $repo   = new ProfileRepository();
        $target = $this->resolveTargetOr404($request, $repo);
        if ($target === null) {
            return;
        }
        $page = max(1, (int) $request->input('pagina', $request->input('page', 1)));
        // Seek pagination: ?before=<id> carica i messaggi più vecchi di quell'id (scroll all'indietro).
        $before = (int) $request->input('before', 0);
        $result = (new ConversationService())->thread(
            $me->id,
            $target->id,
            $page,
            ConversationService::PER_PAGE,
            $before > 0 ? $before : null
        );
        if (!$result->ok) {
            $this->emitJson($result);
            return;
        }
        $cards = $repo->cardsByIds([$target->id]);
        $other = $cards[$target->id] ?? null;
        Response::json([
            'conversation_id' => $result->data['conversation_id'],
            'other'           => $other !== null ? ProfilePresenter::card($other) : null,
            'messages'        => $result->data['messages'],
        ], 200, [
            'has_more'    => $result->data['has_more'],
            'next_cursor' => $result->data['next_cursor'],
        ]);
    }

    /** POST /me/conversations/{handle} — invia un messaggio. */
    public function send(Request $request): void
    {
        $me = $this->meProfile($request);
        if ($me === null) {
            return;
        }
        $target = $this->resolveTargetOr404($request, new ProfileRepository());
        if ($target === null) {
            return;
        }
        $this->emitJson((new MessageService())->send($me->id, $target->id, $request->body(), $request->ip()));
    }

    private function meProfile(Request $request): ?Profile
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return null;
        }
        $me = (new ProfileRepository())->findByUserId($user->id);
        if ($me === null) {
            Response::error(I18n::t('api.error.unauthorized'), 404);
            return null;
        }
        return $me;
    }
}
