<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../models/Athlete.php';

$db = Database::getInstance()->getConnection();

// Recupero feed non ancora pubblicati (wordpress_posted = 0)
$stmt = $db->prepare("select * from rss_cache order by pub_date desc limit 50");
$stmt->execute();
$feeds = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $titlePost = $row['source'] . ": " . $row['title'];

    $feeds[] = [
        'id' => $row['id'],
        'title' => $titlePost,
        'link' => $row['link'],
        'description' => $row['description'],
        'pubDate' => new DateTime($row['pub_date'] ?? ''),
        'source' => $row['source']
    ];
}

// Connessione al DB WordPress (credenziali da .env: niente segreti hardcoded,
// evita che un cron su staging scriva sul WordPress di produzione)
$wpName = env('WP_DB_NAME');
$wpUser = env('WP_DB_USER');
if (empty($wpName) || empty($wpUser)) {
    throw new RuntimeException('Config WordPress mancante: definisci WP_DB_NAME/WP_DB_USER/WP_DB_PASS nel .env.');
}
$wp_db = new PDO(
    'mysql:host=' . env('WP_DB_HOST', 'localhost') . ';dbname=' . $wpName . ';charset=utf8mb4',
    $wpUser,
    env('WP_DB_PASS')
);
$wp_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

foreach ($feeds as $f) {
    // Pubblica su WP solo se il titolo non esiste già
    if (!isAlreadyPublished($wp_db, $f['title'])) {
        publishToWordPress($wp_db, $f, '146');

        // Aggiorna il flag wordpress_posted nella tabella rss_cache
        $stmt_update = $db->prepare("UPDATE rss_cache SET wordpress_posted = 1 WHERE id = ?");
        $stmt_update->execute([$f['id']]);
    }
}

// Funzione ausiliaria per pubblicare su WP
function publishToWordPress($wp_db, $newsItem, $categoria_id): void
{
    $wp_table_prefix = 'tcw_';
    $post_date = $newsItem['pubDate']->format('Y-m-d H:i:s');
    $post_date_gmt = gmdate('Y-m-d H:i:s', strtotime($post_date));
    $slug = sanitize_title($newsItem['title']);
    $athletes = Athlete::getLastTen($newsItem['source'], '', '', '', 1, 6);

    // Aggiungi alla descrizione il link originale
    $content_with_link = removeImages($newsItem['description']) . '<br><br><a href="' . $newsItem['link'] . '" target="_blank" rel="nofollow noopener noreferrer">Leggi l\'articolo completo</a>';
    $content_with_link .= "<br><br><strong>Scopri anche questi atleti su Spoome:</strong><br>";
    $content_with_link .= "Se ti interessano le storie di atleti e protagonisti dello sport, non perderti questi profili selezionati.<br>";
    foreach ($athletes as $ra) {
     $content_with_link .= '<a href="' . getLinkAtleta($ra->id, $ra->title) . '" target="_blank" rel="nofollow noopener noreferrer">' . $ra->title . '</a><br>';
    }
    $content_with_link .= "<br>".$newsItem['source'].": Tutte le biografie, i risultati e gli aggiornamenti solo su <strong>Spoome.it</strong>.";
    try {
        // Inserimento del post
        $stmt_wp = $wp_db->prepare("
            INSERT INTO {$wp_table_prefix}posts
                (post_author, post_date, post_date_gmt, post_content, post_title, post_status, comment_status, ping_status, post_name, post_modified, post_modified_gmt, post_type)
            VALUES
                (:author, :date, :date_gmt, :content, :title, 'publish', 'closed', 'closed', :slug, :mod_date, :mod_date_gmt, 'post')
        ");

        $stmt_wp->execute([
            ':author' => 4, // id autore WP
            ':date' => $post_date,
            ':date_gmt' => $post_date_gmt,
            ':content' => $content_with_link,
            ':title' => $newsItem['title'],
            ':slug' => $slug,
            ':mod_date' => $post_date,
            ':mod_date_gmt' => $post_date_gmt
        ]);

        $post_id = $wp_db->lastInsertId();

        // Associa la categoria al post
        $stmt_category = $wp_db->prepare("
            INSERT INTO {$wp_table_prefix}term_relationships (object_id, term_taxonomy_id, term_order)
            VALUES (?, ?, 0)
        ");
        $stmt_category->execute([$post_id, $categoria_id]);

        // Aggiorna contatore categoria
        $wp_db->exec("
            UPDATE {$wp_table_prefix}term_taxonomy
            SET count = count + 1
            WHERE term_taxonomy_id = {$categoria_id}
        ");

        // Inserisci il "source" come tag del post
        insertTagToPost($wp_db, $wp_table_prefix, $post_id, $newsItem['source']);


    } catch (PDOException $e) {

    }
}

// Inserisce il tag associato al post
function insertTagToPost($wp_db, $wp_table_prefix, $post_id, $tag_name): void
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
        $stmt_create_tag = $wp_db->prepare("INSERT INTO {$wp_table_prefix}terms (name, slug) VALUES (?, ?)");
        $stmt_create_tag->execute([$tag_name, sanitize_title($tag_name)]);
        $tag_id = $wp_db->lastInsertId();

        // Inserisci tassonomia tag
        $stmt_create_tax = $wp_db->prepare("INSERT INTO {$wp_table_prefix}term_taxonomy (term_id, taxonomy, count) VALUES (?, 'post_tag', 1)");
        $stmt_create_tax->execute([$tag_id]);
        $tag_taxonomy_id = $wp_db->lastInsertId();
    } else {
        // Recupera term_taxonomy_id esistente e aggiorna il conteggio
        $stmt_taxonomy = $wp_db->prepare("SELECT term_taxonomy_id FROM {$wp_table_prefix}term_taxonomy WHERE term_id = ? AND taxonomy = 'post_tag'");
        $stmt_taxonomy->execute([$tag_id]);
        $tag_taxonomy_id = $stmt_taxonomy->fetchColumn();

        $stmt_update_count = $wp_db->prepare("UPDATE {$wp_table_prefix}term_taxonomy SET count = count + 1 WHERE term_taxonomy_id = ?");
        $stmt_update_count->execute([$tag_taxonomy_id]);
    }

    // Collega tag al post
    $stmt_tag_rel = $wp_db->prepare("
        INSERT INTO {$wp_table_prefix}term_relationships (object_id, term_taxonomy_id, term_order)
        VALUES (?, ?, 0)
    ");
    $stmt_tag_rel->execute([$post_id, $tag_taxonomy_id]);
}

// Funzione sanitizzazione slug WordPress
function sanitize_title($string): string
{
    // Trasforma tutto in minuscolo
    $slug = strtolower($string);

    // Sostituisce tutto ciò che non è lettera/numero con trattino
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

    // Rimuove trattini multipli consecutivi
    $slug = preg_replace('/-+/', '-', $slug);

    // Rimuove trattini iniziali e finali
    return trim($slug, '-');
}


// Controlla se il titolo esiste già in WP
function isAlreadyPublished($wp_db, $title): bool
{
    $wp_table_prefix = 'tcw_';
    $stmt = $wp_db->prepare("SELECT COUNT(*) FROM {$wp_table_prefix}posts WHERE post_title = ?");
    $stmt->execute([$title]);
    return $stmt->fetchColumn() > 0;
}

// Funzione per rimuovere immagini
function removeImages($html)
{
    return preg_replace('/<img[^>]+\>/i', '', $html);
}