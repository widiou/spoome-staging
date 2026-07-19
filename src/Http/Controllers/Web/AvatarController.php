<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\ServiceResult;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Media\MediaService;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Http\Controllers\Controller;

/**
 * Upload/rimozione delle immagini del profilo (avatar e copertina). AJAX/JSON, auth + CSRF sulle rotte.
 * La logica di file-handling (validazione, ri-codifica, persistenza, swap) vive in MediaService, condiviso
 * con l'API nativa; qui si valida solo il file multipart (transport) e si emette l'envelope JSON.
 *
 * Multi-profilo: le immagini si applicano all'ACTING profile (personale o la pagina su cui si agisce),
 * autorizzato via canActAs('editor'). Un profilo dichiarato ma non gestibile → 403.
 */
final class AvatarController extends Controller
{
    public function upload(Request $request): void
    {
        $this->store($request, 'avatar');
    }

    public function delete(Request $request): void
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
        $user = CurrentUser::resolve($request);
        $pid  = $this->actingProfileId($request, $user);
        if ($pid === null) {
            return; // 403 già emesso
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
        $user = CurrentUser::resolve($request);
        $pid  = $this->actingProfileId($request, $user);
        if ($pid === null) {
            return;
        }
        $this->emitJson((new MediaService())->remove($user->id, $pid, $kind));
    }

    /**
     * Id dell'ACTING profile per le SCRITTURE media, ri-validato via canActAs('editor'). Se il profilo
     * dichiarato non è gestibile → emette 403 JSON e ritorna null (il chiamante deve solo `return`).
     */
    private function actingProfileId(Request $request, \Spoome\Domain\Users\User $user): ?int
    {
        $pid = (new ActingContext())->resolveForWrite($request, $user, 'editor');
        if ($pid === null) {
            $this->emitJson(ServiceResult::fail(I18n::t('act.error.forbidden'), 403));
            return null;
        }
        return $pid;
    }
}
