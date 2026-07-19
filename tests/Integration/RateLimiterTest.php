<?php

declare(strict_types=1);

namespace Spoome\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Spoome\Domain\Auth\RateLimiter;

/**
 * Test d'integrazione: richiede un DB MySQL usa-e-getta via env SPOOME_TEST_DSN
 * (es. "mysql:host=127.0.0.1;dbname=spoome_test", SPOOME_TEST_USER, SPOOME_TEST_PASS).
 * Skippa se non configurato — NON tocca mai il DB di produzione.
 */
final class RateLimiterTest extends TestCase
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
        ]);
        $this->pdo->exec('DROP TABLE IF EXISTS login_attempts');
        $this->pdo->exec('CREATE TABLE login_attempts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(190) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            successful TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }

    public function testThrottleByKeyTripsAtThreshold(): void
    {
        $rl = new RateLimiter($this->pdo);
        $key = 'claim:1.2.3.4';
        for ($i = 0; $i < 4; $i++) {
            $this->assertFalse($rl->tooManyByKey($key, 5, 60), "sotto soglia al colpo $i");
            $rl->hit($key, '1.2.3.4');
        }
        $rl->hit($key, '1.2.3.4'); // 5° colpo
        $this->assertTrue($rl->tooManyByKey($key, 5, 60), 'deve scattare a 5');
    }

    public function testLoginBlockIsPerIpNotEmail(): void
    {
        // I "colpi" del throttle generico (identifier con ':') non devono contare come login falliti per IP.
        $rl = new RateLimiter($this->pdo);
        for ($i = 0; $i < 10; $i++) {
            $rl->hit('pwf:ip:9.9.9.9', '9.9.9.9');
        }
        $this->assertFalse($rl->tooManyByIp('9.9.9.9', 5, 15), 'i colpi generici non lockano il login per IP');
    }
}
