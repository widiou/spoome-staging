<?php
header("Content-Type: application/xml; charset=utf-8");

require_once 'bootstrap.php'; // Assicura di caricare le configurazioni e il database

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <?php
    $athletes = Athlete::getAll(); // Supponiamo che ci sia un metodo per ottenere tutti gli atleti
    $sports = Athlete::getAllSports('MENU');
    $events = Event::getAll();
    foreach ($events as $ev) {
        $slug = strtolower(str_replace(" ", "-", $ev->description ?? ''));
        $url = "https://www.spoome.it" . SUB_ROOT . "/evento/{$slug}";
        ?>
        <url>
            <loc><?= htmlspecialchars($url) ?></loc>
            <lastmod><?= date('Y-m-d') ?></lastmod>
            <priority>0.8</priority>
        </url>
        <?php
    }

    foreach ($sports as $sport) {
        $slug = strtolower(str_replace(" ", "-", $sport['sport'] ?? ''));
        $url = "https://www.spoome.it" . SUB_ROOT . "/sport/{$slug}";
        ?>
        <url>
            <loc><?= htmlspecialchars($url) ?></loc>
            <lastmod><?= date('Y-m-d') ?></lastmod>
            <priority>0.8</priority>
        </url>
        <?php
    }
    foreach ($athletes as $athlete) {
        $slug = strtolower(str_replace(" ", "-", $athlete->title ?? ''));
        $url = "https://www.spoome.it" . SUB_ROOT . "/atleti/{$athlete->getid()}-{$slug}";
        ?>
        <url>
            <loc><?= htmlspecialchars($url) ?></loc>
            <lastmod><?= date('Y-m-d') ?></lastmod>
            <priority>0.8</priority>
        </url>
    <?php } ?>
</urlset>
