<?php
$lastAthletes = Athlete::getLast24();
if ($lastAthletes) {
    ?>
    <div class="container">
        <div class="row mb-5">
            <?= getTitle(T_TITLE_LAST_PROFILE) ?>
            <div class="col-12 slider-container">
                <?php
                foreach ($lastAthletes as $la) {
                    $laTitle = $la->title ?? '';
                    $laPhoto = SUB_ROOT . $la->photo ?? '';
                    $laSurname = getShortName($la);
                    ?>
                    <div class="d-inline-flex align-items-top">
                        <a class="link-light text-decoration-none me-2" aria-current="page"
                           href="<?= SUB_ROOT ?>/atleti/<?= $la->getId() . '-' . strtolower(str_replace(' ', '-', $laTitle)) ?>
">
                            <img onerror="this.onerror=null; this.src='<?= SQUARE_PLACEHOLDER ?>';"
                                 class="img-fluid profile-photo-circle-small lazy"
                                 style="height: 128px; width: 128px;"
                                 src="<?= SQUARE_PLACEHOLDER ?>"
                                 data-src="<?= $laPhoto ?>"
                                 data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-title="<?= $laTitle ?>"
                                 alt="Foto di <?= $laTitle ?>"
                            >
                            <span class="d-block mt-1 text-wrap text-center text-small"><?= $laSurname ?></span>
                        </a>
                    </div>
                    <?php
                }
                ?>
            </div>
        </div>
    </div>
    <?php
}