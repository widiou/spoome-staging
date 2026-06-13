<?php
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
    ?>
    <div class="container">
        <div class="row ">
            <?= getTitle(T_TITLE_EVIDENCE) ?>
        </div>
        <div class="row mb-5 gx-md-5 align-items-center">
            <div class="col-12 col-md-6">
                <div class="row align-items-center">
                    <div class="col-4 col-md-3 offset-md-0 ">

                            <img onerror="this.onerror=null; this.src='<?= SQUARE_PLACEHOLDER ?>';"
                                 class="img-fluid profile-photo-circle lazy"
                                 src="<?= SQUARE_PLACEHOLDER ?>"
                                 data-src="<?= SUB_ROOT . $eaPhoto ?>"
                                 alt="<?= T_ALT_PHOTO_ATHLETE ?> <?= $eaTitle ?>"
                            >

                    </div>
                    <div class="col-8 col-md-9 mt-3 mt-md-0">
                        <a class="link-spoome" href="<?= getLinkAtleta($ea->id, $eaTitle) ?>">
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
                    <a href="https://spoome.it/human-hero/" role="button" class="btn btn-spoome mt-2">SCOPRI HUMAN
                        HERO!</a>
                </div>

            </div>
            <div class="col-12 col-md-6 mt-3 mt-md-0">
                <div class="row px-0 mx-0">
                    <div class="col-12 px-0 mx-0">
                        <div class="ratio ratio-16x9">
                            <iframe style="border-radius: 25px" width="100%" height="600"
                                    src="https://www.youtube.com/embed/ODtlCy72uhQ?si=pIe80NhgWnXSdcdm&autoplay=1"
                                    title="YouTube video player" frameborder="0" allow="autoplay"
                                    referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}