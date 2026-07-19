<?php
/**
 * Scalabilità: PK/FK da `INT` firmato → `BIGINT UNSIGNED` sulle tabelle ad alta crescita
 * (posts, messages, follows, connections, activities). INT firmato satura a ~2,1 mld; farlo
 * ORA che le tabelle sono piccole evita un ALTER con lock di ore a scala.
 *
 * Regola InnoDB: una colonna FK deve combaciare in tipo E signedness col PK referenziato.
 * L'unica catena di FK entranti è `posts.id` ← `post_likes.post_id`, `post_comments.post_id`
 * (entrambe ON DELETE CASCADE), quindi quelle due colonne vanno convertite insieme a posts.id.
 * messages/follows/connections/activities non hanno FK entranti.
 *
 * Sequenza sicura: DROP FK entranti → MODIFY PK e colonne FK → RE-ADD FK (stesse regole).
 * AUTO_INCREMENT (contatore) è preservato da MODIFY. Idempotente: ogni passo controlla
 * information_schema prima di agire, così è ri-eseguibile senza errori.
 */
return new class {
    private function columnType(\PDO $pdo, string $table, string $column): string
    {
        $stmt = $pdo->prepare(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        return strtolower((string) $stmt->fetchColumn());
    }

    private function fkExists(\PDO $pdo, string $table, string $constraint): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :t
               AND CONSTRAINT_NAME = :n AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );
        $stmt->execute(['t' => $table, 'n' => $constraint]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function up(\PDO $pdo): void
    {
        // 1) DROP delle FK entranti su posts.id (necessario per poter alterare i tipi).
        if ($this->fkExists($pdo, 'post_likes', 'fk_like_post')) {
            $pdo->exec('ALTER TABLE post_likes DROP FOREIGN KEY fk_like_post');
        }
        if ($this->fkExists($pdo, 'post_comments', 'fk_comment_post')) {
            $pdo->exec('ALTER TABLE post_comments DROP FOREIGN KEY fk_comment_post');
        }

        // 2) MODIFY dei PK ad alta crescita → BIGINT UNSIGNED (AUTO_INCREMENT preservato).
        if ($this->columnType($pdo, 'posts', 'id') !== 'bigint unsigned') {
            $pdo->exec('ALTER TABLE posts MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
        }
        if ($this->columnType($pdo, 'messages', 'id') !== 'bigint unsigned') {
            $pdo->exec('ALTER TABLE messages MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
        }
        if ($this->columnType($pdo, 'follows', 'id') !== 'bigint unsigned') {
            $pdo->exec('ALTER TABLE follows MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
        }
        if ($this->columnType($pdo, 'connections', 'id') !== 'bigint unsigned') {
            $pdo->exec('ALTER TABLE connections MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
        }
        if ($this->columnType($pdo, 'activities', 'id') !== 'bigint unsigned') {
            $pdo->exec('ALTER TABLE activities MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
        }

        // 3) MODIFY delle colonne FK referenzianti posts.id (devono combaciare in tipo+signedness).
        if ($this->columnType($pdo, 'post_likes', 'post_id') !== 'bigint unsigned') {
            $pdo->exec('ALTER TABLE post_likes MODIFY post_id BIGINT UNSIGNED NOT NULL');
        }
        if ($this->columnType($pdo, 'post_comments', 'post_id') !== 'bigint unsigned') {
            $pdo->exec('ALTER TABLE post_comments MODIFY post_id BIGINT UNSIGNED NOT NULL');
        }

        // 4) RE-ADD delle FK con le stesse regole originali (ON DELETE CASCADE).
        if (!$this->fkExists($pdo, 'post_likes', 'fk_like_post')) {
            $pdo->exec(
                'ALTER TABLE post_likes ADD CONSTRAINT fk_like_post
                 FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE'
            );
        }
        if (!$this->fkExists($pdo, 'post_comments', 'fk_comment_post')) {
            $pdo->exec(
                'ALTER TABLE post_comments ADD CONSTRAINT fk_comment_post
                 FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE'
            );
        }
    }

    public function down(\PDO $pdo): void
    {
        // Reverse: torna a INT firmato. Attenzione: reversibile solo finché i valori stanno in INT.
        if ($this->fkExists($pdo, 'post_likes', 'fk_like_post')) {
            $pdo->exec('ALTER TABLE post_likes DROP FOREIGN KEY fk_like_post');
        }
        if ($this->fkExists($pdo, 'post_comments', 'fk_comment_post')) {
            $pdo->exec('ALTER TABLE post_comments DROP FOREIGN KEY fk_comment_post');
        }

        if ($this->columnType($pdo, 'posts', 'id') !== 'int') {
            $pdo->exec('ALTER TABLE posts MODIFY id INT NOT NULL AUTO_INCREMENT');
        }
        if ($this->columnType($pdo, 'messages', 'id') !== 'int') {
            $pdo->exec('ALTER TABLE messages MODIFY id INT NOT NULL AUTO_INCREMENT');
        }
        if ($this->columnType($pdo, 'follows', 'id') !== 'int') {
            $pdo->exec('ALTER TABLE follows MODIFY id INT NOT NULL AUTO_INCREMENT');
        }
        if ($this->columnType($pdo, 'connections', 'id') !== 'int') {
            $pdo->exec('ALTER TABLE connections MODIFY id INT NOT NULL AUTO_INCREMENT');
        }
        if ($this->columnType($pdo, 'activities', 'id') !== 'int') {
            $pdo->exec('ALTER TABLE activities MODIFY id INT NOT NULL AUTO_INCREMENT');
        }

        if ($this->columnType($pdo, 'post_likes', 'post_id') !== 'int') {
            $pdo->exec('ALTER TABLE post_likes MODIFY post_id INT NOT NULL');
        }
        if ($this->columnType($pdo, 'post_comments', 'post_id') !== 'int') {
            $pdo->exec('ALTER TABLE post_comments MODIFY post_id INT NOT NULL');
        }

        if (!$this->fkExists($pdo, 'post_likes', 'fk_like_post')) {
            $pdo->exec(
                'ALTER TABLE post_likes ADD CONSTRAINT fk_like_post
                 FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE'
            );
        }
        if (!$this->fkExists($pdo, 'post_comments', 'fk_comment_post')) {
            $pdo->exec(
                'ALTER TABLE post_comments ADD CONSTRAINT fk_comment_post
                 FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE'
            );
        }
    }
};
