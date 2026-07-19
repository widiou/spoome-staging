<?php

namespace Spoome\Http\Controllers\Api\V1;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\ServiceResult;
use Spoome\Domain\Connections\ConnectionSuggestionService;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Http\Controllers\ApiController;

/**
 * API suggerimenti di connessione (JSON, solo-Bearer). Guscio sottile sopra ConnectionSuggestionService.
 */
final class SuggestionController extends ApiController
{
    /** DELETE /me/suggestions/{handle} — ignora un suggerimento "persone che potresti conoscere". */
    public function dismiss(Request $request): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        $repo   = new ProfileRepository();
        $me     = $repo->findByUserId($user->id);
        if ($me === null) {
            Response::error(I18n::t('atleti.show.not_found_title'), 404);
            return;
        }
        $target = $this->resolveTargetOr404($request, $repo);
        if ($target === null) {
            return;
        }
        $result = (new ConnectionSuggestionService())->dismiss($me->id, $target->id, $request->ip());
        if ($result->ok) {
            $result = ServiceResult::ok(['dismissed' => true, 'handle' => $target->handle]);
        }
        $this->emitJson($result);
    }
}
