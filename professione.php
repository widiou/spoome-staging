<?php
require_once 'bootstrap.php';
require_once 'models/Athlete.php';
if (array_key_exists('p', $_GET)) {
    $activity = $_GET['p'];
    $activity = str_replace('-', ' ', $activity);
    $title = "Professione: " . $activity;
    require_once 'layout/_header.php';
    $athletes = Athlete::getAthletesByProperty("activity", $activity);
    ?>
    <div class="container mt-5">
        <div class="row mb-3">
            <?= getTitle($activity) ?>
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
    require_once 'widget/adv/_advMain.php';
    require_once 'layout/_footer.php';
}