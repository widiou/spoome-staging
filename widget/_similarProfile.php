<?php
if(isset($descriptionEvent) and $descriptionEvent != ""){
    $randomAthlete = Athlete::getAthletesByEvent($descriptionEvent);
}elseif (isset($obja)) {
    $randomAthlete = Athlete::getRandom6($obja->sport ?? '', $obja->activity ?? '');
} elseif (isset($ea)) {
    $randomAthlete = Athlete::getRandom6($ea->sport ?? '', $ea->activity ?? '');
} else {
    $randomAthlete = Athlete::getRandom6();
}
?>
<div class="container">
    <div class="row mb-5">
        <?= getTitle(T_TITLE_SIMILAR_PROFILE) ?>
        <?php
        foreach ($randomAthlete as $ra) {
            require 'widget/_card.php';
        }
        ?>
    </div>
</div>