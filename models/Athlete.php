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
    private PDO $connection;
    private ?int $id = null;
    private array $fields = [];

    // === CONSTRUCTOR ===
    public function __construct()
    {
        $this->connection = Database::getInstance()->getConnection();
    }

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

    // === SAVE METHODS ===

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function save(): void
    {

        if (empty($this->fields['title']) || empty($this->fields['sport'])) {
            throw new Exception("Dati insufficienti: 'title' e 'sport' sono obbligatori.");
        }
        $this->fields['expire'] = date('Y-m-d H:i:s', strtotime('+5 days'));
        $columns = implode(", ", array_keys($this->fields));
        $placeholders = implode(", ", array_map(fn($key) => ":$key", array_keys($this->fields)));
        $exist = self::findByTitle($this->fields['title']);
        if ($exist === null) {
            $query = "INSERT INTO athletes ($columns) VALUES ($placeholders)";
        } else {
            $sets = implode(", ", array_map(fn($key) => "$key = :$key", array_keys($this->fields)));
            $this->fields['id'] = $this->id;
            $query = "UPDATE athletes SET $sets WHERE id = :id";
        }
        $stmt = $this->connection->prepare($query);
        $stmt->execute($this->fields);
        if ($this->id === null) {
            $this->id = (int)$this->connection->lastInsertId();
        }
        if (empty($this->fields['photo']) || !str_contains($this->fields['photo'], "/assets/profile")) {
            $this->fields['photo'] = SQUARE_PLACEHOLDER;
        }
        if (!empty($this->fields['photo']) && $this->fields['photo'] !== SQUARE_PLACEHOLDER) {
            try {
                $this->savePhotoToServer($this->fields['photo'], $this->id);
            } catch (Exception $e) {
                error_log("Errore durante il salvataggio della foto: " . $e->getMessage());
            }
        }
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function savePhotoToServer(string $photoUrl, int $id): void
    {
        $subDir = substr($id, 0, 2);
        $relativeDirectoryPath = SUB_ROOT . "/assets/profile/$subDir/$id";
        $directoryPath = $_SERVER['DOCUMENT_ROOT'] . $relativeDirectoryPath;
        $photoPath = "$directoryPath/$id.webp";
        $relativePhotoPath = "$relativeDirectoryPath/$id.webp";
        $absolutePhotoPath = $relativePhotoPath;

        if (!is_dir($directoryPath)) {
            if (!mkdir($directoryPath, 0755, true) && !is_dir($directoryPath)) {
                error_log("Errore: impossibile creare la directory: $directoryPath");
                return;
            }
        }

        $client = new Client();
        try {

            $response = $client->get($photoUrl, [
                "timeout" => 5,
                "connect_timeout" => 5,
            ]);
            if ($response->getStatusCode() !== 200) {
                return;
            }

            $imageContent = $response->getBody()->getContents();
        } catch (Exception $e) {
            return;
        }

        $sourceImage = imagecreatefromstring($imageContent);
        if ($sourceImage === false) {
            error_log("Errore nella creazione dell'immagine GD.");
            return;
        }

        // Ridimensionamento
        $maxWidth = 800;
        $width = imagesx($sourceImage);
        $height = imagesy($sourceImage);
        $newWidth = ($width > $maxWidth) ? $maxWidth : $width;
        $newHeight = intval(($height / $width) * $newWidth);

        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($sourceImage);

        // Salva in formato WebP
        if (!imagewebp($resizedImage, $photoPath, 80)) {
            error_log("Errore nel salvataggio dell'immagine WebP.");
        }
        imagedestroy($resizedImage);

        if (file_exists($photoPath)) {
            $this->updatePhotoPath($absolutePhotoPath, $id);
        } else {
            error_log("Errore: il file WebP non è stato creato correttamente.");
        }
    }

    /**
     * @throws Exception
     */
    private function updatePhotoPath(string $photoPath, int $id): void
    {
        $photoPath = str_replace(SUB_ROOT, '', $photoPath);
        $updateQuery = "UPDATE athletes SET photo = :photoPath WHERE id = :id";
        $stmt = $this->connection->prepare($updateQuery);
        if (!$stmt->execute(['photoPath' => $photoPath, 'id' => $id])) {
            error_log("Failed to update photo path in database.");
            throw new Exception("Failed to update photo path in database.");
        }
    }

    private function isTitleUnique(string $title, ?int $id = null): bool
    {
        $query = "SELECT id FROM athletes WHERE title = :title";
        $params = ['title' => $title];

        if (!is_null($id)) {
            $query .= " AND id != :id";
            $params['id'] = $id;
        }

        $stmt = $this->connection->prepare($query);
        $stmt->execute($params);

        return $stmt->rowCount() === 0;
    }

    public function updateBio(string $bio, int $id): void
    {
        $newExpiring = date('Y-m-d H:i:s', strtotime('+5 days'));
        $updateQuery = "UPDATE athletes SET bio = :bio, expire = :expire WHERE id = :id";
        $stmt = $this->connection->prepare($updateQuery);
        if (!$stmt->execute([
            'bio' => $bio,
            'expire' => $newExpiring,
            'id' => $id])
        ) {
            error_log("Failed to update bio in database.");
            throw new Exception("Failed to bio path in database.");
        }
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
        if (empty($query)) {
            return; // Evitiamo di registrare ricerche vuote
        }

        $conn = Database::getInstance();
        $pdo = $conn->getConnection();

        $userIp = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';

        // Se il User-Agent contiene "bot", interrompiamo l'esecuzione
        if (str_contains(strtolower($userAgent), 'bot')) {
            return; // Non registriamo i bot
        }

        // Recuperiamo il referrer e l'URL attuale della pagina
        $referrer = $_SERVER['HTTP_REFERER'] ?? null;
        $referrerUrl = $referrer ?: ($_SERVER['REQUEST_URI'] ?? 'UNKNOWN');

        $timestamp = (new DateTime())->format('Y-m-d H:i:s');
        $userId = $_SESSION['user_id'] ?? null; // ID utente se disponibile

        // Evitiamo di registrare IP specifici
        if ($userIp == '188.8.28.17' or $userIp == '135.181.177.119') {
            return;
        }

        $stmt = $pdo->prepare("
        INSERT INTO search_log (query, user_ip, user_agent, referrer, referrer_url, search_time, user_id) 
        VALUES (:query, :user_ip, :user_agent, :referrer, :referrer_url, :search_time, :user_id)
    ");

        $stmt->execute([
            ':query' => $query,
            ':user_ip' => $userIp,
            ':user_agent' => $userAgent,
            ':referrer' => $referrer,
            ':referrer_url' => $referrerUrl,
            ':search_time' => $timestamp,
            ':user_id' => $userId
        ]);
    }
}



