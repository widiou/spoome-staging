<?php

namespace Spoome\Domain\Opportunities;

use PDO;
use Spoome\Core\Db;

/**
 * Candidature alle Opportunities (`opportunity_applications`). Solo query parametrizzate; named param
 * distinti per occorrenza. Le liste arricchite joinano il profilo controparte (candidato lato org /
 * opportunità+org lato atleta) per evitare N+1 nelle viste.
 */
final class ApplicationRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /**
     * Inserisce una candidatura e incrementa il contatore denormalizzato dell'opportunità nella
     * STESSA transazione (nessun drift sotto crash/concorrenza — modello contatori follow).
     * Il dedupe è pre-verificato dal Service e comunque garantito da UNIQUE(opportunity_id,
     * applicant_profile_id): una duplice candidatura concorrente fa fallire la tx (rollback completo,
     * contatore intatto).
     */
    public function create(int $opportunityId, int $applicantProfileId, ?string $coverMessage): int
    {
        return Db::transaction($this->pdo, function (PDO $pdo) use ($opportunityId, $applicantProfileId, $coverMessage): int {
            $stmt = $pdo->prepare(
                'INSERT INTO opportunity_applications (opportunity_id, applicant_profile_id, cover_message)
                 VALUES (:opp, :applicant, :msg)'
            );
            $stmt->bindValue(':opp', $opportunityId, PDO::PARAM_INT);
            $stmt->bindValue(':applicant', $applicantProfileId, PDO::PARAM_INT);
            $stmt->bindValue(':msg', ($coverMessage ?? '') !== '' ? $coverMessage : null, $coverMessage === null || $coverMessage === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->execute();
            $id = (int) $pdo->lastInsertId();

            (new OpportunityRepository($pdo))->incrementApplications($opportunityId);
            return $id;
        });
    }

    /** Riga grezza (per authz). */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM opportunity_applications WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Candidatura di un atleta a una specifica opportunità (dedupe). */
    public function findByOpportunityAndApplicant(int $opportunityId, int $applicantProfileId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM opportunity_applications
             WHERE opportunity_id = :opp AND applicant_profile_id = :applicant LIMIT 1'
        );
        $stmt->execute(['opp' => $opportunityId, 'applicant' => $applicantProfileId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Registra l'esito (accepted/rejected) SOLO se ancora `submitted` (guard anti-corsa, chiude la
     * finestra TOCTOU: due decisioni concorrenti → la seconda tocca 0 righe).
     * @param string $status 'accepted' | 'rejected' (validato dal Service via whitelist)
     * @return bool true se una riga è stata aggiornata (era submitted); false altrimenti.
     */
    public function respond(int $id, string $status): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE opportunity_applications SET status = :st, responded_at = NOW()
             WHERE id = :id AND status = 'submitted'"
        );
        $stmt->execute(['st' => $status, 'id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Candidature ricevute su un'opportunità (lato ORG). Arricchite col profilo candidato (ap_*).
     * @return array{items:array<int,array<string,mixed>>, total:int}
     */
    public function listForOpportunity(int $opportunityId, int $page = 1, int $perPage = 30): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM opportunity_applications WHERE opportunity_id = :opp');
        $countStmt->execute(['opp' => $opportunityId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.opportunity_id, a.applicant_profile_id, a.cover_message, a.status,
                    a.created_at, a.responded_at,
                    ap.handle AS ap_handle, ap.display_name AS ap_display_name, ap.headline AS ap_headline,
                    ap.verified_at AS ap_verified_at,
                    pt.`key` AS ap_type_key, pt.label AS ap_type_label, pt.is_organization AS ap_is_organization,
                    apam.disk_path AS ap_avatar_path,
                    s.name AS ap_sport_name, s.slug AS ap_sport_slug
             FROM opportunity_applications a
             JOIN profiles ap ON ap.id = a.applicant_profile_id
             JOIN profile_types pt ON pt.id = ap.profile_type_id
             LEFT JOIN media apam ON apam.id = ap.avatar_media_id
             LEFT JOIN sports s ON s.id = ap.sport_id
             WHERE a.opportunity_id = :opp
             ORDER BY a.id DESC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':opp', $opportunityId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /**
     * "Le mie candidature" (lato ATLETA). Arricchite con l'opportunità e l'org pubblicante.
     * @return array{items:array<int,array<string,mixed>>, total:int}
     */
    public function listForApplicant(int $applicantProfileId, int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM opportunity_applications WHERE applicant_profile_id = :applicant');
        $countStmt->execute(['applicant' => $applicantProfileId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.opportunity_id, a.cover_message, a.status, a.created_at, a.responded_at,
                    o.title AS opp_title, o.kind AS opp_kind, o.status AS opp_status, o.deadline AS opp_deadline,
                    op.handle AS org_handle, op.display_name AS org_display_name, op.verified_at AS org_verified_at,
                    opt.`key` AS org_type_key, opt.is_organization AS org_is_organization,
                    oam.disk_path AS org_avatar_path
             FROM opportunity_applications a
             JOIN opportunities o ON o.id = a.opportunity_id
             JOIN profiles op ON op.id = o.org_profile_id
             JOIN profile_types opt ON opt.id = op.profile_type_id
             LEFT JOIN media oam ON oam.id = op.avatar_media_id
             WHERE a.applicant_profile_id = :applicant
             ORDER BY a.id DESC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':applicant', $applicantProfileId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }
}
