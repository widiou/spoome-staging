<?php

/**
 * Grafo "follow" (asimmetrico): un profilo segue un altro profilo (fan → atleta/società).
 * Coppia unica, FK ON DELETE CASCADE. L'anti auto-follow è imposto a livello applicativo (portabilità).
 * Idempotente.
 */
return new class () {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS follows (
                id INT AUTO_INCREMENT PRIMARY KEY,
                follower_id INT NOT NULL,
                followee_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_follow_follower FOREIGN KEY (follower_id) REFERENCES profiles(id) ON DELETE CASCADE,
                CONSTRAINT fk_follow_followee FOREIGN KEY (followee_id) REFERENCES profiles(id) ON DELETE CASCADE,
                UNIQUE KEY uniq_follow (follower_id, followee_id),
                INDEX idx_follow_followee (followee_id),
                INDEX idx_follow_follower (follower_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS follows');
    }
};
