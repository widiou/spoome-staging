<?php

class ProfiloUtils
{
    /**
     * Restituisce la tabella corrispondente al tipo di utente.
     */
    private static function getTableName(string $tipo): ?string
    {
        $mapping = [
            'professionista' => 'professionisti',
            'atleta' => 'atleti',
            'societa' => 'societa',
            'agenzia' => 'agenzie',
            'fan' => 'fan',
        ];

        return $mapping[$tipo] ?? null;
    }

    /**
     * Recupera o crea un profilo per l'utente specificato.
     */
    public static function getOrCreateProfilo(PDO $pdo, string $tipo, int $userId): ?array
    {
        $table = self::getTableName($tipo);
        if (!$table) {
            throw new InvalidArgumentException("Tipo profilo non valido.");
        }

        // Controlla se il profilo esiste
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE user_id = ?");
        $stmt->execute([$userId]);
        $profilo = $stmt->fetch(PDO::FETCH_ASSOC);

        // Se non esiste, lo crea
        if (!$profilo) {
            $stmtInsert = $pdo->prepare("INSERT INTO $table (user_id) VALUES (?)");
            $stmtInsert->execute([$userId]);

            // Ricarica il profilo appena creato
            $stmt->execute([$userId]);
            $profilo = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        return $profilo;
    }

    /**
     * Recupera un profilo esistente senza crearne uno nuovo.
     */
    public static function getProfilo(PDO $pdo, string $tipo, int $userId): ?array
    {
        $table = self::getTableName($tipo);
        if (!$table) {
            throw new InvalidArgumentException("Tipo profilo non valido.");
        }

        $stmt = $pdo->prepare("SELECT * FROM $table WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Aggiorna i dati comuni del profilo nella tabella profili_base.
     */
    public static function updateProfiloBaseCompleto(PDO $pdo, int $userId, array $data): void
    {
        $fields = [];
        $params = ['user_id' => $userId];

        foreach ($data as $key => $value) {
            $fields[] = "$key = :$key";
            $params[$key] = $value;
        }

        $sql = "UPDATE profili_base SET " . implode(', ', $fields) . " WHERE user_id = :user_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    public static function getProfiloBase(PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM profili_base WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }



}
