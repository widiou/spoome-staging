<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\ServiceResult;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Domain\Profiles\RecommendationService;
use Spoome\Http\Controllers\Controller;

/**
 * Raccomandazioni (testimonial LinkedIn-style): scrittura dal profilo pubblico di un connesso
 * (`/atleti/{handle}/raccomanda`) + gestione delle proprie ricevute (accetta/nascondi) dall'editor.
 * Area autenticata, CSRF su tutte le scritture. La logica (authz connessione, self/org, rate-limit,
 * upsert→pending, notifiche) vive in RecommendationService. Guscio sottile, async-first + fallback CSRF.
 */
final class RecommendationController extends Controller
{
    /** POST /atleti/{handle}/raccomanda — scrive (o riscrive) la raccomandazione per il profilo {handle}. */
    public function write(Request $request): void
    {
        $user   = CurrentUser::resolve($request);
        $handle = (string) $request->param('handle', '');

        // L'autore è SEMPRE il profilo PERSONALE (azione solo-personale, come l'endorse) → non una pagina.
        $actor = $user !== null ? (new ActingContext())->personalProfile($user) : null;
        if ($actor === null) {
            $this->notFound($request, 'atleti/' . $handle);
            return;
        }
        $target = (new ProfileRepository())->findByHandle($handle);
        if ($target === null) {
            $this->notFound($request, 'atleti');
            return;
        }

        $body = (string) ($request->body()['body'] ?? '');
        $rel  = trim((string) ($request->body()['relationship'] ?? ''));

        $res = (new RecommendationService())->write($actor->id, $target->id, $body, $rel !== '' ? $rel : null, $request->ip());
        $this->respond($request, $res, 'atleti/' . $handle . '#sez-raccomandazioni', I18n::t('reco.write.sent'));
    }

    /** POST /profilo/raccomandazioni/{id}/accetta — il destinatario approva → pubblica. */
    public function accept(Request $request): void
    {
        $res = $this->act($request, true);
        $this->respond($request, $res, 'profilo/modifica#raccomandazioni', I18n::t('reco.flash.accepted'));
    }

    /** POST /profilo/raccomandazioni/{id}/nascondi — il destinatario rifiuta una pending o nasconde una visibile. */
    public function hide(Request $request): void
    {
        $res = $this->act($request, false);
        $this->respond($request, $res, 'profilo/modifica#raccomandazioni', I18n::t('reco.flash.hidden'));
    }

    /**
     * Esegue accetta/nascondi passando l'ID UTENTE al service: è lì che si risolve il profilo personale
     * del destinatario e si verifica l'ownership (oltre allo scoping SQL per recipient_profile_id nel repo).
     */
    private function act(Request $request, bool $accept): ServiceResult
    {
        $user = CurrentUser::resolve($request);
        if ($user === null) {
            return ServiceResult::fail(I18n::t('reco.error.forbidden'), 403);
        }
        $svc   = new RecommendationService();
        $recId = (int) $request->param('id');
        return $accept ? $svc->accept((int) $user->id, $recId, $request->ip()) : $svc->hide((int) $user->id, $recId, $request->ip());
    }
}
