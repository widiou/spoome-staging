<?php
require_once 'bootstrap.php';
require_once 'models/Athlete.php';
if (array_key_exists('d', $_GET)) {
    $day = $_GET['d'];
    $title = "Atleti nati il " . $day;
    require_once 'layout/_header.php';
    $athletes = Athlete::getAthletesByProperty("birthdate", $day);
    ?>
    <div class="container mt-5">
        <div class="row mb-3">
            <?= getTitle("Atleti nati il " . $day) ?>
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