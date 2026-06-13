<?php
function renderImageUploader(string $label, string $fieldId, ?string $currentImage, string $note = '', int $maxWidth = 300)
{
    ?>
    <div class="mb-3">
        <label><?= $label ?> <?= $note ? "<small class='text-muted'>($note)</small>" : '' ?></label><br>
        <?php if ($currentImage): ?>
            <img src="/<?= htmlspecialchars($currentImage) ?>" alt="<?= $label ?>" class="img-thumbnail mb-2" width="120">
        <?php endif; ?>
        <input type="file" name="<?= $fieldId ?>_raw" id="<?= $fieldId ?>_raw" accept="image/*" class="form-control">
        <canvas id="preview_<?= $fieldId ?>" class="img-fluid d-none mt-3 border w-100"
                style="max-width:<?= $maxWidth ?>px; height:auto;"></canvas>
        <input type="hidden" name="<?= $fieldId ?>" id="<?= $fieldId ?>">
    </div>
    <?php
}
