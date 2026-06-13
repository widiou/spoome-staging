<?php

require 'vendor/autoload.php';
require_once 'db/Database.php';
require_once 'settings/default.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * @property mixed|string $title
 * @property mixed|string $photo
 * @property mixed|string $name
 * @property mixed|string $surname
 * @property mixed|string $birthplace
 * @property mixed|string $birthdate
 * @property mixed|string $birthyear
 * @property mixed|string $activity
 * @property mixed|string $nationality
 * @property mixed|string $bio
 * @property mixed|string $sport
 * @property mixed|string $sex
 * @property mixed|string $instagram
 * @property mixed|string $facebook
 * @property mixed|string $twitter
 * @property mixed|string $linkedin
 * @property mixed|string $website
 * @property mixed|string $query
 * @property mixed $expire
 */

class Athlete
{
    // === PROPERTIES ===
    private ?int $id = null;
    private array $fields = [];

    // === MAGIC METHODS ===
    public function __get(string $name): mixed
    {
        return $this->fields[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->fields[$name] = $value;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    /**
     * Popola l'entità da una riga DB (id + campi). Usato dal repository.
     */
    public function hydrate(array $row): static
    {
        $this->id = isset($row['id']) ? (int) $row['id'] : null;
        $this->fields = $row;
        return $this;
    }

    /** Tutti i campi dell'entità (usato dal repository per il save). */
    public function getFields(): array
    {
        return $this->fields;
    }

    // === SAVE METHODS ===

    /**
     * @throws Exception
     */
    public function save(): void
    {
        (new \Spoome\Athletes\AthleteRepository())->save($this);
    }

    /**
     * @throws Exception
     */
    public function savePhotoToServer(string $photoUrl, int $id): void
    {
        (new \Spoome\Services\AthleteImage())->store($photoUrl, $id);
    }

    public function updateBio(string $bio, int $id): void
    {
        (new \Spoome\Athletes\AthleteRepository())->updateBio($bio, $id);
    }

    // === STATIC DELETE METHODS ===
    public static function deleteAthlete(int $athleteId): bool
    {
        $connection = Database::getInstance()->getConnection();

        // Recupera il percorso della cartella delle foto del profilo
        $query = "SELECT photo FROM athletes WHERE id = :id";
        $stmt = $connection->prepare($query);
        $stmt->execute(['id' => $athleteId]);
        $athlete = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$athlete) {
            return false; // Atleta non trovato
        }

        $photoUrl = $athlete['photo'];
        $photoPath = $_SERVER['DOCUMENT_ROOT'] . parse_url($photoUrl, PHP_URL_PATH);
        $directoryPath = dirname($photoPath); // Ottiene la directory senza il file specifico

        // Inizia una transazione
        $connection->beginTransaction();

        try {
            // Cancella l'atleta dalla tabella
            $deleteQuery = "DELETE FROM athletes WHERE id = :id";
            $deleteStmt = $connection->prepare($deleteQuery);
            $deleteStmt->execute(['id' => $athleteId]);

            // Cancella la cartella con le foto del profilo
            self::deleteDirectory($directoryPath);

            // Commit della transazione
            $connection->commit();

            return true;
        } catch (Exception $e) {
            // Rollback della transazione in caso di errore
            $connection->rollBack();
            error_log("Errore durante la cancellazione dell'atleta: " . $e->getMessage());
            return false;
        }
    }

    private static function deleteDirectory(string $dirPath): void
    {
        if (!is_dir($dirPath)) {
            error_log("Directory non trovata: $dirPath");
            return;
        }
        $files = array_diff(scandir($dirPath), ['.', '..']);
        foreach ($files as $file) {
            $filePath = "$dirPath/$file";
            if (is_dir($filePath)) {
                self::deleteDirectory($filePath);
            } else {
                if (unlink($filePath)) {
                    error_log("File cancellato: $filePath");
                } else {
                    error_log("Errore durante la cancellazione del file: $filePath");
                }
            }
        }
        if (rmdir($dirPath)) {
            error_log("Directory cancellata: $dirPath");
        } else {
            error_log("Errore durante la cancellazione della directory: $dirPath");
        }
    }

    public static function findById(string $id): ?Athlete
    {
        return (new \Spoome\Athletes\AthleteRepository())->findById($id);
    }

    public static function findByTitle(string $title): ?Athlete
    {
        return (new \Spoome\Athletes\AthleteRepository())->findByTitle($title);
    }

    public static function getAll(): array
    {
        return (new \Spoome\Athletes\AthleteRepository())->getAll();
    }

    public static function getLast24(): array
    {
        return (new \Spoome\Athletes\AthleteRepository())->getLast24();
    }

    public static function search(string $field, string $value): array
    {
        return (new \Spoome\Athletes\AthleteRepository())->search($field, $value);
    }

    public static function getLastTen($sport = '', $place = '', $day = '', $activity = '', $page = null, $pageSize = 30): array
    {
        return (new \Spoome\Athletes\AthleteRepository())->getLastTen($sport, $place, $day, $activity, $page, $pageSize);
    }

    public static function getRandom6($sport = '', $activity = ''): array
    {
        return (new \Spoome\Athletes\AthleteRepository())->getRandom6($sport, $activity);
    }

    public static function getAthletesByEvent($event = ''): array
    {
        return (new \Spoome\Athletes\AthleteRepository())->getAthletesByEvent($event);
    }

    public static function getRandom(): ?Athlete
    {
        return (new \Spoome\Athletes\AthleteRepository())->getRandom();
    }

    public static function getTopTenSports(): array
    {
        return (new \Spoome\Athletes\AthleteRepository())->getTopTenSports();
    }

    public static function fetchAthleteFromDatabase($value, $per_page = 20, $page = 1): false|array
    {
        return (new \Spoome\Athletes\AthleteRepository())->fetchAthleteFromDatabase($value, $per_page, $page);
    }

    public static function simpleSearchByAttribute($property, $value, $per_page = 20, $page = 1): array
    {
        return (new \Spoome\Athletes\AthleteRepository())->simpleSearchByAttribute($property, $value, $per_page, $page);
    }

    public static function getAllPlaces($per_page = 20, $page = 1, $query = ''): array
    {
        return (new \Spoome\Athletes\AthleteRepository())->getAllPlaces($per_page, $page, $query);
    }

    public static function getAllSports($type = '', $per_page = 20, $page = 1): array
    {
        return (new \Spoome\Athletes\AthleteRepository())->getAllSports($type, $per_page, $page);
    }

    public static function getAllActivities($type = ''): array
    {
        return (new \Spoome\Athletes\AthleteRepository())->getAllActivities($type);
    }

    public static function getAllYears($type = ''): array
    {
        return (new \Spoome\Athletes\AthleteRepository())->getAllYears($type);
    }

    public static function getAllNationality($type = ''): array
    {
        return (new \Spoome\Athletes\AthleteRepository())->getAllNationality($type);
    }

    public static function getAthletesByProperty($property, $value): array
    {
        return (new \Spoome\Athletes\AthleteRepository())->getAthletesByProperty($property, $value);
    }

    public static function getTotAthletes($property = '', $value = '')
    {
        return (new \Spoome\Athletes\AthleteRepository())->getTotAthletes($property, $value);
    }

    public static function searchSport($term)
    {
        return (new \Spoome\Athletes\AthleteRepository())->searchAttribute('sport', $term);
    }

    public static function searchActivity($term)
    {
        return (new \Spoome\Athletes\AthleteRepository())->searchAttribute('activity', $term);
    }

    public static function searchYear($term)
    {
        return (new \Spoome\Athletes\AthleteRepository())->searchAttribute('birthyear', $term);
    }

    public static function searchNationality($term)
    {
        return (new \Spoome\Athletes\AthleteRepository())->searchAttribute('nationality', $term);
    }

    public static function searchBirthplace($term)
    {
        return (new \Spoome\Athletes\AthleteRepository())->searchAttribute('birthplace', $term);
    }

    public static function advancedSearch($title = '', $sport = '', $activity = '', $nationality = '', $birthplace = '', $year = '', $sex = '')
    {
        return (new \Spoome\Athletes\AthleteRepository())->advancedSearch($title, $sport, $activity, $nationality, $birthplace, $year, $sex);
    }

    public static function searchByName(string $term, int $limit = 10): array
    {
        return (new \Spoome\Athletes\AthleteRepository())->searchByName($term, $limit);
    }

    public static function insertInLog(string $query): void
    {
        \Spoome\Services\SearchLog::record($query);
    }
}



