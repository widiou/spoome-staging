<?php
/**
 * Uniforma il charset/collation di tutte le tabelle a utf8mb4 / utf8mb4_unicode_ci.
 * Risolve alla radice gli errori "1267 Illegal mix of collations" sui JOIN tra tabelle
 * (athletes era unicode_ci, le altre usavano il default utf8mb4_0900_ai_ci).
 * Idempotente: converte solo le tabelle non già allineate. Conversione utf8mb4->utf8mb4:
 * i dati sono preservati (stesso encoding, cambia solo la collation).
 */
return new class {
    public function up(\PDO $pdo): void
    {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();

        // rss_cache esclusa: UNIQUE(title) con titoli RSS che collidono sotto unicode_ci
        // (errore 1062). È una cache rigenerabile e non viene joinata con altre tabelle.
        $tables = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = " . $pdo->quote((string) $db) . "
               AND TABLE_TYPE = 'BASE TABLE'
               AND TABLE_NAME <> 'rss_cache'
               AND (TABLE_COLLATION IS NULL OR TABLE_COLLATION <> 'utf8mb4_unicode_ci')"
        )->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            $pdo->exec("ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
    }

    public function down(\PDO $pdo): void
    {
        // Lo stato misto originale non è ripristinabile in modo significativo: no-op.
    }
};
