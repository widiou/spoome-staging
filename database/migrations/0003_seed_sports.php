<?php

/**
 * Seed della tassonomia sport (dati di riferimento, non contenuto utente). Idempotente.
 * Categorie e discipline principali del panorama sportivo italiano.
 */
return new class () {
    private const SPORTS = [
        // [nome, categoria]
        ['Calcio', 'Sport di squadra'],
        ['Pallacanestro', 'Sport di squadra'],
        ['Pallavolo', 'Sport di squadra'],
        ['Rugby', 'Sport di squadra'],
        ['Pallanuoto', 'Sport di squadra'],
        ['Hockey su prato', 'Sport di squadra'],
        ['Hockey su ghiaccio', 'Sport di squadra'],
        ['Baseball', 'Sport di squadra'],
        ['Softball', 'Sport di squadra'],
        ['Football americano', 'Sport di squadra'],
        ['Atletica leggera', 'Atletica'],
        ['Maratona', 'Atletica'],
        ['Marcia', 'Atletica'],
        ['Nuoto', 'Sport acquatici'],
        ['Nuoto sincronizzato', 'Sport acquatici'],
        ['Tuffi', 'Sport acquatici'],
        ['Canottaggio', 'Sport acquatici'],
        ['Canoa', 'Sport acquatici'],
        ['Vela', 'Sport acquatici'],
        ['Surf', 'Sport acquatici'],
        ['Tennis', 'Sport con racchetta'],
        ['Tennistavolo', 'Sport con racchetta'],
        ['Badminton', 'Sport con racchetta'],
        ['Padel', 'Sport con racchetta'],
        ['Squash', 'Sport con racchetta'],
        ['Ciclismo', 'Ciclismo'],
        ['Ciclismo su pista', 'Ciclismo'],
        ['Mountain bike', 'Ciclismo'],
        ['BMX', 'Ciclismo'],
        ['Sci alpino', 'Sport invernali'],
        ['Sci di fondo', 'Sport invernali'],
        ['Snowboard', 'Sport invernali'],
        ['Biathlon', 'Sport invernali'],
        ['Pattinaggio di figura', 'Sport invernali'],
        ['Short track', 'Sport invernali'],
        ['Bob', 'Sport invernali'],
        ['Slittino', 'Sport invernali'],
        ['Curling', 'Sport invernali'],
        ['Pugilato', 'Sport da combattimento'],
        ['Judo', 'Sport da combattimento'],
        ['Karate', 'Sport da combattimento'],
        ['Taekwondo', 'Sport da combattimento'],
        ['Lotta', 'Sport da combattimento'],
        ['Scherma', 'Sport da combattimento'],
        ['Kickboxing', 'Sport da combattimento'],
        ['Arti marziali miste', 'Sport da combattimento'],
        ['Ginnastica artistica', 'Ginnastica'],
        ['Ginnastica ritmica', 'Ginnastica'],
        ['Trampolino elastico', 'Ginnastica'],
        ['Equitazione', 'Sport equestri'],
        ['Golf', 'Altri sport'],
        ['Tiro con l\'arco', 'Sport di precisione'],
        ['Tiro a segno', 'Sport di precisione'],
        ['Tiro a volo', 'Sport di precisione'],
        ['Bocce', 'Sport di precisione'],
        ['Sollevamento pesi', 'Forza'],
        ['Powerlifting', 'Forza'],
        ['Triathlon', 'Multidisciplina'],
        ['Pentathlon moderno', 'Multidisciplina'],
        ['Arrampicata sportiva', 'Altri sport'],
        ['Skateboard', 'Altri sport'],
        ['Automobilismo', 'Motori'],
        ['Motociclismo', 'Motori'],
        ['Motocross', 'Motori'],
        ['Danza sportiva', 'Altri sport'],
        ['Pattinaggio a rotelle', 'Altri sport'],
    ];

    public function up(\PDO $pdo): void
    {
        $slugify = static function (string $s): string {
            $conv = @\iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            $s = \preg_replace('/[^a-zA-Z0-9]+/', '-', $conv !== false ? $conv : $s);
            return \strtolower(\trim((string) $s, '-'));
        };

        $ins = $pdo->prepare(
            'INSERT IGNORE INTO sports (name, slug, category, active) VALUES (:name, :slug, :cat, 1)'
        );
        foreach (self::SPORTS as [$name, $category]) {
            $ins->execute([':name' => $name, ':slug' => $slugify($name), ':cat' => $category]);
        }
    }

    public function down(\PDO $pdo): void
    {
        // Non rimuove gli sport: potrebbero già essere referenziati dai profili.
    }
};
