<?php

namespace Spoome\Domain\Feed;

use Spoome\Core\Pagination;
use Spoome\Domain\Links\LinkPreviewPresenter;
use Spoome\Domain\Links\LinkPreviewRepository;
use Spoome\Domain\Profiles\ProfileRepository;

/**
 * Compone il feed di un profilo: sorgenti (seguiti ∪ connessi ∪ sé) → timeline unita
 * (post + attività) → idratazione autori + like/commenti → elementi pronti per API/vista.
 */
final class FeedService
{
    public const PER_PAGE = 20;

    private FeedRepository $feed;
    private ProfileRepository $profiles;
    private PostRepository $posts;

    public function __construct(?FeedRepository $feed = null, ?ProfileRepository $profiles = null, ?PostRepository $posts = null)
    {
        $this->feed = $feed ?? new FeedRepository();
        $this->profiles = $profiles ?? new ProfileRepository();
        $this->posts = $posts ?? new PostRepository();
    }

    /**
     * @return array{items:array<int,array>, has_more:bool, page:int, per_page:int}
     */
    public function timeline(int $profileId, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $offset  = Pagination::of($page, $perPage)->offset();

        // Feed SOLO-POST rankato (niente più attività "ha iniziato a seguire…"): affinità autore + engagement + freschezza.
        $sourceIds = $this->feed->sourceIds($profileId);
        $connIds   = $this->feed->connectionIds($profileId);
        // +1 per sapere se c'è una pagina successiva senza un COUNT costoso.
        $rows = $this->feed->rankedPosts($sourceIds, $connIds, $profileId, $perPage + 1, $offset);
        $hasMore = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);

        $authorIds = array_map(static fn ($r) => (int) $r['profile_id'], $rows);
        $authors = $this->profiles->cardsByIds($authorIds);

        // Idratazione engagement: like del viewer + commenti, per i soli post della pagina.
        $postIds = [];
        foreach ($rows as $r) {
            if ($r['kind'] === 'post') {
                $postIds[] = (int) $r['id'];
            }
        }
        $likedSet = array_flip($this->posts->likedPostIds($profileId, $postIds));
        $commentsByPost = $this->posts->commentsForPosts($postIds);

        // Anteprime link dei post della pagina (batch, una sola query).
        $hashes = [];
        foreach ($rows as $r) {
            if ($r['kind'] === 'post' && !empty($r['link_preview_url_hash'])) {
                $hashes[] = (string) $r['link_preview_url_hash'];
            }
        }
        $previews = $hashes === [] ? [] : (new LinkPreviewRepository())->findMany($hashes);

        $items = [];
        foreach ($rows as $row) {
            $author = $authors[(int) $row['profile_id']] ?? null;
            if ($author === null) {
                continue; // autore non più disponibile
            }
            $extra = [];
            if ($row['kind'] === 'post') {
                $pid = (int) $row['id'];
                $hash = (string) ($row['link_preview_url_hash'] ?? '');
                $preview = ($hash !== '' && isset($previews[$hash])) ? LinkPreviewPresenter::card($previews[$hash]) : null;
                $extra = [
                    'likes_count'    => (int) ($row['likes_count'] ?? 0),
                    'comments_count' => (int) ($row['comments_count'] ?? 0),
                    'liked'          => isset($likedSet[$pid]),
                    'comments'       => $commentsByPost[$pid] ?? [],
                    'link_preview'   => $preview,
                ];
            }
            $items[] = FeedPresenter::item($row, $author, $extra);
        }

        // News di settore: solo in pagina 1, mischiate a densità controllata (max 1 ogni ~5 elementi social),
        // rankate per gli sport d'interesse dell'utente (proprio + di chi segue/è connesso). Attribuite alla
        // pagina-federazione della fonte. Non tocca la sorgente social né i contatori.
        if ($page === 1) {
            $items = $this->injectNews($items, $sourceIds);
        }

