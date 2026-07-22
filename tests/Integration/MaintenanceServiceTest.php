<?php

declare(strict_types=1);

namespace Spoome\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Spoome\Domain\Maintenance\MaintenanceService;

/**
 * Test d'integrazione: richiede un DB MySQL usa-e-getta via env SPOOME_TEST_DSN
 * (es. "mysql:host=127.0.0.1;dbname=spoome_test", SPOOME_TEST_USER, SPOOME_TEST_PASS).
 * Skippa se non configurato — NON tocca mai il DB di produzione.
 *
 * Copre MaintenanceService::detectErrorSpikes() (issue #8, parte a): la query di sola lettura
 * che alimenta l'alert digest via Mailer. Niente qui verifica l'invio email in sé (Mailer::send
 * in staging/testing si limita a loggare — vedi src/Core/Mailer.php), solo la logica di
 * rilevazione (soglia, finestra 24h, livello error — 'warning' escluso di proposito, clamp difensivo).
 */
final class MaintenanceServiceTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $dsn = getenv('SPOOME_TEST_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('SPOOME_TEST_DSN non impostato — test d\'integrazione saltato.');
        }
        $this->pdo = new PDO($dsn, (string) getenv('SPOOME_TEST_USER'), (string) getenv('SPOOME_TEST_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // Riproduce la condizione di produzione (Db usa EMULATE_PREPARES=false): è la modalità
            // in cui i named placeholder non sono riusabili e possono emergere gli HY093.
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec('DROP TABLE IF EXISTS app_logs');
        $this->pdo->exec("CREATE TABLE app_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            level VARCHAR(10) NOT NULL,
            channel VARCHAR(40) NOT NULL DEFAULT 'app',
            message VARCHAR(1000) NOT NULL,
            fingerprint CHAR(40) NOT NULL,
            exception_class VARCHAR(190) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
    }

    /** Inserisce $n righe con lo stesso fingerprint, level e età (ore fa) indicati. */
    private function insertRows(string $fingerprint, int $n, string $level = 'error', int $hoursAgo = 1): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO app_logs (level, message, fingerprint, exception_class, created_at)
             VALUES (:me1, :me2, :me3, :me4, NOW() - INTERVAL :me5 HOUR)'
        );
        for ($i = 0; $i < $n; $i++) {
            $stmt->execute([
                ':me1' => $level,
                ':me2' => "errore di prova #$i",
                ':me3' => $fingerprint,
                ':me4' => 'RuntimeException',
                ':me5' => $hoursAgo,
            ]);
        }
    }

    public function testFingerprintSopraSogliaVieneRilevato(): void
    {
        $fp = str_repeat('a', 40);
        $this->insertRows($fp, 6, 'error', 1); // 6 > soglia 5

        $svc = new MaintenanceService($this->pdo);
        $spikes = $svc->detectErrorSpikes(5);

        $this->assertCount(1, $spikes);
        $this->assertSame($fp, $spikes[0]['fingerprint']);
        $this->assertSame(6, $spikes[0]['count']);
        $this->assertSame('RuntimeException', $spikes[0]['exception_class']);
    }

    public function testSottoSogliaNonVieneRilevato(): void
    {
        $fp = str_repeat('b', 40);
        $this->insertRows($fp, 5, 'error', 1); // 5, soglia 5 → HAVING c > 5 esclude

        $svc = new MaintenanceService($this->pdo);
        $this->assertSame([], $svc->detectErrorSpikes(5));
    }

    public function testFuoriFinestra24hNonVieneRilevato(): void
    {
        $fp = str_repeat('c', 40);
        $this->insertRows($fp, 10, 'error', 25); // 25h fa, fuori dalla finestra

        $svc = new MaintenanceService($this->pdo);
        $this->assertSame([], $svc->detectErrorSpikes(5));
    }

    public function testAltroLevelNonVieneRilevato(): void
    {
        // 'warning' è un livello REALE che il Logger persiste, ma è ESCLUSO dall'alert di proposito
        // (scope = errori applicativi): uno spike di warning non deve scattare.
        $fp = str_repeat('d', 40);
        $this->insertRows($fp, 10, 'warning', 1);

        $svc = new MaintenanceService($this->pdo);
        $this->assertSame([], $svc->detectErrorSpikes(5), 'i warning sono esclusi dall\'alert errori');
    }

    public function testSogliaZeroORicadeSulDefault(): void
    {
        // Con soglia <= 0 il clamp difensivo ricade sul default (50): 10 righe restano sotto e non scattano.
        $fp = str_repeat('f', 40);
        $this->insertRows($fp, 10, 'error', 1);

        $svc = new MaintenanceService($this->pdo);
        $this->assertSame([], $svc->detectErrorSpikes(0), 'soglia 0 deve ricadere sul default, non scattare su tutto');
        $this->assertSame([], $svc->detectErrorSpikes(-5), 'soglia negativa deve ricadere sul default');
    }

    public function testPiuFingerprintOrdinatiPerCountDiscendente(): void
    {
        $fpAlto = str_repeat('1', 40);
        $fpBasso = str_repeat('2', 40);
        $this->insertRows($fpAlto, 20, 'error', 1);
        $this->insertRows($fpBasso, 8, 'error', 1);

        $svc = new MaintenanceService($this->pdo);
        $spikes = $svc->detectErrorSpikes(5);

        $this->assertCount(2, $spikes);
        $this->assertSame($fpAlto, $spikes[0]['fingerprint']);
        $this->assertSame($fpBasso, $spikes[1]['fingerprint']);
    }
}
