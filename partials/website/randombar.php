<?php
require_once 'template/header.php';
$lastTen = Athlete::getLastTen();

?>
<div class="container-fluid mb-3">
    <div class="row">
        <div class="col-12 slider-container" id="slider-container">
            <?php
            foreach ($lastTen as $lt) {
                $shortname = getShortName($lt, 10);

                ?>
                <div class="d-inline-flex align-items-top">
                    <a class="link-light text-decoration-none me-2" target="_blank" aria-current="page"
                       href="<?= getLinkAtleta($lt->id, $lt->title) ?>">
                        <img onerror="this.onerror=null; this.src='<?= SQUARE_PLACEHOLDER ?>';"
                             class="img-fluid profile-photo-circle-small lazy"
                             src="<?= SQUARE_PLACEHOLDER ?>"
                             data-src="<?= SUB_ROOT . $lt->photo ?>"
                             data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-title="<?= $lt->title ?>"
                             alt="Foto di <?= $lt->title ?>"
                        >
                        <span class="d-block mt-1 text-wrap small"><?= $shortname ?></span>
                    </a>
                </div>
                <?php
            }
            ?>
        </div>
    </div>
</div>
<?php
require_once 'template/footer.php';
?>
