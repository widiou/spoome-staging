<?php
declare(strict_types=1);

namespace Spoome\Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Spoome\Domain\Claims\ClaimRepository;
use Spoome\Domain\Claims\ClaimService;

/**
 * Work item #3 (R1): prova che la finestra TOCTOU in ClaimService::approve() è CHIUSA dopo
 * l'introduzione dei lock `SELECT ... FOR UPDATE` (profilo → richiesta → utente) da parte di
 * Matteo. tests/Integration/ClaimServiceTest.php copriva solo il ricontrollo "statico" (senza
 * lock, dati già mutati da un'operazione diretta prima della chiamata) — qui verifichiamo la
 * serializzazione VERA e il ricontrollo SOTTO LOCK che vince anche quando il lock è già stato
 * rilasciato.
 *
 * Tre scenari, tre livelli di "quanto serve concorrenza reale":
 *
 * 1) Serializzazione del lock (testForUpdate...): due connessioni PDO REALI ma eseguite in modo
 *    sequenziale nello stesso processo — non serve concorrenza vera, basta che la transazione A
 *    resti aperta (non committata) mentre B tenta lo stesso lock con un `innodb_lock_wait_timeout`
 *    basso. Deterministico al 100%, nessun processo esterno.
 *
 * 2-3) Il perdente deve abortare (idealmente SOTTO LOCK → 409, non al pre-check statico → 422):
 *    una singola chiamata sincrona ad approve() non può essere "messa in pausa" a metà (tra il
 *    pre-check senza lock e il ricontrollo con FOR UPDATE) per interlacciarci un'altra
 *    transazione — questo richiede DAVVERO due esecuzioni concorrenti. Non essendo disponibili
 *    thread/pcntl in modo portabile, usiamo un processo PHP figlio reale (proc_open + PHP_BINARY,
 *    che PHPUnit stesso richiede per esistere) che: apre una transazione, prende i lock FOR
 *    UPDATE nello stesso ordine del codice reale (profilo → richiesta → utente), segnala "LOCKED"
 *    su stdout appena preso il lock CONTESO, attende un breve intervallo per dare tempo al padre
 *    di bloccarsi sullo stesso lock, poi completa l'approvazione (replica SQL di
 *    assignOwner+addMember+markReviewed+rejectOtherPending, la STESSA sequenza di
 *    ClaimService::approve()) e fa COMMIT. Il padre, nel frattempo, chiama il vero
 *    ClaimService::approve() per la richiesta perdente: si blocca sul lock, si sblocca dopo il
 *    commit del figlio, e deve vedere lo stato aggiornato.
 *
 *    REVIEW PAOLO (R-1a): la PROVA CHE FA IL GATE in #2/#3 è l'invariante di sicurezza sullo
 *    STATO FINALE del DB (il perdente resta rejected/non-owner, nessuna doppia owner-row, mai
 *    proprietario di due profili) — verificata SEMPRE, qualunque sia il codice d'errore. Il
 *    codice HTTP (409 atteso, ma 422 tollerato) è solo un segnale secondario: su un runner lento
 *    il padre può imboccare il pre-check statico prima ancora di arrivare al lock, ottenendo 422
 *    invece di 409, senza che questo indichi una regressione della guardia sotto lock.
 *
 *    NOTA IMPORTANTE (trasparenza QA): questi test NON sono stati eseguiti in questo ambiente —
 *    qui non è disponibile un binario PHP locale (solo deploy+curl sono verificabili dal vivo,
 *    cfr. CLAUDE.md). Vanno eseguiti la prima volta con `composer test` su una macchina con PHP
 *    per confermarli, prima di considerarli verdi. Due possibili cause di fallimento, da
 *    distinguere:
 *      - FLAKY (a intermittenza) → sospetto #1: timing. childSleepMs troppo corto rispetto alla
 *        latenza di connessione al DB di test: aumentarlo, NON riscrivere la logica.
 *      - CONSISTENTE (sempre) sul solo test #3 (utente) → la sua base teorica (vedi il commento
 *        sul metodo: FOR UPDATE non stabilisce lo snapshot REPEATABLE READ, quindi la prima
 *        lettura PLAIN della transazione del padre vede il commit del child) potrebbe non valere
 *        per la versione di MySQL/isolation level in uso. In tal caso NON è un bug del test: è un
 *        segnale reale che `ProfileRepository::userHasProfile()` andrebbe reso una lettura
 *        lock-aware dentro `approve()` — da segnalare, non da silenziare.
 */
