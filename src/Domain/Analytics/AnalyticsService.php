<?php

namespace Spoome\Domain\Analytics;

use Spoome\Core\Logger;
use Throwable;

/**
 * Recorder degli eventi d'uso (M4). Facade STATICA, fire-and-forget e FAIL-SAFE: un errore
 * dell'analytics NON deve MAI propagarsi alla richiesta (regola d'oro degli helper di nav —
 * un'eccezione qui manderebbe in 500 pagine come /atleti). Ogni scrittura è avvolta in try/catch
 * su Throwable; in caso di errore si logga best-effort e si prosegue.
 *
 * Instrumentazione SINCRONA/PULL (NO cron, #7): la scrittura è un singolo INSERT inline; la lettura
 * è on-demand nell'area admin ({@see AnalyticsRepository}).
 */
final class AnalyticsService
{
    /** Vocabolario eventi (VARCHAR, cresce con M2/M6 senza ALTER). */
    public const EVENT_SEARCH             = 'search';
    public const EVENT_PROFILE_OPEN       = 'profile_open';
    public const EVENT_OPPORTUNITY_PUBLISH = 'opportunity_publish'; // M2 — hook pronto, call site assente
    public const EVENT_APPLY              = 'apply';                 // M2 — hook pronto, call site assente

    /**
     * Kill-switch privacy per il funnel anonimo (anon_id = hash sessione troncato, no PII).
     * In attesa del sign-off di Sara (#44, punto aperto 2): mettere a false disattiva ogni
     * raccolta di anon_id senza toccare i call site.
     */
    private const COLLECT_ANON = true;

    /* ============================================================ CALL SITE ATTIVI ==== */

    /**
     * "Chi cerca": una ricerca è stata eseguita. Per gli anonimi correla via anon_id di sessione.
     * meta PII-light: SOLO il numero di risultati (mai il testo grezzo della query → potrebbe contenere PII).
     */
    public static function search(?int $actorUserId, int $results): void
    {
        self::record(
            self::EVENT_SEARCH,
            $actorUserId,
            'search',
            null,
            ['results' => $results]
        );
    }

    /** "Apre profilo": visita della pagina pubblica di un profilo. subject = profilo aperto. */
    public static function profileOpen(?int $actorUserId, int $profileId): void
    {
        self::record(
            self::EVENT_PROFILE_OPEN,
            $actorUserId,
            'profile',
            $profileId
        );
    }

    /* ============================================================ HOOK M2 (TODO) ==== */
    // I metodi seguenti sono PRONTI ma NON ancora agganciati: i call site vivono in M2 (Opportunities),
    // in costruzione in parallelo. Nessun accoppiamento creato qui — M2 chiamerà questi wrapper con
    // una riga sola. Vedi i TODO nei controller di M2 quando esisteranno.
    //
    // TODO(M2): al publish di un'opportunità →
    //   AnalyticsService::record(AnalyticsService::EVENT_OPPORTUNITY_PUBLISH, $ownerUserId, 'opportunity', $opportunityId);
    // TODO(M2): alla candidatura →
    //   AnalyticsService::record(AnalyticsService::EVENT_APPLY, $applicantUserId, 'opportunity', $opportunityId);

    /* ============================================================ CORE FAIL-SAFE ==== */

    /**
     * Registrazione generica fail-safe. Deriva anon_id dalla sessione SOLO per gli attori anonimi.
     * Non lancia MAI: cattura Throwable, logga best-effort e prosegue.
     *
     * @param array<string,mixed>|null $meta
     */
    public static function record(
        string $eventType,
        ?int $actorUserId,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?array $meta = null
    ): void {
        try {
            $anonId = $actorUserId === null ? self::anonId() : null;
            (new AnalyticsRepository())->insert($eventType, $actorUserId, $subjectType, $subjectId, $anonId, $meta);
        } catch (Throwable $e) {
            // Best-effort: non deve mai far cadere la richiesta. Anche il log è difeso (se il DB è la
            // causa, Logger — che scrive su app_logs — potrebbe a sua volta fallire).
            try {
                Logger::warning('Analytics: registrazione evento fallita', [
                    'event' => $eventType,
                    'reason' => $e->getMessage(),
                ]);
            } catch (Throwable) {
                // ultima spiaggia: log di sistema, mai un'eccezione verso l'alto.
                @error_log('Analytics record failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * anon_id: 16 byte binari derivati (hash) dall'id di sessione PHP. NON è PII e non è reversibile
     * all'id di sessione; serve solo a correlare più eventi dello stesso visitatore anonimo nel funnel.
     * Ritorna null se il tracking anon è disattivato o non c'è una sessione attiva (es. API stateless).
     */
    private static function anonId(): ?string
    {
        if (!self::COLLECT_ANON) {
            return null;
        }
        if (\session_status() !== \PHP_SESSION_ACTIVE) {
            return null;
        }
        $sid = \session_id();
        if ($sid === '' || $sid === false) {
            return null;
        }
        // sha256 raw → 32 byte, troncati a 16 (BINARY(16)). Prefisso di dominio per separare l'uso.
        return \substr(\hash('sha256', 'analytics|' . $sid, true), 0, 16);
    }
}
