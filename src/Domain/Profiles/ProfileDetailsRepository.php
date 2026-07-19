<?php

namespace Spoome\Domain\Profiles;

use PDO;
use Spoome\Core\Db;

/**
 * Sotto-entità del profilo: esperienze, palmarès (achievements), link.
 * Le cancellazioni includono sempre profile_id nella WHERE (ownership a livello SQL, difesa in profondità).
 */
final class ProfileDetailsRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /* ------------------------------------------------------- ESPERIENZE ---- */

    public function experiences(int $profileId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM profile_experiences WHERE profile_id = :p
             ORDER BY is_current DESC, COALESCE(start_year, 0) DESC, id DESC'
        );
        $stmt->execute(['p' => $profileId]);
        return $stmt->fetchAll();
    }

    public function addExperience(int $profileId, array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO profile_experiences (profile_id, org_name, role, location, start_year, end_year, is_current, description)
             VALUES (:p, :org, :role, :loc, :sy, :ey, :cur, :desc)'
        );
        $stmt->execute([
            'p'    => $profileId,
            'org'  => $d['org_name'],
            'role' => $d['role'],
            'loc'  => $d['location'],
            'sy'   => $d['start_year'],
            'ey'   => $d['end_year'],
            'cur'  => $d['is_current'],
            'desc' => $d['description'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return bool true se la riga esiste ed è del profilo (ownership), false → 404 a monte. */
    public function updateExperience(int $id, int $profileId, array $d): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE profile_experiences SET org_name = :org, role = :role, location = :loc,
                start_year = :sy, end_year = :ey, is_current = :cur, description = :desc
             WHERE id = :id AND profile_id = :p'
        );
        $stmt->execute([
            'org'  => $d['org_name'],
            'role' => $d['role'],
            'loc'  => $d['location'],
            'sy'   => $d['start_year'],
            'ey'   => $d['end_year'],
            'cur'  => $d['is_current'],
            'desc' => $d['description'],
            'id'   => $id,
            'p'    => $profileId,
        ]);
        return $this->confirmOwnership('profile_experiences', $stmt, $id, $profileId);
    }

    public function deleteExperience(int $id, int $profileId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM profile_experiences WHERE id = :id AND profile_id = :p');
        $stmt->execute(['id' => $id, 'p' => $profileId]);
    }

    /* --------------------------------------------------------- PALMARÈS ---- */

    public function achievements(int $profileId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM profile_achievements WHERE profile_id = :p ORDER BY COALESCE(year, 0) DESC, id DESC'
        );
        $stmt->execute(['p' => $profileId]);
        return $stmt->fetchAll();
    }

    public function addAchievement(int $profileId, array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO profile_achievements (profile_id, title, year, description) VALUES (:p, :t, :y, :desc)'
        );
        $stmt->execute([
            'p'    => $profileId,
            't'    => $d['title'],
            'y'    => $d['year'],
            'desc' => $d['description'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return bool true se la riga esiste ed è del profilo (ownership), false → 404 a monte. */
    public function updateAchievement(int $id, int $profileId, array $d): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE profile_achievements SET title = :t, year = :y, description = :desc
             WHERE id = :id AND profile_id = :p'
        );
        $stmt->execute([
            't' => $d['title'], 'y' => $d['year'], 'desc' => $d['description'],
            'id' => $id, 'p' => $profileId,
        ]);
        return $this->confirmOwnership('profile_achievements', $stmt, $id, $profileId);
    }

    public function deleteAchievement(int $id, int $profileId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM profile_achievements WHERE id = :id AND profile_id = :p');
        $stmt->execute(['id' => $id, 'p' => $profileId]);
    }

    /* ------------------------------------------------------------- LINK ---- */

    public function links(int $profileId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM profile_links WHERE profile_id = :p ORDER BY sort ASC, id ASC');
        $stmt->execute(['p' => $profileId]);
        return $stmt->fetchAll();
    }

    public function addLink(int $profileId, array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO profile_links (profile_id, kind, label, url) VALUES (:p, :k, :l, :u)'
        );
        $stmt->execute([
            'p' => $profileId,
            'k' => $d['kind'],
            'l' => $d['label'],
            'u' => $d['url'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return bool true se la riga esiste ed è del profilo (ownership), false → 404 a monte. */
    public function updateLink(int $id, int $profileId, array $d): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE profile_links SET kind = :k, label = :l, url = :u WHERE id = :id AND profile_id = :p'
        );
        $stmt->execute([
            'k' => $d['kind'], 'l' => $d['label'], 'u' => $d['url'],
            'id' => $id, 'p' => $profileId,
        ]);
        return $this->confirmOwnership('profile_links', $stmt, $id, $profileId);
    }

    public function deleteLink(int $id, int $profileId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM profile_links WHERE id = :id AND profile_id = :p');
        $stmt->execute(['id' => $id, 'p' => $profileId]);
    }

    /**
     * Conferma che la riga (id, profile_id) esista → ownership. L'UPDATE già filtra per profile_id
     * (difesa in profondità, nessuna mutazione cross-owner); qui distinguiamo "non tua/inesistente" (404)
     * da "tua ma valori identici" (rowCount 0 su MySQL non è assenza). $table è sempre un literal interno.
     */
    private function confirmOwnership(string $table, \PDOStatement $stmt, int $id, int $profileId): bool
    {
        if ($stmt->rowCount() > 0) {
            return true;
        }
        $check = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE id = :id AND profile_id = :p");
        $check->execute(['id' => $id, 'p' => $profileId]);
        return (bool) $check->fetchColumn();
    }
}
