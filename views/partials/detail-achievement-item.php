<?php
/**
 * Un palmarès nell'editor del profilo. Sorgente UNICA (lista iniziale + frammento async).
 * @var array $a riga profile_achievements
 */
$id = (int) $a['id'];
?>
<li class="item" data-detail-item>
    <details class="item-edit">
        <summary class="item-summary">
            <span class="item-main">
                <span><strong><?= e($a['title']) ?></strong><?php if (!empty($a['year'])): ?> · <?= e($a['year']) ?><?php endif; ?></span>
                <?php if (!empty($a['description'])): ?><span class="item-desc"><?= e($a['description']) ?></span><?php endif; ?>
            </span>
            <span class="edit-hint"><i class="fa-solid fa-pen" aria-hidden="true"></i></span>
        </summary>
        <form method="post" action="<?= e(url('profilo/palmares/' . $id)) ?>" class="add-form edit-inline" data-async data-async-success="replaceHtml" data-target="[data-detail-item]">
            <?= csrf_field() ?>
            <div class="field-row">
                <div class="field" style="grid-column: span 2"><label><?= e(t('profile.ach.name')) ?> <span class="req">*</span></label><input class="input" type="text" name="title" maxlength="200" required value="<?= e($a['title']) ?>"></div>
                <div class="field"><label><?= e(t('profile.ach.year')) ?></label><input class="input" type="number" name="year" min="1900" max="2100" value="<?= e($a['year'] ?? '') ?>"></div>
            </div>
            <div class="field"><label><?= e(t('profile.ach.description')) ?></label><input class="input" type="text" name="description" maxlength="500" value="<?= e($a['description'] ?? '') ?>"></div>
            <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit"><?= e(t('profile.details.save')) ?></button></div>
        </form>
    </details>
    <form method="post" action="<?= e(url('profilo/palmares/' . $id . '/elimina')) ?>" class="del-form" data-async data-async-success="removeCard" data-target="[data-detail-item]">
        <?= csrf_field() ?>
        <button type="submit" class="icon-btn" aria-label="<?= e(t('profile.details.remove')) ?>"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
    </form>
</li>
