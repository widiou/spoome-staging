<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../models/Athlete.php';

$db = Database::getInstance()->getConnection();

/**
 * LISTA KEYWORD CHE IDENTIFICANO LA BASILICATA
 * Puoi aggiungere/limare come vuoi.
 */
$basilicataKeywords = [
    'basilicata', 'lucania', 'lucano', 'lucana', 'lucani', 'lucane',
    'potenza', 'matera',

    // principali città/paesi
    'melfi', 'venosa', 'rionero in vulture', 'rionero in vulture', 'rionero',
    'pisticci', 'policoro', 'nova siri', 'tursi', 'bernald', 'marconia',
    'metaponto', 'tricarico', 'viggiano', 'val d\'agri', 'valdagri',
    'lagonegro', 'tito', 'avigliano', 'grassano', 'stigliano',
    'lavello', 'genzano di lucania', 'ginestra', 'rota greca', // aggiungi pure altri

    // forme più generiche
    'regione basilicata', 'sport lucano', 'lucano doc'
];

/**
 * Recupero feed dal DB Spoome
 * (qui puoi eventualmente filtrare solo quelli non ancora trattati
 *   per Basilicata Sport aggiungendo un campo dedicato, es. basilicata_posted)
 */
$stmt = $db->prepare("SELECT * FROM rss_cache ORDER BY pub_date DESC LIMIT 100");
$stmt->execute();

$feeds = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

    $titlePost = $row['source'] . ": " . $row['title'];

    $feeds[] = [
        'id' => $row['id'],
        'title' => $titlePost,
        'original_title' => $row['title'],
        'link' => $row['link'],
        'description' => $row['description'],
        'pubDate' => new DateTime($row['pub_date'] ?? ''),
        'source' => $row['source']
    ];
}

// Connessione al DB WordPress di BASILICATASPORT.COM
// >>> SOSTITUISCI con i dati reali di basilicatasport <<<
$wp_db = new PDO(
    'mysql:host=localhost;dbname=XXXXXXXXXXXX;charset=utf8mb4',
    'USERNAME_DB',
    'PASSWORD_DB'
);
$wp_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Prefix di WordPress su Basilicata Sport (di solito "wp_", ma verifica)
$wp_table_prefix = 'wp_';

// ID categoria su Basilicata Sport dove pubblicare queste news auto-importate
// >>> SOSTITUISCI con l'ID reale (es. categoria "Rassegna Stampa" / "Dal web") <<<
$basilicata_category_id = 123;

// Loop sui feed
foreach ($feeds as $f) {

    // 1) filtro: pubblichiamo su BasilicataSport SOLO se parla di Basilicata
    if (!isBasilicataRelated($f, $basilicataKeywords)) {
        continue;
    }

    // 2) Evita duplicati sul DB di Basilicata Sport (titolo completo "Fonte: Titolo")
    if (!isAlreadyPublished($wp_db, $wp_table_prefix, $f['title'])) {

        publishToBasilicataSport($wp_db, $wp_table_prefix, $f, $basilicata_category_id);

        // 3) (OPZIONALE ma consigliato)
        // Se vuoi segnare sul DB Spoome che questa news è stata pubblicata anche su BasilicataSport,
        // aggiungi in rss_cache un campo tipo `basilicata_posted TINYINT(1)` e decommenta:
        /*
        $stmt_update = $db->prepare("UPDATE rss_cache SET basilicata_posted = 1 WHERE id = ?");
        $stmt_update->execute([$f['id']]);
        */
    }
}

/**
 * Verifica se una news riguarda la Basilicata
 * controllando presenza delle keyword in titolo + descrizione (case-insensitive).
 */
