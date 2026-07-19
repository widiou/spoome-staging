<?php

namespace Spoome\Http\Controllers\Api\V1;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Domain\Auth\RateLimiter;
use Spoome\Domain\Events\EventRepository;
use Spoome\Domain\Feed\FeedRepository;
use Spoome\Domain\Messaging\MessageRepository;
use Spoome\Domain\Notifications\NotificationRepository;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Http\Controllers\ApiController;

/**
 * Endpoint consolidato di realtime Phase 1 (§4.1 realtime-spec). Una sola chiamata, letta,
 * ritorna gli eventi pendenti dell'utente autenticato dopo il suo cursore + i contatori nav
 * denormalizzati + il segnale "nuovi post" del feed. Nessun hold: ritorna subito (il client
 * fa short/adaptive polling). Auth: sessione (web) O Bearer (native/API), come gli altri GET.
 *
 * SICUREZZA: legge SOLO `user_events WHERE user_id = <me>` — mai eventi di altri utenti.
 */
final class StreamController extends ApiController
{
    private const LIMIT = 50;

    /**
     * Floor anti-abuso per-utente sull'endpoint pollato: ~40 richieste/minuto. Una cadenza legittima
     * (2-5s → 12-30/min) non viene MAI limitata; un client ostile/rotto che martella l'FPM sì.
     * Il controllo è un solo COUNT indicizzato (idx_login_identifier) — leggero.
     */
    private const POLL_MAX      = 40;
    private const POLL_WINDOW_M = 1;

    /** GET /stream/since?cursor=&feed_cursor= */
    public function since(Request $request): void
    {
        // Il front controller NON avvia la sessione sui path /api (API stateless). Questo endpoint è
        // l'eccezione deliberata: read-only, ritorna SOLO i dati dell'utente stesso, quindi accetta la
        // sessione web oltre al Bearer. Avviare la sessione per una GET read-only non introduce CSRF
        // (nessuna mutazione) e la lettura cross-site è comunque bloccata da CORS/SameSite.
        \Spoome\Core\Session::start();

        $user = $this->requireUser($request);
        if ($user === null) {
            return;
        }

        // Floor anti-abuso per-utente PRIMA di qualsiasi lavoro DB (protegge il pool FPM condiviso).
        $rl  = new RateLimiter();
        $key = 'stream:' . $user->id;
        if ($rl->tooManyByKey($key, self::POLL_MAX, self::POLL_WINDOW_M)) {
            header('Retry-After: 5');
            Response::error(I18n::t('auth.error.throttled'), 429, null, ['retry_after' => 5]);
            return;
        }
        $rl->hit($key, $request->ip());

        $cursor     = max(0, (int) $request->input('cursor', 0));
        $feedCursor = max(0, (int) $request->input('feed_cursor', 0));

        $repo = new EventRepository();
        $rows = $repo->since($user->id, $cursor, self::LIMIT);

        $events = [];
        $maxId  = $cursor;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if ($id > $maxId) {
                $maxId = $id;
            }
            $payload = ($row['payload'] ?? null) !== null ? json_decode((string) $row['payload'], true) : null;
            $events[] = [
                'id'               => $id,
                'type'             => (string) $row['type'],
                'actor_profile_id' => $row['actor_profile_id'] !== null ? (int) $row['actor_profile_id'] : null,
                'created_at'       => $row['created_at'],
                'data'             => is_array($payload) ? $payload : new \stdClass(),
            ];
        }

        // Contatori denormalizzati (identici a quelli letti dai nav helper) — letture O(1).
        $notifUnread = (new NotificationRepository())->unreadCount($user->id);
        $profile     = (new ProfileRepository())->findByUserId($user->id);
        $dmUnread    = $profile !== null ? (new MessageRepository())->unreadTotal($profile->id) : 0;

        // Feed: audience reale dell'utente (sé + seguiti + connessi) — la stessa sorgente della timeline.
        // Il conteggio "nuovi post" e il cursore feed sono scopati a questi autori: nessun dato site-wide
        // (né il MAX(id) globale) trapela mai. Se l'utente non ha un profilo, feed vuoto.
        $sourceIds  = $profile !== null ? (new FeedRepository())->sourceIds($profile->id) : [];
        $newPosts   = ($feedCursor > 0 && $sourceIds !== []) ? $repo->newPostsCount($sourceIds, $feedCursor) : 0;
        $feedLatest = $sourceIds !== [] ? $repo->feedLatestPostId($sourceIds) : 0;

        Response::json([
            'events'   => $events,
            'counters' => [
                'notif_unread' => $notifUnread,
                'dm_unread'    => $dmUnread,
            ],
            'feed'     => [
                'new_posts_count' => $newPosts,
                'latest_post_id'  => $feedLatest,
            ],
        ], 200, [
            'cursor'   => $maxId,
            'has_more' => count($rows) === self::LIMIT,
        ]);
    }
}
