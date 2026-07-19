<?php
/**
 * Un link nell'editor del profilo. Sorgente UNICA (lista iniziale + frammento async).
 * @var array $l riga profile_links @var array $linkKinds elenco kind ammessi
 */
$id = (int) $l['id'];
?>
<li class="item" data-detail-item>
    <details class="item-edit">
        <summary class="item-summary">
            <span class="item-main item-inline">
                <i class="<?= e(link_icon($l['kind'])) ?>" aria-hidden="true"></i>
                <span><?= e($l['label'] ?: link_kind_label($l['kind'])) ?></span>
                <span class="item-sub"><?= e($l['url']) ?></span>
            </span>
            <span class="edit-hint"><i class="fa-solid fa-pen" aria-hidden="true"></i></span>
        </summary>
        <form method="post" action="<?= e(url('profilo/link/' . $id)) ?>" class="add-form edit-inline" data-async data-async-success="replaceHtml" data-target="[data-detail-item]">
            <?= csrf_field() ?>
            <div class="field-row">
                <div class="field"><label><?= e(t('profile.link.kind')) ?></label>
                    <select class="select" name="kind">
                        <?php foreach ($linkKinds as $k): ?>
                            <option value="<?= e($k) ?>"<?= $l['kind'] === $k ? ' selected' : '' ?>><?= e(link_kind_label($k)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label><?= e(t('profile.link.label')) ?></label><input class="input" type="text" name="label" maxlength="120" value="<?= e($l['label'] ?? '') ?>"></div>
                <div class="field"><label><?= e(t('profile.link.url')) ?> <span class="req">*</span></label><input class="input" type="text" name="url" maxlength="500" required value="<?= e(str_starts_with((string) $l['url'], 'mailto:') ? substr((string) $l['url'], 7) : $l['url']) ?>"></div>
            </div>
            <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit"><?= e(t('profile.details.save')) ?></button></div>
        </form>
    </details>
    <form method="post" action="<?= e(url('profilo/link/' . $id . '/elimina')) ?>" class="del-form" data-async data-async-success="removeCard" data-target="[data-detail-item]">
        <?= csrf_field() ?>
        <button type="submit" class="icon-btn" aria-label="<?= e(t('profile.details.remove')) ?>"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
    </form>
</li>
