<?php

namespace Spoome\Domain\Profiles;

use PDO;
use Spoome\Core\Db;

/**
 * Competenze del profilo (free-text) + endorsement delle connessioni.
 * Ownership a livello SQL: tutte le mutazioni proprietarie filtrano per profile_id (difesa in profondità).
 * Contatore denormalizzato `profile_skills.endorsements_count` mantenuto qui, aggiornato SOLO quando
 * la riga di endorsement è effettivamente creata/rimossa (idempotenza garantita, niente drift).
 */
final class SkillRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /** @return array<int,array<string,mixed>> competenze del profilo, in ordine di posizione. */
    public function forProfile(int $profileId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, profile_id, label, position, endorsements_count
             FROM profile_skills WHERE profile_id = :p ORDER BY position ASC, id ASC'
        );
        $stmt->execute(['p' => $profileId]);
        return $stmt->fetchAll();
    }

    public function countForProfile(int $profileId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM profile_skills WHERE profile_id = :p');
        $stmt->execute(['p' => $profileId]);
        return (int) $stmt->fetchColumn();
    }

    public function add(int $profileId, string $label, int $position): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO profile_skills (profile_id, label, position) VALUES (:p, :l, :pos)'
        );
        $stmt->execute(['p' => $profileId, 'l' => $label, 'pos' => $position]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Aggiorna la posizione degli id posseduti (WHERE profile_id sempre presente). */
    public function reorder(int $profileId, array $orderedIds): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE profile_skills SET position = :pos WHERE id = :id AND profile_id = :p'
        );
        $pos = 0;
        foreach ($orderedIds as $id) {
            $stmt->execute(['pos' => $pos, 'id' => (int) $id, 'p' => $profileId]);
            $pos++;
        }
    }

    public function delete(int $id, int $profileId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM profile_skills WHERE id = :id AND profile_id = :p');
        $stmt->execute(['id' => $id, 'p' => $profileId]);
    }

    /** True se esiste già una competenza con la stessa label (normalizzata) per il profilo. */
    public function labelExists(int $profileId, string $label): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM profile_skills WHERE profile_id = :p AND label = :l LIMIT 1'
        );
        $stmt->execute(['p' => $profileId, 'l' => $label]);
        return (bool) $stmt->fetchColumn();
    }

    /** Profilo proprietario della competenza (per authz endorse), o null se la skill non esiste. */
    public function findOwnerProfileId(int $skillId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT profile_id FROM profile_skills WHERE id = :id');
        $stmt->execute(['id' => $skillId]);
        $val = $stmt->fetchColumn();
        return $val === false ? null : (int) $val;
    }

    public function findLabel(int $skillId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT label FROM profile_skills WHERE id = :id');
        $stmt->execute(['id' => $skillId]);
        $val = $stmt->fetchColumn();
        return $val === false ? null : (string) $val;
    }

    public function endorsementCount(int $skillId): int
    {
        $stmt = $this->pdo->prepare('SELECT endorsements_count FROM profile_skills WHERE id = :id');
        $stmt->execute(['id' => $skillId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Aggiunge un endorsement (INSERT IGNORE, anti-doppione a livello DB) e incrementa il contatore
     * SOLO se la riga è stata effettivamente creata. @return bool true se creato (no-op se già presente).
     */
    public function endorse(int $skillId, int $endorserProfileId): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO skill_endorsements (skill_id, endorser_profile_id) VALUES (:s, :e)'
        );
        $stmt->execute(['s' => $skillId, 'e' => $endorserProfileId]);
        if ($stmt->rowCount() !== 1) {
            return false; // già presente → idempotente, contatore invariato
        }
        $this->pdo->prepare('UPDATE profile_skills SET endorsements_count = endorsements_count + 1 WHERE id = :s')
            ->execute(['s' => $skillId]);
        return true;
    }

    /**
     * Rimuove un endorsement e decrementa il contatore SOLO se la riga esisteva.
     * @return bool true se rimosso.
     */
    public function removeEndorsement(int $skillId, int $endorserProfileId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM skill_endorsements WHERE skill_id = :s AND endorser_profile_id = :e'
        );
        $stmt->execute(['s' => $skillId, 'e' => $endorserProfileId]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $this->pdo->prepare('UPDATE profile_skills SET endorsements_count = GREATEST(0, endorsements_count - 1) WHERE id = :s')
            ->execute(['s' => $skillId]);
        return true;
    }

    /**
     * Id delle competenze di un profilo che un dato endorser ha già confermato (per lo stato dei bottoni).
     * @return int[]
     */
    public function endorsedSkillIdsBy(int $endorserProfileId, int $ownerProfileId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT se.skill_id
             FROM skill_endorsements se
             JOIN profile_skills ps ON ps.id = se.skill_id
             WHERE se.endorser_profile_id = :e AND ps.profile_id = :p'
        );
        $stmt->execute(['e' => $endorserProfileId, 'p' => $ownerProfileId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Endorser recenti per ciascuna competenza del profilo (per la riga "Confermata da …" + avatar).
     * @return array<int,array<int,array{display_name:string,avatar_path:?string}>> skill_id => elenco recenti
     */
    public function recentEndorsers(int $ownerProfileId, int $perSkill = 3): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT se.skill_id, pr.display_name, am.disk_path AS avatar_path
             FROM skill_endorsements se
             JOIN profile_skills ps ON ps.id = se.skill_id
             JOIN profiles pr       ON pr.id = se.endorser_profile_id
             LEFT JOIN media am      ON am.id = pr.avatar_media_id
             WHERE ps.profile_id = :p
             ORDER BY se.skill_id ASC, se.created_at DESC, se.id DESC'
        );
        $stmt->execute(['p' => $ownerProfileId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $sid = (int) $row['skill_id'];
            if (count($out[$sid] ?? []) >= $perSkill) {
                continue;
            }
            $out[$sid][] = [
                'display_name' => (string) $row['display_name'],
                'avatar_path'  => $row['avatar_path'] !== null ? (string) $row['avatar_path'] : null,
            ];
        }
        return $out;
    }
}
