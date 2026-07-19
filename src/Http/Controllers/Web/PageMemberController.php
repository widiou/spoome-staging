<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\ServiceResult;
use Spoome\Core\View;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Domain\Profiles\MemberInviteRepository;
use Spoome\Domain\Profiles\PageMemberService;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Http\Controllers\Controller;

/**
 * Gestione MEMBRI di una pagina org (web, autenticato + CSRF). Controller SOTTILE: risolve la pagina
 * target dall'handle di rotta, ricava auth_id() e delega TUTTA la logica/authz a PageMemberService
 * (canActAs('admin') ri-valida ad ogni call → difesa a livello dominio). Traduce ServiceResult in
 * risposta async JSON ({data,meta}/{errors}) o redirect+flash per il fallback no-JS (respond()).
 *
 * Due superfici:
 *  - lato PAGINA (owner/admin): elenco membri, invita, cambia ruolo, rimuovi, revoca invito;
 *  - lato INVITATO: inbox degli inviti ricevuti + accetta/rifiuta (scopati su invited_user_id).
 */
final class PageMemberController extends Controller
{
    /**
     * GET /pagine/{handle}/membri — pagina di gestione. Visibile SOLO a owner/admin della pagina org;
     * altrimenti 404-cloak (stessa "non trovata" per pagina inesistente, non-org, o utente senza ruolo)
     * per non rivelare l'esistenza/roster a chi non può gestirla.
     */
    public function manage(Request $request): void
    {
        $userId = auth_id();
        if ($userId === null) {
            Response::redirect('accedi');
            return;
        }

        $repo = new ProfileRepository();
        $page = $repo->findByHandle((string) $request->param('handle', ''));
        if ($page === null) {
            $this->cloak404();
            return;
        }

        $enriched   = $repo->findEnrichedById($page->id);
        $isOrg      = $enriched !== null && !empty($enriched['is_organization']);
        $actingRole = (new ActingContext())->roleFor($userId, $page->id);
        $canManage  = $actingRole === 'owner' || $actingRole === 'admin';
        if (!$isOrg || !$canManage) {
            $this->cloak404();
            return;
        }

        $roster = (new PageMemberService())->members($page->id);
        $data   = is_array($roster->data) ? $roster->data : [];

        View::render('pagine/membri', [
            'title'        => $this->title('member.manage.title'),
            'page'         => $enriched,
            'handle'       => $page->handle,
            'members'      => $data['members'] ?? [],
            'pending'      => $data['pending_invites'] ?? [],
            'actingRole'   => $actingRole,
            'actingUserId' => $userId,
            'roles'        => PageMemberService::ASSIGNABLE_ROLES,
            'notice'       => \Spoome\Core\Session::takeFlash(),
        ], 'base');
    }

    /** POST /pagine/{handle}/membri/invita — invita per handle personale (owner/admin). */
    public function invite(Request $request): void
    {
        [$userId, $pageId, $handle] = $this->resolvePage($request);
        if ($pageId === null) {
            return;
        }
        $res = (new PageMemberService())->invite(
            $userId,
            $pageId,
            (string) $request->input('handle', ''),
            (string) $request->input('role', 'editor')
        );
        $this->respond($request, $res, $this->manageBack($handle), I18n::t('member.flash.invited'));
    }

    /** POST /pagine/{handle}/membri/{userId}/ruolo — cambia ruolo (admin|editor). */
    public function changeRole(Request $request): void
    {
        [$userId, $pageId, $handle] = $this->resolvePage($request);
        if ($pageId === null) {
            return;
        }
        $res = (new PageMemberService())->changeRole(
            $userId,
            $pageId,
            (int) $request->param('userId'),
            (string) $request->input('role', '')
        );
        $this->respond($request, $res, $this->manageBack($handle), I18n::t('member.flash.role_changed'));
    }

    /** POST /pagine/{handle}/membri/{userId}/rimuovi — rimuove un membro (safeguard ultimo-owner nel service). */
    public function removeMember(Request $request): void
    {
        [$userId, $pageId, $handle] = $this->resolvePage($request);
        if ($pageId === null) {
            return;
        }
        $res = (new PageMemberService())->removeMember($userId, $pageId, (int) $request->param('userId'));
        $this->respond($request, $res, $this->manageBack($handle), I18n::t('member.flash.removed'));
    }

