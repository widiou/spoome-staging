<?php
/**
 * Horizon 0 · perf discovery — indici per la directory pubblica (`ProfileRepository::listPublic`).
 *
 * Prima: `WHERE visibility='public' [AND profile_type_id=…] ORDER BY created_at DESC` andava in
 * full-scan + filesort (nessun indice copriva filtro+ordinamento); la landing la chiama 6 volte
 * (una per tipo). Dopo: due indici che coprono filtro E ordinamento → "Backward index scan; Using
 * index", zero filesort. Idempotente (controlla information_schema prima di creare/eliminare).
 */
return new class {
    private function indexExists(\PDO $pdo, string $name): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profiles' AND INDEX_NAME = :n"
        );
        $stmt->execute(['n' => $name]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function up(\PDO $pdo): void
    {
        // Lista generale: visibility + ordinamento per data.
        if (!$this->indexExists($pdo, 'idx_profiles_vis_created')) {
            $pdo->exec('CREATE INDEX idx_profiles_vis_created ON profiles (visibility, created_at)');
        }
        // By-type: rimpiazza il vecchio (profile_type_id, visibility) col composito che include created_at.
        if (!$this->indexExists($pdo, 'idx_profiles_type_vis_created')) {
            $pdo->exec('CREATE INDEX idx_profiles_type_vis_created ON profiles (profile_type_id, visibility, created_at)');
        }
        if ($this->indexExists($pdo, 'idx_profiles_type_vis')) {
            $pdo->exec('DROP INDEX idx_profiles_type_vis ON profiles');
        }
    }

    public function down(\PDO $pdo): void
    {
        if ($this->indexExists($pdo, 'idx_profiles_vis_created')) {
            $pdo->exec('DROP INDEX idx_profiles_vis_created ON profiles');
        }
        if ($this->indexExists($pdo, 'idx_profiles_type_vis_created')) {
            $pdo->exec('DROP INDEX idx_profiles_type_vis_created ON profiles');
        }
        if (!$this->indexExists($pdo, 'idx_profiles_type_vis')) {
            $pdo->exec('CREATE INDEX idx_profiles_type_vis ON profiles (profile_type_id, visibility)');
        }
    }
};
