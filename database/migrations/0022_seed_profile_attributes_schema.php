<?php
/**
 * Checkpoint 3 · P1 — Campi descrittivi type-specific.
 * Popola `profile_types.attributes_schema` (colonna già esistente) con la whitelist di campi
 * per tipo che guida sia l'editor sia la pagina pubblica. Data-only, reversibile, nessun DDL.
 * atleta/fan restano senza campi (profilo CV / minimale).
 */
return new class {
    public function up(\PDO $pdo): void
    {
        $schemas = [
            'societa' => [
                'fields' => [
                    ['key' => 'categoria',       'label' => 'Categoria / Serie',   'type' => 'text', 'maxlen' => 80],
                    ['key' => 'anno_fondazione', 'label' => 'Anno di fondazione',  'type' => 'year', 'maxlen' => 4],
                    ['key' => 'sede_impianto',   'label' => 'Sede / Impianto',     'type' => 'text', 'maxlen' => 120],
                    ['key' => 'colori_sociali',  'label' => 'Colori sociali',      'type' => 'text', 'maxlen' => 60],
                    ['key' => 'sito_web',        'label' => 'Sito ufficiale',      'type' => 'url',  'maxlen' => 200],
                ],
            ],
            'federazione' => [
                'fields' => [
                    ['key' => 'ambito',          'label' => 'Ambito',              'type' => 'select', 'options' => ['Nazionale', 'Regionale', 'Provinciale'], 'maxlen' => 20],
                    ['key' => 'regione',         'label' => 'Regione',             'type' => 'text', 'maxlen' => 80],
                    ['key' => 'discipline',      'label' => 'Discipline',          'type' => 'text', 'maxlen' => 200],
                    ['key' => 'anno_fondazione', 'label' => 'Anno di fondazione',  'type' => 'year', 'maxlen' => 4],
                    ['key' => 'sito_web',        'label' => 'Sito ufficiale',      'type' => 'url',  'maxlen' => 200],
                ],
            ],
            'associazione' => [
                'fields' => [
                    ['key' => 'attivita',        'label' => 'Attività principali', 'type' => 'text', 'maxlen' => 120],
                    ['key' => 'anno_fondazione', 'label' => 'Anno di fondazione',  'type' => 'year', 'maxlen' => 4],
                    ['key' => 'sede',            'label' => 'Sede',                'type' => 'text', 'maxlen' => 120],
                    ['key' => 'sito_web',        'label' => 'Sito ufficiale',      'type' => 'url',  'maxlen' => 200],
                ],
            ],
        ];

        $stmt = $pdo->prepare('UPDATE profile_types SET attributes_schema = :s WHERE `key` = :k');
        foreach ($schemas as $key => $schema) {
            $stmt->execute([
                's' => json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'k' => $key,
            ]);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("UPDATE profile_types SET attributes_schema = NULL WHERE `key` IN ('societa','federazione','associazione')");
    }
};