final class ClaimApproveRaceTest extends TestCase
{
    private ?PDO $pdo = null;
    private int $handleSeq = 0;
    private int $adminId = 0;

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
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['profile_members', 'claim_requests', 'profiles', 'profile_types', 'sports', 'login_attempts', 'admin_audit_log', 'notifications', 'users'] as $t) {
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

        // NOTA (review Paolo, R-4): niente UNIQUE su user_id qui. La migrazione 0024 l'ha rimosso
        // in produzione (multi-profilo: un utente può possedere il proprio profilo + N pagine org).
        // Se questo setUp ricreasse l'UNIQUE, il test #3 (doppio profilo per lo stesso utente)
        // potrebbe "passare" grazie a un vincolo DB che in produzione non esiste, mascherando
        // un'eventuale regressione nella guardia applicativa (userHasProfile sotto lock in
        // approve()). L'univocità dell'ownership è garantita da profile_members, non da qui.
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
            KEY idx_profiles_user (user_id),
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

        $this->pdo->exec('CREATE TABLE login_attempts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(190) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            successful TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_login_identifier (identifier, attempted_at),
            KEY idx_login_ip (ip, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // R-5 (review Paolo): schema minimo reale di admin_audit_log/notifications (cfr. migrazioni
        // 0011/0013). ClaimService::approve() scrive su entrambe (AuditRepository::record e
        // NotificationRepository::create) SIA nel percorso vincente sia — potenzialmente, a seconda
        // di dove cade il ricontrollo — in punti intermedi. Senza queste tabelle, se il timing si
        // inverte e il padre finisse per vincere invece di perdere la corsa, il test esploderebbe
        // con una PDOException "tabella mancante" invece di far asserire pulito il fallimento reale.
        $this->pdo->exec("CREATE TABLE admin_audit_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            admin_user_id INT NOT NULL,
            action VARCHAR(60) NOT NULL,
            target_type VARCHAR(40) NULL,
            target_id BIGINT NULL,
            meta JSON NULL,
            ip VARCHAR(45) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_audit_admin (admin_user_id, created_at),
            KEY idx_audit_action (action, created_at),
            KEY idx_audit_target (target_type, target_id),
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
            KEY idx_notif_user_unread (user_id, read_at),
            KEY idx_notif_user_time (user_id, created_at),
            CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
            'handle' => 'profilo-race-' . $this->handleSeq,
            'name'   => 'Profilo Race ' . $this->handleSeq,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /* ------------------------------------------------------------------ 1) serializzazione reale */

    /**
     * Due connessioni PDO reali (nessun processo esterno necessario): A prende il lock e NON
     * committa; B, con `innodb_lock_wait_timeout` basso, deve bloccarsi/andare in timeout sullo
     * STESSO lock — la prova diretta che `SELECT ... FOR UPDATE` serializza davvero. Poi A
     * committa e B, ritentando, procede subito.
     */
    public function testForUpdateOnProfileGenuinelySerializesConcurrentLockAttempts(): void
    {
        $profileId = $this->insertProfile(null, 'unclaimed');

        $dsn  = (string) getenv('SPOOME_TEST_DSN');
        $user = (string) getenv('SPOOME_TEST_USER');
        $pass = (string) getenv('SPOOME_TEST_PASS');

        $connA = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $connB = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // B deve arrendersi in fretta: è la prova (non un test lento) che il lock serializza.
        $connB->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $repoA = new ClaimRepository($connA);
        $repoB = new ClaimRepository($connB);

        try {
            $connA->beginTransaction();
            $lockedByA = $repoA->lockProfileForClaim($profileId);
            $this->assertNotNull($lockedByA, 'A deve ottenere il lock: nessun altro lo detiene ancora');
            $this->assertSame('unclaimed', $lockedByA['claim_status']);

            $connB->beginTransaction();
            $threwLockWaitTimeout = false;
            try {
                $repoB->lockProfileForClaim($profileId);
            } catch (PDOException $e) {
                $threwLockWaitTimeout = true;
                $this->assertStringContainsStringIgnoringCase(
                    'lock wait timeout',
                    $e->getMessage(),
                    'B deve fallire per lock wait timeout (errore MySQL 1205): prova che FOR UPDATE serializza davvero'
                );
            }
            $this->assertTrue($threwLockWaitTimeout, 'B doveva bloccarsi/andare in timeout: il lock di A non sta serializzando');

            if ($connB->inTransaction()) {
                $connB->rollBack();
            }

            // A completa e rilascia il lock.
            $connA->commit();

            // Ora B, in una nuova transazione, deve riuscire subito (nessuna scrittura è avvenuta).
            $connB->beginTransaction();
            $lockedByB = $repoB->lockProfileForClaim($profileId);
            $this->assertNotNull($lockedByB);
            $this->assertSame('unclaimed', $lockedByB['claim_status']);
            $connB->commit();
        } finally {
            if ($connA->inTransaction()) {
                $connA->rollBack();
            }
            if ($connB->inTransaction()) {
                $connB->rollBack();
            }
        }

        $stmt = $this->pdo->prepare('SELECT user_id, claim_status FROM profiles WHERE id = :id');
        $stmt->execute(['id' => $profileId]);
        $row = $stmt->fetch();
        $this->assertNull($row['user_id'], 'questo test fa solo letture FOR UPDATE: nessuna scrittura attesa');
        $this->assertSame('unclaimed', $row['claim_status']);
    }

    /* ---------------------------------------------------- 2-3) helper per la corsa a due processi */

    private function writeRaceChildScript(): string
    {
        $path = sys_get_temp_dir() . '/spoome_claim_race_' . bin2hex(random_bytes(6)) . '.php';
        $src = <<<'PHP'
<?php
/**
 * Child di test (NON fa parte dell'app): simula il processo che vince la corsa su
 * ClaimService::approve(), tenendo un lock FOR UPDATE mentre il padre tenta la sua approvazione
 * concorrente sullo stesso profilo/utente. Comunica via stdout: "LOCKED\n" appena preso il lock
 * conteso, poi "DONE\n" a commit avvenuto. Solo PDO, nessuna dipendenza da Spoome.
 */
$mode       = $argv[1]; // 'profile' | 'user'
$dsn        = $argv[2];
$user       = $argv[3];
$pass       = $argv[4];
$profileId  = (int) $argv[5];
$requestIdA = (int) $argv[6];
$ownerId    = (int) $argv[7];
$adminId    = (int) $argv[8];
$sleepMs    = (int) $argv[9];

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->beginTransaction();

if ($mode === 'profile') {
    // Contesa sulla riga PROFILO: prende il lock conteso, segnala SUBITO, poi (dopo l'attesa)
    // prende anche richiesta+utente (non contesi in questo scenario) prima di scrivere.
    $pdo->prepare('SELECT id, user_id, claim_status FROM profiles WHERE id = :pid FOR UPDATE')
        ->execute(['pid' => $profileId]);
    fwrite(STDOUT, "LOCKED\n");
    fflush(STDOUT);
    usleep($sleepMs * 1000);
    $pdo->prepare('SELECT status FROM claim_requests WHERE id = :rid FOR UPDATE')->execute(['rid' => $requestIdA]);
    $pdo->prepare('SELECT id FROM users WHERE id = :uid FOR UPDATE')->execute(['uid' => $ownerId]);
} else {
    // Contesa sulla riga UTENTE: prende profilo+richiesta (non contesi), poi l'utente (conteso),
    // e SOLO ORA segnala — replica l'ordine di lock reale (profilo -> richiesta -> utente).
    $pdo->prepare('SELECT id, user_id, claim_status FROM profiles WHERE id = :pid FOR UPDATE')
        ->execute(['pid' => $profileId]);
    $pdo->prepare('SELECT status FROM claim_requests WHERE id = :rid FOR UPDATE')->execute(['rid' => $requestIdA]);
    $pdo->prepare('SELECT id FROM users WHERE id = :uid FOR UPDATE')->execute(['uid' => $ownerId]);
    fwrite(STDOUT, "LOCKED\n");
    fflush(STDOUT);
    usleep($sleepMs * 1000);
}

// Replica l'esito di un approve() vincente: stessa sequenza di scritture di
// ClaimService::approve() (assignOwner + addMember owner-row + markReviewed + rejectOtherPending).
$pdo->prepare("UPDATE profiles SET user_id = :uid, claim_status = 'claimed' WHERE id = :pid")
    ->execute(['uid' => $ownerId, 'pid' => $profileId]);
$pdo->prepare("INSERT IGNORE INTO profile_members (profile_id, user_id, role) VALUES (:pid, :uid, 'owner')")
    ->execute(['pid' => $profileId, 'uid' => $ownerId]);
$pdo->prepare("UPDATE claim_requests SET status = 'approved', reviewed_by_user_id = :a, reviewed_at = NOW() WHERE id = :id")
    ->execute(['a' => $adminId, 'id' => $requestIdA]);
$pdo->prepare("UPDATE claim_requests SET status = 'rejected', reviewed_by_user_id = :a, reviewed_at = NOW()
               WHERE profile_id = :pid AND id <> :ex AND status = 'pending'")
    ->execute(['a' => $adminId, 'pid' => $profileId, 'ex' => $requestIdA]);

$pdo->commit();
fwrite(STDOUT, "DONE\n");
fflush(STDOUT);
PHP;
        file_put_contents($path, $src);
        return $path;
    }

    /**
     * Avvia il child in un PROCESSO reale separato: necessario perché una singola chiamata
     * sincrona ad approve() non si può "mettere in pausa" a metà per interlacciarci un'altra
     * transazione — serve concorrenza vera.
     * @return array{0:resource,1:array<int,resource>,2:string}
     */
    private function startRaceChild(string $mode, int $profileId, int $requestIdA, int $ownerId, int $sleepMs): array
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open non disponibile: test di concorrenza reale saltato.');
        }
        $script = $this->writeRaceChildScript();
        $cmd = [
            PHP_BINARY, $script, $mode,
            (string) getenv('SPOOME_TEST_DSN'),
            (string) getenv('SPOOME_TEST_USER'),
            (string) getenv('SPOOME_TEST_PASS'),
            (string) $profileId, (string) $requestIdA, (string) $ownerId, (string) $this->adminId, (string) $sleepMs,
        ];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($cmd, $descriptors, $pipes);
        if ($process === false || !is_resource($process)) {
            @unlink($script);
            $this->markTestSkipped('Impossibile avviare il processo child (proc_open ha fallito).');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        return [$process, $pipes, $script];
    }

    /** Attende la riga "LOCKED" dal child (ha preso il lock conteso), con timeout generoso. */
    private function waitForLocked(array $pipes, float $timeoutSec = 8.0): void
    {
        $buffer   = '';
        $deadline = microtime(true) + $timeoutSec;
        while (microtime(true) < $deadline) {
            if (str_contains($buffer, "LOCKED\n")) {
                return;
            }
            $read = [$pipes[1]];
            $write = $except = [];
            if (stream_select($read, $write, $except, 0, 100000) > 0) {
                $chunk = fread($pipes[1], 8192);
                if ($chunk !== false && $chunk !== '') {
                    $buffer .= $chunk;
                }
            }
        }
        $this->fail('Il processo child non ha segnalato LOCKED entro il timeout. Output finora: ' . var_export($buffer, true));
    }

    /** Chiude il child, ne verifica l'uscita pulita e ripulisce lo script temporaneo. */
    private function finishRaceChild($process, array $pipes, string $script, float $timeoutSec = 8.0): void
    {
        $out      = '';
        $deadline = microtime(true) + $timeoutSec;
        while (microtime(true) < $deadline && !str_contains($out, "DONE\n")) {
            $chunk = fread($pipes[1], 8192);
            if ($chunk !== false && $chunk !== '') {
                $out .= $chunk;
            } else {
                usleep(50000);
            }
        }
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        @unlink($script);

        $this->assertStringContainsString('DONE', $out, "Il child non ha completato il commit; stderr: {$err}");
        $this->assertSame(0, $exitCode, "Il child è terminato con errore ({$exitCode}); stderr: {$err}");
    }

    /* ------------------------------------------------------ 2) il perdente aborta SOTTO LOCK (409) */

    /**
     * R1(userA)/R2(userB) pendenti sullo STESSO profilo unclaimed. Il child vince (come se fosse
     * un admin che approva R1 un istante prima), tenendo il lock sulla riga PROFILO mentre il
     * padre tenta approve(R2). Il padre deve bloccarsi su lockProfileForClaim e, alla ripresa,
     * trovare il profilo già assegnato → 409 err_taken (non 422: quello è il pre-check statico,
     * già coperto in ClaimServiceTest; qui vogliamo la via del lock).
     */
    public function testApproveRecheckAbortsLoserUnderLockEvenAfterLockIsReleased(): void
    {
        $profileId = $this->insertProfile(null, 'unclaimed');
        $userA = $this->insertUser('vincitoreA@demo.spoome.local');
        $userB = $this->insertUser('perdenteB@demo.spoome.local');

        $svc  = $this->service();
        $reqA = $svc->request($userA, $profileId, null, '9.9.1.1');
        $reqB = $svc->request($userB, $profileId, null, '9.9.1.2');
        $this->assertTrue($reqA->ok);
        $this->assertTrue($reqB->ok);
        $requestIdA = (int) $reqA->data['id'];
        $requestIdB = (int) $reqB->data['id'];

        [$process, $pipes, $script] = $this->startRaceChild('profile', $profileId, $requestIdA, $userA, 1500);
        try {
            $this->waitForLocked($pipes);

            $result = $svc->approve($this->adminId, $requestIdB, '9.9.1.3');

            $this->assertFalse($result->ok, 'il perdente della corsa deve fallire');
        } finally {
            $this->finishRaceChild($process, $pipes, $script);
        }

        // PROVA PRIMARIA (review Paolo, R-1a) — l'invariante di sicurezza sullo STATO FINALE del
        // DB è il gate: qualunque sia il ramo di codice che ha fatto fallire approve(), il
        // perdente non deve MAI riuscire a corrompere l'ownership. Nessuna riassegnazione,
        // nessuna seconda owner-row: il vincitore (child) resta l'unico owner.
        $stmt = $this->pdo->prepare('SELECT user_id, claim_status FROM profiles WHERE id = :id');
        $stmt->execute(['id' => $profileId]);
        $profile = $stmt->fetch();
        $this->assertSame($userA, (int) $profile['user_id']);
        $this->assertSame('claimed', $profile['claim_status']);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM profile_members WHERE profile_id = :p');
        $stmt->execute(['p' => $profileId]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'una sola owner-row: il perdente non deve averne creata una sua');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM profile_members WHERE profile_id = :p AND user_id = :u');
        $stmt->execute(['p' => $profileId, 'u' => $userB]);
        $this->assertSame(0, (int) $stmt->fetchColumn());

        // Segnale SECONDARIO, tollerante al timing (review Paolo, R-1a): idealmente è il
        // ricontrollo SOTTO LOCK a far fallire il padre (409), ma su un runner lento è possibile
        // che l'esecuzione del padre venga ritardata abbastanza da fargli imboccare il pre-check
        // statico (422, senza mai arrivare al lock) — l'invariante di sicurezza sopra vale in
        // ENTRAMBI i casi, quindi qui accettiamo entrambi senza far fallire il test per un
        // dettaglio di timing.
        $this->assertTrue(
            in_array($result->code, [409, 422], true),
            'atteso 409 (ricontrollo sotto lock) o 422 (pre-check statico su runner lento); ottenuto: ' . $result->code
        );
    }

    /* ---------------------------------------------- 3) doppio profilo per lo stesso utente (409) */

    /**
     * Stesso utente con richieste pendenti su DUE profili diversi. Il child vince sul primo
     * profilo (tenendo il lock sulla riga UTENTE mentre scrive), il padre tenta approve() sulla
     * richiesta del SECONDO profilo. Il padre deve bloccarsi su lockUserExists() e, alla ripresa,
     * trovare l'utente già proprietario altrove → 409 err_user_has_profile.
     *
     * Base teorica (documentata perché non verificabile empiricamente in questo ambiente, cfr.
     * nota di classe): il pre-check profilo/richiesta del padre (lockProfileForClaim/
     * lockRequestStatus, sul secondo profilo, non conteso) passa subito; poi lockUserExists()
     * si blocca sulla riga utente finché il child non committa. La successiva
     * ProfileRepository::userHasProfile() è una lettura PLAIN (non FOR UPDATE) — ma è anche la
     * PRIMA lettura "consistente" (non lock) della transazione del padre (le tre precedenti sono
     * tutte FOR UPDATE, che secondo la doc InnoDB non stabiliscono lo snapshot REPEATABLE READ):
     * lo snapshot si stabilisce quindi SOLO ORA, dopo il commit del child, e la vede aggiornata.
     */
    public function testApproveRecheckAbortsSecondProfileForSameUserUnderLockEvenAfterLockIsReleased(): void
    {
        $profile1 = $this->insertProfile(null, 'unclaimed');
        $profile2 = $this->insertProfile(null, 'unclaimed');
        $userX = $this->insertUser('doppiaidentitaConcorrente@demo.spoome.local');

        $svc = $this->service();
        $reqOnProfile1 = $svc->request($userX, $profile1, null, '9.9.2.1');
        $reqOnProfile2 = $svc->request($userX, $profile2, null, '9.9.2.2');
        $this->assertTrue($reqOnProfile1->ok);
        $this->assertTrue($reqOnProfile2->ok);
        $requestId1 = (int) $reqOnProfile1->data['id'];
        $requestId2 = (int) $reqOnProfile2->data['id'];

        [$process, $pipes, $script] = $this->startRaceChild('user', $profile1, $requestId1, $userX, 1500);
        try {
            $this->waitForLocked($pipes);

            $result = $svc->approve($this->adminId, $requestId2, '9.9.2.3');

            $this->assertFalse($result->ok);
        } finally {
            $this->finishRaceChild($process, $pipes, $script);
        }

        // PROVA PRIMARIA (review Paolo, R-1a) — l'invariante di sicurezza sullo STATO FINALE del
        // DB è il gate: l'utente possiede SOLO il primo profilo (vinto dal child), MAI il secondo,
        // e non deve mai finire proprietario di due profili contemporaneamente.
        $stmt = $this->pdo->prepare('SELECT user_id FROM profiles WHERE id = :id');
        $stmt->execute(['id' => $profile1]);
        $this->assertSame($userX, (int) $stmt->fetchColumn());
        $stmt->execute(['id' => $profile2]);
        $this->assertNull($stmt->fetchColumn());

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM profile_members WHERE user_id = :u');
        $stmt->execute(['u' => $userX]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'una sola owner-row per questo utente: non deve possedere due profili');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM profile_members WHERE profile_id = :p');
        $stmt->execute(['p' => $profile2]);
        $this->assertSame(0, (int) $stmt->fetchColumn());

        // Segnale SECONDARIO, tollerante al timing (review Paolo, R-1a): idealmente è il
        // ricontrollo SOTTO LOCK a far fallire il padre (409), ma su un runner lento il padre può
        // imboccare il pre-check statico (422) senza mai arrivare al lock — l'invariante di
        // sicurezza sopra vale in ENTRAMBI i casi, quindi qui accettiamo entrambi senza far
        // fallire il test per un dettaglio di timing.
        $this->assertTrue(
            in_array($result->code, [409, 422], true),
            'atteso 409 (ricontrollo sotto lock) o 422 (pre-check statico su runner lento); ottenuto: ' . $result->code
        );
    }
}
