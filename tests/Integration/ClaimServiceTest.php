<?php

declare(strict_types=1);

namespace Spoome\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Spoome\Domain\Claims\ClaimService;

/**
 * Test d'integrazione di ClaimService: request (dedup, guard "hai già un profilo", 404, throttle),
 * approve (assegna ownership + owner-row + auto-rifiuto concorrenti + audit + notifica, ricontrolli
 * anti-corsa), reject (audit + notifica).
 *
 * ClaimService è costruito SENZA dipendenze iniettate (`new ClaimService()`): sia i suoi repository
 * di default sia la transazione hardcoded in approve() (`Db::transaction(Db::connection(), ...)`)
 * passano da Spoome\Core\Db::connection(). tests/bootstrap.php reindirizza quel singleton verso
 * questo stesso DB usa-e-getta (SPOOME_TEST_DSN) — MAI verso il DB reale di staging.
 *
 * NOTA (work item #3): approve() fa un ricontrollo "statico" (SELECT prima della UPDATE, senza
 * FOR UPDATE) contro le corse — qui riproduciamo in modo deterministico e single-thread lo stato
 * "qualcun altro ha già ottenuto il profilo nel frattempo" per verificare che il ricontrollo blocchi
 * l'approvazione. NON testiamo qui la vera finestra TOCTOU (due approve() realmente concorrenti tra
 * la SELECT e la UPDATE): richiede concorrenza reale e resta la correzione tracciata separatamente
 * (SELECT ... FOR UPDATE).
 */
final class ClaimServiceTest extends TestCase
{
    private ?PDO $pdo = null;
    private int $handleSeq = 0;
    private int $adminId = 0;

    /** id del profile_type 'atleta' seminato in setUp(). */
    private const TYPE_ATLETA_ID = 1;

