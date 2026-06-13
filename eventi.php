<?php
require_once 'bootstrap.php';
require_once 'layout/_header.php';
$events = Event::getAll();
?>
    <div class="container mt-5">
    <div class="row mb-3">
        <?= getTitle("I grandi eventi") ?>
    </div>
    <div class="row mb-5">
        <?php
        foreach ($events as $ev) {
            $eventDescription = str_replace("'", " ", $ev->description ?? '-');
            $eventDescription = str_replace(' ', '-', $eventDescription);
            ?>
            <div class="col-6 col-md-2 g-2 my-2">
                <div class="card text-bg-dark">
                    <a href="/network/evento/<?= $eventDescription ?>">
                        <img
                             src="<?= $ev->photo ?>"
                             class="card-img-top profile-photo-card-event"
                             alt="Foto di <?= $ev->description ?>" style="background: white">
                    </a>
                    <div class="card-body">
                        <div class="ps-2">
                            <a href="/network/evento/<?= $eventDescription ?>" class="link-light text-decoration-none">
                                <h6 class="card-title"><?= $ev->description ?></h6>
                            </a>
                            <div class="" style="color: var(--light); text-transform: uppercase">
                                <p class="my-0 small"><?= ucfirst($ev->description) ?></p>
                                <p class="my-0 small"><?= cleanUrl($ev->sport) ?? '-' ?></p>
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
