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
        if ($value) {
            $connection = Database::getInstance()->getConnection();
            if ($page == 0) {
                $offset = 0;

            }

            $query = "SELECT id, title, photo, sport FROM athletes WHERE title LIKE :value LIMIT :limit OFFSET :offset;";
            $stmt = $connection->prepare($query);
            $likeValue = "%$value%";
            $stmt->bindParam(':value', $likeValue);
            $stmt->bindParam(':limit', $per_page, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            return false;
        }
    }

    public static function simpleSearchByAttribute($property, $value, $per_page = 20, $page = 1): array
    {
        $connection = Database::getInstance()->getConnection();
        if ($page == 0) {
            $offset = 0;
        } else {
            $offset = ($page) * $per_page;
        }
        if ($property == 'birthdate') {
            $query = "SELECT id, title, photo, name, surname, birthplace, 
       birthdate, birthyear, activity, nationality, sport 
FROM athletes 
WHERE $property = '$value'
LIMIT :limit OFFSET :offset;
";
        } else {
            $query = "SELECT id, title, photo, name, surname, birthplace, 
       birthdate, birthyear, activity, nationality, sport 
FROM athletes 
WHERE $property LIKE '%$value%'
LIMIT :limit OFFSET :offset;
";
        }

        $stmt = $connection->prepare($query);
        $stmt->bindParam(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getAllPlaces($per_page = 20, $page = 1, $query = ''): array
    {
        $connection = Database::getInstance()->getConnection();
        if ($page == 0) {
            $offset = 0;
        } else {
            $offset = ($page) * $per_page;
        }
        $query = "
        SELECT DISTINCT birthplace as place, COUNT(*) as total
FROM athletes
where trim(birthplace) != '' and trim(birthplace) like '%$query%'
GROUP BY birthplace
ORDER BY total desc
LIMIT :limit OFFSET :offset;";
        $stmt = $connection->prepare($query);
        $stmt->bindParam(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getAllSports($type = '', $per_page = 20, $page = 1): array
    {
        $connection = Database::getInstance()->getConnection();
        if ($type === 'ALL') {
            if ($page == 0) {
                $offset = 0;
            } else {
                $offset = ($page) * $per_page;
            }

            $query = "
            SELECT sport, COUNT(*) as athlete_count 
            FROM athletes 
            WHERE athletes.sport IS NOT NULL 
            GROUP BY sport 
            ORDER BY athlete_count desc
            LIMIT :limit OFFSET :offset";
            $stmt = $connection->prepare($query);
            $stmt->bindParam(':limit', $per_page, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
        } elseif ($type === 'MENU') {
            $query = "
        SELECT sport, COUNT(*) as athlete_count 
        FROM athletes 
        WHERE athletes.sport IS NOT NULL 
        GROUP BY sport 
        HAVING COUNT(*) > 20
        ORDER BY athlete_count DESC";
            $stmt = $connection->prepare($query);
            $stmt->execute();
        } else {
            $query = "
        SELECT sport, COUNT(*) as athlete_count 
        FROM athletes 
        WHERE athletes.sport IS NOT NULL 
        GROUP BY sport 
        ORDER BY athlete_count DESC
        LIMIT 20";
            $stmt = $connection->prepare($query);
            $stmt->execute();
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getAllActivities($type = ''): array
    {
        $connection = Database::getInstance()->getConnection();
        if ($type === 'ALL') {
            $query = "
        SELECT activity, COUNT(*) as athlete_count 
        FROM athletes 
        WHERE athletes.activity IS NOT NULL 
        GROUP BY activity 
        ORDER BY activity";
        } else {
            $query = "
        SELECT activity, COUNT(*) as athlete_count 
        FROM athletes 
        WHERE athletes.activity IS NOT NULL 
        GROUP BY activity 
        ORDER BY athlete_count DESC
        LIMIT 20";
        }
        $stmt = $connection->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getAllYears($type = ''): array
    {
        $connection = Database::getInstance()->getConnection();
        if ($type === 'ALL') {
            $query = "
        SELECT birthyear, COUNT(*) as athlete_count 
        FROM athletes 
        WHERE athletes.birthyear IS NOT NULL 
        GROUP BY birthyear 
        ORDER BY birthyear";
        } else {
            $query = "
        SELECT birthyear, COUNT(*) as athlete_count 
        FROM athletes 
        WHERE athletes.birthyear IS NOT NULL 
        GROUP BY birthyear 
        ORDER BY athlete_count DESC
        LIMIT 20";
        }
        $stmt = $connection->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getAllNationality($type = ''): array
    {
        $connection = Database::getInstance()->getConnection();
        if ($type === 'ALL') {
            $query = "
        SELECT nationality, COUNT(*) as athlete_count 
        FROM athletes 
        WHERE athletes.nationality IS NOT NULL 
        GROUP BY nationality 
        ORDER BY athlete_count DESC;";
        } else {
            $query = "
        SELECT nationality, COUNT(*) as athlete_count 
        FROM athletes 
        WHERE athletes.nationality IS NOT NULL 
        GROUP BY nationality 
        ORDER BY athlete_count DESC
        LIMIT 30";
        }

        $stmt = $connection->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getAthletesByProperty($property, $value): array
    {
        if (!$value) {
            return [];
        }
        $connection = Database::getInstance()->getConnection();
        $query = "SELECT * FROM athletes where athletes.photo != '' and $property like :value ORDER BY birthyear desc LIMIT 48";
        $stmt = $connection->prepare($query);
        $stmt->execute(['value' => "%" . $value . "%"]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $athletes = [];
        foreach ($results as $row) {
            $athlete = new Athlete();
            $athlete->id = $row['id'];
            $athlete->fields = $row;
            $athletes[] = $athlete;
        }
        return $athletes;
    }

    public static function getTotAthletes($property = '', $value = '')
    {
        $totAthletes = 0;
        $connection = Database::getInstance()->getConnection();
        if ($property == '') {
            $query = "SELECT count(*) as totAthletes FROM athletes";
            $stmt = $connection->prepare($query);
            $stmt->execute();
        } else {
            $query = "SELECT count(*) as totAthletes FROM athletes where $property = :value";
            $stmt = $connection->prepare($query);
            $stmt->execute(['value' => $value ?? '']);
        }


        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($results) {
            $totAthletes = $results[0]['totAthletes'];
        }

        return $totAthletes;
    }

    public static function searchSport($term)
    {
        return self::searchAttribute('sport', $term);
    }

    private static function searchAttribute($attribute, $term)
    {
        $connection = Database::getInstance()->getConnection();
        $query = "SELECT DISTINCT $attribute FROM athletes WHERE $attribute LIKE :term LIMIT 10";
        $stmt = $connection->prepare($query);
        $stmt->execute(['term' => '%' . $term . '%']);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function searchActivity($term)
    {
        return self::searchAttribute('activity', $term);
    }

    public static function searchYear($term)
    {
        return self::searchAttribute('birthyear', $term);
    }

    public static function searchNationality($term)
    {
        return self::searchAttribute('nationality', $term);
    }

    public static function searchBirthplace($term)
    {
        return self::searchAttribute('birthplace', $term);
    }

    public static function advancedSearch($title = '', $sport = '', $activity = '', $nationality = '', $birthplace = '', $year = '', $sex = '')
    {
        $connection = Database::getInstance()->getConnection();
        $query = "SELECT * FROM athletes WHERE 1=1";
        $params = [];
        if ($title !== '') {
            $query .= " AND title LIKE :title";
            $params[':title'] = "%$title%";
        }

        if ($sport !== '') {
            $query .= " AND sport LIKE :sport";
            $params[':sport'] = "%$sport%";
        }

        if ($activity !== '') {
            $query .= " AND activity LIKE :activity";
            $params[':activity'] = "%$activity%";
        }

        if ($nationality !== '') {
            $query .= " AND nationality LIKE :nationality";
            $params[':nationality'] = "%$nationality%";
        }

        if ($birthplace !== '') {
            $query .= " AND birthplace LIKE :birthplace";
            $params[':birthplace'] = "%$birthplace%";
        }

        if ($year !== '') {
            $query .= " AND birthyear = :year";
            $params[':year'] = $year;
        }

        if ($sex !== '') {
            $query .= " AND sex = :sex";
            $params[':sex'] = $sex;
        }

        $stmt = $connection->prepare($query . " ORDER BY title LIMIT 30");
        $stmt->execute($params);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $athletes = [];
        foreach ($results as $row) {
            $athlete = new Athlete();
            $athlete->id = $row['id'];
            $athlete->fields = $row;
            $athletes[] = $athlete;
        }
        return $athletes;
    }

    public static function searchByName(string $term, int $limit = 10): array
    {
        $connection = Database::getInstance()->getConnection();

        $query = "SELECT id, title FROM athletes WHERE title LIKE :term ORDER BY title ASC LIMIT :limit";
        $stmt = $connection->prepare($query);

        $likeTerm = "%$term%";
        $stmt->bindParam(':term', $likeTerm, PDO::PARAM_STR);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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



