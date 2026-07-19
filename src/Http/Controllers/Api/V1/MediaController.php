<?php

namespace Spoome\Http\Controllers\Api\V1;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\ServiceResult;
use Spoome\Domain\Media\MediaService;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Domain\Users\User;
use Spoome\Http\Controllers\ApiController;

/**
 * API immagini del profilo (JSON, solo-Bearer). Upload multipart (`multipart/form-data`, campo `image`)
 * e rimozione di avatar/copertina. Guscio sottile sopra MediaService, condiviso col web (parity nativa).
 *
 * Multi-profilo: opera sull'ACTING profile (header X-Acting-Profile), autorizzato via canActAs('editor').
 */
final class MediaController extends ApiController
{
    public function uploadAvatar(Request $request): void
    {
        $this->store($request, 'avatar');
    }

    public function deleteAvatar(Request $request): void
    {
        $this->removeKind($request, 'avatar');
    }

    public function uploadCover(Request $request): void
    {
        $this->store($request, 'cover');
    }

    public function deleteCover(Request $request): void
    {
        $this->removeKind($request, 'cover');
    }

    /* ------------------------------------------------------------- shared ---- */

    private function store(Request $request, string $kind): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        $pid = $this->actingProfileId($request, $user);
        if ($pid === null) {
            return; // 403/404 già emesso
        }
        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $this->emitJson(ServiceResult::fail(I18n::t('avatar.error.upload'), 422));
            return;
        }
        $this->emitJson((new MediaService())->replace($user->id, $pid, $kind, $file['tmp_name'], $request->ip()));
    }

    private function removeKind(Request $request, string $kind): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        $pid = $this->actingProfileId($request, $user);
        if ($pid === null) {
            return;
        }
        $this->emitJson((new MediaService())->remove($user->id, $pid, $kind));
    }

    /**
     * Id dell'ACTING profile per le SCRITTURE media, ri-validato via canActAs('editor'). Se il profilo
     * dichiarato non è gestibile → emette 403 (o 404 se nemmeno il personale esiste) e ritorna null.
     */
    private function actingProfileId(Request $request, User $user): ?int
    {
        $ctx = new ActingContext();
        $pid = $ctx->resolveForWrite($request, $user, 'editor');
        if ($pid === null) {
            Response::error(I18n::t('act.error.forbidden'), $ctx->personalProfileId($user) !== null ? 403 : 404);
            return null;
        }
        return $pid;
    }
}
