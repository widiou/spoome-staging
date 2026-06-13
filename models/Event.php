<?php
require_once 'db/Database.php';

class Event
{
    private PDO $connection;

    public function __construct()
    {
        $this->connection = Database::getInstance()->getConnection();
    }

    public static function getAll(): array
    {
        $connection = Database::getInstance()->getConnection();
        $query = "SELECT * from bigevents order by description";
        $stmt = $connection->query($query);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    public static function getByDescription(string $description)
    {
        $description = "%" . str_replace(" ", "%", $description) . "%";
        $connection = Database::getInstance()->getConnection();
        $query = "SELECT * FROM bigevents WHERE description like :description LIMIT 1";
        $stmt = $connection->prepare($query);
        $stmt->execute(['description' => $description]);
        $result = $stmt->fetch(PDO::FETCH_OBJ);
        if ($result) {
            return $result;
        }
        return null;
    }

    public static function listAllMatchs(): array
    {
        $connection = Database::getInstance()->getConnection();
        $query = "SELECT * FROM bigevents";
        $stmt = $connection->query($query);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
}
