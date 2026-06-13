<?php
if (isset($obja)) {
    $cut = 23;
    $emptyPlaceholder = '-';
    $title = $obja->title ?? '';
    $photo = $obja->photo ?? '';
    $activity = substr(ucfirst($obja->activity ?? '-'), 0, $cut);
    $birthplace = substr($obja->birthplace ?? '', 0, $cut);
    $birthdate = substr($obja->birthdate ?? '', 0, $cut);
    $born = (ucwords($obja->birthdate ?? '') . ' ' . $obja->birthyear ?? '') ?? $emptyPlaceholder;
    $sport = ucfirst(substr($obja->sport ?? '-', 0, $cut));
    $shortBio = extractShortBio($obja->bio ?? '-');
    $instagram = generateLinkSocial($obja->instagram ?? '', 'ig');
    $facebook = generateLinkSocial($obja->facebook ?? '', 'fb');
    $twitter = generateLinkSocial($obja->twitter ?? '', 'x');
    $linkedin = generateLinkSocial($obja->linkedin ?? '', 'lk');
    $website = generateLinkSocial($obja->website ?? '', 'www');
    ?>
    <div class="container">
        <div class="row mt-4 mx-0">
            <div class="col-12">
                <div class="row align-items-center">
                    <div class="col-4 col-md-2">
                        <?php
                        if ($photo) {
                            ?>
                            <img onerror="this.onerror=null; this.src='<?= SQUARE_PLACEHOLDER ?>';"
                                 class="img-fluid profile-photo-circle lazy" src="<?= SQUARE_PLACEHOLDER ?>"
                                 data-src="<?= SUB_ROOT . $photo ?>"
                                 alt="Foto di <?= $title ?>">
                            <?php
                        }
                        ?>
                    </div>
                    <div class="col-8 col-md-10">
                        <?php
                        if (checkAdmin()) {
                            ?>
                            <div class="row mb-2">
                                <div class="col-12">
                                    <a href="<?= SUB_ROOT ?>/behind/editProfile.php?a=<?= $obja->id ?>" target="_blank"><i
                                                class="bi bi-pencil-square fs-3 link-spoome"></i></a>
                                </div>
                            </div>
                            <?php
                        }
                        ?>
                        <?= getTitle($title, 'h3') ?>
                        <div class="row" style="margin-top: -13px;">
                            <div class="col-12">
                                <?= $instagram ?> <?= $facebook ?> <?= $twitter ?> <?= $linkedin ?> <?= $website ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12 col-md-10 mt-3">
                        <div class="bio-header">
                            <div class="row mb-3">
                                <div class="col-12">
                                    <?= $shortBio ?>
                                    <?php
                                    if (strlen($obja->bio) > strlen($shortBio)) {
                                        ?>
                                        <a role="button" class="link-secondary text-decoration-none"
                                           onclick="document.getElementById('bio-tab').click();">...continua</a> <br>
                                        <?php
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row bio-details gy-2">
                    <div class="col-6">
                        <i class="bi bi bi-briefcase me-2 "></i>
                        <a class="link-spoome"
                           href="<?= SUB_ROOT ?>/professione/<?= !empty($activity) ? toSanitize($activity) : $emptyPlaceholder ?>">
                            <?= !empty($activity) ? $activity : $emptyPlaceholder ?>
                        </a>
                    </div>
                    <div class="col-6">
                        <i class="bi bi bi-geo-alt me-2"></i>
                        <a class="link-spoome"
                           href="<?= SUB_ROOT ?>/luogo/<?= !empty($birthplace) ? toSanitize($birthplace) : $emptyPlaceholder ?>">
                            <?= !empty($birthplace) ? $birthplace : $emptyPlaceholder ?>
                        </a>
                        <br>
                    </div>
                    <div class="col-6">
                        <i class="bi bi-award me-2"></i>
                        <a class="link-spoome"
                           href="<?= SUB_ROOT ?>/sport/<?= !empty($sport) ? toSanitize($sport) : $emptyPlaceholder ?>">
                            <?= !empty($sport) ? $sport : $emptyPlaceholder ?>
                        </a>
                    </div>
                    <div class="col-6">
                        <i class="bi bi-calendar2-heart me-2"></i>
                        <a class="link-spoome" href="<?= SUB_ROOT ?>/date.php?d=<?= $birthdate ?>">
                            <?= !empty($born) ? $born : $emptyPlaceholder ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}