<?php

namespace Spoome\Athletes;

use PDO;
use Spoome\Core\Logger;

/**
 * Accesso ai dati delle schede enciclopediche (tabella `athletes`).
 * Primo passo per smontare il god object models/Athlete.php: i metodi statici
 * legacy (findById/findByTitle) delegano qui. Per ora ritorna oggetti \Athlete
 * (entità legacy) per non rompere i consumatori.
 */
final class AthleteRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? \Database::getInstance()->getConnection();
    }

    public function findById(string|int $id): ?\Athlete
    {
        $stmt = $this->pdo->prepare('SELECT * FROM athletes WHERE athletes.id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrateWithPhoto($row) : null;
    }

    public function findByTitle(string $title): ?\Athlete
    {
        $stmt = $this->pdo->prepare('SELECT * FROM athletes WHERE athletes.title = :title LIMIT 1');
        $stmt->execute(['title' => $title]);
        $row = $stmt->fetch();

        return $row ? $this->hydrateWithPhoto($row) : null;
    }

    /** Ultimi profili inseriti (home). */
    public function getLast24(): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM athletes WHERE athletes.sport != '' ORDER BY id DESC LIMIT 12");
        $stmt->execute();

        return $this->hydrateAll($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Un atleta casuale con foto, nazionalità e bio significativa. */
    public function getRandom(): ?\Athlete
    {
        $stmt = $this->pdo->prepare("SELECT * FROM athletes WHERE athletes.photo != '' AND nationality != '' AND LENGTH(athletes.bio) > 250 ORDER BY RAND() DESC LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ? (new \Athlete())->hydrate($row) : null;
    }

    /** Conteggio atleti per sport (top 8). Ritorna righe grezze [sport, athlete_count]. */
    public function getTopTenSports(): array
    {
        $stmt = $this->pdo->prepare("SELECT sport, COUNT(*) as athlete_count FROM athletes WHERE athletes.sport IS NOT NULL GROUP BY sport ORDER BY athlete_count DESC LIMIT 8");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return \Athlete[] */
    private function hydrateAll(array $rows): array
    {
        return \array_map(static fn(array $row): \Athlete => (new \Athlete())->hydrate($row), $rows);
    }

    /**
     * Idrata un'entità \Athlete dalla riga DB e, se la foto punta ancora a
     * Wikimedia, prova a scaricarla in locale (comportamento legacy preservato).
     */
    private function hydrateWithPhoto(array $row): ?\Athlete
    {
        $athlete = (new \Athlete())->hydrate($row);

        if (\str_contains((string) $athlete->photo, 'wikimedia.org')) {
            try {
                $athlete->savePhotoToServer($athlete->photo, $athlete->getId());
            } catch (\Throwable $e) {
                Logger::error('Aggiornamento foto atleta fallito', [
                    'id'  => $athlete->getId(),
                    'err' => $e->getMessage(),
                ]);
                return null;
            }
        }

        return $athlete;
    }
}
