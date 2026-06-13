<?php
require_once 'db/Database.php';

class Organizations
{
    private PDO $connection;

    public function __construct()
    {
        $this->connection = Database::getInstance()->getConnection();
    }

    public static function getAll($type = ""): array
    {
        $connection = Database::getInstance()->getConnection();
        if($type === ""){
            $query = "SELECT * from organizations order by sport";
            $stmt = $connection->query($query);
        }else{
            $query = "SELECT * from organizations where type = :type order by sport";
            $stmt = $connection->prepare($query);
            $stmt->execute(['type' => $type]);
        }
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    public static function getByDescription(string $description)
    {
        $description = "%" . str_replace(" " , "%", $description) . "%";
        $connection = Database::getInstance()->getConnection();
        $query = "SELECT * FROM organizations WHERE description like :description LIMIT 1";
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
        $query = "SELECT * FROM organizations";
        $stmt = $connection->query($query);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
}