function isBasilicataRelated(array $newsItem, array $keywords): bool
{
    $haystack = strtolower(
        ($newsItem['title'] ?? '') . ' ' .
        ($newsItem['original_title'] ?? '') . ' ' .
        strip_tags($newsItem['description'] ?? '')
    );

    foreach ($keywords as $kw) {
        $kw = trim($kw);
        if ($kw === '') {
            continue;
        }
        if (strpos($haystack, strtolower($kw)) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Pubblica la news nel WordPress di Basilicata Sport
 */
function publishToBasilicataSport(PDO $wp_db, string $wp_table_prefix, array $newsItem, int $categoria_id): void
{
    $post_date = $newsItem['pubDate']->format('Y-m-d H:i:s');
    $post_date_gmt = gmdate('Y-m-d H:i:s', strtotime($post_date));
    $slug = sanitize_title($newsItem['title']);

    // Recupera alcuni atleti da Spoome da suggerire
    // (stessa logica del tuo script per Spoome, volendo puoi filtrare solo atleti lucani)
    $athletes = Athlete::getLastTen($newsItem['source'], '', '', '', 1, 6);

    // Aggiungi alla descrizione il link originale + promo Spoome
    $content_with_link  = removeImages($newsItem['description']);
    $content_with_link .= '<br><br><a href="' . htmlspecialchars($newsItem['link'], ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="nofollow noopener noreferrer">Leggi l\'articolo completo</a>';

    $content_with_link .= "<br><br><strong>Scopri anche questi atleti su Spoome:</strong><br>";
    $content_with_link .= "Se ti interessano le storie di atleti e protagonisti dello sport lucano e italiano, non perderti questi profili selezionati.<br>";

    foreach ($athletes as $ra) {
        $content_with_link .= '<a href="' . getLinkAtleta($ra->id, $ra->title) . '" target="_blank" rel="nofollow noopener noreferrer">'
            . htmlspecialchars($ra->title, ENT_QUOTES, 'UTF-8') .
            '</a><br>';
    }

    $content_with_link .= "<br>" . htmlspecialchars($newsItem['source'], ENT_QUOTES, 'UTF-8') . ": biografie, risultati e aggiornamenti solo su <strong>Spoome.it</strong>.";

    try {
        // Inserimento post in tabella posts
        $stmt_wp = $wp_db->prepare("
            INSERT INTO {$wp_table_prefix}posts
                (post_author, post_date, post_date_gmt, post_content, post_title, post_status,
                 comment_status, ping_status, post_name, post_modified, post_modified_gmt, post_type)
            VALUES
                (:author, :date, :date_gmt, :content, :title, 'publish',
                 'closed', 'closed', :slug, :mod_date, :mod_date_gmt, 'post')
        ");

        $stmt_wp->execute([
            ':author'       => 1, // id autore WP su BasilicataSport (modifica se serve)
            ':date'         => $post_date,
            ':date_gmt'     => $post_date_gmt,
            ':content'      => $content_with_link,
            ':title'        => $newsItem['title'],
            ':slug'         => $slug,
            ':mod_date'     => $post_date,
            ':mod_date_gmt' => $post_date_gmt
        ]);

        $post_id = (int)$wp_db->lastInsertId();

        // Associa la categoria al post
        $stmt_category = $wp_db->prepare("
            INSERT INTO {$wp_table_prefix}term_relationships (object_id, term_taxonomy_id, term_order)
            VALUES (:post_id, :term_taxonomy_id, 0)
        ");
        $stmt_category->execute([
            ':post_id'         => $post_id,
            ':term_taxonomy_id'=> $categoria_id
        ]);

        // Aggiorna contatore categoria
        $stmt_update_cat = $wp_db->prepare("
            UPDATE {$wp_table_prefix}term_taxonomy
            SET count = count + 1
            WHERE term_taxonomy_id = :term_taxonomy_id
        ");
        $stmt_update_cat->execute([':term_taxonomy_id' => $categoria_id]);

        // Inserisci il "source" come tag del post
        insertTagToPost($wp_db, $wp_table_prefix, $post_id, $newsItem['source']);

    } catch (PDOException $e) {
        // Logga l'errore (in produzione meglio usare un logger)
        error_log('Errore pubblicazione BasilicataSport: ' . $e->getMessage());
    }
}

/**
 * Inserisce/aggiorna un TAG e lo collega al post
 */
function insertTagToPost(PDO $wp_db, string $wp_table_prefix, int $post_id, string $tag_name): void
{
    // Verifica se il tag esiste già
    $stmt_tag = $wp_db->prepare("
        SELECT t.term_id
        FROM {$wp_table_prefix}terms AS t
        INNER JOIN {$wp_table_prefix}term_taxonomy AS tt ON t.term_id = tt.term_id
        WHERE tt.taxonomy = 'post_tag' AND t.name = ?
        LIMIT 1
    ");
    $stmt_tag->execute([$tag_name]);
    $tag_id = $stmt_tag->fetchColumn();

    if (!$tag_id) {
        // Crea il tag se non esiste
        $stmt_create_tag = $wp_db->prepare("
            INSERT INTO {$wp_table_prefix}terms (name, slug)
            VALUES (?, ?)
        ");
        $stmt_create_tag->execute([$tag_name, sanitize_title($tag_name)]);
        $tag_id = $wp_db->lastInsertId();

        // Inserisci tassonomia tag
        $stmt_create_tax = $wp_db->prepare("
            INSERT INTO {$wp_table_prefix}term_taxonomy (term_id, taxonomy, count)
            VALUES (?, 'post_tag', 1)
        ");
        $stmt_create_tax->execute([$tag_id]);
        $tag_taxonomy_id = $wp_db->lastInsertId();
    } else {
        // Recupera term_taxonomy_id esistente e aggiorna il conteggio
        $stmt_taxonomy = $wp_db->prepare("
            SELECT term_taxonomy_id
            FROM {$wp_table_prefix}term_taxonomy
            WHERE term_id = ? AND taxonomy = 'post_tag'
        ");
        $stmt_taxonomy->execute([$tag_id]);
        $tag_taxonomy_id = $stmt_taxonomy->fetchColumn();

        $stmt_update_count = $wp_db->prepare("
            UPDATE {$wp_table_prefix}term_taxonomy
            SET count = count + 1
            WHERE term_taxonomy_id = ?
        ");
        $stmt_update_count->execute([$tag_taxonomy_id]);
    }

    // Collega tag al post
    $stmt_tag_rel = $wp_db->prepare("
        INSERT INTO {$wp_table_prefix}term_relationships (object_id, term_taxonomy_id, term_order)
        VALUES (?, ?, 0)
    ");
    $stmt_tag_rel->execute([$post_id, $tag_taxonomy_id]);
}

/**
 * Sanitizzazione slug in stile WordPress
 */
function sanitize_title(string $string): string
{
    $slug = strtolower($string);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    return trim($slug, '-');
}

/**
 * Controlla se il titolo esiste già nel WP di Basilicata Sport
 */
function isAlreadyPublished(PDO $wp_db, string $wp_table_prefix, string $title): bool
{
    $stmt = $wp_db->prepare("
        SELECT COUNT(*) FROM {$wp_table_prefix}posts
        WHERE post_title = ?
    ");
    $stmt->execute([$title]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Rimuove i tag <img> dal contenuto HTML
 */
function removeImages(string $html): string
{
    return preg_replace('/<img[^>]*>/i', '', $html);
}
