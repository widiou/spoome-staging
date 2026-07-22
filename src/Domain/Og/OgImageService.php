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
     * Byte PNG della card per un handle pubblico + flag `fallback`. Non solleva mai.
     *
     *  - card reale (ricca o degradata): `fallback=false` → il controller la può cachare a lungo (l'URL è
     *    content-addressed via `?v=firma`).
     *  - card di brand (handle inesistente/non pubblico, o errore di rendering): `fallback=true` → il
     *    controller emette `no-store`, così un ripiego NON resta cachato 1 anno sull'URL versionato
     *    (anti cache-poisoning, P2 review).
     *
     * DoS: la card di brand è deterministica → è renderizzata UNA sola volta e servita dal disco su ogni
     * hit ({@see brandBytes}); l'endpoint pubblico sessionless non rigenera GD ad ogni richiesta.
     *
     * @return array{bytes:string, fallback:bool}
     */
    public function imageFor(string $handle): array
    {
        try {
            $profile = $handle !== '' ? $this->profiles->findPublicByHandle($handle) : null;
            if ($profile === null) {
                return ['bytes' => $this->brandBytes(), 'fallback' => true];
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
                return ['bytes' => $cached, 'fallback' => false];
            }

            // render() solleva su errore GD "duro" (non sulla degradazione senza font, che è una card valida):
            // così la card degradata viene cachata come reale, mentre un errore vero cade sul brand (no-store).
            $card  = OgCardData::fromProfile($profile, $clubVerified);
            $bytes = $this->renderer->render($card);
            if ($bytes !== '') {
                $this->store($cacheFile, $bytes, $this->cacheDir() . '/' . $pid . '-*.png');
                return ['bytes' => $bytes, 'fallback' => false];
            }
            return ['bytes' => $this->brandBytes(), 'fallback' => true];
        } catch (\Throwable $e) {
            return ['bytes' => $this->brandBytes(), 'fallback' => true];
        }
    }

    /**
     * Byte della card di brand, renderizzata UNA sola volta e cachata su disco (`_brand-r{RENDER_VERSION}.png`).
     * Elimina il vettore di amplificazione DoS: nessun render GD full-canvas per gli hit su handle
     * inesistenti/privati o in caso di errore. Best-effort: se la scrittura fallisce, ritorna comunque i byte.
     */
    private function brandBytes(): string
    {
        $file = $this->cacheDir() . '/_brand-r' . OgCardData::RENDER_VERSION . '.png';
        $cached = @file_get_contents($file);
        if ($cached !== false && $cached !== '') {
            return $cached;
        }
        $bytes = $this->renderer->brandCard();
        if ($bytes !== '') {
            $this->store($file, $bytes, null);
        }
        return $bytes !== '' ? $bytes : OgImageRenderer::floor();
    }

    /** Rete di sicurezza statica per il controller (PNG sempre valido). */
    public static function floor(): string
    {
        return OgImageRenderer::floor();
    }

    /**
     * Scrittura atomica (tmp + rename). Se `$cleanupGlob` è dato, ripulisce pigramente le versioni vecchie
     * che matchano (le card obsolete dello stesso profilo). Best-effort: una cache mancata non rompe mai la risposta.
     */
    private function store(string $cacheFile, string $bytes, ?string $cleanupGlob): void
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
            if ($cleanupGlob !== null) {
                foreach (glob($cleanupGlob) ?: [] as $old) {
                    if ($old !== $cacheFile) {
                        @unlink($old);
                    }
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
