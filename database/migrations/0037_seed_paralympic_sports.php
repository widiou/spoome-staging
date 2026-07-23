<?php

/**
 * Seed delle discipline PARALIMPICHE nella tassonomia sport (dati di riferimento).
 * I para-atleti sono di prima classe: senza queste voci, in registrazione un
 * para-atleta non troverebbe la propria disciplina. Idempotente e non distruttiva.
 *
 * Nomi/slug volutamente DISTINTI dalle voci olimpiche già seedate in 0003
 * (prefisso "Para-", suffisso "paralimpico/a", "in carrozzina", o nome proprio
 * standard come Boccia/Goalball/Sitting volley) per evitare collisioni sugli
 * UNIQUE (name, slug). Tutte marcate con category = 'Sport paralimpici'.
 *
 * Approccio ORIZZONTALE (rete olimpica/paralimpica): calcio escluso — nessun
 * blind football / calcio a 5 non vedenti.
 */
return new class () {
    private const CATEGORY = 'Sport paralimpici';

    /** @var string[] Discipline paralimpiche (estive + invernali). */
    private const SPORTS = [
        // Estivi
        'Para-atletica',
        'Para-nuoto',
        'Paraciclismo',
        'Para-triathlon',
        'Para-canottaggio',
        'Para-canoa',
        'Para-vela',
        'Para-tennistavolo',
        'Para-badminton',
        'Para-tiro con l\'arco',
        'Tiro a segno paralimpico',
        'Para-equitazione',
        'Para-judo',
        'Para-taekwondo',
        'Powerlifting paralimpico',
        'Scherma in carrozzina',
        'Basket in carrozzina',
        'Rugby in carrozzina',
        'Tennis in carrozzina',
        'Sitting volley',
        'Boccia',
        'Goalball',
        'Danza sportiva in carrozzina',
        // Invernali
        'Para-sci alpino',
        'Para-sci di fondo',
        'Para-snowboard',
        'Biathlon paralimpico',
        'Para-hockey su ghiaccio',
        'Curling in carrozzina',
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
        foreach (self::SPORTS as $name) {
            $ins->execute([':name' => $name, ':slug' => $slugify($name), ':cat' => self::CATEGORY]);
        }
    }

    public function down(\PDO $pdo): void
    {
        // Non rimuove gli sport: potrebbero già essere referenziati dai profili.
    }
};
