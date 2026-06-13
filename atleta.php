<?php

use GuzzleHttp\Exception\GuzzleException;

require_once 'bootstrap.php';
Athlete::insertInLog(str_replace(SUB_ROOT . '/atleti/', '', $_SERVER['REQUEST_URI']));
$title = "";
$today = new DateTime();
// Estrai ID dall’URL
$path = explode("/", parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$athleteData = explode("-", end($path)); // Divide "123-mario-rossi"
$athleteID = intval($athleteData[0]); // Prende solo l'ID

$searchAthlete = Athlete::findById($athleteID);
$athlete = $searchAthlete->title;

if ($athlete == '') {
    $searchAthlete = Athlete::findByTitle($_GET['a'] ?? '');
    if (!$searchAthlete) {
        $searchAthlete = Athlete::getRandom();
    }
} else {
    $searchAthlete = Athlete::findByTitle($athlete ?? '');
}

if ($searchAthlete) {
    $obja = $searchAthlete;
    $expiring = new DateTime($obja->expire);
    $interval = $today->diff($expiring);
    if ($interval->days >= 5 && $today > $expiring) {
        $objw = getAthleteFromWikipedia((string)$obja->title);
        if ($objw->bio) {
            $obja->bio = $objw->bio . "<br>Ultimo aggiornamento da Wikipedia: " . $today->format('d-m-Y');
            $obja->updateBio($obja->bio, $obja->getId());
            $obja->savePhotoToServer($objw->photo,
                $obja->getId());
        }
    }

    if ($obja->photo != '' and !str_contains($obja->photo, 'webp')) {
        try {
            $obja->savePhotoToServer($obja->photo,
                $obja->id);
        } catch (GuzzleException $e) {
            //error_log("Errore durante aggiornamento foto di " . $obja->title . ". Dettagli dell'errore: " . $e->getMessage());
        } catch (Exception $e) {
            //error_log("Errore durante aggiornamento foto di " . $obja->title . ". Dettagli dell'errore: " . $e->getMessage());
        }
    }

} else {
    $obja = getAthleteFromWikipedia($athlete);
}
$title = $obja->title . ": carriera, risultati e ultime notizie | ";
require_once 'layout/_header.php';

if ($obja->title != "") {
    $news_filter = $obja->surname;
    require_once 'widget/profile/_headerProfile.php';
    ?>
    <div class="container" id="topProfile">
        <div class="row mt-3 mt-md-5 mb-md-5">
            <div class="col-12">
                <ul class="nav nav-underline nav-justified " id="pDetails" role="tablist">
                    <li class="nav-item mx-1" role="presentation">
                        <button class="nav-link link-spoome-outline active" id="news-tab" data-bs-toggle="tab"
                                data-bs-target="#news-tab-pane" type="button" role="tab" aria-controls="news-tab-pane"
                                aria-selected="true">NOTIZIE
                        </button>
                    </li>
                    <li class="nav-item mx-1" role="presentation">
                        <button class="nav-link link-spoome-outline" id="video-tab" data-bs-toggle="tab"
                                data-bs-target="#video-tab-pane" type="button" role="tab" aria-controls="video-tab-pane"
                                aria-selected="false">VIDEO
                        </button>
                    </li>
                    <li class="nav-item mx-1" role="presentation">
                        <button class="nav-link link-spoome-outline" id="social-tab" data-bs-toggle="tab"
                                data-bs-target="#social-tab-pane"
                                type="button" role="tab" aria-controls="social-tab-pane" aria-selected="false">
                            SOCIAL
                        </button>
                    </li>
                    <li class="nav-item mx-1" role="presentation">
                        <button class="nav-link link-spoome-outline" id="bio-tab" data-bs-toggle="tab"
                                data-bs-target="#bio-tab-pane" type="button" role="tab" aria-controls="bio-tab-pane"
                                aria-selected="false">BIO
                        </button>
                    </li>
                </ul>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-12">

                <div class="tab-content " id="pDetailsContent">
                    <div class="tab-pane fade show active" id="news-tab-pane" role="tabpanel"
                         aria-labelledby="news-tab"
                         tabindex="0">
                        <?php require_once 'widget/_lastNews.php'; ?>
                    </div>
                    <div class="tab-pane fade" id="video-tab-pane" role="tabpanel" aria-labelledby="video-tab"
                         tabindex="0">
                        <?php require_once 'widget/_popularVideo.php'; ?>
                    </div>
                    <div class="tab-pane fade" id="social-tab-pane" role="tabpanel" aria-labelledby="social-tab"
                         tabindex="0">
                        <?php require_once 'widget/_lastPosts.php'; ?>
                    </div>
                    <div class="tab-pane fade" id="bio-tab-pane" role="tabpanel" aria-labelledby="bio-tab"
                         tabindex="0">
                        <?php require_once 'widget/profile/_bioProfile.php'; ?>
                    </div>
                    <div class="tab-pane fade" id="stats-tab-pane" role="tabpanel" aria-labelledby="stats-tab"
                         tabindex="0">
                    </div>
                </div>

            </div>
        </div>
    </div>
    <?php
} else {
    $title = "Ancora nessun risultato per " . $_GET['a'];
    ?>
    <div class="container">
        <div class="row my-5">
            <div class="col-12">
                <h3>Ops! Spoome è ancora in beta, non abbiamo nessun risultato per la tua ricerca.</h3>
                <hr>
                <p>Stiamo lavorando per costruire il più grande database di sport al mondo. Stay with us!</p>
            </div>
        </div>
    </div>
    <?php
}
?>

<?php
require_once 'widget/_newsFeedFiltered.php';
require_once 'widget/adv/_advMain.php';
require_once 'layout/_footer.php';