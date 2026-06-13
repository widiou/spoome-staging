<?php
if (isset($m)) {
    $labelFooter = "";
    $type = "";
    if (array_key_exists('views', $m)) {
        $labelFooter = $m['views'] . ' views';
        $type = "video";
    } else {
        $labelFooter = $m['date'];
        $type = "news";
    }
    $title = substr_to_end_of_word($m['title'], 40) ?? '';
    $description = $m['snippet'] ?? '';
    if (strlen($description) > 140) {
        $description = substr_to_end_of_word($m['snippet'], 120) . '...';
    }

    $link = $m['link'];
    ?>
    <div class="col-12 col-md-3">
        <div class="card my-2" style="background: var(--black)">
            <?php
            if ($type === 'video') {
                ?>
                <iframe class="player-spoome" src='<?= $m['embed_url'] ?>' allowfullscreen>
                </iframe>
                <?php
            } else {
                ?>
                <img onerror="this.onerror=null; this.src='<?= SQUARE_PLACEHOLDER ?>';"
                     src="<?= $m['thumb'] ?>"
                     class="card-img-top news-photo" style="max-height: 150px" alt="...">
                <?php
            }
            ?>
            <div class="card-body text-light">
                <a class="link-spoome" href="<?= $link ?>" target="_blank">
                    <h5 class="card-title text-spoome"><?= $title ?></h5>
                </a>
                <p class="card-text small">
                    <?= htmlentities($description) ?>
                </p>
                <!--<hr>
                <span class="mt-5 text-light" style="font-size: 0.75rem"><?php /*= $labelFooter */ ?></span>-->
            </div>
        </div>
    </div>
    <?php
}