<?php

namespace Spoome\Domain\Feed;

use PDO;
use Spoome\Core\Db;
use Spoome\Core\Pagination;

/**
 * Accesso ai `posts` (contenuti scritti dagli utenti).
 */
final class PostRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    public function create(int $profileId, string $body, ?string $linkHash = null): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO posts (profile_id, body, link_preview_url_hash) VALUES (:p, :b, :lh)');
        $stmt->execute(['p' => $profileId, 'b' => $body, 'lh' => $linkHash]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Elimina un post proprio (ownership imposta a livello SQL). True se eliminato. */
    public function delete(int $id, int $profileId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM posts WHERE id = :id AND profile_id = :p');
        $stmt->execute(['id' => $id, 'p' => $profileId]);
        return $stmt->rowCount() === 1;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, profile_id, body, link_preview_url_hash, likes_count, comments_count, created_at FROM posts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /* ---------------------------------------------------------- ENGAGEMENT ---- */

    /**
     * Toggla il like del profilo sul post e mantiene `posts.likes_count` denormalizzato.
     * @return array{liked:bool,count:int}
     */
    public function toggleLike(int $postId, int $profileId): array
    {
        // Atomico: riga post_likes + contatore denormalizzato + lettura count nella STESSA transazione (no drift).
        return Db::transaction($this->pdo, function (PDO $pdo) use ($postId, $profileId): array {
            $ins = $pdo->prepare('INSERT IGNORE INTO post_likes (post_id, profile_id) VALUES (:p, :pr)');
            $ins->execute(['p' => $postId, 'pr' => $profileId]);

            if ($ins->rowCount() === 1) {
                $pdo->prepare('UPDATE posts SET likes_count = likes_count + 1 WHERE id = :id')
                    ->execute(['id' => $postId]);
                $liked = true;
            } else {
                $del = $pdo->prepare('DELETE FROM post_likes WHERE post_id = :p AND profile_id = :pr');
                $del->execute(['p' => $postId, 'pr' => $profileId]);
                if ($del->rowCount() === 1) {
                    $pdo->prepare('UPDATE posts SET likes_count = GREATEST(0, likes_count - 1) WHERE id = :id')
                        ->execute(['id' => $postId]);
                }
                $liked = false;
            }

            $cnt = $pdo->prepare('SELECT likes_count FROM posts WHERE id = :id');
            $cnt->execute(['id' => $postId]);
            return ['liked' => $liked, 'count' => (int) $cnt->fetchColumn()];
        });
    }

    /**
     * Sottoinsieme di $postIds che $profileId ha messo like (stato per la vista feed).
     * @param int[] $postIds
     * @return int[]
     */
    public function likedPostIds(int $profileId, array $postIds): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($postIds))));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT post_id FROM post_likes WHERE profile_id = ? AND post_id IN ($in)");
        $stmt->bindValue(1, $profileId, PDO::PARAM_INT);
        foreach ($ids as $k => $v) {
            $stmt->bindValue($k + 2, $v, PDO::PARAM_INT);
        }
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function addComment(int $postId, int $profileId, string $body): int
    {
        // Atomico: INSERT commento + contatore denormalizzato nella STESSA transazione (no drift).
        return Db::transaction($this->pdo, function (PDO $pdo) use ($postId, $profileId, $body): int {
            $stmt = $pdo->prepare('INSERT INTO post_comments (post_id, profile_id, body) VALUES (:p, :pr, :b)');
            $stmt->execute(['p' => $postId, 'pr' => $profileId, 'b' => $body]);
            $id = (int) $pdo->lastInsertId(); // catturato PRIMA dell'UPDATE
            $pdo->prepare('UPDATE posts SET comments_count = comments_count + 1 WHERE id = :id')
                ->execute(['id' => $postId]);
            return $id;
        });
    }

    public function findComment(int $commentId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, post_id, profile_id, body FROM post_comments WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $commentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Elimina un commento (chiamante già autorizzato) e decrementa il contatore. */
    public function deleteComment(int $commentId, int $postId): void
    {
        // Atomico: DELETE commento + decremento contatore nella STESSA transazione (no drift).
        Db::transaction($this->pdo, function (PDO $pdo) use ($commentId, $postId): void {
            $del = $pdo->prepare('DELETE FROM post_comments WHERE id = :id');
            $del->execute(['id' => $commentId]);
            if ($del->rowCount() === 1) {
                $pdo->prepare('UPDATE posts SET comments_count = GREATEST(0, comments_count - 1) WHERE id = :id')
                    ->execute(['id' => $postId]);
            }
        });
    }

    /**
     * Commenti dei post della pagina, con autore, in un'unica query.
     * @param int[] $postIds
     * @return array<int,array<int,array<string,mixed>>> mappa post_id => lista commenti (cronologica)
     */
    public function commentsForPosts(array $postIds): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($postIds))));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.post_id, c.body, c.created_at, p.handle, p.display_name, p.avatar_media_id, m.disk_path AS avatar_path
             FROM post_comments c
             JOIN profiles p ON p.id = c.profile_id
             LEFT JOIN media m ON m.id = p.avatar_media_id
             WHERE c.post_id IN ($in) ORDER BY c.id ASC"
        );
        foreach ($ids as $k => $v) {
            $stmt->bindValue($k + 1, $v, PDO::PARAM_INT);
        }
        $stmt->execute();
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['post_id']][] = $row;
        }
        return $map;
    }

    /* ------------------------------------------------------------ ADMIN ---- */

    /**
     * Post più recenti con autore, per la moderazione.
     * @return array{items:array<int,array<string,mixed>>, total:int}
     */
    public function recentWithAuthor(int $page = 1, int $perPage = 30): array
    {
        $offset = Pagination::of($page, $perPage)->offset();
        $total  = (int) $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT po.id, po.body, po.created_at, p.handle, p.display_name
             FROM posts po JOIN profiles p ON p.id = po.profile_id
             ORDER BY po.id DESC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /** Elimina un post senza vincolo di ownership (azione admin). True se eliminato. */
    public function deleteById(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM posts WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() === 1;
    }
}
