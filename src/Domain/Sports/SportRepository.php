<?php

namespace Spoome\Domain\Sports;

use PDO;
use Spoome\Core\Db;

/**
 * Accesso in sola lettura alla tassonomia `sports` (dati di riferimento, non contenuto utente).
 * Usato dai filtri della directory pubblica dei profili.
 */
final class SportRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /** Tutti gli sport attivi: [id, name, slug, category]. Dati di riferimento → in cache. */
    public function all(): array
    {
        return \Spoome\Core\Cache::remember('sports_active', 600, function (): array {
            return $this->pdo->query(
                'SELECT id, name, slug, category FROM sports WHERE active = 1 ORDER BY category, name'
            )->fetchAll();
        });
    }

    /** id dello sport dato lo slug (per risolvere il filtro dell'URL). Null se assente. Derivato dalla cache. */
    public function idBySlug(string $slug): ?int
    {
        foreach ($this->all() as $s) {
            if ((string) $s['slug'] === $slug) {
                return (int) $s['id'];
            }
        }
        return null;
    }
}
