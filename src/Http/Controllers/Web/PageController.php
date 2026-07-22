<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\ServiceResult;
use Spoome\Core\Session;
use Spoome\Core\View;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Domain\Profiles\PageService;
use Spoome\Http\Controllers\Controller;

/**
 * Creazione pagine organizzazione (web) + switch dell'acting context ("agisci come").
 * Rotte protette da AuthMiddleware + CSRF. La logica di creazione vive in PageService.
 */
final class PageController extends Controller
{
    /** GET /pagine/nuova — form di creazione pagina. */
    public function newForm(Request $request): void
    {
        View::render('pagine/nuova', [
            'title' => $this->title('page.new.title'),
            'types' => PageService::ORG_TYPES,
            'error' => null,
            'old'   => [],
        ], 'base');
    }

    /** POST /pagine — crea la pagina, imposta l'acting sulla nuova pagina, va all'editor. */
    public function create(Request $request): void
    {
        $user   = CurrentUser::resolve($request);
        $result = (new PageService())->create($user, $request->body(), $request->ip());

        if (!$result->ok) {
            if ($request->wantsJson()) {
                $this->emitJson($result);
                return;
            }
            View::render('pagine/nuova', [
                'title' => $this->title('page.new.title'),
                'types' => PageService::ORG_TYPES,
                'error' => $result->error,
                'old'   => $request->body(),
            ], 'base');
            return;
        }

        // Switch immediato dell'acting sulla nuova pagina (la sessione è già validata dalla proprietà).
        Session::set('acting_profile_id', (int) $result->data['id']);

        if ($request->wantsJson()) {
            $this->emitJson($result);
            return;
        }
        Session::flash(I18n::t('page.flash.created'), 'success');
        // Onboarding (R-Moat M5, #45): ingresso automatico nel flusso Società/Federazione subito dopo
        // la creazione della pagina. Solo il ramo web no-JS (i client nativi/AJAX restano sull'envelope
        // JSON sopra, senza redirect concettuale) — coerente con "nessuna nuova voce di nav".
        Response::redirect('onboarding/societa');
    }

    /** POST /agisci-come — cambia il profilo per cui l'utente agisce (personale o una pagina gestita). */
    public function switchActing(Request $request): void
    {
        $user = CurrentUser::resolve($request);
        $pid  = (int) $request->input('profile_id', 0);
        $ctx  = new ActingContext();

        $personalId = $ctx->personalProfileId($user);

        // Consentito: il proprio personale, oppure una pagina su cui si ha almeno ruolo editor.
        // Il client non è mai fidato: canActAs ri-valida contro profile_members.
        if ($pid > 0 && ($pid === $personalId || $ctx->canActAs($user->id, $pid, 'editor'))) {
            Session::set('acting_profile_id', $pid);
            $result = ServiceResult::ok(['acting_profile_id' => $pid]);
        } else {
            $result = ServiceResult::fail(I18n::t('act.error.forbidden'), 403);
        }

        // Torna alla pagina di provenienza (whitelist relativa) o al feed.
        $back = (string) $request->input('return', 'feed');
        if ($back === '' || !preg_match('#^[a-z0-9/_-]+$#i', $back)) {
            $back = 'feed';
        }
        $this->respond($request, $result, $back);
    }
}
