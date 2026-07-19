<?php

namespace Spoome\Domain\Feed;

use PDO;
use Spoome\Core\Db;

/**
 * Costruzione della timeline: sorgenti (profili seguiti ∪ connessi ∪ sé) e merge cronologico
 * di post + attività. Le righe grezze vengono poi idratate (autore) dal FeedService.
 */
final class FeedRepository
{
    /* --- Pesi dell'algoritmo di ranking del feed (solo-post). Tunabili. ---
     * score = affinità_autore·W_AFFINITY + ln(1+engagement)·W_ENGAGE − età_ore·W_DECAY
     * Affinità: sé=3, connessione=2, follow=1. L'engagement usa il logaritmo per smorzare i viral outlier;
     * il decadimento lineare per ora fa scendere i post vecchi ma l'engagement li tiene su più a lungo. */
    private const W_AFFINITY  = 2.5;
    private const W_ENGAGE    = 1.5;
    private const W_DECAY     = 0.08; // punti sottratti per ora di età
    private const WINDOW_DAYS = 45;   // finestra dei candidati (niente post antichi)

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /**
     * Id dei profili CONNESSI (accepted) a $profileId — per pesare l'affinità nel ranking. @return int[]
     */
    public function connectionIds(int $profileId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT CASE WHEN requester_id = :me1 THEN addressee_id ELSE requester_id END
             FROM connections WHERE status = 'accepted' AND (requester_id = :me2 OR addressee_id = :me3)"
        );
        $stmt->execute(['me1' => $profileId, 'me2' => $profileId, 'me3' => $profileId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Feed SOLO-POST rankato: post dei profili sorgente (sé ∪ follows ∪ connessioni) nella finestra,
     * ordinati per uno score che combina affinità autore, engagement e freschezza. Stessa shape di timeline()
     * (kind='post') così l'idratazione e il partial feed-item restano invariati.
     * @param int[] $sourceIds @param int[] $connectionIds
     * @return array<int,array>
     */
    public function rankedPosts(array $sourceIds, array $connectionIds, int $me, int $limit, int $offset): array
    {
        $src = array_values(array_unique(array_map('intval', $sourceIds)));
        if ($src === []) {
            return [];
        }
        $conn = array_values(array_unique(array_map('intval', $connectionIds)));

        $inSrc = implode(',', array_fill(0, count($src), '?'));
        if ($conn !== []) {
            $inConn   = implode(',', array_fill(0, count($conn), '?'));
            $affinity = "CASE WHEN profile_id = ? THEN 3 WHEN profile_id IN ($inConn) THEN 2 ELSE 1 END";
        } else {
            $affinity = "CASE WHEN profile_id = ? THEN 3 ELSE 1 END";
        }

        $sql = "SELECT 'post' AS kind, id, profile_id, body AS text, NULL AS act_type, NULL AS subject_id,
                       created_at, likes_count, comments_count, link_preview_url_hash,
                       ( ($affinity) * " . self::W_AFFINITY . "
                         + LN(1 + likes_count + 2 * comments_count) * " . self::W_ENGAGE . "
                         - TIMESTAMPDIFF(HOUR, created_at, UTC_TIMESTAMP()) * " . self::W_DECAY . "
                       ) AS score
                FROM posts
                WHERE profile_id IN ($inSrc)
                  AND created_at > (UTC_TIMESTAMP() - INTERVAL " . self::WINDOW_DAYS . " DAY)
                ORDER BY score DESC, id DESC
                LIMIT ? OFFSET ?";

        $stmt = $this->pdo->prepare($sql);
        // Ordine di binding = ordine dei ? nella query: me (affinità), connIds, srcIds (WHERE), limit, offset.
        $i = 1;
        $stmt->bindValue($i++, $me, PDO::PARAM_INT);
        foreach ($conn as $v) { $stmt->bindValue($i++, $v, PDO::PARAM_INT); }
        foreach ($src as $v)  { $stmt->bindValue($i++, $v, PDO::PARAM_INT); }
        $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($i++, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** TTL (secondi) della cache del set-sorgente del feed: staleness accettabile (un follow/una
     *  connessione si riflette entro ~1 min). Chiave per-profilo → nessun leak cross-utente. */
    private const SOURCES_TTL = 45;

    private static function sourcesCacheKey(int $profileId): string
    {
        return 'feed_sources_' . $profileId;
    }

    /**
     * Invalida la cache del set-sorgente di un profilo. Da chiamare nei punti di mutazione del grafo
     * (follow/unfollow, accept/delete connessione) così l'aggiunta/rimozione si riflette subito;
     * anche senza, il TTL breve limita la staleness. Statico: invocabile dai repository del grafo
     * senza istanziare FeedRepository.
     */
    public static function forgetSources(int $profileId): void
    {
        \Spoome\Core\Cache::forget(self::sourcesCacheKey($profileId));
    }

    /**
     * Id dei profili le cui attività compaiono nel feed di $profileId:
     * sé stesso + i profili seguiti + i profili connessi (accepted).
     *
     * In cache per-profilo (TTL breve): questo grafo è ricalcolato ad OGNI poll dello stream realtime
     * (StreamController::since) oltre che ad ogni caricamento feed → la cache toglie quelle 2 query
     * dal percorso caldo. Invalidazione esplicita nei punti di mutazione del grafo; il TTL è il backstop.
     * @return int[]
     */
    public function sourceIds(int $profileId): array
    {
        return \Spoome\Core\Cache::remember(self::sourcesCacheKey($profileId), self::SOURCES_TTL, function () use ($profileId): array {
            $stmt = $this->pdo->prepare(
                "SELECT :me AS id
                 UNION SELECT followee_id FROM follows WHERE follower_id = :me1
                 UNION SELECT CASE WHEN requester_id = :me2 THEN addressee_id ELSE requester_id END
                       FROM connections WHERE status = 'accepted' AND (requester_id = :me3 OR addressee_id = :me4)"
            );
            $stmt->execute(['me' => $profileId, 'me1' => $profileId, 'me2' => $profileId, 'me3' => $profileId, 'me4' => $profileId]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        });
    }

    /**
     * Solo i POST di un singolo profilo (per la sezione "Post" della pagina profilo), più recenti prima.
     * Stessa shape di timeline() così da riusare l'idratazione + il partial feed-item.
     * @return array<int,array>
     */
    public function postsOf(int $profileId, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT 'post' AS kind, id, profile_id, body AS text, NULL AS act_type, NULL AS subject_id, created_at, likes_count, comments_count, link_preview_url_hash
             FROM posts WHERE profile_id = :p ORDER BY created_at DESC, id DESC LIMIT :lim"
        );
        $stmt->bindValue(':p', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Timeline unita (post + attività) dei profili indicati, più recenti prima.
     * @param int[] $profileIds
     * @return array<int,array> righe grezze: kind, id, profile_id, text, act_type, subject_id, created_at
     */
    public function timeline(array $profileIds, int $limit, int $offset): array
    {
        $profileIds = array_values(array_unique(array_map('intval', $profileIds)));
        if ($profileIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($profileIds), '?'));
        $sql = "(SELECT 'post' AS kind, id, profile_id, body AS text, NULL AS act_type, NULL AS subject_id, created_at, likes_count, comments_count, link_preview_url_hash
                 FROM posts WHERE profile_id IN ($in))
                UNION ALL
                (SELECT 'activity' AS kind, id, profile_id, meta AS text, type AS act_type, subject_id, created_at, 0 AS likes_count, 0 AS comments_count, NULL AS link_preview_url_hash
                 FROM activities WHERE profile_id IN ($in))
                ORDER BY created_at DESC, id DESC
                LIMIT ? OFFSET ?";
        $stmt = $this->pdo->prepare($sql);
        $i = 1;
        foreach ($profileIds as $v) {
            $stmt->bindValue($i++, $v, PDO::PARAM_INT);
        }
        foreach ($profileIds as $v) {
            $stmt->bindValue($i++, $v, PDO::PARAM_INT);
        }
        $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($i++, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
