<?php
/**
 * Vivacità del feed: like e commenti sui post, con contatori denormalizzati su `posts`
 * (letti nella timeline senza COUNT live, coerenti con la linea del progetto).
 */
return new class {
    public function up(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS post_likes (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            profile_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_post_like (post_id, profile_id),
            KEY idx_like_profile (profile_id),
            CONSTRAINT fk_like_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
            CONSTRAINT fk_like_profile FOREIGN KEY (profile_id) REFERENCES profiles (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS post_comments (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            profile_id INT NOT NULL,
            body VARCHAR(1000) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_comment_post (post_id, id),
            CONSTRAINT fk_comment_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
            CONSTRAINT fk_comment_profile FOREIGN KEY (profile_id) REFERENCES profiles (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        foreach (['likes_count', 'comments_count'] as $col) {
            $exists = $pdo->query("SHOW COLUMNS FROM posts LIKE '{$col}'")->fetchAll();
            if (!$exists) {
                $pdo->exec("ALTER TABLE posts ADD COLUMN {$col} INT NOT NULL DEFAULT 0");
            }
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS post_comments');
        $pdo->exec('DROP TABLE IF EXISTS post_likes');
        // Drop guardato colonna per colonna (simmetrico all'up() condizionale): non fallisce se ne manca una.
        foreach (['likes_count', 'comments_count'] as $col) {
            $exists = $pdo->query("SHOW COLUMNS FROM posts LIKE '{$col}'")->fetchAll();
            if ($exists) {
                $pdo->exec("ALTER TABLE posts DROP COLUMN {$col}");
            }
        }
    }
};
