<?php

namespace Spoome\Domain\Og;

use Spoome\Domain\Profiles\AffiliationRepository;
use Spoome\Domain\Profiles\ProfileRepository;

/**
 * Orchestratore della card og:image di un profilo: risolve il profilo pubblico, calcola la card,
 * serve dalla cache su disco o la genera e la memorizza. Fail-safe: non solleva mai — su qualunque
 * problema (profilo assente, errore di rendering) ritorna la card di brand, mai un'anteprima rotta.
 *
 * Cache & invalidazione — scelta di design:
 *  - la cache su disco è keyed su `{profile_id}-{signature}.png`, dove la firma ({@see OgCardData::signature})
 *    è un hash dello STATO MOSTRATO (nome, tipo, sport, città, avatar/cover, verifica).
 *  - lo STESSO hash è messo nell'URL og:image (`?v=`) dalla pagina profilo → l'invalidazione è
 *    CONTENT-ADDRESSED e automatica: cambiando i dati cambia la firma, quindi cambiano sia l'URL
 *    (i crawler rifanno il fetch) sia il file di cache (nessun file stantìo servito). Niente hook di
 *    invalidazione sparsi nei Service (avatar/cover/verifica) → nessuna race di revoca.
 *  - i file vecchi dello stesso profilo vengono ripuliti pigramente alla riscrittura.
 */
final class OgImageService
{
    private ProfileRepository $profiles;
    private AffiliationRepository $affiliations;
    private OgImageRenderer $renderer;

    public function __construct(
        ?ProfileRepository $profiles = null,
        ?AffiliationRepository $affiliations = null,
        ?OgImageRenderer $renderer = null
    ) {
        $this->profiles     = $profiles ?? new ProfileRepository();
        $this->affiliations = $affiliations ?? new AffiliationRepository();
        $this->renderer     = $renderer ?? new OgImageRenderer();
    }

    /**
     * Byte PNG della card per un handle pubblico. Handle inesistente/non pubblico → card di brand (200,
     * anteprima non rotta), senza rivelare nulla del profilo. Non solleva mai.
     */
    public function imageFor(string $handle): string
    {
        try {
            $profile = $handle !== '' ? $this->profiles->findPublicByHandle($handle) : null;
            if ($profile === null) {
                return $this->renderer->brandCard();
            }

            $pid = (int) $profile['id'];

            // Badge derivato "verificato dalla società": query indicizzata SOLO quando serve (atleta non
            // staff-verificato). Endpoint cachato → costo trascurabile.
            $clubVerified = false;
            if (empty($profile['verified_at']) && empty($profile['is_organization'])) {
                $clubVerified = $this->affiliations->verifyingOrgsOf($pid) !== [];
            }

            $sig       = OgCardData::signature($profile, $clubVerified);
            $cacheFile = $this->cacheDir() . '/' . $pid . '-' . $sig . '.png';

            $cached = @file_get_contents($cacheFile);
            if ($cached !== false && $cached !== '') {
                return $cached;
            }

            $card  = OgCardData::fromProfile($profile, $clubVerified);
            $bytes = $this->renderer->render($card);
            if ($bytes !== '') {
                $this->store($cacheFile, $bytes, $pid);
            }
            return $bytes !== '' ? $bytes : $this->renderer->brandCard();
        } catch (\Throwable $e) {
            return $this->renderer->brandCard();
        }
    }

    /** Rete di sicurezza statica per il controller (PNG sempre valido). */
    public static function floor(): string
    {
        return OgImageRenderer::floor();
    }

    /** Scrittura atomica (tmp + rename) + pulizia pigra delle versioni vecchie dello stesso profilo. Best-effort. */
    private function store(string $cacheFile, string $bytes, int $pid): void
    {
        try {
            $dir = dirname($cacheFile);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return;
            }
            $tmp = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) {
                return;
            }
            @chmod($tmp, 0644);
            if (!@rename($tmp, $cacheFile)) {
                @unlink($tmp);
                return;
            }
            foreach (glob($this->cacheDir() . '/' . $pid . '-*.png') ?: [] as $old) {
                if ($old !== $cacheFile) {
                    @unlink($old);
                }
            }
        } catch (\Throwable $e) {
            // best-effort: una cache mancata non deve mai rompere la risposta.
        }
    }

    private function cacheDir(): string
    {
        return dirname(__DIR__, 3) . '/storage/cache/og';
    }
}
