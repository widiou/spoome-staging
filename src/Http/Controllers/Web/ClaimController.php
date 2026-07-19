<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\Request;
use Spoome\Core\ServiceResult;
use Spoome\Core\Session;
use Spoome\Core\View;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Claims\ClaimRepository;
use Spoome\Domain\Claims\ClaimService;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Http\Controllers\Controller;

/**
 * Rivendicazione profilo lato utente: invio richiesta dal profilo pubblico + pagina "Le mie rivendicazioni".
 */
final class ClaimController extends Controller
{
    public function request(Request $request): void
    {
        $handle  = (string) ($request->params['handle'] ?? '');
        $profile = $handle !== '' ? (new ProfileRepository())->findPublicByHandle($handle) : null;
        if ($profile === null) {
            $this->notFound($request, 'atleti');
            return;
        }
        $user = CurrentUser::resolve($request);
        $res  = (new ClaimService())->request(
            $user->id,
            (int) $profile['id'],
            (string) $request->input('message', ''),
            $request->ip()
        );

        // No-JS: successo → "Le mie rivendicazioni"; errore → torna al profilo. Async: envelope {requested:true}.
        $redirect = $res->ok ? 'rivendicazioni' : 'atleti/' . $handle;
        $flashOk  = $res->ok ? (string) ($res->meta['message'] ?? '') : null;
        if ($res->ok) {
            $res = ServiceResult::ok(['requested' => true], ['message' => (string) ($res->meta['message'] ?? '')]);
        }
        $this->respond($request, $res, $redirect, $flashOk);
    }

    public function mine(Request $request): void
    {
        $user     = CurrentUser::resolve($request);
        $requests = (new ClaimRepository())->forUser($user->id);
        $hasProfile = (new ProfileRepository())->userHasProfile($user->id);

        View::render('claim/mine', [
            'title'      => $this->title('claim.mine.title'),
            'requests'   => $requests,
            'hasProfile' => $hasProfile,
            'notice'     => Session::takeFlash(),
        ], 'base');
    }
}
