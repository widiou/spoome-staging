<?php
/**
 * Riga di raccomandazione RICEVUTA nell'editor. Testo libero grezzo → SEMPRE nl2br(e()). Ogni campo via e().
 * @var array $r        riga (author_display_name/author_handle/author_avatar_path + body/relationship/id)
 * @var bool  $pending  true → azioni Pubblica/Rifiuta (in attesa); false → Nascondi (già visibile)
 */
$pending      = $pending ?? false;
$authorName   = (string) ($r['author_display_name'] ?? '');
$authorHandle = (string) ($r['author_handle'] ?? '');
$href         = url('atleti/' . $authorHandle);
$recId        = (int) $r['id'];
?>
<li class="reco-item reco-manage" data-async-card>
    <a class="pv-avatar reco-avatar" href="<?= e($href) ?>">
        <?php if (!empty($r['author_avatar_path'])): ?><img src="<?= e(url($r['author_avatar_path'])) ?>" alt="" loading="lazy"><?php else: ?><?= e(initials($authorName)) ?><?php endif; ?>
    </a>
    <div class="reco-main">
        <div class="reco-head">
            <a class="reco-author" href="<?= e($href) ?>"><?= e($authorName) ?></a>
            <?php if (!empty($r['relationship'])): ?><span class="reco-rel"><?= e($r['relationship']) ?></span><?php endif; ?>
        </div>
        <p class="reco-text"><?= nl2br(e((string) $r['body'])) ?></p>
        <div class="reco-actions">
            <?php if ($pending): ?>
                <form method="post" action="<?= e(url('profilo/raccomandazioni/' . $recId . '/accetta')) ?>" class="reco-act" data-async data-async-success="reload">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-check" aria-hidden="true"></i> <?= e(t('reco.action.accept')) ?></button>
                </form>
                <form method="post" action="<?= e(url('profilo/raccomandazioni/' . $recId . '/nascondi')) ?>" class="reco-act" data-async data-async-success="reload">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm"><?= e(t('reco.action.reject')) ?></button>
                </form>
            <?php else: ?>
                <form method="post" action="<?= e(url('profilo/raccomandazioni/' . $recId . '/nascondi')) ?>" class="reco-act" data-async data-async-success="reload">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm"><i class="fa-solid fa-eye-slash" aria-hidden="true"></i> <?= e(t('reco.action.hide')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</li>
