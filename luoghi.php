<?php
require_once 'bootstrap.php';
require_once 'models/Athlete.php';
if (array_key_exists('l', $_GET)) {
    $place = $_GET['l'];
    $place = str_replace('-', ' ', $place);
    $title = "Atleti nati a " . $place;
    require_once 'layout/_header.php';
    $athletes = Athlete::getAthletesByProperty("birthplace", $place);
    $news_filter = $place;
    ?>
    <div class="container mt-5">
        <div class="row mb-3">
            <?= getTitle('Atleti nati a ' . $place) ?>
        </div>
        <div class="row mb-5">
            <?php
            foreach ($athletes as $ra) {
                require 'widget/_card.php';
            }
            ?>
        </div>
    </div>
    <?php
    require_once 'widget/_newsFeedFiltered.php';
    require_once 'widget/adv/_advMain.php';
    require_once 'layout/_footer.php';
}