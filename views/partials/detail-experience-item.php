<?php
/**
 * Un'esperienza nell'editor del profilo (summary + form di modifica inline + elimina).
 * Sorgente UNICA: lista iniziale e frammento async (append/replace) → e() su ogni campo dinamico.
 * @var array $x riga profile_experiences
 */
$id = (int) $x['id'];
?>
<li class="item" data-detail-item>
    <details class="item-edit">
        <summary class="item-summary">
            <span class="item-main">
                <span><strong><?= e($x['role']) ?></strong> · <?= e($x['org_name']) ?></span>
                <span class="item-sub">
                    <?php
                    $yr = trim((string) ($x['start_year'] ?? ''));
                    if ($x['is_current']) { $yr .= ($yr !== '' ? '–' : '') . t('profile.exp.present'); }
                    elseif (!empty($x['end_year'])) { $yr .= '–' . $x['end_year']; }
                    $bits = array_filter([$x['location'] ?? '', $yr]);
                    echo e(implode(' · ', $bits));
                    ?>
                </span>
                <?php if (!empty($x['description'])): ?><span class="item-desc"><?= e($x['description']) ?></span><?php endif; ?>
            </span>
            <span class="edit-hint"><i class="fa-solid fa-pen" aria-hidden="true"></i></span>
        </summary>
        <form method="post" action="<?= e(url('profilo/esperienze/' . $id)) ?>" class="add-form edit-inline" data-async data-async-success="replaceHtml" data-target="[data-detail-item]">
            <?= csrf_field() ?>
            <div class="field-row">
                <div class="field"><label><?= e(t('profile.exp.role')) ?> <span class="req">*</span></label><input class="input" type="text" name="role" maxlength="160" required value="<?= e($x['role']) ?>"></div>
                <div class="field"><label><?= e(t('profile.exp.org')) ?> <span class="req">*</span></label><input class="input" type="text" name="org_name" maxlength="160" required value="<?= e($x['org_name']) ?>"></div>
                <div class="field"><label><?= e(t('profile.exp.location')) ?></label><input class="input" type="text" name="location" maxlength="160" value="<?= e($x['location'] ?? '') ?>"></div>
            </div>
            <div class="field-row">
                <div class="field"><label><?= e(t('profile.exp.start')) ?></label><input class="input" type="number" name="start_year" min="1900" max="2100" value="<?= e($x['start_year'] ?? '') ?>"></div>
                <div class="field"><label><?= e(t('profile.exp.end')) ?></label><input class="input" type="number" name="end_year" min="1900" max="2100" value="<?= e($x['end_year'] ?? '') ?>"></div>
                <div class="field field-check"><label class="check"><input type="checkbox" name="is_current" value="1"<?= !empty($x['is_current']) ? ' checked' : '' ?>> <?= e(t('profile.exp.current')) ?></label></div>
            </div>
            <div class="field"><label><?= e(t('profile.exp.description')) ?></label><textarea class="input textarea" name="description" rows="2" maxlength="1000"><?= e($x['description'] ?? '') ?></textarea></div>
            <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit"><?= e(t('profile.details.save')) ?></button></div>
        </form>
    </details>
    <form method="post" action="<?= e(url('profilo/esperienze/' . $id . '/elimina')) ?>" class="del-form" data-async data-async-success="removeCard" data-target="[data-detail-item]">
        <?= csrf_field() ?>
        <button type="submit" class="icon-btn" aria-label="<?= e(t('profile.details.remove')) ?>"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
    </form>
</li>
