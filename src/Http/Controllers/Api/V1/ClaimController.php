<?php

namespace Spoome\Http\Controllers\Api\V1;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\ServiceResult;
use Spoome\Domain\Claims\ClaimService;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Http\Controllers\ApiController;

/**
 * API rivendicazione profilo (JSON, solo-Bearer). Guscio sottile sopra ClaimService (stessa logica del web).
 */
final class ClaimController extends ApiController
{
    /** POST /profiles/{handle}/claim — invia una richiesta di rivendicazione del profilo non rivendicato. */
    public function request(Request $request): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        $handle  = (string) $request->param('handle', '');
        $profile = $handle !== '' ? (new ProfileRepository())->findPublicByHandle($handle) : null;
        if ($profile === null) {
            Response::error(I18n::t('atleti.show.not_found_title'), 404);
            return;
        }
        $result = (new ClaimService())->request(
            $user->id,
            (int) $profile['id'],
            (string) $request->input('message', ''),
            $request->ip()
        );
        if ($result->ok) {
            $result = ServiceResult::ok(['requested' => true], ['message' => (string) ($result->meta['message'] ?? '')]);
        }
        $this->emitJson($result);
    }
}
