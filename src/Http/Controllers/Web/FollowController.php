<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\Request;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Follows\FollowService;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Http\Controllers\Controller;

/**
 * Azioni follow lato web (sessione + CSRF). Progressive enhancement: senza JS fa redirect con flash,
 * con JS (spoome.js) risponde JSON con lo stato aggiornato per aggiornare bottone e conteggi live.
 */
final class FollowController extends Controller
{
    public function follow(Request $request): void
    {
        $this->act($request, true);
    }

    public function unfollow(Request $request): void
    {
        $this->act($request, false);
    }

    private function act(Request $request, bool $follow): void
    {
        $user   = CurrentUser::resolve($request); // garantito dall'AuthMiddleware
        $repo   = new ProfileRepository();
        // Split multi-profilo: il follow parte SEMPRE dal profilo PERSONALE, mai dall'acting page
        // (le pagine sono seguite, non seguono).
        $actor  = (new ActingContext())->personalProfile($user);
        $target = $this->resolveTargetOr404($request, $repo);
        if ($target === null) {
            return;
        }
        if ($actor === null) {
            $this->notFound($request, 'atleti');
            return;
        }

        $svc    = new FollowService();
        $result = $follow
            ? $svc->follow($actor->id, $target->id, $target->visibility, $target->displayName, $request->ip())
            : $svc->unfollow($actor->id, $target->id, $request->ip());

        $this->respond($request, $result, 'atleti/' . $target->handle);
    }
}
