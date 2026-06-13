<?php
require_once 'template/header.php';
$today = getTodayDate();
$birthdays = Athlete::getLastTen($_GET['sport'] ?? '', '', $today, '');
$labelDay = ucwords($today ?? '');
if ($birthdays) {
    ?>
    <div class="container">
        <div class="row mb-5">
            <?= getTitle('Atleti nati il ' . $labelDay, 'h3') ?>
            <div class="col-12 slider-container">
                <?php
                foreach ($birthdays as $ba) {
                    $baTitle = $ba->title ?? '';
                    $baPhoto = SUB_ROOT . $ba->photo ?? '';
                    $baSurname = getShortName($ba);
                    ?>
                    <div class="d-inline-flex align-items-top">
                        <a class="link-light text-decoration-none me-2" aria-current="page"
                           href="<?= SUB_ROOT ?>/atleta.php?a=<?= $baTitle ?>">
                            <img onerror="this.onerror=null; this.src='<?= SQUARE_PLACEHOLDER ?>';"
                                 class="img-fluid profile-photo-circle-small lazy"
                                 style="height: 128px; width: 128px;"
                                 src="<?= SQUARE_PLACEHOLDER ?>"
                                 data-src="<?= $baPhoto ?>"
                                 data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-title="<?= $baTitle ?>"
                                 alt="<?= T_ALT_PHOTO_ATHLETE . ' ' . $baTitle ?>"
                            >
                            <span class="d-block mt-1 text-wrap small"><?= $baSurname ?></span>
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
require_once 'template/footer.php';

