<?php

namespace Spoome\Domain\Connections;

use Spoome\Core\Cache;
use Spoome\Core\I18n;
use Spoome\Core\ServiceResult;
use Spoome\Domain\Auth\RateLimiter;

/**
 * Scoperta connessioni: 2° grado con top-up di fallback + azione "ignora".
 * NON emette notifiche (il bottone "Collegati" riusa la rotta /connetti che le gestisce).
 */
final class ConnectionSuggestionService
{
    private const MAX_DISMISS = 60;
    private const WINDOW_MIN  = 10;

    private ConnectionSuggestionRepository $repo;
    private RateLimiter $limiter;

    public function __construct(?ConnectionSuggestionRepository $repo = null, ?RateLimiter $limiter = null)
    {
        $this->repo = $repo ?? new ConnectionSuggestionRepository();
        $this->limiter = $limiter ?? new RateLimiter();
    }

    /**
     * Suggerimenti per un profilo: 2° grado, completato col fallback se sotto soglia.
     * Cache breve (5 min) per assorbire i refresh ravvicinati sulla pagina Rete.
     * @return array<int,array> righe arricchite (2° grado con 'mutual_count', fallback senza)
     */
    public function suggestionsFor(int $profileId, ?int $sportId, ?string $city, int $limit = 12): array
    {
        return Cache::remember('sugg:' . $profileId, 300, function () use ($profileId, $sportId, $city, $limit): array {
            $primary = $this->repo->secondDegree($profileId, $sportId, $city, $limit);
            if (count($primary) >= $limit) {
                return $primary;
            }

            $excludeIds = array_map(static fn (array $r): int => (int) $r['id'], $primary);
            $need = $limit - count($primary);
            $fallback = $this->repo->fallbackBySportOrCity($profileId, $sportId, $city, $excludeIds, $need);

            return array_merge($primary, $fallback);
        });
    }

    /** Ignora un suggerimento (rate-limit per profilo attore). */
    public function dismiss(int $actorProfileId, int $targetProfileId, string $ip): ServiceResult
    {
        if ($this->limiter->tooManyByKey('sugg:' . $actorProfileId, self::MAX_DISMISS, self::WINDOW_MIN)) {
            return ServiceResult::fail(I18n::t('connect.error.throttled'), 429);
        }
        $this->repo->dismiss($actorProfileId, $targetProfileId);
        $this->limiter->hit('sugg:' . $actorProfileId, $ip);
        // Il suggerimento ignorato deve sparire subito: invalida la cache di lettura.
        Cache::forget('sugg:' . $actorProfileId);
        return ServiceResult::ok();
    }
}
