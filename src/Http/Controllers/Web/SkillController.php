<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\ServiceResult;
use Spoome\Core\View;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Domain\Profiles\SkillService;
use Spoome\Http\Controllers\Controller;

/**
 * Competenze del profilo: gestione proprietaria (editor `/profilo`) + endorsement dal profilo
 * pubblico altrui (`/atleti/{handle}`). Area autenticata, CSRF su tutte le scritture.
 * La logica (validazione, max, authz endorse, rate-limit, notifica) vive in SkillService.
 * Progressive enhancement: l'endorse risponde JSON in AJAX, redirect+flash senza JS.
 */
final class SkillController extends Controller
{
    /* ------------------------------------------- gestione proprietaria (editor) ---- */

    public function add(Request $request): void
    {
        $pid = $this->ownProfileId($request);
        $res = (new SkillService())->addSkill($pid, (string) ($request->body()['label'] ?? ''), $request->ip());
        // Ramo async: allega il frammento server-rendered (chip skill) per l'append in-place.
        // Il partial è la STESSA sorgente usata dalla lista iniziale → escaping via e() su ogni campo.
        if ($res->ok && $request->wantsJson()) {
            $res = ServiceResult::ok(
                $res->data + ['html' => View::partial('skill-edit-item', ['s' => [
                    'id'                 => (int) $res->data['id'],
                    'label'              => (string) $res->data['label'],
                    'endorsements_count' => 0,
                ]])],
                $res->meta,
                $res->code
            );
        }
        $this->respond($request, $res, 'profilo#competenze', I18n::t('profile.details.added'));
    }

    public function delete(Request $request): void
    {
        $pid = $this->ownProfileId($request);
        $res = (new SkillService())->removeSkill($pid, (int) $request->param('id'), $request->ip());
        $this->respond($request, $res, 'profilo#competenze', I18n::t('profile.details.removed'));
    }

    public function reorder(Request $request): void
    {
        $pid = $this->ownProfileId($request);
        $ids = $request->body()['ids'] ?? [];
        $res = (new SkillService())->reorder($pid, is_array($ids) ? $ids : [], $request->ip());
        $this->respond($request, $res, 'profilo#competenze', I18n::t('profile.details.updated'));
    }

    /* ----------------------------------------------------------------- endorse ---- */

    public function endorse(Request $request): void
    {
        $this->endorseAct($request, true);
    }

    public function removeEndorse(Request $request): void
    {
        $this->endorseAct($request, false);
    }

    private function endorseAct(Request $request, bool $endorse): void
    {
        $user  = CurrentUser::resolve($request);
        // L'endorse è un'azione SOLO-personale (split model): identità = profilo personale, non una pagina.
        // findByUserId è non-deterministico per chi possiede personale + pagine → prima il personale.
        $actor = (new ActingContext())->personalProfile($user);
        $handle = (string) $request->param('handle', '');
        $skillId = (int) $request->param('id');

        if ($actor === null) {
            $this->notFound($request, 'atleti/' . $handle);
            return;
        }

        $svc = new SkillService();
        $res = $endorse
            ? $svc->endorse($actor->id, $skillId, $request->ip())
            : $svc->removeEndorsement($actor->id, $skillId, $request->ip());

        $this->respond($request, $res, 'atleti/' . $handle . '#competenze');
    }

    /* ------------------------------------------------------------------ helper ---- */

    /**
     * Id dell'ACTING profile (personale o pagina) per la gestione proprietaria delle competenze,
     * autorizzato via canActAs('editor'). Un profilo dichiarato ma non gestibile → 403; termina.
     */
    private function ownProfileId(Request $request): int
    {
        $user = CurrentUser::resolve($request);
        $ctx  = new ActingContext();
        $pid  = $ctx->resolveForWrite($request, $user, 'editor');
        if ($pid === null) {
            if ($ctx->personalProfileId($user) !== null) {
                $this->respond($request, ServiceResult::fail(I18n::t('act.error.forbidden'), 403), 'profilo#competenze');
            } else {
                Response::redirect('rivendicazioni');
            }
            exit;
        }
        return $pid;
    }
}
