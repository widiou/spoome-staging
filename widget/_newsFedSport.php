<?php
$db = Database::getInstance()->getConnection();
const CACHE_TIME = 1800;
if (isset($sport)) {
    $news = getFeedAthlete($db, $sport);
}
if (count($news) > 0) {
    ?>
    <div class="container mb-5">
        <div class="row">
            <?= getTitle("Altre notizie") ?>
        </div>
        <div class="row">
            <div class="col-12">
                <ul id="news-list" class="list-group">
                    <?php foreach ($news as $new) {
                        $source = $new['source'];
                        $title = $new['title'] ?? '';
                        ?>
                        <li class="list-group-item">
                            <p class="text-small text-secondary text-uppercase my-1"><?= $new['pubDate']->format('d/m H:i') ?> > <?= $source ?></p>
                            <a class="link-light text-decoration-none fw-bold"
                               href="<?= $new['link'] ?>"
                               target="_blank"><?= $title ?></a>
                        </li>
                    <?php } ?>
                </ul>
            </div>
        </div>
    </div>

    <?php
}


