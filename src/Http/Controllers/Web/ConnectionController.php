<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\ServiceResult;
use Spoome\Core\View;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Connections\ConnectionService;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Http\Controllers\Controller;

/**
 * Azioni sulle connessioni lato web (sessione + CSRF).
 *  - connect():    richiedi-o-accetta
 *  - disconnect(): annulla/rifiuta/rimuovi
 * Progressive enhancement: JSON se richiesto (AJAX), altrimenti redirect al profilo con flash.
 */
final class ConnectionController extends Controller
{
    public function connect(Request $request): void
    {
        $this->act($request, true);
    }

    public function disconnect(Request $request): void
    {
        $this->act($request, false);
    }

    private function act(Request $request, bool $connect): void
    {
        $user   = CurrentUser::resolve($request);
        $repo   = new ProfileRepository();
        // Split multi-profilo: le connessioni sono SEMPRE del profilo PERSONALE (le pagine non connettono).
        $actor  = (new ActingContext())->personalProfile($user);
        $target = $this->resolveTargetOr404($request, $repo);
        if ($target === null) {
            return;
        }
        if ($actor === null) {
            $this->notFound($request, 'atleti');
            return;
        }

        $svc    = new ConnectionService();
        $result = $connect
            ? $svc->connect($actor->id, $target->id, $target->visibility, $target->displayName, $request->ip())
            : $svc->disconnect($actor->id, $target->id, $request->ip());

        // Ramo async: allega il frammento server-rendered del blocco connessione (stessa sorgente del
        // render iniziale, e() su ogni campo) per il replaceHtml in-place sul profilo. La pagina Rete usa
        // invece effetti propri (disable/removeCard) e ignora questo html.
        if ($result->ok && $request->wantsJson()) {
            $conn = [
                'count'       => (int) ($result->data['connections_count'] ?? 0),
                'status'      => (string) ($result->data['status'] ?? ConnectionService::NONE),
                'can_connect' => true,
            ];
            $html = View::partial('connection-actions', ['connection' => $conn, 'h' => $target->handle]);
            $result = ServiceResult::ok($result->data + ['html' => $html], $result->meta, $result->code);
        }

        // Torna alla pagina Rete se l'azione è partita da lì, altrimenti al profilo (solo ramo no-JS).
        $back = $request->input('return') === 'rete' ? 'rete' : 'atleti/' . $target->handle;
        // Flash di successo solo per il "connect" (richiesta inviata / collegato); il disconnect è silenzioso.
        $flashOk = null;
        if ($result->ok && $connect) {
            $done    = ($result->data['status'] ?? '') === ConnectionService::CONNECTED;
            $flashOk = I18n::t($done ? 'connect.flash.connected' : 'connect.flash.requested');
        }
        $this->respond($request, $result, $back, $flashOk);
    }
}
