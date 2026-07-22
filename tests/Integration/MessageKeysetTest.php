<?php

declare(strict_types=1);

namespace Spoome\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Spoome\Domain\Messaging\MessageRepository;

/**
 * Test d'integrazione: richiede un DB MySQL usa-e-getta via env SPOOME_TEST_DSN
 * (come MaintenanceServiceTest). Skippa se non configurato — NON tocca mai il DB di produzione.
 *
 * Verifica la seek/keyset pagination del thread DM (issue #9): MessageRepository::threadBefore().
 * Obiettivi:
 *  - parità di risultati col vecchio percorso OFFSET (stesso ordine, stesse righe, nessun buco/duplicato);
 *  - correttezza del cursore (id < :before) al confine di pagina;
 *  - assenza di HY093: il PDO è in EMULATE_PREPARES=false (come in produzione), dove i named placeholder
 *    non sono riusabili — il test fallirebbe se threadBefore riusasse un placeholder.
 */
final class MessageKeysetTest extends TestCase
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
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Tabella minima e autosufficiente (niente FK): threadBefore fa solo SELECT su queste colonne.
        $this->pdo->exec('DROP TABLE IF EXISTS messages');
        $this->pdo->exec("CREATE TABLE messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT NOT NULL,
            sender_id INT NOT NULL,
            body VARCHAR(4000) NOT NULL,
            read_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_msg_conv (conversation_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function seed(int $conversationId, int $n): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO messages (conversation_id, sender_id, body) VALUES (:c, :s, :b)'
        );
        for ($i = 1; $i <= $n; $i++) {
            $stmt->execute([':c' => $conversationId, ':s' => 1, ':b' => "msg #$i"]);
        }
    }

    /** @param array<int,array> $rows @return int[] */
    private function ids(array $rows): array
    {
        return array_map(static fn ($r) => (int) $r['id'], $rows);
    }

    public function testFirstPageMatchesOffsetZero(): void
    {
        $this->seed(10, 25);
        $repo = new MessageRepository($this->pdo);

        $keyset = $repo->threadBefore(10, null, 10);
        $offset = $repo->thread(10, 10, 0);

        $this->assertSame($this->ids($offset), $this->ids($keyset), 'La prima pagina keyset deve coincidere con OFFSET 0.');
        $this->assertCount(10, $keyset);
        // Ordine più-recenti-prima: id strettamente decrescenti (25, 24, …, 16).
        $ids = $this->ids($keyset);
        $sorted = $ids;
        rsort($sorted);
        $this->assertSame($sorted, $ids, 'La prima pagina deve essere in ordine id DESC.');
        $this->assertSame(range(25, 16), $ids);
    }

    public function testCursorPaginationCoversAllRowsWithoutGapOrDuplicate(): void
    {
        $this->seed(10, 25);
        // Rumore in un'altra conversazione: non deve mai comparire (isolamento del cursore per conversazione).
        $this->seed(99, 5);
        $repo = new MessageRepository($this->pdo);

        $collected = [];
        $before = null;
        $pages = 0;
        do {
            $rows = $repo->threadBefore(10, $before, 10);
            foreach ($rows as $r) {
                $collected[] = (int) $r['id'];
            }
            $before = $rows === [] ? null : (int) $rows[count($rows) - 1]['id'];
            $pages++;
            $this->assertLessThan(10, $pages, 'Loop di paginazione non terminato.');
        } while (count($rows) === 10);

        // 25 righe della conv 10, tutte una volta sola, in ordine id DESC continuo.
        $this->assertCount(25, $collected);
        $this->assertSame(count($collected), count(array_unique($collected)), 'Nessun id duplicato tra le pagine.');
        $expected = range(25, 1); // id 25..1 (conv 10 = prime 25 righe inserite)
        $this->assertSame($expected, $collected, 'Copertura completa e ordinata, nessun buco al confine di pagina.');
    }

    public function testCursorEquivalentToOffsetPageByPage(): void
    {
        $this->seed(10, 23);
        $repo = new MessageRepository($this->pdo);

        $before = null;
        for ($page = 0; $page < 3; $page++) {
            $keyset = $repo->threadBefore(10, $before, 10);
            $offset = $repo->thread(10, 10, $page * 10);
            $this->assertSame($this->ids($offset), $this->ids($keyset), "Pagina $page: keyset deve eguagliare OFFSET.");
            if ($keyset === []) {
                break;
            }
            $before = (int) $keyset[count($keyset) - 1]['id'];
        }
    }
}
