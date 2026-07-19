<?php

namespace Spoome\Http\Controllers\Api\V1;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Domain\Profiles\SkillService;
use Spoome\Http\Controllers\ApiController;

/**
 * API competenze (JSON, solo-Bearer): gestione proprietaria (CRUD + riordino) ed endorsement sulle
 * competenze altrui. Guscio sottile sopra SkillService (stessa logica del web) → nessuna duplicazione.
 */
final class SkillController extends ApiController
{
    /** POST /me/skills — aggiunge una competenza al proprio profilo. */
    public function add(Request $request): void
    {
        $pid = $this->meProfileId($request);
        if ($pid === null) {
            return;
        }
        $this->emitJson((new SkillService())->addSkill($pid, (string) ($request->body()['label'] ?? '')));
    }

    /** DELETE /me/skills/{id} — rimuove una propria competenza. */
    public function remove(Request $request): void
    {
        $pid = $this->meProfileId($request);
        if ($pid === null) {
            return;
        }
        $this->emitJson((new SkillService())->removeSkill($pid, (int) $request->param('id')));
    }

    /** PATCH /me/skills/order — riordina le proprie competenze ({ids:[...]}). */
    public function reorder(Request $request): void
    {
        $pid = $this->meProfileId($request);
        if ($pid === null) {
            return;
        }
        $ids = $request->body()['ids'] ?? [];
        $this->emitJson((new SkillService())->reorder($pid, is_array($ids) ? $ids : []));
    }

    /** POST /profiles/{handle}/skills/{id}/endorsement — endorsa la competenza di un altro profilo. */
    public function endorse(Request $request): void
    {
        $this->endorseAction($request, true);
    }

    /** DELETE /profiles/{handle}/skills/{id}/endorsement — rimuove il proprio endorsement. */
    public function removeEndorse(Request $request): void
    {
        $this->endorseAction($request, false);
    }

    private function endorseAction(Request $request, bool $endorse): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        // L'endorse è un'azione SOLO-personale (split model): identità = profilo personale, non una pagina.
        $actor = (new ActingContext())->personalProfile($user);
        if ($actor === null) {
            Response::error(I18n::t('atleti.show.not_found_title'), 404);
            return;
        }
        $skillId = (int) $request->param('id');
        $svc     = new SkillService();
        $this->emitJson($endorse
            ? $svc->endorse($actor->id, $skillId, $request->ip())
            : $svc->removeEndorsement($actor->id, $skillId, $request->ip()));
    }

    /**
     * Id dell'ACTING profile (personale o pagina) dell'utente Bearer per la gestione proprietaria delle
     * competenze, autorizzato via canActAs('editor'). Un profilo dichiarato ma non gestibile → 403;
     * emette 401/403/404 e ritorna null se impossibile.
     */
    private function meProfileId(Request $request): ?int
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return null;
        }
        $ctx = new ActingContext();
        $pid = $ctx->resolveForWrite($request, $user, 'editor');
        if ($pid === null) {
            Response::error(I18n::t('act.error.forbidden'), $ctx->personalProfileId($user) !== null ? 403 : 404);
            return null;
        }
        return $pid;
    }
}
