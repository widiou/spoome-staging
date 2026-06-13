<?php
if (isset($obja)) {
    $bio = formatBio($obja->bio ?? '');
    //$bio = replaceMultipleWithLinks($bio);
    $bio = trim($bio);
    if (str_ends_with($bio, '..')) {
        $bio = substr($bio, 0, -2);
    }
    ?>
    <div class="row my-3">
        <div class="col-12">
            <div class="col-12">
                <p class="mb-3 text-uppercase"><?= $obja->title ?> SU WIKIPEDIA</p>
            </div>
            <p id="bio-summary">
                <?= $bio ?>
            </p>
        </div>
    </div>
    <?php
}
?>
