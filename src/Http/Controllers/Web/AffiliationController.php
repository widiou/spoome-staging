<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\ServiceResult;
use Spoome\Core\View;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Domain\Profiles\AffiliationRepository;
use Spoome\Domain\Profiles\AffiliationService;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Http\Controllers\Controller;

/**
 * Scritture web (CSRF) delle affiliazioni: richiesta, conferma, rifiuto, rimozione.
 * L'authz multi-profilo passa da ActingContext::resolveForWrite('admin'): il lato org agisce come la
 * propria pagina (canActAs admin), il lato atleta come profilo personale (owner ≥ admin). L'authz di
 * dominio (chi è parte/può confermare) vive in AffiliationService. Async-first (data-async → reload).
 */
final class AffiliationController extends Controller
{
    /**
     * Propone un'affiliazione verso il profilo {handle}. La direzione è dedotta da is_organization:
     *  - acting persona + target org  → l'atleta richiede (member=acting, org=target)
     *  - acting org + target persona  → l'org aggiunge al roster (member=target, org=acting)
     */
    public function request(Request $request): void
    {
        [$actingPid, $svc] = $this->context($request);
        if ($actingPid === null) {
            return;
        }
        $repo   = new ProfileRepository();
        $acting = $repo->findEnrichedById($actingPid);
        $target = $repo->findPublicByHandle((string) $request->input('handle', ''));
        if ($acting === null || $target === null) {
            $this->respond($request, ServiceResult::fail(I18n::t('affil.error.not_found'), 404), 'profilo');
            return;
        }

        $pair = $this->direction($acting, $target);
        if ($pair === null) {
            $this->respond($request, ServiceResult::fail(I18n::t('affil.error.not_org'), 422), 'profilo');
            return;
        }
        [$memberPid, $orgPid] = $pair;

        $res = $svc->request($actingPid, $memberPid, $orgPid, $request->body(), $request->ip());

        // Async: allega data.html = la card "In attesa" (stesso partial della lista, ogni campo via e())
        // così il client la appende alla sezione "Richieste inviate" senza reload → feedback immediato.
        // Il frammento è ri-letto dal DB e scoping-ato al richiedente: mai costruito da input client.
        if ($res->ok && $res->code === 201 && $request->wantsJson()) {
            $affId = (int) (is_array($res->data) ? ($res->data['id'] ?? 0) : 0);
            $row   = $affId > 0 ? (new AffiliationRepository())->outgoingById($affId, $actingPid) : null;
            if ($row !== null) {
                $res = ServiceResult::ok(
                    $res->data + ['html' => View::partial('affiliation-card', [
                        'a' => $row, 'manage' => true, 'outgoing' => true, 'return' => 'profilo',
                    ])],
                    $res->meta,
                    $res->code
                );
            }
        }
        $this->respond($request, $res, 'profilo#affiliazioni', I18n::t('affil.flash.requested'));
    }

    public function confirm(Request $request): void
    {
        [$actingPid, $svc] = $this->context($request);
        if ($actingPid === null) {
            return;
        }
        $res = $svc->confirm($actingPid, (int) $request->param('id'), $request->ip());
        $this->respond($request, $res, $this->back($request), I18n::t('affil.flash.confirmed'));
    }

    public function reject(Request $request): void
    {
        [$actingPid, $svc] = $this->context($request);
        if ($actingPid === null) {
            return;
        }
        $res = $svc->remove($actingPid, (int) $request->param('id'), $request->ip());
        $this->respond($request, $res, $this->back($request), I18n::t('affil.flash.rejected'));
    }

    public function remove(Request $request): void
    {
        [$actingPid, $svc] = $this->context($request);
        if ($actingPid === null) {
            return;
        }
        $res = $svc->remove($actingPid, (int) $request->param('id'), $request->ip());
        $this->respond($request, $res, $this->back($request), I18n::t('affil.flash.removed'));
    }

    /* ------------------------------------------------------------ helpers ---- */

    /**
     * Risolve l'acting profile per la SCRITTURA (ruolo ≥ admin). Emette 403 e ritorna [null,_] se
     * l'utente dichiara un profilo che non può gestire.
     * @return array{0:?int,1:AffiliationService}
     */
    private function context(Request $request): array
    {
        $user = CurrentUser::resolve($request);
        $ctx  = new ActingContext();
        $actingPid = $user !== null ? $ctx->resolveForWrite($request, $user, 'admin') : null;
        if ($actingPid === null) {
            $this->respond($request, ServiceResult::fail(I18n::t('act.error.forbidden'), 403), 'profilo');
            return [null, new AffiliationService()];
        }
        return [$actingPid, new AffiliationService()];
    }

    /**
     * Determina (memberPid, orgPid) dalla coppia acting/target usando is_organization.
     * @return array{0:int,1:int}|null null se nessuno dei due è un'organizzazione
     */
    private function direction(array $acting, array $target): ?array
    {
        $actingOrg = !empty($acting['is_organization']);
        $targetOrg = !empty($target['is_organization']);
        if ($targetOrg) {
            return [(int) $acting['id'], (int) $target['id']]; // acting è il membro, target l'org
        }
        if ($actingOrg) {
            return [(int) $target['id'], (int) $acting['id']]; // acting è l'org, target il membro
        }
        return null;
    }

    /** Redirect di ripiego no-JS: 'return' whitelistato a path interni, altrimenti /profilo. */
    private function back(Request $request): string
    {
        $ret = trim((string) $request->input('return', ''));
        if ($ret !== '' && $ret[0] !== '/' && !str_contains($ret, '://')) {
            return $ret;
        }
        return 'profilo';
    }
}
