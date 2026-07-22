<?php

namespace Spoome\Domain\Users;

use PDO;
use Spoome\Core\Db;
use Spoome\Core\Pagination;

/**
 * Accesso ai dati della tabella `users`. Solo query parametrizzate.
 */
final class UserRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    public function findById(int $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? User::fromRow($row) : null;
    }

    public function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row ? User::fromRow($row) : null;
    }

    public function emailExists(string $email): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        return (bool) $stmt->fetchColumn();
    }

    /** Crea l'utente (status 'pending') e ne ritorna l'id. */
    public function create(string $email, string $passwordHash): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (email, password_hash, role, status)
             VALUES (:email, :hash, 'member', 'pending')"
        );
        $stmt->execute(['email' => $email, 'hash' => $passwordHash]);
        return (int) $this->pdo->lastInsertId();
    }

    public function markVerifiedAndActive(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['id' => $userId]);
    }

    /**
     * Aggiorna la password E incrementa `session_epoch` nella STESSA UPDATE (atomico, single-statement):
     * ogni cambio password — reset via token o cambio volontario — invalida le sessioni web emesse prima
     * (CurrentUser confronta l'epoch salvato in sessione con questo). Coprire il choke-point qui garantisce
     * che nessun percorso di modifica password possa dimenticare l'invalidazione.
     *
     * Richiede la colonna `session_epoch` (migrazione 0032): a differenza del read-path, questa
     * scrittura NON è fail-safe a colonna assente. La 0032 va applicata prima del deploy del codice.
     */
    public function updatePassword(int $userId, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET password_hash = :hash, session_epoch = session_epoch + 1 WHERE id = :id'
        );
        $stmt->execute(['hash' => $passwordHash, 'id' => $userId]);
    }

    public function recordLogin(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }

    /* ------------------------------------------------------------ ADMIN ---- */

    /**
     * Elenco paginato per l'admin, con handle/nome del profilo collegato (LEFT JOIN: può mancare).
     * @param array{q?:string,status?:string,role?:string} $filters
     * @return array{items:array<int,array<string,mixed>>, total:int}
     */
    public function paginate(array $filters, int $page = 1, int $perPage = 25): array
    {
        [$where, $bind] = $this->buildFilters($filters);
        $offset = Pagination::of($page, $perPage)->offset();

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM users u {$where}");
        $countStmt->execute($bind);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT u.id, u.email, u.role, u.status, u.email_verified_at, u.last_login_at, u.created_at,
                       p.handle AS profile_handle, p.display_name AS profile_name
                FROM users u
                LEFT JOIN profiles p ON p.user_id = u.id
                {$where}
                ORDER BY u.id DESC
                LIMIT :lim OFFSET :off";
        $stmt = $this->pdo->prepare($sql);
        foreach ($bind as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /**
     * @param array{q?:string,status?:string,role?:string} $filters
     * @return array{0:string,1:array<string,mixed>} clausola WHERE + bind
     */
    private function buildFilters(array $filters): array
    {
        $clauses = [];
        $bind = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $clauses[] = 'u.email LIKE :q';
            $bind['q'] = '%' . $q . '%';
        }
        if (in_array($filters['status'] ?? '', ['pending', 'active', 'suspended'], true)) {
            $clauses[] = 'u.status = :status';
            $bind['status'] = $filters['status'];
        }
        if (in_array($filters['role'] ?? '', ['member', 'moderator', 'admin'], true)) {
            $clauses[] = 'u.role = :role';
            $bind['role'] = $filters['role'];
        }
        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
        return [$where, $bind];
    }

    /** Riga utente + profilo collegato per la scheda di dettaglio admin. */
    public function findDetailRow(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            // Il profilo mostrato è il PERSONALE (is_organization = 0, il più vecchio) per allinearsi a
            // ciò su cui agisce la verifica-profilo utente (findPersonalByUserId, deterministico). Le
            // pagine-org di un utente multi-profilo si verificano dall'area /admin/profili, non da qui.
            'SELECT u.*, p.id AS profile_id, p.handle AS profile_handle, p.display_name AS profile_name,
                    p.visibility AS profile_visibility, p.verified_at AS profile_verified_at
             FROM users u
             LEFT JOIN profiles p ON p.id = (
                 SELECT p2.id FROM profiles p2
                 JOIN profile_types pt2 ON pt2.id = p2.profile_type_id
                 WHERE p2.user_id = u.id AND pt2.is_organization = 0
                 ORDER BY p2.id ASC LIMIT 1
             )
             WHERE u.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Numero di admin attivi (per impedire di rimuovere l'ultimo admin). */
    public function activeAdminCount(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'"
        )->fetchColumn();
    }

    public function updateStatus(int $id, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET status = :s WHERE id = :id');
        $stmt->execute(['s' => $status, 'id' => $id]);
    }

    public function updateRole(int $id, string $role): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET role = :r WHERE id = :id');
        $stmt->execute(['r' => $role, 'id' => $id]);
    }
}
