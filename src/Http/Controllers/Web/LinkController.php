<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\ServiceResult;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Auth\RateLimiter;
use Spoome\Domain\Links\LinkImageProxyService;
use Spoome\Domain\Links\LinkUnfurlService;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Http\Controllers\Controller;

/**
 * Link unfurl + image-proxy per il WEB (sessione + CSRF), gemello dell'API Bearer.
 * L'unfurl del composer passa da qui perché la sessione NON viene avviata sul prefisso /api (stateless):
 * un utente web autenticato via cookie è risolvibile solo su una rotta web.
 */
final class LinkController extends Controller
{
    private const IMG_RL_MAX        = 120; // hit/finestra per IP sull'image-proxy
    private const IMG_RL_WINDOW_MIN = 10;

    /** POST /feed/unfurl — anteprima di un URL (auth di sessione + CSRF). Envelope JSON. */
    public function unfurl(Request $request): void
    {
        $user = CurrentUser::resolve($request);
        if ($user === null) {
            $this->emitJson(ServiceResult::fail(I18n::t('api.error.unauthorized'), 401));
            return;
        }
        $profile = (new ProfileRepository())->findByUserId($user->id);
        if ($profile === null) {
            $this->emitJson(ServiceResult::fail(I18n::t('api.error.unauthorized'), 404));
            return;
        }
        $result = (new LinkUnfurlService())->unfurl((string) $request->input('url', ''), $profile->id, $request->ip());
        $this->emitJson($result);
    }

    /** GET /link-image?u=<firmato> — image-proxy same-origin (nessun auth: la firma HMAC è la barriera). */
    public function image(Request $request): void
    {
        $token = (string) $request->input('u', '');
        if ($token === '') {
            Response::error(I18n::t('api.error.invalid_data'), 400);
            return;
        }

        // Rate-limit per IP: l'endpoint è HMAC-gated ma non autenticato → token raccolti potrebbero
        // usarci come relay/amplificatore d'immagini (ogni hit = un fetch outbound fino al cap byte).
        // Soglia allineata al normale caricamento immagini del feed (le immagini sono cache-abili lato browser).
        $ip = $request->ip();
        $limiter = new RateLimiter();
        if ($limiter->tooManyByKey('imgproxy:' . $ip, self::IMG_RL_MAX, self::IMG_RL_WINDOW_MIN)) {
            http_response_code(429);
            header('Cache-Control: no-store');
            return;
        }
        $limiter->hit('imgproxy:' . $ip, $ip);

        $out = (new LinkImageProxyService())->fetch($token);
        if (!$out['ok']) {
            // Nessun corpo: solo lo status (403 firma · 415 non-immagine · 502 irraggiungibile).
            http_response_code($out['status']);
            header('Cache-Control: no-store');
            return;
        }
        http_response_code(200);
        header('Content-Type: ' . $out['mime']);
        header('Content-Length: ' . strlen($out['body']));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline');
        // Immutabile: il token è firmato su un URL specifico → cache lunga lato browser/edge.
        header('Cache-Control: public, max-age=86400, immutable');
        echo $out['body'];
    }
}