    /**
     * POST /pagine/inviti/{inviteId}/revoca — revoca (owner/admin) un invito pendente per id.
     * L'id non porta il contesto pagina: lo si ricava dalla riga, poi il service RI-VALIDA che l'utente
     * sia admin di QUELLA pagina prima di agire (nessun bypass dell'authz). Redirect no-JS whitelistato.
     */
    public function revokeInvite(Request $request): void
    {
        $userId = auth_id();
        if ($userId === null) {
            Response::redirect('accedi');
            return;
        }
        $invite = (new MemberInviteRepository())->findById((int) $request->param('inviteId'));
        if ($invite === null) {
            $this->respond($request, ServiceResult::fail(I18n::t('member.error.invite_not_found'), 404), $this->safeReturn($request));
            return;
        }
        $res = (new PageMemberService())->revokeInvite(
            $userId,
            (int) $invite['profile_id'],
            (int) $invite['invited_user_id']
        );
        $this->respond($request, $res, $this->safeReturn($request), I18n::t('member.flash.revoked'));
    }

    /** GET /pagine/inviti — inbox degli inviti RICEVUTI dall'utente loggato (scopo su invited_user_id). */
    public function inbox(Request $request): void
    {
        $userId = auth_id();
        if ($userId === null) {
            Response::redirect('accedi');
            return;
        }
        $invites = (new MemberInviteRepository())->pendingForUser($userId, 50);
        View::render('pagine/inviti', [
            'title'   => $this->title('member.inbox.title'),
            'invites' => $invites,
            'notice'  => \Spoome\Core\Session::takeFlash(),
        ], 'base');
    }

    /** POST /pagine/inviti/{inviteId}/accetta — l'invitato accetta (scopato nel service su invited_user_id). */
    public function accept(Request $request): void
    {
        $userId = auth_id();
        if ($userId === null) {
            Response::redirect('accedi');
            return;
        }
        $res = (new PageMemberService())->accept($userId, (int) $request->param('inviteId'));
        $this->respond($request, $res, 'pagine/inviti', I18n::t('member.flash.accepted'));
    }

    /** POST /pagine/inviti/{inviteId}/rifiuta — l'invitato rifiuta. */
    public function decline(Request $request): void
    {
        $userId = auth_id();
        if ($userId === null) {
            Response::redirect('accedi');
            return;
        }
        $res = (new PageMemberService())->decline($userId, (int) $request->param('inviteId'));
        $this->respond($request, $res, 'pagine/inviti', I18n::t('member.flash.declined'));
    }

    /* ------------------------------------------------------------ helpers ---- */

    /**
     * Risolve (userId, pageId, handle) per le azioni di gestione. L'authz effettiva vive nel service
     * (canActAs('admin'), ri-valida ad ogni call): qui si risolve solo la pagina dall'handle di rotta.
     * Ritorna pageId=null (dopo aver già emesso la risposta) se utente anonimo o pagina inesistente.
     * @return array{0:int,1:?int,2:string}
     */
    private function resolvePage(Request $request): array
    {
        $handle = (string) $request->param('handle', '');
        $userId = auth_id();
        if ($userId === null) {
            Response::redirect('accedi');
            return [0, null, $handle];
        }
        $page = (new ProfileRepository())->findByHandle($handle);
        if ($page === null) {
            $this->respond($request, ServiceResult::fail(I18n::t('member.error.page_not_found'), 404), 'feed');
            return [$userId, null, $handle];
        }
        return [$userId, $page->id, $page->handle];
    }

    /** Path di ripiego no-JS verso la pagina di gestione della pagina org. */
    private function manageBack(string $handle): string
    {
        return 'pagine/' . rawurlencode($handle) . '/membri';
    }

    /**
     * Redirect di ripiego no-JS: campo `return` whitelistato a path interni relativi, altrimenti la
     * inbox inviti. Difende dagli open-redirect (niente schema/host, niente leading slash).
     */
    private function safeReturn(Request $request): string
    {
        $ret = trim((string) $request->input('return', ''));
        if ($ret !== '' && preg_match('#^[a-z0-9/_-]+$#i', $ret)) {
            return $ret;
        }
        return 'pagine/inviti';
    }

    /** 404-cloak coerente col resto del sito (stessa "profilo non trovato"). */
    private function cloak404(): void
    {
        \http_response_code(404);
        View::render('message', [
            'title'       => $this->title('atleti.show.not_found_title'),
            'heading'     => I18n::t('atleti.show.not_found_title'),
            'message'     => I18n::t('atleti.show.not_found_msg'),
            'type'        => 'error',
            'actionUrl'   => url('feed'),
            'actionLabel' => I18n::t('nav.feed'),
        ], 'base');
    }
}
