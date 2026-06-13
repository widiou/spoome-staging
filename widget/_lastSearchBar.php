<div class="container mb-3">
    <div class="row">
        <div class="col-12 slider-container" id="slider-container">
            <?php
            if (isset($obja)) {
                $lastTen = Athlete::getLastTen($obja->sport ?? '');
            } else if (isset($sport)) {
                $lastTen = Athlete::getLastTen($sport ?? '');
            } else if (isset($place)) {
                $lastTen = Athlete::getLastTen('', $place ?? '');
            } else if (isset($day)) {
                $lastTen = Athlete::getLastTen('', '', $day ?? '');
            } else if (isset($activity)) {
                $lastTen = Athlete::getLastTen('', '', '', $activity);
            } else {
                $lastTen = Athlete::getLastTen();
            }
            foreach ($lastTen as $lt) {
                if ($obja->title == $lt->title) {
                    continue;
                }
                $shortname = getShortName($lt, 10);
                ?>
                <div class="d-inline-flex align-items-top">
                    <a class="link-light text-decoration-none me-2" aria-current="page"
                       href="<?= getLinkAtleta($lt->id, $lt->title) ?>
">
                        <img onerror="this.onerror=null; this.src='<?= SQUARE_PLACEHOLDER ?>';"
                             class="img-fluid profile-photo-circle-small lazy"
                             src="<?= SQUARE_PLACEHOLDER ?>"
                             data-src="<?= SUB_ROOT . $lt->photo ?>"
                             data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-title="<?= $lt->title ?>"
                             alt="Foto di <?= $lt->title ?>"
                        >
                        <span class="d-block mt-1 text-wrap text-center text-small"><?= $shortname ?></span>
                    </a>
                </div>
                <?php
            }
            ?>
        </div>
    </div>
</div>