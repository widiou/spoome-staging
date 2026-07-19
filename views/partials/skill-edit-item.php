<?php
/**
 * Un elemento competenza nell'editor del profilo. Sorgente UNICA: usata sia dalla lista iniziale
 * sia dal frammento async (append dopo l'aggiunta) → escaping garantito da e() su ogni campo.
 * @var array $s (id, label, endorsements_count)
 */
$id  = (int) $s['id'];
$cnt = (int) ($s['endorsements_count'] ?? 0);
?>
<li class="edit-skill" data-detail-item>
    <span class="edit-skill-name"><?= e((string) $s['label']) ?></span>
    <?php if ($cnt > 0): ?><span class="edit-skill-count"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> <?= e((string) $cnt) ?></span><?php endif; ?>
    <form method="post" action="<?= e(url('profilo/competenze/' . $id . '/elimina')) ?>" class="del-form" data-async data-async-success="removeCard" data-target="[data-detail-item]">
        <?= csrf_field() ?>
        <button type="submit" class="icon-btn" aria-label="<?= e(t('profile.details.remove')) ?>"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
    </form>
</li>
