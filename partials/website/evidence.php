<?php
require_once 'template/header.php';
$ea = Athlete::getRandom();
if ($ea) {
    $eaTitle = $ea->title ?? '';
    $eaBirthplace = $ea->birthplace ? $ea->birthplace . ',' : '';
    $eaBirthdate = $ea->birthdate ?? '';
    $eaBirthyear = $ea->birthyear ?? '';
    $eaPhoto = $ea->photo ?? '';
    $eaSport = $ea->sport ? $ea->sport . ' | ' : '';
    $eaActivity = $ea->activity ?? '';
    $eaNationality = $ea->nationality ?? '';
    $eaBio = strlen($ea->bio ?? '') > 300 ? substr($ea->bio, 0, 300) . '...' : $ea->bio ?? '';
    $eaBio = str_replace('<br>', '.', $eaBio);
}
?>
    <div class="container">
        <div class="row ">
            <?= getTitle("L'atleta in evidenza") ?>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="row align-items-center">
                    <div class="col-4 col-md-3 offset-md-0 ">
                        <a class="link-light text-decoration-none me-2" target="_blank"
                           href="<?= getLinkAtleta($ea->id, $eaTitle) ?>">
                            <img onerror="this.onerror=null; this.src='<?= SQUARE_PLACEHOLDER ?>';"
                                 class="img-fluid profile-photo-circle lazy"
                                 src="<?= SQUARE_PLACEHOLDER ?>"
                                 data-src="<?= SUB_ROOT . $eaPhoto ?>"
                                 data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-title="<?= $eaTitle ?>"
                                 alt="<?= T_ALT_PHOTO_ATHLETE ?> <?= $eaTitle ?>"
                            >
                        </a>
                    </div>
                    <div class="col-8 col-md-9 mt-3 mt-md-0">
                        <a class="link-spoome" href="<?= getLinkAtleta($ea->id, $eaTitle) ?>" target="_blank">
                            <h1><?= $eaTitle ?></h1></a>
                        <p>
                            <?php
                            if ($eaBirthplace or $eaBirthdate or $eaBirthyear) {
                                ?>
                                <?= ucwords($eaBirthplace) ?> <?= ucwords($eaBirthdate) ?> <?= ucwords($eaBirthyear) ?>
                                <br>
                                <?php
                            }
                            ?>
                            <?= ucwords($eaSport) ?><?= ucwords($eaNationality) ?>
                        </p>
                    </div>
                </div>
                <div class="col-12 mt-1 mt-md-3">
                    <p><?= $eaBio ?></p>
                </div>
            </div>
        </div>
    </div>
<?php
require_once 'template/footer.php';