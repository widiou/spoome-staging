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

    /** Tutti gli atleti (id DESC). */
    public function getAll(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM athletes ORDER BY id DESC');
        $stmt->execute();

        return $this->hydrateAll($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Ricerca LIKE su una colonna ($field deve essere whitelisted dal chiamante). */
    public function search(string $field, string $value): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM athletes WHERE $field LIKE :value ORDER BY birthyear DESC LIMIT 25");
        $stmt->execute(['value' => "%$value%"]);

        return $this->hydrateAll($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Listing filtrato (sport/luogo/giorno/attività) con paginazione opzionale. */
    public function getLastTen($sport = '', $place = '', $day = '', $activity = '', $page = null, $pageSize = 30): array
    {
        if (\str_contains((string) $day, '1 ')) {
            $day = \str_replace('1 ', '1º ', \trim($day));
        }
        $excludePH = 'https://spoome.it/agenzia/wp-content/uploads/2024/05/cropped-favicon-270x270.png';

        $query  = "SELECT * FROM athletes WHERE athletes.nationality like 'ital%' and athletes.surname != '' and athletes.photo != '' and athletes.photo != :excludePath and birthyear != ''";
        $params = ['excludePath' => $excludePH];

        if ($sport) {
            if ($sport == 'Sport Invernali') {
                $winterSportsDisciplines = [
                    'Sci alpino', 'Sci nordico', 'Biathlon', 'Bob', 'Slittino', 'Skeleton',
                    'Freestyle', 'Snowboard', 'Sci alpinismo', 'Sci d\'erba',
                    'Sci di velocità', 'Carving', 'Slittino su pista naturale'
                ];
                $disciplinePlaceholders = [];
                foreach ($winterSportsDisciplines as $index => $discipline) {
                    $disciplinePlaceholders[] = ':discipline' . $index;
                    $params['discipline' . $index] = $discipline;
                }
                $query .= ' AND athletes.sport IN (' . \implode(', ', $disciplinePlaceholders) . ')';
            } else {
                $sport .= '%';
                $query .= ' and athletes.sport like :sport';
                $params['sport'] = $sport;
            }
        } elseif ($place) {
            $query .= ' and athletes.birthplace = :place';
            $params['place'] = $place;
        } elseif ($day) {
            $query .= ' and athletes.birthdate like :day';
            $params['day'] = $day;
        } elseif ($activity) {
            $query .= ' and athletes.activity like :activity';
            $params['activity'] = $activity;
        }

        $query .= ' ORDER BY RAND() DESC';

        if ($page !== null) {
            $offset = ($page - 1) * $pageSize;
            $query .= ' LIMIT :offset, :pageSize';
            $params['offset']   = $offset;
            $params['pageSize'] = $pageSize;
        } else {
            $query .= ' LIMIT 30';
        }

        $stmt = $this->pdo->prepare($query);
        foreach ($params as $key => &$val) {
            if ($key === 'offset' || $key === 'pageSize') {
                $stmt->bindParam($key, $val, PDO::PARAM_INT);
            } else {
                $stmt->bindParam($key, $val);
            }
        }
        unset($val);
        $stmt->execute();

        return $this->hydrateAll($stmt->fetchAll());
    }

    /** 6 atleti (filtrabili per sport/attività) per i widget "profili simili/in evidenza". */
    public function getRandom6($sport = '', $activity = ''): array
    {
        if ($sport) {
            if ($activity) {
                $query = "
    SELECT *
    FROM athletes
    WHERE athletes.photo != '' AND (activity = :activity OR sport = :sport)
    ORDER BY
        IF(activity = :orderActivity, 2, 1)
    LIMIT 6
";
                $stmt = $this->pdo->prepare($query);
                $stmt->execute([
                    'activity'      => $activity,
                    'sport'         => $sport,
                    'orderActivity' => $activity,
                ]);
            } else {
                $sport .= '%';
                $query = "SELECT * FROM athletes where athletes.photo != '' and sport like :sport  ORDER BY birthyear DESC LIMIT 6";
                $stmt  = $this->pdo->prepare($query);
                $stmt->execute(['sport' => $sport]);
            }
        } else {
            $query = "SELECT * FROM athletes where athletes.photo != ''  ORDER BY birthyear DESC LIMIT 6";
            $stmt  = $this->pdo->prepare($query);
            $stmt->execute();
        }

        return $this->hydrateAll($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** 6 atleti citati in un evento (match sulla bio) oppure fallback recenti. */
    public function getAthletesByEvent($event = ''): array
    {
        if ($event) {
            $event = '% ' . $event . ' %';
            $query = "
    SELECT *
    FROM athletes
    WHERE athletes.photo != '' and athletes.bio like :event
    ORDER BY RAND()
    LIMIT 6
";
            $stmt = $this->pdo->prepare($query);
            $stmt->execute(['event' => $event]);
        } else {
            $query = "SELECT * FROM athletes where athletes.photo != ''  ORDER BY birthyear DESC LIMIT 6";
            $stmt  = $this->pdo->prepare($query);
            $stmt->execute();
        }

        return $this->hydrateAll($stmt->fetchAll(PDO::FETCH_ASSOC));
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
