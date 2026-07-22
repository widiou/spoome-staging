<?php

namespace Spoome\Domain\Og;

/**
 * Modello dati (puro) della card social og:image di un profilo.
 *
 * Trasforma la riga pubblica arricchita del profilo ({@see \Spoome\Domain\Profiles\ProfileRepository::findPublicByHandle})
 * nella forma minima che il renderer disegna: SOLO dati pubblici e strutturati (nome, handle, tipo, sport,
 * città, stato di verifica, avatar/cover). Mai bio/headline in free-text nella card — niente overflow
 * imprevedibile né injection visiva da testo non moderato (spec M5, Bianca).
 *
 * È anche l'unica sede della FIRMA (`signature`) che versiona l'URL og:image: cambiando i dati mostrati
 * cambia la firma → l'URL cambia → i crawler rifanno il fetch. Invalidazione automatica e race-free,
 * senza hook sparsi nei Service.
 */
final class OgCardData
{
    /**
     * Versione del RENDER. Va incrementata quando cambia l'output visivo del renderer (es. quando si
     * aggiungono i font TTF e la card passa da "degradata" a "ricca"): bumpando questa costante TUTTE
     * le firme cambiano → i crawler rigenerano le anteprime già cachate. Vedi OgImageRenderer.
     */
    public const RENDER_VERSION = 1;

    // Etichette del badge di verifica (superficie raster, non HTML → stringhe fisse italiane, niente i18n/e()).
    private const LABEL_VERIFIED_M = 'Verificato';
    private const LABEL_VERIFIED_F = 'Verificata';
    private const LABEL_CLUB       = 'Verificato dalla società';

    /**
     * Firma breve e stabile dello STATO MOSTRATO nella card. Riusata sia dal percorso web (per costruire
     * l'URL `?v=`) sia dall'endpoint (per la chiave di cache su disco): stessa firma da entrambi i lati.
     *
     * @param array<string,mixed> $p            riga pubblica arricchita del profilo
     * @param bool                $clubVerified badge derivato "verificato dalla società" (già calcolato a monte)
     */
    public static function signature(array $p, bool $clubVerified): string
    {
        $parts = [
            'r' . self::RENDER_VERSION,
            (string) ($p['display_name'] ?? ''),
            (string) ($p['handle'] ?? ''),
            (string) ($p['type_label'] ?? ''),
            (string) ($p['sport_name'] ?? ''),
            (string) ($p['location_city'] ?? ''),
            (string) ($p['verified_at'] ?? ''),
            (string) ($p['avatar_media_id'] ?? ''),
            (string) ($p['cover_media_id'] ?? ''),
            $clubVerified ? '1' : '0',
        ];
        return substr(sha1(implode('|', $parts)), 0, 12);
    }

    /**
     * Costruisce il modello di card per il renderer a partire dalla riga pubblica del profilo.
     *
     * @param array<string,mixed> $p            riga pubblica arricchita
     * @param bool                $clubVerified badge derivato "verificato dalla società"
     * @return array<string,mixed>
     */
    public static function fromProfile(array $p, bool $clubVerified): array
    {
        $isOrg = !empty($p['is_organization']);
        $staff = !empty($p['verified_at']);

        if ($staff) {
            $badge      = 'staff';
            $badgeLabel = $isOrg ? self::LABEL_VERIFIED_F : self::LABEL_VERIFIED_M;
        } elseif ($clubVerified && !$isOrg) {
            $badge      = 'club';
            $badgeLabel = self::LABEL_CLUB;
        } else {
            $badge      = 'none';
            $badgeLabel = null;
        }

        $name = trim((string) ($p['display_name'] ?? ''));
        if ($name === '') {
            $name = (string) ($p['handle'] ?? 'Spoome');
        }

        // Riga fatti: tipo · sport · città. Segmenti vuoti omessi (mai un placeholder tipo "— Nessuno —"
        // in un'immagine pubblica). Solo dati strutturati.
        $facts = implode(' · ', array_values(array_filter([
            (string) ($p['type_label'] ?? ''),
            (string) ($p['sport_name'] ?? ''),
            (string) ($p['location_city'] ?? ''),
        ], static fn (string $s): bool => $s !== '')));

        return [
            'name'       => $name,
            'handle'     => (string) ($p['handle'] ?? ''),
            'facts'      => $facts,
            'initials'   => initials($name),
            'is_org'     => $isOrg,
            'badge'      => $badge,          // staff | club | none
            'badge_label' => $badgeLabel,     // stringa o null
            'verified_ring' => $badge !== 'none', // anello giallo attorno all'avatar (rinforzo, mai unico segnale)
            'avatar_path' => self::resolveAsset($p['avatar_path'] ?? null),
            'cover_path'  => self::resolveAsset($p['cover_path'] ?? null),
        ];
    }

    /**
     * Mappa un `disk_path` del DB (es. "uploads/avatars/xyz.webp") al percorso su filesystem, con guardia
     * anti-traversal: SOLO percorsi confinati in `uploads/`, senza `..`, e solo se il file esiste. Altrimenti
     * null → il renderer userà le iniziali (fallback dignitoso). Il path proviene dal DB (fidato), non dall'utente.
     */
    private static function resolveAsset(mixed $diskPath): ?string
    {
        if (!is_string($diskPath) || $diskPath === '') {
            return null;
        }
        if (!str_starts_with($diskPath, 'uploads/') || str_contains($diskPath, '..')) {
            return null;
        }
        $abs = dirname(__DIR__, 3) . '/public/' . $diskPath;
        return is_file($abs) ? $abs : null;
    }
}