    protected function setUp(): void
    {
        $dsn = getenv('SPOOME_TEST_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('SPOOME_TEST_DSN non impostato — test d\'integrazione saltato.');
        }
        $this->pdo = new PDO($dsn, (string) getenv('SPOOME_TEST_USER'), (string) getenv('SPOOME_TEST_PASS'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Fedele a Db::connection(): niente placeholder nominati riusati nella stessa query.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ([
            'notifications', 'admin_audit_log', 'profile_members', 'claim_requests',
            'profiles', 'profile_types', 'sports', 'login_attempts', 'users',
        ] as $t) {
            $this->pdo->exec("DROP TABLE IF EXISTS `$t`");
        }

        $this->pdo->exec("CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('member','moderator','admin') NOT NULL DEFAULT 'member',
            status ENUM('pending','active','suspended') NOT NULL DEFAULT 'pending',
            unread_notifications INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_users_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec('CREATE TABLE sports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            slug VARCHAR(140) NOT NULL,
            UNIQUE KEY uq_sports_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->pdo->exec("CREATE TABLE profile_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            `key` VARCHAR(50) NOT NULL,
            label VARCHAR(120) NOT NULL,
            is_organization TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_ptype_key (`key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Schema ATTUALE (post migrazione 0012): user_id nullable + claim_status.
        $this->pdo->exec("CREATE TABLE profiles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            claim_status ENUM('unclaimed','claimed') NOT NULL DEFAULT 'claimed',
            profile_type_id INT NOT NULL,
            handle VARCHAR(60) NOT NULL,
            display_name VARCHAR(160) NOT NULL,
            sport_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_profiles_handle (handle),
            UNIQUE KEY uq_profiles_user (user_id),
            CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_profiles_type FOREIGN KEY (profile_type_id) REFERENCES profile_types (id) ON DELETE RESTRICT,
            CONSTRAINT fk_profiles_sport FOREIGN KEY (sport_id) REFERENCES sports (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE claim_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            profile_id INT NOT NULL,
            user_id INT NOT NULL,
            message VARCHAR(1000) NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            review_note VARCHAR(500) NULL,
            reviewed_by_user_id INT NULL,
            reviewed_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_claim_profile FOREIGN KEY (profile_id) REFERENCES profiles (id) ON DELETE CASCADE,
            CONSTRAINT fk_claim_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_claim_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE profile_members (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            profile_id INT NOT NULL,
            user_id INT NOT NULL,
            role ENUM('owner','admin','editor') NOT NULL DEFAULT 'owner',
            invited_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_member (profile_id, user_id),
            CONSTRAINT fk_member_profile FOREIGN KEY (profile_id) REFERENCES profiles (id) ON DELETE CASCADE,
            CONSTRAINT fk_member_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE admin_audit_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            admin_user_id INT NOT NULL,
            action VARCHAR(60) NOT NULL,
            target_type VARCHAR(40) NULL,
            target_id BIGINT NULL,
            meta JSON NULL,
            ip VARCHAR(45) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_audit_admin FOREIGN KEY (admin_user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE notifications (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type VARCHAR(40) NOT NULL,
            title VARCHAR(200) NOT NULL,
            body VARCHAR(500) NULL,
            url VARCHAR(255) NULL,
            read_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec('CREATE TABLE login_attempts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(190) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            successful TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_login_identifier (identifier, attempted_at),
            KEY idx_login_ip (ip, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        $this->pdo->exec("INSERT INTO profile_types (`key`, label, is_organization) VALUES ('atleta', 'Atleta', 0)");

        $this->handleSeq = 0;
        $this->adminId = $this->insertUser('admin@demo.spoome.local', 'active', 'admin');
    }

    private function service(): ClaimService
    {
        return new ClaimService();
    }

    private function insertUser(string $email, string $status = 'active', string $role = 'member'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, role, status) VALUES (:e, :h, :r, :s)'
        );
        $stmt->execute(['e' => $email, 'h' => password_hash('irrilevante12', PASSWORD_DEFAULT), 'r' => $role, 's' => $status]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertProfile(?int $userId, string $claimStatus): int
    {
        $this->handleSeq++;
        $stmt = $this->pdo->prepare(
            'INSERT INTO profiles (user_id, claim_status, profile_type_id, handle, display_name)
             VALUES (:uid, :cs, :tid, :handle, :name)'
        );
        $stmt->execute([
            'uid'    => $userId,
            'cs'     => $claimStatus,
            'tid'    => self::TYPE_ATLETA_ID,
            'handle' => 'profilo-test-' . $this->handleSeq,
            'name'   => 'Profilo Test ' . $this->handleSeq,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function claimRequestRow(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM claim_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /* --------------------------------------------------------------------------- request() ---- */

    public function testRequestCreatesPendingClaimForUnclaimedProfile(): void
    {
        $profileId = $this->insertProfile(null, 'unclaimed');
        $userId    = $this->insertUser('claimante@demo.spoome.local');

        $r = $this->service()->request($userId, $profileId, 'vorrei rivendicarlo', '1.1.1.1');

        $this->assertTrue($r->ok);
        $this->assertSame(200, $r->code);
        $row = $this->claimRequestRow((int) $r->data['id']);
        $this->assertNotNull($row);
        $this->assertSame('pending', $row['status']);
        $this->assertSame($profileId, (int) $row['profile_id']);
        $this->assertSame($userId, (int) $row['user_id']);
    }

    public function testRequestFailsWhenProfileNotFound(): void
    {
        $userId = $this->insertUser('nessunprofilo@demo.spoome.local');
        $r = $this->service()->request($userId, 999999, null, '1.1.1.2');
        $this->assertFalse($r->ok);
        $this->assertSame(404, $r->code);
    }

    public function testRequestFailsWhenProfileAlreadyClaimed(): void
    {
        $owner     = $this->insertUser('owner@demo.spoome.local');
        $profileId = $this->insertProfile($owner, 'claimed');
        $claimant  = $this->insertUser('altro@demo.spoome.local');

        $r = $this->service()->request($claimant, $profileId, null, '1.1.1.3');
        $this->assertFalse($r->ok);
        $this->assertSame(422, $r->code);
    }

    public function testRequestGuardsUserWhoAlreadyHasAProfile(): void
    {
        $userId = $this->insertUser('haGiaProfilo@demo.spoome.local');
        $this->insertProfile($userId, 'claimed'); // possiede già un profilo
        $target = $this->insertProfile(null, 'unclaimed');

        $r = $this->service()->request($userId, $target, null, '1.1.1.4');
        $this->assertFalse($r->ok);
        $this->assertSame(422, $r->code);
    }

    public function testRequestDedupsPendingRequestPerUserButAllowsAnotherUser(): void
    {
        $profileId = $this->insertProfile(null, 'unclaimed');
        $userA = $this->insertUser('a@demo.spoome.local');
        $userC = $this->insertUser('c@demo.spoome.local');

        $svc = $this->service();
        $first = $svc->request($userA, $profileId, 'primo', '1.1.1.5');
        $this->assertTrue($first->ok);

        // Stesso utente, stesso profilo, richiesta già pendente: dedup.
        $dup = $svc->request($userA, $profileId, 'secondo tentativo', '1.1.1.5');
        $this->assertFalse($dup->ok);
        $this->assertSame(422, $dup->code);

        // Un utente DIVERSO può comunque richiedere lo stesso profilo ancora libero.
        $other = $svc->request($userC, $profileId, null, '1.1.1.6');
        $this->assertTrue($other->ok, 'il dedup è per (profilo,utente), non blocca altri utenti');
    }

    public function testRequestThrottlesByIpAfterTenAttempts(): void
    {
        $ip = '2.2.2.2';
        $svc = $this->service();
        for ($i = 1; $i <= 10; $i++) {
            $profileId = $this->insertProfile(null, 'unclaimed');
            $userId    = $this->insertUser("claim{$i}@demo.spoome.local");
            $r = $svc->request($userId, $profileId, null, $ip);
            $this->assertTrue($r->ok, "il tentativo $i deve riuscire (sotto soglia)");
        }
        $profileId11 = $this->insertProfile(null, 'unclaimed');
        $userId11    = $this->insertUser('claim11@demo.spoome.local');
        $r11 = $svc->request($userId11, $profileId11, null, $ip);
        $this->assertFalse($r11->ok);
        $this->assertSame(429, $r11->code);
    }

    /* --------------------------------------------------------------------------- approve() ---- */

    public function testApproveAssignsOwnershipCreatesOwnerRowAutoRejectsOtherPendingAndNotifies(): void
    {
        $profileId = $this->insertProfile(null, 'unclaimed');
        $userA = $this->insertUser('vincitore@demo.spoome.local');
        $userB = $this->insertUser('perdente@demo.spoome.local');

        $svc = $this->service();
        $reqA = $svc->request($userA, $profileId, null, '3.3.3.1');
        $reqB = $svc->request($userB, $profileId, null, '3.3.3.2');
        $this->assertTrue($reqA->ok);
        $this->assertTrue($reqB->ok);
        $requestIdA = (int) $reqA->data['id'];
        $requestIdB = (int) $reqB->data['id'];

        $result = $svc->approve($this->adminId, $requestIdA, '3.3.3.3');
        $this->assertTrue($result->ok, 'approve deve riuscire su una richiesta pendente valida');

        // Ownership trasferita + claim_status aggiornato.
        $stmt = $this->pdo->prepare('SELECT user_id, claim_status FROM profiles WHERE id = :id');
        $stmt->execute(['id' => $profileId]);
        $profile = $stmt->fetch();
        $this->assertSame($userA, (int) $profile['user_id']);
        $this->assertSame('claimed', $profile['claim_status']);

        // Owner-row autoritativa in profile_members.
        $stmt = $this->pdo->prepare('SELECT role FROM profile_members WHERE profile_id = :p AND user_id = :u');
        $stmt->execute(['p' => $profileId, 'u' => $userA]);
        $this->assertSame('owner', $stmt->fetchColumn());

        // La richiesta approvata è 'approved', quella concorrente è stata auto-rifiutata.
        $this->assertSame('approved', $this->claimRequestRow($requestIdA)['status']);
        $this->assertSame('rejected', $this->claimRequestRow($requestIdB)['status']);

        // Audit registrato.
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM admin_audit_log WHERE admin_user_id = :a AND action = 'claim.approve' AND target_id = :p"
        );
        $stmt->execute(['a' => $this->adminId, 'p' => $profileId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());

        // Notifica in-app al vincitore + contatore non-letti aggiornato.
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :u AND type = 'claim_approved'");
        $stmt->execute(['u' => $userA]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
        $stmt = $this->pdo->prepare('SELECT unread_notifications FROM users WHERE id = :u');
        $stmt->execute(['u' => $userA]);
        $this->assertSame(1, (int) $stmt->fetchColumn());

        // Osservazione: l'auto-rifiuto del concorrente (rejectOtherPending) è una UPDATE diretta,
        // NON passa da notifyDecision — nessuna notifica arriva all'utente B. Comportamento attuale
        // documentato qui (non un fallimento del test): possibile gap UX da segnalare a prodotto.
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :u');
        $stmt->execute(['u' => $userB]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testApproveFailsWhenRequestNotFound(): void
    {
        $r = $this->service()->approve($this->adminId, 999999, '3.3.3.9');
        $this->assertFalse($r->ok);
        $this->assertSame(404, $r->code);
    }

    public function testApproveFailsWhenRequestAlreadyDecided(): void
    {
        $profileId = $this->insertProfile(null, 'unclaimed');
        $userId = $this->insertUser('decisa@demo.spoome.local');
        $svc = $this->service();
        $req = $svc->request($userId, $profileId, null, '3.3.4.1');
        $requestId = (int) $req->data['id'];

        $first = $svc->approve($this->adminId, $requestId, '3.3.4.2');
        $this->assertTrue($first->ok);

        // La stessa richiesta non è più 'pending': una seconda approvazione deve fallire.
        $second = $svc->approve($this->adminId, $requestId, '3.3.4.3');
        $this->assertFalse($second->ok);
        $this->assertSame(422, $second->code);
    }

    /**
     * Ricontrollo anti-corsa "statico" (non la vera finestra TOCTOU, cfr. nota di classe):
     * tra la creazione della richiesta e l'approvazione, il profilo risulta nel frattempo già
     * assegnato a un altro utente (operazione diretta, fuori dal Service, che simula una
     * corsa già completata). approve() deve accorgersene e rifiutare.
     */
    public function testApproveRecheckFailsWhenProfileGotClaimedInTheMeantime(): void
    {
        $profileId = $this->insertProfile(null, 'unclaimed');
        $userId = $this->insertUser('inritardo@demo.spoome.local');
        $svc = $this->service();
        $req = $svc->request($userId, $profileId, null, '3.3.5.1');
        $requestId = (int) $req->data['id'];

        $otherOwner = $this->insertUser('altropiuveloce@demo.spoome.local');
        $this->pdo->prepare("UPDATE profiles SET user_id = :u, claim_status = 'claimed' WHERE id = :id")
            ->execute(['u' => $otherOwner, 'id' => $profileId]);

        $result = $svc->approve($this->adminId, $requestId, '3.3.5.2');
        $this->assertFalse($result->ok, 'il ricontrollo deve bloccare l\'approvazione se il profilo è già stato preso');
        $this->assertSame(422, $result->code);

        // Nessuna modifica ulteriore: la richiesta resta pending, l'owner resta quello "già preso".
        $this->assertSame('pending', $this->claimRequestRow($requestId)['status']);
        $stmt = $this->pdo->prepare('SELECT user_id FROM profiles WHERE id = :id');
        $stmt->execute(['id' => $profileId]);
        $this->assertSame($otherOwner, (int) $stmt->fetchColumn());
    }

    /**
     * Ricontrollo anti-corsa "statico" sul lato richiedente: tra la richiesta e l'approvazione,
     * l'utente ha nel frattempo ottenuto (o già possedeva) un ALTRO profilo.
     */
    public function testApproveRecheckFailsWhenRequesterAlreadyGotAnotherProfile(): void
    {
        $profileId = $this->insertProfile(null, 'unclaimed');
        $userId = $this->insertUser('doppiaidentita@demo.spoome.local');
        $svc = $this->service();
        $req = $svc->request($userId, $profileId, null, '3.3.6.1');
        $requestId = (int) $req->data['id'];

        // Nel frattempo l'utente ottiene un profilo diverso (es. approvato su un'altra richiesta).
        $this->insertProfile($userId, 'claimed');

        $result = $svc->approve($this->adminId, $requestId, '3.3.6.2');
        $this->assertFalse($result->ok);
        $this->assertSame(422, $result->code);
        $this->assertSame('pending', $this->claimRequestRow($requestId)['status']);
    }

    /* ---------------------------------------------------------------------------- reject() ---- */

    public function testRejectMarksRejectedRecordsAuditAndNotifies(): void
    {
        $profileId = $this->insertProfile(null, 'unclaimed');
        $userId = $this->insertUser('rifiutato@demo.spoome.local');
        $svc = $this->service();
        $req = $svc->request($userId, $profileId, null, '4.4.4.1');
        $requestId = (int) $req->data['id'];

        $result = $svc->reject($this->adminId, $requestId, 'documenti insufficienti', '4.4.4.2');
        $this->assertTrue($result->ok);

        $row = $this->claimRequestRow($requestId);
        $this->assertSame('rejected', $row['status']);
        $this->assertSame('documenti insufficienti', $row['review_note']);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM admin_audit_log WHERE admin_user_id = :a AND action = 'claim.reject' AND target_id = :p"
        );
        $stmt->execute(['a' => $this->adminId, 'p' => $profileId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :u AND type = 'claim_rejected'");
        $stmt->execute(['u' => $userId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());

        // Il profilo resta libero: un rifiuto non tocca la ownership.
        $stmt = $this->pdo->prepare('SELECT user_id, claim_status FROM profiles WHERE id = :id');
        $stmt->execute(['id' => $profileId]);
        $profile = $stmt->fetch();
        $this->assertNull($profile['user_id']);
        $this->assertSame('unclaimed', $profile['claim_status']);
    }

    public function testRejectFailsWhenRequestNotFoundOrNotPending(): void
    {
        $notFound = $this->service()->reject($this->adminId, 999999, null, '4.4.4.9');
        $this->assertFalse($notFound->ok);
        $this->assertSame(404, $notFound->code);

        $profileId = $this->insertProfile(null, 'unclaimed');
        $userId = $this->insertUser('giadeciso@demo.spoome.local');
        $svc = $this->service();
        $req = $svc->request($userId, $profileId, null, '4.4.5.1');
        $requestId = (int) $req->data['id'];

        $first = $svc->reject($this->adminId, $requestId, null, '4.4.5.2');
        $this->assertTrue($first->ok);

        $second = $svc->reject($this->adminId, $requestId, null, '4.4.5.3');
        $this->assertFalse($second->ok);
        $this->assertSame(422, $second->code);
    }
}
