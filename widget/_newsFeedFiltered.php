<?php
$db = Database::getInstance()->getConnection();
const CACHE_TIME = 1800;
if (isset($news_filter)) {
    $news = getFeedAthlete($db, $news_filter);
}
if (count($news) > 0) {
    ?>
    <div class="container mb-5">
        <div class="row">
            <div class="col-12">
                <ul id="news-list" class="list-group">
                    <?php foreach ($news as $new) {
                        $source = $new['source'];
                        $title = $new['title'] ?? '';
                        ?>
                        <li class="list-group-item">
                            <p class="text-small text-secondary text-uppercase my-1"><?= $new['pubDate']->format('d/m H:i') ?>
                                > <?= $source ?></p>
                            <a class="link-light text-decoration-none fw-bold"
                               href="https://spoome.it/?s=<?=$news_filter?>"><?= $title ?></a>
                        </li>
                    <?php } ?>
                </ul>
            </div>
        </div>
    </div>

    <?php
}


