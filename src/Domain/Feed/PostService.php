<?php

namespace Spoome\Domain\Feed;

use Spoome\Core\I18n;
use Spoome\Core\ServiceResult;
use Spoome\Domain\Auth\RateLimiter;
use Spoome\Domain\Links\LinkPreviewRepository;

/**
 * Regole dei post: validazione corpo, rate-limit anti-spam, ownership in cancellazione.
 * Riusato da Web e API.
 */
final class PostService
{
    private const BODY_MAX = 2000;
    private const MAX_POSTS = 20;   // post
    private const WINDOW_MIN = 10;  // per finestra (minuti)

    private PostRepository $posts;
    private RateLimiter $limiter;

    public function __construct(?PostRepository $posts = null, ?RateLimiter $limiter = null)
    {
        $this->posts = $posts ?? new PostRepository();
        $this->limiter = $limiter ?? new RateLimiter();
    }

    public function create(int $profileId, array $input, string $ip = 'unknown'): ServiceResult
    {
        $body = trim((string) ($input['body'] ?? ''));
        if ($body === '') {
            return ServiceResult::fail(I18n::t('post.error.empty'), 422, ['body' => I18n::t('post.error.empty')]);
        }
        if (mb_strlen($body) > self::BODY_MAX) {
            return ServiceResult::fail(I18n::t('post.error.too_long'), 422, ['body' => I18n::t('post.error.too_long')]);
        }
        if ($this->limiter->tooManyByKey('post:' . $profileId, self::MAX_POSTS, self::WINDOW_MIN)) {
            return ServiceResult::fail(I18n::t('post.error.throttled'), 429);
        }

        // Link-card opzionale: si accetta SOLO un hash che esiste già in link_previews (status ok),
        // creato da un unfurl precedente. Un hash arbitrario/inesistente viene semplicemente ignorato.
        $linkHash = $this->resolveLinkHash($input['link_preview_url_hash'] ?? null);

        $id = $this->posts->create($profileId, $body, $linkHash);
        $this->limiter->hit('post:' . $profileId, $ip);
        return ServiceResult::ok(['id' => $id], [], 201);
    }

    /** Ritorna l'hash SOLO se è un sha256 valido e referenzia un'anteprima esistente e 'ok'. */
    private function resolveLinkHash(mixed $raw): ?string
    {
        $hash = trim((string) ($raw ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return null;
        }
        $row = (new LinkPreviewRepository())->find($hash);
        return ($row !== null && ($row['status'] ?? '') === 'ok') ? $hash : null;
    }

    public function delete(int $id, int $profileId): ServiceResult
    {
        return $this->posts->delete($id, $profileId)
            ? ServiceResult::noContent()
            : ServiceResult::fail(I18n::t('post.error.not_found'), 404);
    }
}
