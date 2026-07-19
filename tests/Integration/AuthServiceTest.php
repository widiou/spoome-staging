<?php

declare(strict_types=1);

namespace Spoome\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Spoome\Core\Cache;
use Spoome\Domain\Auth\AuthService;
use Spoome\Domain\Auth\EmailVerificationService;
use Spoome\Domain\Auth\PasswordResetService;
use Spoome\Domain\Users\UserRepository;
use Spoome\Support\Str;

/**
 * Test d'integrazione di AuthService: register (atomico), login (throttle per-IP vs per-email,
 * anti-enumeration, gate pending produzione/beta), reset password (monouso+atomico), verifica email.
 *
 * Richiede un DB MySQL usa-e-getta via env SPOOME_TEST_DSN/SPOOME_TEST_USER/SPOOME_TEST_PASS
 * (stesso pattern di RateLimiterTest). Skippa se non configurato — NON tocca mai il DB reale
 * (tests/bootstrap.php reindirizza anche Db::connection() verso questo stesso DB usa-e-getta,
 * per gli usi interni non iniettati come Logger::security).
 */
final class AuthServiceTest extends TestCase
{
    private ?PDO $pdo = null;

    /** id dei due profile_types seminati in setUp(). */
    private const TYPE_ATLETA_ID  = 1;
    private const TYPE_SOCIETA_ID = 2;

    protected function setUp(): void
    {
        $dsn = getenv('SPOOME_TEST_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('SPOOME_TEST_DSN non impostato — test d\'integrazione saltato.');
        }
        $this->pdo = new PDO($dsn, (string) getenv('SPOOME_TEST_USER'), (string) getenv('SPOOME_TEST_PASS'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Fedele a Db::connection(): i placeholder nominati NON sono riusabili nella stessa
            // query con prepare native — è il gotcha che ha già causato 500 in produzione.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ([
            'profile_members', 'profiles', 'profile_types', 'sports',
            'auth_tokens', 'password_resets', 'email_verifications', 'login_attempts', 'users',
        ] as $t) {
            $this->pdo->exec("DROP TABLE IF EXISTS `$t`");
        }

