<?php
require_once 'bootstrap.php';
if (array_key_exists('description', $_GET)) {
    $description = filter_var($_GET['description']);
    $description = str_replace("-", " ", $description);
    $event = Event::getByDescription($description);
    if ($event) {
        $descriptionEvent = htmlspecialchars($event->description);
        $title = htmlspecialchars($description);
        $news_filter = htmlspecialchars($description);
        $obja = new Athlete();
        $obja->title = $description;
        $obja->sport = $event->sport;
        $website = generateLinkSocial($event->website ?? '', 'www');
        $facebook = generateLinkSocial($event->facebook ?? '', 'fb');
        $instagram = generateLinkSocial($event->instagram ?? '', 'ig');
        $tiktok = generateLinkSocial($event->tiktok ?? '', 'tiktok');
        $twitter = generateLinkSocial($event->twitter ?? '', 'x');
        $store = generateLinkSocial($event->store ?? '', 'store');
        $youtube = generateLinkSocial($event->youtube ?? '', 'yt');
        $tv = generateLinkSocial($event->tv ?? '', 'tv');
        $title = $description . " | Spoome - Il punto di riferimento per lo sport";
        require_once 'layout/_header.php';
        ?>
        <!-- PORTING HEADER -->
        <div class="container">
            <div class="row mt-5 mx-0">
                <div class="col-12">
                    <div class="row align-items-center">
                        <div class="col-4 col-md-2">
                            <img onerror="this.onerror=null; this.src='<?= SQUARE_PLACEHOLDER ?>';"
                                 class="img-fluid profile-photo-circle lazy" src="<?= SQUARE_PLACEHOLDER ?>"
                                 data-src="<?= $event->photo ?>"
                                 alt="Foto di <?= $title ?>"
                                 style="background:white;">
                        </div>
                        <div class="col-8 col-md-10">
                            <div class="row">
                                <?= getTitle($descriptionEvent, 'h3') ?>
                                <div class="col-12 d-flex justify-content-start  flex-wrap">
                                    <?= $website ?> <?= $store ?> <?= $tv ?> <?= $youtube ?> <?= $instagram ?> <?= $facebook ?> <?= $tiktok ?> <?= $twitter ?>
                                </div>
                                <?php
                                if ($event->risultati and $event->live) {
                                    ?>
                                    <div class="col-12 mt-2 d-flex justify-content-start flex-wrap text-spoome">
                                        <a href="<?= $event->live ?>" target="_blank" class="link-spoome me-3">LIVE</a>
                                        <a href="<?= $event->risultati ?>" target="_blank"
                                           class="link-spoome">RISULTATI</a>
                                    </div>
                                    <?php
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="container" id="topProfile">
                    <div class="row mt-3 mt-md-5 mb-md-5">
                        <div class="col-12">
                            <ul class="nav nav-underline nav-justified" id="pDetails" role="tablist">
                                <li class="nav-item mx-1" role="presentation">
                                    <button class="nav-link link-spoome-outline active" id="news-tab"
                                            data-bs-toggle="tab"
                                            data-bs-target="#news-tab-pane" type="button" role="tab"
                                            aria-controls="news-tab-pane" aria-selected="true">
                                        NOTIZIE
                                    </button>
                                </li>
                                <li class="nav-item mx-1" role="presentation">
                                    <button class="nav-link link-spoome-outline" id="video-tab" data-bs-toggle="tab"
                                            data-bs-target="#video-tab-pane" type="button" role="tab"
                                            aria-controls="video-tab-pane" aria-selected="false">
                                        VIDEO
                                    </button>
                                </li>
                                <li class="nav-item mx-1" role="presentation">
                                    <button class="nav-link link-spoome-outline" id="social-tab" data-bs-toggle="tab"
                                            data-bs-target="#social-tab-pane"
                                            type="button" role="tab" aria-controls="social-tab-pane"
                                            aria-selected="false">
                                        SOCIAL
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-12">
                            <div class="tab-content" id="pDetailsContent">
                                <div class="tab-pane fade show active" id="news-tab-pane" role="tabpanel"
                                     aria-labelledby="news-tab" tabindex="0">
                                    <?php require_once 'widget/_lastNews.php'; ?>
                                </div>
                                <div class="tab-pane fade" id="video-tab-pane" role="tabpanel"
                                     aria-labelledby="video-tab" tabindex="0">
                                    <?php require_once 'widget/_popularVideo.php'; ?>
                                </div>
                                <div class="tab-pane fade" id="social-tab-pane" role="tabpanel"
                                     aria-labelledby="social-tab" tabindex="0">
                                    <?php require_once 'widget/_lastPosts.php'; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php
        require_once 'widget/_newsFeedFiltered.php';
        require_once 'widget/adv/_advMain.php';
        require_once 'widget/_similarProfile.php';
        require_once 'layout/_footer.php';

    } else {
        header("Location: /network/index.php");
    }
}