        return ['items' => $items, 'has_more' => $hasMore, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Mischia le news d'interesse tra gli elementi social del feed.
     * @param array<int,array> $items @param int[] $sourceIds profili sorgente del feed (incl. sé)
     * @return array<int,array>
     */
    private function injectNews(array $items, array $sourceIds): array
    {
        $sportIds = $this->profiles->sportIdsFor($sourceIds);
        sort($sportIds);
        // Le news cambiano solo alla cadenza di ingest (refresh_minutes ≥ 60): cachiamo per set-di-sport
        // (chiave condivisa tra tutti gli utenti con lo stesso interesse) → 2 query fuori dal percorso caldo.
        $key  = 'news_sports_' . md5(implode(',', $sportIds));
        $news = \Spoome\Core\Cache::remember($key, 600, static fn () => (new \Spoome\Domain\News\NewsRepository())->forSports($sportIds, 3));
        if ($news === []) {
            return $items;
        }
        $positions = [2, 7, 12];
        foreach (array_values($news) as $k => $n) {
            $item = [
                'kind'       => 'news',
                'id'         => 'news-' . (int) $n['id'],
                'created_at' => (string) ($n['published_at'] ?? ''),
                'news'       => [
                    'title'     => (string) $n['title'],
                    'url'       => (string) $n['url'],
                    'summary'   => $n['summary'] ?? null,
                    'image'     => $n['image_url'] ?? null,
                    'sport'     => $n['sport_name'] ?? null,
                    'source'    => (string) $n['source_name'],
                ],
                'org'        => [
                    'handle'          => (string) ($n['org_handle'] ?? ''),
                    'display_name'    => (string) ($n['org_name'] ?? $n['source_name']),
                    'type_key'        => $n['org_type_key'] ?? null,
                    'is_organization' => !empty($n['org_is_org']),
                    'avatar_path'     => $n['org_avatar_path'] ?? null,
                    'verified'        => !empty($n['org_verified']),
                ],
            ];
            $pos = $positions[$k] ?? (($k + 1) * 5);
            $pos = min($pos, count($items));
            array_splice($items, $pos, 0, [$item]);
        }
        return $items;
    }

    /**
     * Post di un singolo profilo (sezione "Post" della pagina profilo), idratati come gli elementi del feed
     * (autore, like-state del visitatore, commenti, anteprima link) → render con lo stesso partial feed-item.
     * @param int      $ownerPid  profilo di cui mostrare i post
     * @param int|null $viewerPid profilo personale del visitatore (per lo stato "mi piace"); null se anonimo
     * @return array<int,array>
     */
    public function postsOf(int $ownerPid, ?int $viewerPid, int $limit = 12): array
    {
        $rows = $this->feed->postsOf($ownerPid, max(1, min(50, $limit)));
        if ($rows === []) {
            return [];
        }
        $author = $this->profiles->cardsByIds([$ownerPid])[$ownerPid] ?? null;
        if ($author === null) {
            return [];
        }

        $postIds        = array_map(static fn ($r) => (int) $r['id'], $rows);
        $likedSet       = $viewerPid !== null ? array_flip($this->posts->likedPostIds($viewerPid, $postIds)) : [];
        $commentsByPost = $this->posts->commentsForPosts($postIds);

        $hashes = [];
        foreach ($rows as $r) {
            if (!empty($r['link_preview_url_hash'])) {
                $hashes[] = (string) $r['link_preview_url_hash'];
            }
        }
        $previews = $hashes === [] ? [] : (new LinkPreviewRepository())->findMany($hashes);

        $items = [];
        foreach ($rows as $row) {
            $pid     = (int) $row['id'];
            $hash    = (string) ($row['link_preview_url_hash'] ?? '');
            $preview = ($hash !== '' && isset($previews[$hash])) ? LinkPreviewPresenter::card($previews[$hash]) : null;
            $items[] = FeedPresenter::item($row, $author, [
                'likes_count'    => (int) ($row['likes_count'] ?? 0),
                'comments_count' => (int) ($row['comments_count'] ?? 0),
                'liked'          => isset($likedSet[$pid]),
                'comments'       => $commentsByPost[$pid] ?? [],
                'link_preview'   => $preview,
            ]);
        }
        return $items;
    }
}
