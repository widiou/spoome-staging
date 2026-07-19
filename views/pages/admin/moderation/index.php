<?php
/**
 * Moderazione contenuti (post). @var array $posts @var int $total
 * @var int $page @var int $pages @var array|null $notice
 */
?>
<header class="admin-head">
    <div>
        <h1 class="admin-title"><?= e(t('admin.nav.moderation')) ?></h1>
        <p class="admin-subtitle"><?= e(t('admin.mod.subtitle', ['n' => (string) $total])) ?></p>
    </div>
</header>

<?php if (!empty($notice)): ?>
    <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
<?php endif; ?>

<p class="admin-hint muted"><?= e(t('admin.mod.privacy_note')) ?></p>

<section class="admin-panel admin-panel-flush">
    <?php if (!$posts): ?>
        <p class="muted admin-empty"><?= e(t('admin.mod.none')) ?></p>
    <?php else: ?>
        <ul class="admin-mod-list">
            <?php foreach ($posts as $po): ?>
                <li class="admin-mod-item">
                    <div class="admin-mod-body">
                        <div class="admin-mod-head">
                            <a class="admin-link" href="<?= e(url('atleti/' . $po['handle'])) ?>" target="_blank" rel="noopener"><?= e($po['display_name']) ?></a>
                            <span class="admin-handle">/<?= e($po['handle']) ?></span>
                            <span class="admin-mod-time muted"><?= e(time_ago((string) $po['created_at'])) ?></span>
                        </div>
                        <p class="admin-mod-text"><?= nl2br(e((string) $po['body'])) ?></p>
                    </div>
                    <form method="post" action="<?= e(url('admin/contenuti/' . (int) $po['id'] . '/elimina')) ?>" onsubmit="return confirm('<?= e(t('admin.mod.confirm')) ?>');">
                        <?= csrf_field() ?>
                        <button class="btn btn-danger btn-sm" aria-label="<?= e(t('admin.mod.delete')) ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if ($pages > 1): ?>
    <nav class="admin-pager">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/contenuti') . '?page=' . ($page - 1)) ?>"><?= e(t('admin.prev')) ?></a><?php endif; ?>
        <span class="muted"><?= e(t('admin.page_of', ['a' => (string) $page, 'b' => (string) $pages])) ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/contenuti') . '?page=' . ($page + 1)) ?>"><?= e(t('admin.next')) ?></a><?php endif; ?>
    </nav>
<?php endif; ?>
