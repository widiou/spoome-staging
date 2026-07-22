<?php

namespace Spoome\Domain\Opportunities;

use PDO;
use Spoome\Core\Db;

/**
 * Accesso alle Opportunities (bacheca reclutamento). Solo query parametrizzate; identificatori di
 * ordinamento/filtro SOLO da whitelist server-side (mai input utente). Placeholder named DISTINTI
 * per occorrenza (EMULATE_PREPARES=false → riuso = HY093/500).
 *
 * Il contatore `applications_count` è mantenuto qui (incrementApplications), invocato DENTRO la
 * transazione di candidatura da ApplicationRepository — stesso modello dei contatori follow.
 */
final class OpportunityRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /**
     * SELECT arricchito: opportunità + org pubblicante (op_*) + sport (disciplina). Unica sede della
     * forma "riga arricchita" consumata da Presenter e viste. `op` = organization profile.
     */
    private const SELECT_ENRICHED =
        'SELECT o.id, o.org_profile_id, o.title, o.kind, o.sport_id,
                o.location_region, o.location_city, o.description, o.event_date, o.deadline,
                o.status, o.applications_count, o.created_at, o.closed_at,
                op.handle AS org_handle, op.display_name AS org_display_name, op.verified_at AS org_verified_at,
                pt.`key` AS org_type_key, pt.label AS org_type_label, pt.is_organization AS org_is_organization,
                oam.disk_path AS org_avatar_path,
                s.name AS sport_name, s.slug AS sport_slug, s.category AS sport_category
         FROM opportunities o
         JOIN profiles op ON op.id = o.org_profile_id
         JOIN profile_types pt ON pt.id = op.profile_type_id
         LEFT JOIN media oam ON oam.id = op.avatar_media_id
         LEFT JOIN sports s ON s.id = o.sport_id';

    /**
     * Crea un'opportunità. $data è già sanificato dal Service (whitelist campi).
     * @param array{title:string,kind:string,sport_id:?int,location_region:?string,location_city:?string,description:string,event_date:?string,deadline:?string} $data
     */
    public function create(int $orgProfileId, ?int $createdByUserId, array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO opportunities
                (org_profile_id, created_by_user_id, title, kind, sport_id,
                 location_region, location_city, description, event_date, deadline)
             VALUES
                (:org, :creator, :title, :kind, :sport,
                 :region, :city, :descr, :event_date, :deadline)'
        );
        $stmt->bindValue(':org', $orgProfileId, PDO::PARAM_INT);
        $stmt->bindValue(':creator', $createdByUserId, $createdByUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':title', $data['title']);
        $stmt->bindValue(':kind', $data['kind']);
        $stmt->bindValue(':sport', $data['sport_id'], $data['sport_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':region', $data['location_region'], $data['location_region'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':city', $data['location_city'], $data['location_city'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':descr', $data['description']);
        $stmt->bindValue(':event_date', $data['event_date'], $data['event_date'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':deadline', $data['deadline'], $data['deadline'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();
        return (int) $this->pdo->lastInsertId();
    }

    /** Riga grezza (per authz/ownership): solo colonne della tabella. */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM opportunities WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Riga arricchita (org + sport) per la pagina di dettaglio e le liste. */
    public function findEnrichedById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT_ENRICHED . ' WHERE o.id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Chiude un'opportunità SOLO se ancora aperta (guard idempotente anti-corsa).
     * @return bool true se una riga è stata chiusa ORA; false se già chiusa/inesistente.
     */
    public function close(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE opportunities SET status = 'closed', closed_at = NOW()
             WHERE id = :id AND status = 'open'"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Incrementa il contatore denormalizzato delle candidature. DA CHIAMARE dentro la transazione di
     * inserimento candidatura (ApplicationRepository), sulla stessa connessione → nessun drift.
     */
    public function incrementApplications(int $id): void
    {
        $this->pdo->prepare('UPDATE opportunities SET applications_count = applications_count + 1 WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /**
     * Browse pubblico: opportunità APERTE e non scadute, filtrabili per disciplina (sport) e zona
     * (regione). "Scaduta" è derivata a lettura (deadline < oggi) → niente cron di chiusura.
     * @return array{items:array<int,array<string,mixed>>, total:int}
     */
    public function listPublic(?int $sportId, ?string $region, int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        // Clausole condivise da COUNT e SELECT. Named param distinti (nessun riuso nella stessa query).
        // UTC_DATE() (non CURDATE()): la "scadenza" è confrontata in UTC a prescindere dal fuso di
        // sessione MySQL, coerente col gmdate() UTC di ApplicationService::apply e
        // OpportunityPresenter::state (e con UTC_TIMESTAMP() già usato nel feed).
        $where  = ["o.status = 'open'", '(o.deadline IS NULL OR o.deadline >= UTC_DATE())'];
        $params = [];
        if ($sportId !== null) {
            $where[] = 'o.sport_id = :sport';
            $params[':sport'] = $sportId;
        }
        if ($region !== null && $region !== '') {
            $where[] = 'o.location_region = :region';
            $params[':region'] = $region;
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM opportunities o WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(self::SELECT_ENRICHED . " WHERE $whereSql ORDER BY o.id DESC LIMIT :lim OFFSET :off");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /**
     * "Le mie opportunità" di un'organizzazione (gestione), tutti gli stati, più recenti prima.
     * @return array{items:array<int,array<string,mixed>>, total:int}
     */
    public function listForOrg(int $orgProfileId, int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM opportunities WHERE org_profile_id = :org');
        $countStmt->execute(['org' => $orgProfileId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(self::SELECT_ENRICHED . ' WHERE o.org_profile_id = :org ORDER BY o.id DESC LIMIT :lim OFFSET :off');
        $stmt->bindValue(':org', $orgProfileId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }
}
