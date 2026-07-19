<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\ServiceResult;
use Spoome\Core\View;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Domain\Profiles\ProfileDetailsRepository;
use Spoome\Domain\Profiles\ProfileDetailsService;
use Spoome\Http\Controllers\Controller;

/**
 * CRUD delle sotto-entità del profilo (esperienze, palmarès, link). Area autenticata, CSRF.
 * Tutta la logica (validazione, ownership, sicurezza URL) è in ProfileDetailsService: qui si adatta
 * l'input del form, si opera sempre sul profilo dell'utente corrente e si traduce l'esito in flash+redirect.
 */
final class ProfileDetailsController extends Controller
{
    /* ------------------------------------------------------- ESPERIENZE ---- */

    public function addExperience(Request $request): void
    {
        [$pid, $svc] = $this->context($request);
        $this->done($request, 'esperienze', $svc->addExperience($pid, $request->body()), $pid);
    }

    public function updateExperience(Request $request): void
    {
        [$pid, $svc] = $this->context($request);
        $this->done($request, 'esperienze', $svc->updateExperience((int) $request->param('id'), $pid, $request->body()), $pid);
    }

    public function deleteExperience(Request $request): void
    {
        [$pid, $svc] = $this->context($request);
        $this->done($request, 'esperienze', $svc->deleteExperience((int) $request->param('id'), $pid), $pid);
    }

    /* --------------------------------------------------------- PALMARÈS ---- */

    public function addAchievement(Request $request): void
    {
        [$pid, $svc] = $this->context($request);
        $this->done($request, 'palmares', $svc->addAchievement($pid, $request->body()), $pid);
    }

    public function updateAchievement(Request $request): void
    {
        [$pid, $svc] = $this->context($request);
        $this->done($request, 'palmares', $svc->updateAchievement((int) $request->param('id'), $pid, $request->body()), $pid);
    }

    public function deleteAchievement(Request $request): void
    {
        [$pid, $svc] = $this->context($request);
        $this->done($request, 'palmares', $svc->deleteAchievement((int) $request->param('id'), $pid), $pid);
    }

    /* ------------------------------------------------------------- LINK ---- */

    public function addLink(Request $request): void
    {
        [$pid, $svc] = $this->context($request);
        $this->done($request, 'link', $svc->addLink($pid, $request->body()), $pid);
    }

    public function updateLink(Request $request): void
    {
        [$pid, $svc] = $this->context($request);
        $this->done($request, 'link', $svc->updateLink((int) $request->param('id'), $pid, $request->body()), $pid);
    }

    public function deleteLink(Request $request): void
    {
        [$pid, $svc] = $this->context($request);
        $this->done($request, 'link', $svc->deleteLink((int) $request->param('id'), $pid), $pid);
    }

    /* ------------------------------------------------------------ helpers ---- */

    /**
     * @return array{0:int,1:ProfileDetailsService} id ACTING profile + service.
     * Multi-profilo: opera sull'acting profile (personale o la pagina su cui si agisce), autorizzato via
     * canActAs('editor'). Un profilo dichiarato ma non gestibile → 403 (mai fallback sul personale); termina.
     */
    private function context(Request $request): array
    {
        // CurrentUser è già risolto e in cache dal middleware (stesso oggetto Request).
        $user = CurrentUser::resolve($request);
        $ctx  = new ActingContext();
        $pid  = $ctx->resolveForWrite($request, $user, 'editor');
        if ($pid === null) {
            if ($ctx->personalProfileId($user) !== null) {
                $this->respond($request, ServiceResult::fail(I18n::t('act.error.forbidden'), 403), 'profilo');
            } else {
                Response::redirect('rivendicazioni');
            }
            exit;
        }
        return [$pid, new ProfileDetailsService()];
    }

    /**
     * Traduce l'esito del Service: async → envelope JSON; no-JS → flash (per-codice) + redirect alla sezione.
     * Il messaggio di successo dipende dal codice (201 aggiunto · 204 rimosso · altrimenti aggiornato).
     * Per add/update (async) allega data.html = il list-item renderizzato dallo STESSO partial della lista
     * iniziale (append per add, replaceHtml per update). L'html non è mai costruito da input client grezzo:
     * il partial ri-legge la riga persistita e passa ogni campo da e(). Delete (204) → nessun html (removeCard).
     */
    private function done(Request $request, string $anchor, ServiceResult $res, int $pid): void
    {
        if ($res->ok && $res->code !== 204 && $request->wantsJson()) {
            $id  = (int) (is_array($res->data) ? ($res->data['id'] ?? 0) : 0);
            $row = $id > 0 ? $this->findRow($anchor, $pid, $id) : null;
            if ($row !== null) {
                $res = ServiceResult::ok(
                    $res->data + ['html' => View::partial($this->partialFor($anchor), $this->partialData($anchor, $row))],
                    $res->meta,
                    $res->code
                );
            }
        }
        $flashOk = match ($res->code) {
            201     => I18n::t('profile.details.added'),
            204     => I18n::t('profile.details.removed'),
            default => I18n::t('profile.details.updated'),
        };
        $this->respond($request, $res, 'profilo#' . $anchor, $flashOk);
    }

    /** Rilegge la riga persistita (sorgente del frammento) filtrando per profilo (ownership a livello dati). */
    private function findRow(string $anchor, int $pid, int $id): ?array
    {
        $repo = new ProfileDetailsRepository();
        $rows = match ($anchor) {
            'esperienze' => $repo->experiences($pid),
            'palmares'   => $repo->achievements($pid),
            'link'       => $repo->links($pid),
            default      => [],
        };
        foreach ($rows as $r) {
            if ((int) $r['id'] === $id) {
                return $r;
            }
        }
        return null;
    }

    private function partialFor(string $anchor): string
    {
        return match ($anchor) {
            'esperienze' => 'detail-experience-item',
            'palmares'   => 'detail-achievement-item',
            'link'       => 'detail-link-item',
            default      => '',
        };
    }

    /** @return array<string,mixed> variabili attese dal partial del list-item. */
    private function partialData(string $anchor, array $row): array
    {
        return match ($anchor) {
            'esperienze' => ['x' => $row],
            'palmares'   => ['a' => $row],
            'link'       => ['l' => $row, 'linkKinds' => ProfileDetailsService::LINK_KINDS],
            default      => [],
        };
    }
}
