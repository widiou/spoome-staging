<?php
require_once 'db/Database.php';

class GruppiSportivi
{
    public ?int $id = null;
    public ?string $description = null;
    public ?string $website = null;
    public ?string $facebook = null;
    public ?string $instagram = null;
    public ?string $twitter = null;
    public ?string $store = null;
    public ?string $youtube = null;
    public ?string $feed = null;
    public ?string $tiktok = null;
    public ?string $photo = null;
    private PDO $connection;

    public function __construct()
    {
        $this->connection = Database::getInstance()->getConnection();
    }

    // Create a new event

    public static function getByDescription(string $description): ?self
    {
        $description = "%" . str_replace(" " , "%", $description) . "%";
        $connection = Database::getInstance()->getConnection();
        $query = "SELECT `match` FROM aliasEvents WHERE alias like :description LIMIT 1";
        $stmt = $connection->prepare($query);
        $stmt->execute(['description' => $description]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            echo $result->match;
            return self::read($result['match']);
        }
        return null;
    }

    // Read an event by id

    public static function read(int $id): ?self
    {
        $connection = Database::getInstance()->getConnection();
        $query = "SELECT * FROM gs WHERE id = :id";
        $stmt = $connection->prepare($query);
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            return self::fromArray($result);
        }
        return null;
    }

    private static function fromArray(array $data): self
    {
        $event = new self();
        $event->id = $data['id'] ?? null;
        $event->description = $data['description'] ?? null;
        $event->website = $data['website'] ?? null;
        $event->facebook = $data['facebook'] ?? null;
        $event->instagram = $data['instagram'] ?? null;
        $event->twitter = $data['twitter'] ?? null;
        $event->store = $data['store'] ?? null;
        $event->youtube = $data['youtube'] ?? null;
        $event->feed = $data['feed'] ?? null;
        $event->tiktok = $data['tiktok'] ?? null;
        $event->photo = $data['photo'] ?? null;
        return $event;
    }

    // Update an existing event

    public static function delete(int $id): bool
    {
        $query = "DELETE FROM gs WHERE id = :id";
        $stmt = self::$connection->prepare($query);
        return $stmt->execute(['id' => $id]);
    }

    // Delete an event by id

    public static function getAll(): array
    {
        $connection = Database::getInstance()->getConnection();
        $query = "SELECT * from dbzppzhzsjhdcm.events";
        $stmt = $connection->query($query);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    // List all event descriptions

    public static function listAllMatchs(): array
    {
        $connection = Database::getInstance()->getConnection();
        $query = "SELECT alias FROM aliasEvents ORDER BY LENGTH(alias)";
        $stmt = $connection->query($query);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Helper methods

    // Convert object to array

    public function create(): bool
    {
        $query = "INSERT INTO events (description, website, facebook, instagram, twitter, store, youtube, feed, tiktok) 
                  VALUES (:description, :website, :facebook, :instagram, :twitter, :store, :youtube, :feed, :tiktok)";
        $stmt = self::$connection->prepare($query);
        return $stmt->execute($this->toArray());
    }

    // Create an object from an array

    private function toArray(bool $includeId = false): array
    {
        $array = [
            'description' => $this->description,
            'website' => $this->website,
            'facebook' => $this->facebook,
            'instagram' => $this->instagram,
            'twitter' => $this->twitter,
            'store' => $this->store,
            'youtube' => $this->youtube,
            'feed' => $this->feed,
            'tiktok' => $this->tiktok
        ];
        if ($includeId) {
            $array['id'] = $this->id;
        }
        return $array;
    }

}