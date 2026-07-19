<?php

namespace Spoome\Http\Controllers\Api\V1;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Domain\Links\LinkUnfurlService;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Http\Controllers\ApiController;

/**
 * Unfurl link per client nativi (JSON, solo-Bearer, anti-CSRF via token esplicito).
 * Il web usa la rotta gemella /feed/unfurl (sessione+CSRF): stessa logica, unico LinkUnfurlService.
 */
final class LinkController extends ApiController
{
    /** POST /api/v1/links/unfurl */
    public function unfurl(Request $request): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        $profile = (new ProfileRepository())->findByUserId($user->id);
        if ($profile === null) {
            Response::error(I18n::t('api.error.unauthorized'), 404);
            return;
        }
        $this->emitJson((new LinkUnfurlService())->unfurl((string) $request->input('url', ''), $profile->id, $request->ip()));
    }
}
