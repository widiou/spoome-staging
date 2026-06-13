<?php
require_once 'bootstrap.php';
require_once 'layout/_header.php';
$federations = Organizations::getAll();
?>
    <div class="container mt-5">
    <div class="row mb-3">
        <?= getTitle("Federazioni") ?>
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
                    <a href="/network/federazione/<?= $fedDescription ?>">
                        <img
                                src="<?= $fed->photo ?>"
                                class="card-img-top profile-photo-card-event"
                                alt="Foto di <?= $fed->description ?>" style="background: white">
                    </a>
                    <div class="card-body">
                        <div class="ps-2">
                            <a href="/network/federazione/<?= $fedDescription ?>" class="link-light text-decoration-none">
                                <h6 class="card-title"><?= $fed->description ?></h6>
                            </a>
                            <div class="" style="color: var(--light); text-transform: uppercase">
                                <p class="my-0 small"><?= cleanUrl($fed->sport) ?? '-' ?></p>
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
