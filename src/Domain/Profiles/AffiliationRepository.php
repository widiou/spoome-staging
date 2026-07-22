<?php

namespace Spoome\Domain\Profiles;

use PDO;
use Spoome\Core\Db;

/**
 * Accesso ai dati di `profile_affiliations`: la relazione strutturata atleta↔organizzazione
 * (Roster/Membri dell'org · "Militanza / Carriera" dell'atleta). Conferma bilaterale con stato
 * pending/confirmed. Difesa a livello dati: ogni mutazione è per id; l'authz "chi può confermare"
 * vive nel Service (parte destinataria). Tutte le query sono parametrizzate; placeholder distinti
 * dove un id è riusato (PDO EMULATE_PREPARES=false → named non riutilizzabili).
 */
final class AffiliationRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /** Colonne del profilo "controparte" (cp) per l'idratazione delle card roster/militanza. */
    private const CP_COLS =
        'cp.id AS cp_id, cp.handle AS cp_handle, cp.display_name AS cp_display_name, cp.headline AS cp_headline,
         cp.verified_at AS cp_verified_at, cp.location_city AS cp_location_city, cp.location_region AS cp_location_region,
         cpt.`key` AS cp_type_key, cpt.label AS cp_type_label, cpt.is_organization AS cp_is_organization,
         cpm.disk_path AS cp_avatar_path';

    private const AFF_COLS =
        'a.id, a.member_profile_id, a.org_profile_id, a.role, a.team, a.jersey, a.start_year, a.end_year,
         a.is_current, a.status, a.requested_by_profile_id, a.created_at, a.confirmed_at';

    /** Riga grezza per id (per l'authz nel Service). */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM profile_affiliations WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Riga esistente per la coppia (member, org) — UNIQUE. */
    public function findPair(int $memberPid, int $orgPid): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM profile_affiliations WHERE member_profile_id = :mem AND org_profile_id = :org LIMIT 1'
        );
        $stmt->execute(['mem' => $memberPid, 'org' => $orgPid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array{role:?string,team:?string,jersey:?string,start_year:?int,end_year:?int,is_current:int} $d */
    public function insertPending(int $memberPid, int $orgPid, int $requestedByPid, array $d): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO profile_affiliations
                (member_profile_id, org_profile_id, role, team, jersey, start_year, end_year, is_current, status, requested_by_profile_id)
             VALUES (:mem, :org, :role, :team, :jersey, :sy, :ey, :cur, 'pending', :req)"
        );
        $stmt->bindValue(':mem', $memberPid, PDO::PARAM_INT);
        $stmt->bindValue(':org', $orgPid, PDO::PARAM_INT);
        $stmt->bindValue(':role', $d['role'], $d['role'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':team', $d['team'], $d['team'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':jersey', $d['jersey'], $d['jersey'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':sy', $d['start_year'], $d['start_year'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':ey', $d['end_year'], $d['end_year'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':cur', $d['is_current'], PDO::PARAM_INT);
        $stmt->bindValue(':req', $requestedByPid, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $this->pdo->lastInsertId();
    }

    /** Conferma una riga pending → confirmed. True se ha effettivamente aggiornato (era pending). */
    public function confirm(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE profile_affiliations SET status = 'confirmed', confirmed_at = NOW()
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() === 1;
    }

    /** Rimuove una riga (reject di una pending o rimozione di una confermata). */
    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM profile_affiliations WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Roster/Membri di un'organizzazione: affiliazioni CONFERMATE, controparte = il membro.
     * Rosa attuale prima (is_current DESC), poi per nome. @return array<int,array>
     */
    public function rosterOf(int $orgPid): array
    {
        $sql = 'SELECT ' . self::AFF_COLS . ', ' . self::CP_COLS . '
                FROM profile_affiliations a
                JOIN profiles cp ON cp.id = a.member_profile_id
                JOIN profile_types cpt ON cpt.id = cp.profile_type_id
                LEFT JOIN media cpm ON cpm.id = cp.avatar_media_id
                WHERE a.org_profile_id = :org AND a.status = \'confirmed\'
                ORDER BY a.is_current DESC, cp.display_name ASC, a.id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['org' => $orgPid]);
        return $stmt->fetchAll();
    }

    /**
     * "Militanza / Carriera" di un membro: affiliazioni CONFERMATE, controparte = l'organizzazione.
     * Attuali prima, poi per anno d'inizio desc. @return array<int,array>
     */
    public function affiliationsOf(int $memberPid): array
    {
        $sql = 'SELECT ' . self::AFF_COLS . ', ' . self::CP_COLS . '
                FROM profile_affiliations a
                JOIN profiles cp ON cp.id = a.org_profile_id
                JOIN profile_types cpt ON cpt.id = cp.profile_type_id
                LEFT JOIN media cpm ON cpm.id = cp.avatar_media_id
                WHERE a.member_profile_id = :mem AND a.status = \'confirmed\'
                ORDER BY a.is_current DESC, a.start_year DESC, a.id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['mem' => $memberPid]);
        return $stmt->fetchAll();
    }

    /**
     * Richieste di affiliazione IN INGRESSO da confermare per $pid: pending in cui $pid è una parte
     * ma NON è il richiedente. La controparte mostrata è il richiedente. Placeholder distinti (id riusato).
     * @return array<int,array>
     */
    public function pendingFor(int $pid): array
    {
        $sql = 'SELECT ' . self::AFF_COLS . ', ' . self::CP_COLS . '
                FROM profile_affiliations a
                JOIN profiles cp ON cp.id = a.requested_by_profile_id
                JOIN profile_types cpt ON cpt.id = cp.profile_type_id
                LEFT JOIN media cpm ON cpm.id = cp.avatar_media_id
                WHERE a.status = \'pending\'
                  AND a.requested_by_profile_id <> :me1
                  AND (a.member_profile_id = :me2 OR a.org_profile_id = :me3)
                ORDER BY a.created_at DESC, a.id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['me1' => $pid, 'me2' => $pid, 'me3' => $pid]);
        return $stmt->fetchAll();
    }

    /**
     * Richieste di affiliazione IN USCITA di $pid: pending create DA questo profilo (è il richiedente),
     * ancora in attesa di conferma della controparte. La controparte mostrata è l'ALTRA parte (il target,
     * cioè quella diversa dal richiedente). Copre entrambe le direzioni: atleta→società, società→federazione
     * e org→atleta (aggiunta al roster). Placeholder distinti (id riusato). @return array<int,array>
     */
    public function pendingOutgoingFor(int $pid): array
    {
        $sql = 'SELECT ' . self::AFF_COLS . ', ' . self::CP_COLS . '
                FROM profile_affiliations a
                JOIN profiles cp ON cp.id = CASE WHEN a.member_profile_id = :me1 THEN a.org_profile_id ELSE a.member_profile_id END
                JOIN profile_types cpt ON cpt.id = cp.profile_type_id
                LEFT JOIN media cpm ON cpm.id = cp.avatar_media_id
                WHERE a.status = \'pending\'
                  AND a.requested_by_profile_id = :me2
                ORDER BY a.created_at DESC, a.id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['me1' => $pid, 'me2' => $pid]);
        return $stmt->fetchAll();
    }

    /**
     * ANCORAGGIO del badge "verificato dalla società" (M3 Verification-da-club). Ritorna le organizzazioni
     * che ANCORANO la verifica di $memberPid: affiliazioni CONFERMATE dove $memberPid è il membro e la
     * controparte è un'organizzazione **essa stessa verificata** (`o.verified_at IS NOT NULL`).
     *
     * Il badge è DERIVATO da questa condizione — nessun flag denormalizzato da tenere in sync: la revoca è
     * automatica e atomica (rimozione dell'affiliazione, oppure l'admin che annulla la verifica dell'org →
     * alla lettura successiva l'ancora sparisce, zero finestra di stato stantìo / zero race di revoca).
     *
     * SPORT-GENERICO: l'ancora è "una qualsiasi org verificata" (società/associazione/federazione) — nessuna
     * federazione hardcodata. La verifica dell'org è concessa fuori banda dallo staff (step-up + audit) contro
     * evidenza reale (tesseramento/affiliazione ufficiale): è quella la "fonte reale". Un profilo-pagina creato
     * ad-hoc nasce NON verificato → non ancora nulla (chiude lo spoof self-attest della finta società).
     *
     * Un solo placeholder (nessun id riusato). Correnti prima, poi per conferma più recente.
     * @return array<int,array{org_id:int,org_handle:string,org_name:string,is_organization:int,is_current:int,confirmed_at:?string}>
     */
    public function verifyingOrgsOf(int $memberPid): array
    {
        $sql = "SELECT o.id AS org_id, o.handle AS org_handle, o.display_name AS org_name,
                       ot.is_organization AS is_organization, a.is_current, a.confirmed_at
                FROM profile_affiliations a
                JOIN profiles o ON o.id = a.org_profile_id
                JOIN profile_types ot ON ot.id = o.profile_type_id
                WHERE a.member_profile_id = :mem
                  AND a.status = 'confirmed'
                  AND ot.is_organization = 1
                  AND o.verified_at IS NOT NULL
                ORDER BY a.is_current DESC, a.confirmed_at DESC, a.id DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['mem' => $memberPid]);
        return $stmt->fetchAll();
    }

    /**
     * Singola richiesta IN USCITA arricchita per id, ristretta a quelle create da $pid (scoping a livello
     * dati). Alimenta il frammento async (append della card outgoing dopo l'invio). Placeholder distinti.
     */
    public function outgoingById(int $affId, int $pid): ?array
    {
        $sql = 'SELECT ' . self::AFF_COLS . ', ' . self::CP_COLS . '
                FROM profile_affiliations a
                JOIN profiles cp ON cp.id = CASE WHEN a.member_profile_id = :me1 THEN a.org_profile_id ELSE a.member_profile_id END
                JOIN profile_types cpt ON cpt.id = cp.profile_type_id
                LEFT JOIN media cpm ON cpm.id = cp.avatar_media_id
                WHERE a.id = :id AND a.requested_by_profile_id = :me2
                LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $affId, 'me1' => $pid, 'me2' => $pid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
