<?php

/**
 * Migrazione 0026 — News di settore (feed RSS di federazioni/organismi iniettate nel feed).
 *
 * news_sources: fonti RSS/Atom, ognuna ATTRIBUITA a una pagina organizzazione (federazione) —
 *   così le news alimentano la pagina e le federazioni diventano motori di contenuto.
 * news_items: articoli deduplicati per url_hash, taggati per sport, per il ranking d'interesse.
 *
 * Applicata via q.py e registrata in `migrations`. Fetch sicuro via SafeHttpFetcher (SSRF-guard).
 */
return [
    'up' => [
        // org_profile_id NULLABLE: fonti terze (non attribuite a una pagina federazione). refresh_minutes:
        // intervallo di aggiornamento per-fonte. Gli sport di match stanno in news_source_sports (multi-sport).
        "CREATE TABLE IF NOT EXISTS news_sources (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_profile_id INT NULL,
            name VARCHAR(120) NOT NULL,
            feed_url VARCHAR(500) NOT NULL,
            sport_id INT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            refresh_minutes INT NOT NULL DEFAULT 60,
            last_fetched_at TIMESTAMP NULL,
            etag VARCHAR(255) NULL,
            last_modified VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_news_source_feed (feed_url),
            KEY idx_news_source_active (active),
            CONSTRAINT fk_news_source_org FOREIGN KEY (org_profile_id) REFERENCES profiles (id) ON DELETE CASCADE,
            CONSTRAINT fk_news_source_sport FOREIGN KEY (sport_id) REFERENCES sports (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS news_source_sports (
            source_id INT NOT NULL,
            sport_id INT NOT NULL,
            PRIMARY KEY (source_id, sport_id),
            KEY idx_nss_sport (sport_id),
            CONSTRAINT fk_nss_source FOREIGN KEY (source_id) REFERENCES news_sources (id) ON DELETE CASCADE,
            CONSTRAINT fk_nss_sport FOREIGN KEY (sport_id) REFERENCES sports (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS news_items (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            source_id INT NOT NULL,
            url_hash CHAR(64) NOT NULL,
            title VARCHAR(300) NOT NULL,
            url VARCHAR(700) NOT NULL,
            summary VARCHAR(600) NULL,
            image_url VARCHAR(700) NULL,
            sport_id INT NULL,
            published_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_news_item_url (url_hash),
            KEY idx_news_item_sport_pub (sport_id, published_at),
            KEY idx_news_item_pub (published_at),
            CONSTRAINT fk_news_item_source FOREIGN KEY (source_id) REFERENCES news_sources (id) ON DELETE CASCADE,
            CONSTRAINT fk_news_item_sport FOREIGN KEY (sport_id) REFERENCES sports (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        "DROP TABLE IF EXISTS news_items",
        "DROP TABLE IF EXISTS news_source_sports",
        "DROP TABLE IF EXISTS news_sources",
    ],
];
