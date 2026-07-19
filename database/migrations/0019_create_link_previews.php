<?php

/**
 * Checkpoint 2 — Link unfurl (rich previews).
 * Cache delle anteprime link chiavata su url_hash = sha256(URL normalizzato), con TTL (expires_at).
 * L'immagine di anteprima NON è re-hostata (R2 differito): si conserva l'URL remoto (untrusted) e il
 * path del NOSTRO image-proxy firmato (image_proxy_path). Swappare proxy→R2 = valorizzare image_proxy_path
 * con l'URL R2 in un solo punto (LinkUnfurlService).
 *
 * NB: numerazione allineata al "prossimo libero" reale del DB (0019), non allo schema teorico della SPEC
 * (che riservava 0019-0020 a realtime): quelle migrazioni non esistono in questo ramo.
 */
return new class () {
    public function up(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS link_previews (
            url_hash         CHAR(64) NOT NULL,
            url              TEXT NOT NULL,
            type             VARCHAR(20)  NOT NULL DEFAULT 'link',   -- 'link' | 'video' | 'rich'
            title            VARCHAR(300) NULL,
            description      VARCHAR(600) NULL,
            image_url        TEXT NULL,                              -- URL immagine remota (UNTRUSTED, mai in DOM)
            image_proxy_path VARCHAR(2048) NULL,                     -- path same-origin firmato del nostro image-proxy (token può essere lungo)
            site_name        VARCHAR(160) NULL,
            domain           VARCHAR(255) NULL,
            provider         VARCHAR(60)  NULL,                      -- 'YouTube' | 'Vimeo' | ...
            author           VARCHAR(200) NULL,
            embed_url        VARCHAR(600) NULL,                      -- src iframe COSTRUITO DA NOI (youtube-nocookie, ...)
            embed_html       MEDIUMTEXT NULL,                        -- HTML oEmbed grezzo del provider (mai renderizzato as-is)
            status           ENUM('ok','blocked','failed') NOT NULL DEFAULT 'ok',
            fetched_at       TIMESTAMP NULL DEFAULT NULL,
            expires_at       TIMESTAMP NULL DEFAULT NULL,
            created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (url_hash),
            KEY idx_lp_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Un post può referenziare una (0 o 1) link-card. Aggiunta guardata (idempotente).
        $exists = $pdo->query("SHOW COLUMNS FROM posts LIKE 'link_preview_url_hash'")->fetch();
        if ($exists === false) {
            $pdo->exec("ALTER TABLE posts
                ADD COLUMN link_preview_url_hash CHAR(64) NULL DEFAULT NULL AFTER body");
        }
    }

    public function down(\PDO $pdo): void
    {
        $col = $pdo->query("SHOW COLUMNS FROM posts LIKE 'link_preview_url_hash'")->fetch();
        if ($col !== false) {
            $pdo->exec("ALTER TABLE posts DROP COLUMN link_preview_url_hash");
        }
        $pdo->exec('DROP TABLE IF EXISTS link_previews');
    }
};
