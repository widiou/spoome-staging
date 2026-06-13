<?php
require_once 'bootstrap.php';
require_once 'layout/_header.php';
$active_filter = 'active';
if(isset($_GET['type'])){
    $federations = Organizations::getAll($_GET['type']);
    $type = $_GET['type'];
}else{
    $federations = Organizations::getAll();
    $type = "";
}

?>
    <div class="container mt-5">
    <div class="row mb-3">
        <?= getTitle("Organizzazioni") ?>
        <div class="col-12">
            <nav class="nav nav-pills flex-column flex-sm-row">
                <a class="flex-sm-fill text-sm-center nav-link <?= $type == "Federazione" ? $active_filter : ''?> me-3"  href="https://www.spoome.it<?= SUB_ROOT ?>/organizzazioni?type=Federazione">Federazioni</a>
                <a class="flex-sm-fill text-sm-center nav-link <?= $type == "Comitato" ? $active_filter : ''?> me-3" href="https://www.spoome.it<?= SUB_ROOT ?>/organizzazioni?type=Comitato">Comitati</a>
                <a class="flex-sm-fill text-sm-center nav-link <?= $type == "Gruppo Sportivo" ? $active_filter : ''?> me-3" href="https://www.spoome.it<?= SUB_ROOT ?>/organizzazioni?type=Gruppo Sportivo">Gruppi Sportivi</a>
                <a class="flex-sm-fill text-sm-center nav-link <?= $type == "PA" ? $active_filter : ''?> me-3" href="https://www.spoome.it<?= SUB_ROOT ?>/organizzazioni?type=PA">PA</a>
            </nav>
        </div>
    </div>
    <div class="row mb-5">
        <?php
        foreach ($federations as $fed) {
            if(!$fed->description or !$fed->photo) {
                continue;
            }
            $fedDescription = str_replace("'", " ", $fed->description ?? '-');
            $fedDescription = str_replace(' ', '-', $fedDescription);
            ?>
            <div class="col-6 col-md-2 g-2 my-2">
                <div class="card text-bg-dark">
                    <a href="<?= SUB_ROOT ?>/organizzazione/<?= $fedDescription ?>">
                        <img
                                src="<?= $fed->photo ?>"
                                class="card-img-top profile-photo-card-event"
                                alt="Foto di <?= $fed->description ?>" style="background: white">
                    </a>
                    <div class="card-body">
                        <div class="ps-2">
                            <a href="<?= SUB_ROOT ?>/organizzazione/<?= $fedDescription ?>" class="link-light text-decoration-none">
                                <h6 class="card-title"><?= substr($fed->description,0, 45) ?></h6>
                            </a>
                            <div class="" style="color: var(--light); text-transform: uppercase">
                                <p class="my-0 small"><?= cleanUrl($fed->type) ?? '-' ?></p>
                                <p class="my-0 small"><?= cleanUrl($fed->sport) ?? '' ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        }
        ?>
    </div>

<?php

require_once 'layout/_footer.php';
