<?php

namespace Spoome\Http\Controllers\Api\V1;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Domain\Auth\RateLimiter;
use Spoome\Domain\Events\PushDeviceRepository;
use Spoome\Http\Controllers\ApiController;

/**
 * Registrazione device-token per il push nativo/web (§4.3 realtime-spec). Bearer-only (scritture).
 * Scaffolding Phase 1: memorizza i token; l'invio APNs/FCM/WebPush arriva in Phase 2.
 */
final class DevicesController extends ApiController
{
    private const PLATFORMS = ['ios', 'android', 'web'];
    private const TOKEN_MAX = 255;

    /** Tetto di device per utente (anti-bloat): oltre questo, i più vecchi vengono potati. */
    private const MAX_PER_USER = 20;
    /** Floor anti-abuso sulla registrazione: max 20 chiamate / 10 min per utente. */
    private const RL_MAX      = 20;
    private const RL_WINDOW_M = 10;

    /** POST /devices — body {platform, token} → upsert. */
    public function register(Request $request): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }

        // Floor anti-abuso: un utente autenticato che varia il `token` inserirebbe righe illimitate.
        $rl  = new RateLimiter();
        $key = 'dev:' . $user->id;
        if ($rl->tooManyByKey($key, self::RL_MAX, self::RL_WINDOW_M)) {
            header('Retry-After: 60');
            Response::error(I18n::t('auth.error.throttled'), 429, null, ['retry_after' => 60]);
            return;
        }

        $platform = strtolower(trim((string) $request->input('platform', '')));
        $token    = trim((string) $request->input('token', ''));

        if (!in_array($platform, self::PLATFORMS, true)) {
            Response::error(I18n::t('api.error.invalid_data'), 422, null, ['fields' => ['platform' => 'invalid']]);
            return;
        }
        if ($token === '' || mb_strlen($token) > self::TOKEN_MAX) {
            Response::error(I18n::t('api.error.invalid_data'), 422, null, ['fields' => ['token' => 'invalid']]);
            return;
        }

        $rl->hit($key, $request->ip());

        // Cap per-utente: la registrazione di un NUOVO token oltre il tetto pota i device più vecchi.
        $device = (new PushDeviceRepository())->upsert($user->id, $platform, $token, self::MAX_PER_USER);
        Response::json([
            'id'       => isset($device['id']) ? (int) $device['id'] : null,
            'platform' => $platform,
            'token'    => $token,
        ], 201);
    }

    /** DELETE /devices/{token} — rimozione al logout / token invalido. */
    public function unregister(Request $request): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        $token = trim((string) $request->param('token', ''));
        if ($token === '') {
            Response::error(I18n::t('api.error.invalid_data'), 422, null, ['fields' => ['token' => 'invalid']]);
            return;
        }
        (new PushDeviceRepository())->delete($user->id, $token);
        Response::noContent();
    }
}
