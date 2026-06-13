<?php

class SportUtils
{
    public static function getAll($onlyActive = true)
    {
        $pdo = Database::getInstance()->getConnection();
        $sql = "SELECT * FROM sports";
        if ($onlyActive) {
            $sql .= " WHERE attivo = 1";
        }
        $sql .= " ORDER BY nome ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getById($id)
    {
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM sports WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