        $this->pdo->exec("CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('member','moderator','admin') NOT NULL DEFAULT 'member',
            status ENUM('pending','active','suspended') NOT NULL DEFAULT 'pending',
            email_verified_at TIMESTAMP NULL DEFAULT NULL,
            last_login_at TIMESTAMP NULL DEFAULT NULL,
            unread_notifications INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
            active TINYINT(1) NOT NULL DEFAULT 1,
            sort INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_ptype_key (`key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Schema ATTUALE (post migrazione 0012 claim): user_id nullable + claim_status, anche se
        // AuthService::register crea sempre profili "claimed" con owner — replichiamo lo schema
        // reale per non divergere da quanto gira davvero in produzione.
        $this->pdo->exec("CREATE TABLE profiles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            claim_status ENUM('unclaimed','claimed') NOT NULL DEFAULT 'claimed',
            profile_type_id INT NOT NULL,
            handle VARCHAR(60) NOT NULL,
            display_name VARCHAR(160) NOT NULL,
            sport_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_profiles_handle (handle),
            UNIQUE KEY uq_profiles_user (user_id),
            CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_profiles_type FOREIGN KEY (profile_type_id) REFERENCES profile_types (id) ON DELETE RESTRICT,
            CONSTRAINT fk_profiles_sport FOREIGN KEY (sport_id) REFERENCES sports (id) ON DELETE SET NULL
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

        $this->pdo->exec("CREATE TABLE password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            used_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_pwreset_hash (token_hash),
            KEY idx_pwreset_user (user_id),
            CONSTRAINT fk_pwreset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE email_verifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            used_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_emailver_hash (token_hash),
            KEY idx_emailver_user (user_id),
            CONSTRAINT fk_emailver_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE auth_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            kind ENUM('access','refresh') NOT NULL DEFAULT 'access',
            device_label VARCHAR(190) NULL,
            expires_at TIMESTAMP NOT NULL,
            revoked_at TIMESTAMP NULL DEFAULT NULL,
            last_used_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_auth_token_hash (token_hash),
            KEY idx_auth_user (user_id),
            CONSTRAINT fk_auth_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        // Seed dei tipi profilo: 'atleta' (id 1, personale) e 'societa' (id 2, organizzazione).
        // L'ordine di inserimento fissa gli id: ricreando la tabella identica ad ogni test,
        // ProfileRepository::typeIdByKey() resta corretto anche con la cache statica di Cache
        // (memoizzazione per-processo, senza APCu in CI) che sopravvive tra i metodi di test.
        $this->pdo->exec("INSERT INTO profile_types (`key`, label, is_organization, active, sort) VALUES
            ('atleta', 'Atleta', 0, 1, 10),
            ('societa', 'Società', 1, 1, 20)");
        // Invalida esplicitamente la cache di processo: i dati di riferimento sono stati
        // appena ricreati, non vogliamo mai leggere uno stato di un test precedente.
        Cache::forget('profile_types_active');

        // Ambiente non-produzione di default (bootstrap.php lo imposta a 'testing'); alcuni test
        // lo forzano temporaneamente a 'production' e lo ripristinano in tearDown().
        $_ENV['APP_ENV'] = 'testing';
        putenv('APP_ENV=testing');
    }

    protected function tearDown(): void
    {
        // Mai lasciare l'ambiente in 'production' per il test successivo nello stesso processo.
        $_ENV['APP_ENV'] = 'testing';
        putenv('APP_ENV=testing');
    }

    private function service(): AuthService
    {
        return new AuthService($this->pdo);
    }

    private const STRONG_PW = 'Sup3rSegreta!';

    /* ------------------------------------------------------------------ register() ---- */

    public function testRegisterIsAtomicAndCreatesUserProfileAndOwnerMembership(): void
    {
        $r = $this->service()->register('nuovo@demo.spoome.local', self::STRONG_PW, 'Nuovo Utente', 'atleta', '10.0.0.1');

        $this->assertTrue($r['ok'], 'register deve riuscire con dati validi');
        $this->assertIsInt($r['userId']);

        $userRow = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $userRow->execute(['id' => $r['userId']]);
        $user = $userRow->fetch();
        $this->assertNotFalse($user, 'la riga utente deve esistere');
        $this->assertSame('pending', $user['status']);

        $profileRow = $this->pdo->prepare('SELECT * FROM profiles WHERE user_id = :uid');
        $profileRow->execute(['uid' => $r['userId']]);
        $profile = $profileRow->fetch();
        $this->assertNotFalse($profile, 'il profilo deve essere creato nella stessa registrazione');
        $this->assertSame(self::TYPE_ATLETA_ID, (int) $profile['profile_type_id']);

        $memberRow = $this->pdo->prepare(
            "SELECT role FROM profile_members WHERE profile_id = :pid AND user_id = :uid"
        );
        $memberRow->execute(['pid' => $profile['id'], 'uid' => $r['userId']]);
        $this->assertSame('owner', $memberRow->fetchColumn(), 'la owner-row autoritativa deve esistere da subito');
    }

    public function testRegisterRejectsAlreadyTakenEmailWithoutLeakingSideEffects(): void
    {
        $first = $this->service()->register('doppio@demo.spoome.local', self::STRONG_PW, 'Primo', 'atleta', '10.0.0.2');
        $this->assertTrue($first['ok']);

        $second = $this->service()->register('doppio@demo.spoome.local', self::STRONG_PW, 'Secondo', 'atleta', '10.0.0.2');
        $this->assertFalse($second['ok']);
        $this->assertSame('email_taken', $second['error']);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE email = 'doppio@demo.spoome.local'")->fetchColumn();
        $this->assertSame(1, $count, 'un solo utente deve esistere per quella email');
    }

    public function testRegisterRejectsOrganizationTypeAsSelfRegistration(): void
    {
        $r = $this->service()->register('org@demo.spoome.local', self::STRONG_PW, 'Società X', 'societa', '10.0.0.3');
        $this->assertFalse($r['ok']);
        $this->assertSame('org_not_allowed', $r['error']);
        $this->assertFalse((new UserRepository($this->pdo))->emailExists('org@demo.spoome.local'));
    }

    /** La transazione è tutto-o-niente: un errore DB dopo la creazione utente annulla anche quella. */
    public function testRegisterRollsBackUserCreationOnProfileInsertFailure(): void
    {
        // sport_id inesistente viola la FK fk_profiles_sport all'INSERT del profilo, DOPO che
        // l'utente è già stato creato nella stessa transazione: deve sparire anche lui.
        $r = $this->service()->register('atomico@demo.spoome.local', self::STRONG_PW, 'Atomico', 'atleta', '10.0.0.4', 999999);

        $this->assertFalse($r['ok']);
        $this->assertSame('db_error', $r['error']);
        $this->assertFalse(
            (new UserRepository($this->pdo))->emailExists('atomico@demo.spoome.local'),
            'la creazione utente deve essere annullata dal rollback (atomicità register)'
        );
    }

    public function testRegisterThrottlesByIpAfterTenAttempts(): void
    {
        $ip = '10.0.0.9';
        for ($i = 1; $i <= 10; $i++) {
            $r = $this->service()->register("utente{$i}@demo.spoome.local", self::STRONG_PW, "Utente {$i}", 'atleta', $ip);
            $this->assertTrue($r['ok'], "il tentativo $i deve riuscire (sotto soglia)");
        }
        $r11 = $this->service()->register('utente11@demo.spoome.local', self::STRONG_PW, 'Utente 11', 'atleta', $ip);
        $this->assertFalse($r11['ok']);
        $this->assertSame('throttled', $r11['error']);
    }

    /* ---------------------------------------------------------------------- login() ---- */

    public function testLoginBlockedByIpAfterThresholdEvenForADifferentEmail(): void
    {
        $ip = '20.0.0.1';
        $svc = $this->service();
        $victim = $svc->register('vittima@demo.spoome.local', self::STRONG_PW, 'Vittima', 'atleta', '9.9.9.9');
        $this->assertTrue($victim['ok']);
        (new UserRepository($this->pdo))->markVerifiedAndActive($victim['userId']);

        // L'attaccante fallisce 5 volte contro un'email fantasma, dallo stesso IP.
        for ($i = 0; $i < 5; $i++) {
            $r = $svc->login('fantasma@demo.spoome.local', 'sbagliata123', $ip);
            $this->assertFalse($r['ok']);
        }

        // Anche il login CORRETTO della vittima, dallo STESSO IP, viene bloccato: throttle per-IP.
        $r = $svc->login('vittima@demo.spoome.local', self::STRONG_PW, $ip);
        $this->assertFalse($r['ok']);
        $this->assertSame(429, $r['code']);
    }

    public function testLoginNotBlockedFromDifferentIpDespiteFailedAttemptsOnSameEmail(): void
    {
        $svc = $this->service();
        $victim = $svc->register('vittima2@demo.spoome.local', self::STRONG_PW, 'Vittima 2', 'atleta', '9.9.9.10');
        $this->assertTrue($victim['ok']);
        (new UserRepository($this->pdo))->markVerifiedAndActive($victim['userId']);

        // L'attaccante fallisce 5 volte contro l'email della vittima, ma da un IP diverso ogni volta
        // (o comunque diverso da quello con cui la vittima farà login): il blocco è per-IP, non
        // per-email, quindi NON deve impedire alla vittima di entrare da un IP pulito.
        for ($i = 0; $i < 5; $i++) {
            $svc->login('vittima2@demo.spoome.local', 'sbagliata123', '20.0.0.2');
        }

        $r = $svc->login('vittima2@demo.spoome.local', self::STRONG_PW, '20.0.0.3');
        $this->assertTrue($r['ok'], 'un IP pulito non deve mai essere bloccato da fallimenti su un altro IP');
    }

    public function testLoginAntiEnumerationNonexistentEmailBehavesLikeWrongPassword(): void
    {
        $svc = $this->service();
        $existing = $svc->register('reale@demo.spoome.local', self::STRONG_PW, 'Reale', 'atleta', '30.0.0.1');
        $this->assertTrue($existing['ok']);
        (new UserRepository($this->pdo))->markVerifiedAndActive($existing['userId']);

        $rNonexistent = $svc->login('fantasma2@demo.spoome.local', 'qualsiasi123', '30.0.0.2');
        $rWrongPass   = $svc->login('reale@demo.spoome.local', 'passwordSbagliata1', '30.0.0.3');

        $this->assertFalse($rNonexistent['ok']);
        $this->assertFalse($rWrongPass['ok']);
        $this->assertSame($rWrongPass['code'], $rNonexistent['code'], 'stesso codice: nessun segnale di enumerazione');
        $this->assertSame($rWrongPass['error'], $rNonexistent['error'], 'stesso messaggio: nessun segnale di enumerazione');

        // Il tentativo su email inesistente è comunque registrato (per il throttle), a riprova
        // che il codice passa comunque dal path di verifica (bcrypt fittizio) prima di rispondere.
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE identifier = :e AND successful = 0");
        $stmt->execute(['e' => 'fantasma2@demo.spoome.local']);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testLoginRejectsPendingUserInProductionButAllowsInBeta(): void
    {
        $svc = $this->service();
        $r = $svc->register('pending@demo.spoome.local', self::STRONG_PW, 'Pendente', 'atleta', '40.0.0.1');
        $this->assertTrue($r['ok']);
        // Nessuna verifica email: l'utente resta 'pending'.

        // Fuori produzione (beta/staging/testing): il login pending è ammesso.
        $rBeta = $svc->login('pending@demo.spoome.local', self::STRONG_PW, '40.0.0.2');
        $this->assertTrue($rBeta['ok'], 'in beta un utente pending deve poter accedere');

        // In produzione: il gate resta attivo.
        $_ENV['APP_ENV'] = 'production';
        putenv('APP_ENV=production');
        $rProd = $svc->login('pending@demo.spoome.local', self::STRONG_PW, '40.0.0.3');
        $this->assertFalse($rProd['ok']);
        $this->assertSame(403, $rProd['code']);
    }

    public function testLoginRejectsSuspendedUserRegardlessOfEnvironment(): void
    {
        $svc = $this->service();
        $r = $svc->register('sospeso@demo.spoome.local', self::STRONG_PW, 'Sospeso', 'atleta', '50.0.0.1');
        $this->assertTrue($r['ok']);
        $this->pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = :id")->execute(['id' => $r['userId']]);

        $rBeta = $svc->login('sospeso@demo.spoome.local', self::STRONG_PW, '50.0.0.2');
        $this->assertFalse($rBeta['ok']);
        $this->assertSame(403, $rBeta['code']);
    }

    /* ------------------------------------------------------------ password reset flow ---- */

    public function testRequestPasswordResetIsSilentAndNoOpForUnknownEmail(): void
    {
        $this->service()->requestPasswordReset('non-esiste@demo.spoome.local', '60.0.0.1');
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testRequestPasswordResetIsSilentForSuspendedUser(): void
    {
        $svc = $this->service();
        $r = $svc->register('sospeso2@demo.spoome.local', self::STRONG_PW, 'Sospeso 2', 'atleta', '60.0.0.2');
        $this->pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = :id")->execute(['id' => $r['userId']]);

        $svc->requestPasswordReset('sospeso2@demo.spoome.local', '60.0.0.3');
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn();
        $this->assertSame(0, $count, 'un utente sospeso non deve poter ricevere un token di reset');
    }

    public function testRequestPasswordResetThrottlesByEmailIndependentlyFromIp(): void
    {
        $svc = $this->service();
        $r = $svc->register('reset1@demo.spoome.local', self::STRONG_PW, 'Reset Uno', 'atleta', '70.0.0.1');
        $this->assertTrue($r['ok']);

        // 5 IP diversi, stessa email: il tetto per-email (3/60min) scatta indipendentemente dall'IP.
        for ($i = 1; $i <= 5; $i++) {
            $svc->requestPasswordReset('reset1@demo.spoome.local', "70.0.0.{$i}");
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = :u');
        $stmt->execute(['u' => $r['userId']]);
        $this->assertSame(3, (int) $stmt->fetchColumn(), 'oltre 3 richieste/ora sulla stessa email vengono scartate');
    }

    public function testRequestPasswordResetThrottlesByIpAcrossDifferentEmails(): void
    {
        $svc = $this->service();
        $ip = '80.0.0.1';
        $ids = [];
        for ($i = 1; $i <= 6; $i++) {
            $reg = $svc->register("resetip{$i}@demo.spoome.local", self::STRONG_PW, "Reset IP {$i}", 'atleta', "81.0.0.{$i}");
            $this->assertTrue($reg['ok']);
            $ids[] = $reg['userId'];
        }
        // 6 email diverse, stesso IP: il tetto per-IP (5/15min) scatta alla 6ª richiesta.
        foreach (range(1, 6) as $i) {
            $svc->requestPasswordReset("resetip{$i}@demo.spoome.local", $ip);
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id IN (' . implode(',', $ids) . ')');
        $stmt->execute();
        $this->assertSame(5, (int) $stmt->fetchColumn(), 'oltre 5 richieste/15min dallo stesso IP vengono scartate');
    }

    public function testResetPasswordConsumesTokenAtomicallyAndOnlyOnce(): void
    {
        $svc = $this->service();
        $r = $svc->register('reset2@demo.spoome.local', self::STRONG_PW, 'Reset Due', 'atleta', '90.0.0.1');
        $this->assertTrue($r['ok']);

        $prs = new PasswordResetService($this->pdo);
        $token = $prs->issue($r['userId']);

        $newPw = 'AltraSegreta9!';
        $first = $svc->resetPassword($token, $newPw, '90.0.0.2');
        $this->assertTrue($first['ok']);
        $this->assertSame($r['userId'], $first['userId']);

        $userRow = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $userRow->execute(['id' => $r['userId']]);
        $this->assertTrue(password_verify($newPw, (string) $userRow->fetchColumn()));

        // Monouso: lo stesso token non deve più funzionare.
        $second = $svc->resetPassword($token, 'UnaTerzaSegreta9!', '90.0.0.3');
        $this->assertFalse($second['ok']);
    }

    public function testResetPasswordRejectsWeakPasswordWithoutConsumingToken(): void
    {
        $svc = $this->service();
        $r = $svc->register('reset3@demo.spoome.local', self::STRONG_PW, 'Reset Tre', 'atleta', '90.0.1.1');
        $prs = new PasswordResetService($this->pdo);
        $token = $prs->issue($r['userId']);

        $weak = $svc->resetPassword($token, 'weak', '90.0.1.2');
        $this->assertFalse($weak['ok']);

        // Il token deve restare valido: la policy fallisce PRIMA del consumo.
        $stmt = $this->pdo->prepare('SELECT used_at FROM password_resets WHERE token_hash = :h');
        $stmt->execute(['h' => Str::hashToken($token)]);
        $this->assertNull($stmt->fetchColumn());
    }

    public function testResetPasswordThrottlesByIpRegardlessOfTokenValidity(): void
    {
        $svc = $this->service();
        $ip = '90.0.2.1';
        for ($i = 0; $i < 10; $i++) {
            $r = $svc->resetPassword('token-non-valido', 'weak', $ip);
            $this->assertFalse($r['ok']);
        }
        $r11 = $svc->resetPassword('token-non-valido', 'weak', $ip);
        $this->assertFalse($r11['ok']);
        $this->assertNotSame(
            AuthService::passwordPolicyMessage(),
            $r11['error'],
            'oltre soglia deve rispondere col messaggio di throttling, non con quello di policy'
        );
    }

    /* --------------------------------------------------------------------- verifyEmail() ---- */

    public function testVerifyEmailActivatesUserAndTokenIsSingleUse(): void
    {
        $svc = $this->service();
        $r = $svc->register('verifica1@demo.spoome.local', self::STRONG_PW, 'Verifica Uno', 'atleta', '95.0.0.1');
        $this->assertTrue($r['ok']);

        $evs = new EmailVerificationService($this->pdo);
        $token = $evs->issue($r['userId']);

        $userId = $svc->verifyEmail($token);
        $this->assertSame($r['userId'], $userId);

        $stmt = $this->pdo->prepare('SELECT status, email_verified_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $r['userId']]);
        $row = $stmt->fetch();
        $this->assertSame('active', $row['status']);
        $this->assertNotNull($row['email_verified_at']);

        // Monouso.
        $this->assertNull($svc->verifyEmail($token));
    }

    public function testVerifyEmailWithInvalidTokenReturnsNull(): void
    {
        $this->assertNull($this->service()->verifyEmail('token-non-esiste-mai'));
    }
}
