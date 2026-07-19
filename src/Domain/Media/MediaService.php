<?php

namespace Spoome\Domain\Media;

use InvalidArgumentException;
use Spoome\Core\I18n;
use Spoome\Core\Logger;
use Spoome\Core\ServiceResult;
use Spoome\Domain\Auth\RateLimiter;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Support\Str;

/**
 * Gestione delle immagini del profilo (avatar/copertina) come Service condiviso: validazione+ri-codifica
 * (ImageService), persistenza (media + profilo), swap atomico del vecchio file. Consumato sia dal web
 * (cropper.js → JSON) sia dall'API nativa (multipart Bearer). Ritorna sempre un ServiceResult, così la
 * traduzione HTTP resta nell'unico responder condiviso (Controller::emitJson).
 */
final class MediaService
{
    /** Configurazione per tipo: cartella pubblica, colonna profilo, setter e processore ImageService. */
    private const KINDS = [
        'avatar' => ['dir' => 'uploads/avatars', 'col' => 'avatar_media_id', 'setter' => 'setAvatarMediaId', 'processor' => 'processAvatar'],
        'cover'  => ['dir' => 'uploads/covers',  'col' => 'cover_media_id',  'setter' => 'setCoverMediaId',  'processor' => 'processCover'],
    ];

    /**
     * Elabora e salva un'immagine caricata, sostituendo l'eventuale precedente.
     * $tmpPath deve essere il file già validato come upload (`is_uploaded_file`) dal chiamante (transport).
     *
     * Multi-profilo: opera sul profilo $profileId (l'ACTING profile già autorizzato via canActAs dal
     * controller); $userId resta il proprietario della riga media (l'uploader) per lo scoping del disco.
     *
     * @return ServiceResult ok(['image_url'=>...]) | fail (422 immagine · 429 throttle · 500 profilo assente)
     */
    public function replace(int $userId, int $profileId, string $kind, string $tmpPath, string $ip): ServiceResult
    {
        $cfg = self::KINDS[$kind] ?? null;
        if ($cfg === null) {
            return ServiceResult::fail(I18n::t('avatar.error.invalid'), 422);
        }

        $profiles = new ProfileRepository();
        $profile  = $profiles->findEnrichedById($profileId);
        if ($profile === null) {
            return ServiceResult::fail(I18n::t('api.error.internal'), 500);
        }

        // Rate-limit: max 20 upload/10min per utente (anti-DoS/abuso). Vale per web e API.
        $limiter = new RateLimiter();
        if ($limiter->tooManyByKey('upload:' . $userId, 20, 10)) {
            return ServiceResult::fail(I18n::t('auth.error.throttled'), 429);
        }
        $limiter->hit('upload:' . $userId, $ip);

        $publicDir = \dirname(__DIR__, 3) . '/public';
        $relPath   = $cfg['dir'] . '/' . Str::token(16) . '.webp';
        $destPath  = $publicDir . '/' . $relPath;

        try {
            $meta = (new ImageService())->{$cfg['processor']}($tmpPath, $destPath);
        } catch (InvalidArgumentException $e) {
            Logger::warning('Upload immagine rifiutato', ['reason' => $e->getMessage(), 'kind' => $kind, 'user' => $userId]);
            return ServiceResult::fail($this->reasonMessage($e->getMessage()), 422);
        }

        $media   = new MediaRepository();
        $mediaId = $media->create($userId, $kind, $relPath, $meta['mime'], $meta['width'], $meta['height'], $meta['size']);

        $oldId = isset($profile[$cfg['col']]) ? (int) $profile[$cfg['col']] : 0;
        $profiles->{$cfg['setter']}((int) $profile['id'], $mediaId);
        if ($oldId > 0) {
            $this->deleteMedia($media, $publicDir, $oldId, $userId);
        }

        return ServiceResult::ok(['image_url' => url($relPath)]);
    }

    /**
     * Rimuove l'immagine corrente (avatar o cover) del profilo $profileId (ACTING profile già
     * autorizzato dal controller). $userId = proprietario della riga media, per lo scoping del disco.
     * @return ServiceResult noContent | 500
     */
    public function remove(int $userId, int $profileId, string $kind): ServiceResult
    {
        $cfg = self::KINDS[$kind] ?? null;
        if ($cfg === null) {
            return ServiceResult::fail(I18n::t('avatar.error.invalid'), 422);
        }

        $profiles = new ProfileRepository();
        $profile  = $profiles->findEnrichedById($profileId);
        if ($profile === null) {
            return ServiceResult::fail(I18n::t('api.error.internal'), 500);
        }

        $oldId = isset($profile[$cfg['col']]) ? (int) $profile[$cfg['col']] : 0;
        if ($oldId > 0) {
            $profiles->{$cfg['setter']}((int) $profile['id'], null);
            $this->deleteMedia(new MediaRepository(), \dirname(__DIR__, 3) . '/public', $oldId, $userId);
        }

        return ServiceResult::noContent();
    }

    /** Rimuove il file dal disco (path confinato in uploads/) e la riga media, SOLO se appartiene a $ownerId. */
    private function deleteMedia(MediaRepository $media, string $publicDir, int $mediaId, int $ownerId): void
    {
        $row = $media->findById($mediaId);
        if ($row === null || $row->userId !== $ownerId) {
            return; // difesa in profondità: non tocca media di altri
        }
        if (str_starts_with($row->diskPath, 'uploads/') && !str_contains($row->diskPath, '..')) {
            $abs = $publicDir . '/' . $row->diskPath;
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        $media->deleteById($mediaId);
    }

    private function reasonMessage(string $code): string
    {
        return match ($code) {
            'too_large' => I18n::t('avatar.error.too_large'),
            'bad_type'  => I18n::t('avatar.error.bad_type'),
            default     => I18n::t('avatar.error.invalid'),
        };
    }
}
