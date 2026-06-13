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

    /** Luoghi di nascita distinti con conteggio (filtri ricerca). */
    public function getAllPlaces($per_page = 20, $page = 1, $query = ''): array
    {
        $offset = $page == 0 ? 0 : ($page) * $per_page;
        $stmt = $this->pdo->prepare("SELECT DISTINCT birthplace as place, COUNT(*) as total FROM athletes WHERE trim(birthplace) != '' AND trim(birthplace) LIKE :q GROUP BY birthplace ORDER BY total desc LIMIT :limit OFFSET :offset");
        $like = '%' . $query . '%';
        $stmt->bindParam(':q', $like);
        $stmt->bindParam(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Sport con conteggio. $type: ''=top20, 'ALL'=paginati, 'MENU'=>20 atleti. */
    public function getAllSports($type = '', $per_page = 20, $page = 1): array
    {
        if ($type === 'ALL') {
            $offset = $page == 0 ? 0 : ($page) * $per_page;
            $stmt = $this->pdo->prepare("SELECT sport, COUNT(*) as athlete_count FROM athletes WHERE athletes.sport IS NOT NULL GROUP BY sport ORDER BY athlete_count desc LIMIT :limit OFFSET :offset");
            $stmt->bindParam(':limit', $per_page, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
        } elseif ($type === 'MENU') {
            $stmt = $this->pdo->prepare("SELECT sport, COUNT(*) as athlete_count FROM athletes WHERE athletes.sport IS NOT NULL GROUP BY sport HAVING COUNT(*) > 20 ORDER BY athlete_count DESC");
            $stmt->execute();
        } else {
            $stmt = $this->pdo->prepare("SELECT sport, COUNT(*) as athlete_count FROM athletes WHERE athletes.sport IS NOT NULL GROUP BY sport ORDER BY athlete_count DESC LIMIT 20");
            $stmt->execute();
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllActivities($type = ''): array
    {
        $sql = $type === 'ALL'
            ? "SELECT activity, COUNT(*) as athlete_count FROM athletes WHERE athletes.activity IS NOT NULL GROUP BY activity ORDER BY activity"
            : "SELECT activity, COUNT(*) as athlete_count FROM athletes WHERE athletes.activity IS NOT NULL GROUP BY activity ORDER BY athlete_count DESC LIMIT 20";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllYears($type = ''): array
    {
        $sql = $type === 'ALL'
            ? "SELECT birthyear, COUNT(*) as athlete_count FROM athletes WHERE athletes.birthyear IS NOT NULL GROUP BY birthyear ORDER BY birthyear"
            : "SELECT birthyear, COUNT(*) as athlete_count FROM athletes WHERE athletes.birthyear IS NOT NULL GROUP BY birthyear ORDER BY athlete_count DESC LIMIT 20";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllNationality($type = ''): array
    {
        $sql = $type === 'ALL'
            ? "SELECT nationality, COUNT(*) as athlete_count FROM athletes WHERE athletes.nationality IS NOT NULL GROUP BY nationality ORDER BY athlete_count DESC"
            : "SELECT nationality, COUNT(*) as athlete_count FROM athletes WHERE athletes.nationality IS NOT NULL GROUP BY nationality ORDER BY athlete_count DESC LIMIT 30";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Ricerca avanzata multi-criterio (tutti i valori bound). @return \Athlete[] */
    public function advancedSearch($title = '', $sport = '', $activity = '', $nationality = '', $birthplace = '', $year = '', $sex = ''): array
    {
        $query  = 'SELECT * FROM athletes WHERE 1=1';
        $params = [];
        if ($title !== '')       { $query .= ' AND title LIKE :title';             $params[':title'] = "%$title%"; }
        if ($sport !== '')       { $query .= ' AND sport LIKE :sport';             $params[':sport'] = "%$sport%"; }
        if ($activity !== '')    { $query .= ' AND activity LIKE :activity';       $params[':activity'] = "%$activity%"; }
        if ($nationality !== '') { $query .= ' AND nationality LIKE :nationality'; $params[':nationality'] = "%$nationality%"; }
        if ($birthplace !== '')  { $query .= ' AND birthplace LIKE :birthplace';   $params[':birthplace'] = "%$birthplace%"; }
        if ($year !== '')        { $query .= ' AND birthyear = :year';             $params[':year'] = $year; }
        if ($sex !== '')         { $query .= ' AND sex = :sex';                    $params[':sex'] = $sex; }

        $stmt = $this->pdo->prepare($query . ' ORDER BY title LIMIT 30');
        $stmt->execute($params);

        return $this->hydrateAll($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Ricerca per titolo (campi ridotti). Comportamento legacy: offset di fatto 0. */
    public function fetchAthleteFromDatabase($value, int $per_page = 20, int $page = 1): false|array
    {
        if (!$value) {
            return false;
        }
        $offset = 0;
        $stmt = $this->pdo->prepare('SELECT id, title, photo, sport FROM athletes WHERE title LIKE :value LIMIT :limit OFFSET :offset');
        $like = '%' . $value . '%';
        $stmt->bindParam(':value', $like);
        $stmt->bindParam(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Ricerca per colonna whitelisted (= per birthdate, LIKE altrimenti). Valore bound. */
    public function simpleSearchByAttribute(string $property, $value, int $per_page = 20, int $page = 1): array
    {
        $col = $this->column($property);
        if ($col === null) {
            return [];
        }
        $offset = $page == 0 ? 0 : ($page) * $per_page;
        $fields = 'id, title, photo, name, surname, birthplace, birthdate, birthyear, activity, nationality, sport';
        if ($col === 'birthdate') {
            $sql = "SELECT $fields FROM athletes WHERE $col = :value LIMIT :limit OFFSET :offset";
            $val = $value;
        } else {
            $sql = "SELECT $fields FROM athletes WHERE $col LIKE :value LIMIT :limit OFFSET :offset";
            $val = '%' . $value . '%';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':value', $val);
        $stmt->bindParam(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Atleti con foto per colonna whitelisted LIKE valore. @return \Athlete[] */
    public function getAthletesByProperty(string $property, $value): array
    {
        $col = $this->column($property);
        if (!$value || $col === null) {
            return [];
        }
        $stmt = $this->pdo->prepare("SELECT * FROM athletes WHERE athletes.photo != '' AND $col LIKE :value ORDER BY birthyear desc LIMIT 48");
        $stmt->execute(['value' => '%' . $value . '%']);
        return $this->hydrateAll($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Conteggio atleti, opzionalmente filtrato per colonna whitelisted = valore. */
    public function getTotAthletes(string $property = '', $value = ''): int
    {
        if ($property === '') {
            $stmt = $this->pdo->prepare('SELECT count(*) as totAthletes FROM athletes');
            $stmt->execute();
        } else {
            $col = $this->column($property);
            if ($col === null) {
                return 0;
            }
            $stmt = $this->pdo->prepare("SELECT count(*) as totAthletes FROM athletes WHERE $col = :value");
            $stmt->execute(['value' => $value]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($row['totAthletes'] ?? 0);
    }

    /** Autocomplete nomi: [id, title]. */
    public function searchByName(string $term, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare('SELECT id, title FROM athletes WHERE title LIKE :term ORDER BY title ASC LIMIT :limit');
        $like = '%' . $term . '%';
        $stmt->bindParam(':term', $like, PDO::PARAM_STR);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Autocomplete: valori distinti di una colonna whitelisted. */
    public function searchAttribute(string $attribute, string $term): array
    {
        $col = $this->column($attribute);
        if ($col === null) {
            return [];
        }
        $stmt = $this->pdo->prepare("SELECT DISTINCT $col FROM athletes WHERE $col LIKE :term LIMIT 10");
        $stmt->execute(['term' => '%' . $term . '%']);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Aggiorna bio e ne rinnova la scadenza (+5 giorni). */
    public function updateBio(string $bio, int $id): void
    {
        $expire = \date('Y-m-d H:i:s', \strtotime('+5 days'));
        $stmt = $this->pdo->prepare('UPDATE athletes SET bio = :bio, expire = :expire WHERE id = :id');
        if (!$stmt->execute(['bio' => $bio, 'expire' => $expire, 'id' => $id])) {
            \Spoome\Core\Logger::error('Aggiornamento bio fallito', ['id' => $id]);
            throw new \RuntimeException('Aggiornamento bio fallito per atleta ' . $id);
        }
    }

    /** Colonne consentite per l'interpolazione sicura dei nomi (anti-SQLi). */
    private const COLUMNS = [
        'id', 'title', 'photo', 'name', 'surname', 'birthplace', 'birthdate',
        'birthyear', 'activity', 'nationality', 'bio', 'sport', 'sex',
        'instagram', 'facebook', 'twitter', 'linkedin', 'website', 'query',
        'latitude', 'longitude', 'expire',
    ];

    private function column(string $name): ?string
    {
        return \in_array($name, self::COLUMNS, true) ? $name : null;
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
