<?php
if (isset($ra)) {
    $labelName = getShortName($ra);
    ?>
    <div class="col-6 col-md-2 g-2 my-2">
        <div class="card text-bg-dark">
            <a href="<?= getLinkAtleta($ra->id, $ra->title) ?>">
                <img onerror="this.onerror=null; this.src='<?= SQUARE_PLACEHOLDER ?>';" src="<?= SQUARE_PLACEHOLDER ?>"
                     data-src="<?= SUB_ROOT . $ra->photo ?>"
                     class="card-img-top profile-photo-card lazy" alt="Foto di <?= $ra->title ?>">
            </a>
            <div class="card-body">
                <div class="ps-2">
                    <a href="<?= getLinkAtleta($ra->id, $ra->title) ?>" class="link-light text-decoration-none">
                        <h6 class="card-title"><?= $labelName ?></h6>
                    </a>
                    <div class="" style="color: var(--light); text-transform: uppercase">
                        <p class="my-0 small"><?= ucfirst($ra->sport) ?></p>
                        <p class="my-0 small"><?= ucfirst($ra->nationality) ?? '-' ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}