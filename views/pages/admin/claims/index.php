<?php
/**
 * Coda rivendicazioni. @var array $requests @var int $total @var string $status
 * @var int $pendingCount @var int $page @var int $pages @var array|null $notice
 */
$tabs = ['pending' => 'admin.claims.tab_pending', 'approved' => 'admin.claims.tab_approved', 'rejected' => 'admin.claims.tab_rejected'];
?>
<header class="admin-head">
    <div>
        <h1 class="admin-title"><?= e(t('admin.nav.claims')) ?></h1>
        <p class="admin-subtitle"><?= e(t('admin.claims.subtitle', ['n' => (string) $pendingCount])) ?></p>
    </div>
    <a class="btn btn-primary" href="<?= e(url('admin/rivendicazioni/nuovo')) ?>"><i class="fa-solid fa-plus" aria-hidden="true"></i> <?= e(t('admin.claims.create_cta')) ?></a>
</header>

<?php if (!empty($notice)): ?>
    <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
<?php endif; ?>

<div class="admin-range">
    <?php foreach ($tabs as $key => $lbl): ?>
        <a href="<?= e(url('admin/rivendicazioni') . '?status=' . $key) ?>" class="admin-range-btn<?= $status === $key ? ' is-active' : '' ?>">
            <?= e(t($lbl)) ?><?php if ($key === 'pending' && $pendingCount > 0): ?> (<?= e((string) $pendingCount) ?>)<?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<section class="admin-panel admin-panel-flush">
    <?php if (!$requests): ?>
        <p class="muted admin-empty"><?= e(t('admin.claims.none')) ?></p>
    <?php else: ?>
        <ul class="admin-claim-list">
            <?php foreach ($requests as $r): ?>
                <li class="admin-claim-item">
                    <div class="admin-claim-body">
                        <div class="admin-claim-head">
                            <a class="admin-link" href="<?= e(url('atleti/' . $r['profile_handle'])) ?>" target="_blank" rel="noopener"><?= e($r['profile_name']) ?> ↗</a>
                            <span class="admin-handle">/<?= e($r['profile_handle']) ?></span>
                            <?php if ($r['claim_status'] !== 'unclaimed'): ?>
                                <span class="admin-badge admin-badge-suspended"><?= e(t('admin.claims.already_taken')) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="admin-claim-meta">
                            <i class="fa-solid fa-user" aria-hidden="true"></i> <?= e($r['user_email']) ?>
                            <span class="muted">· <?= e(time_ago((string) $r['created_at'])) ?></span>
                        </div>
                        <?php if (!empty($r['message'])): ?>
                            <p class="admin-claim-msg"><?= nl2br(e((string) $r['message'])) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if ($status === 'pending'): ?>
                        <div class="admin-claim-actions">
                            <form method="post" action="<?= e(url('admin/rivendicazioni/' . (int) $r['id'] . '/approva')) ?>" onsubmit="return confirm('<?= e(t('admin.claims.confirm_approve')) ?>');">
                                <?= csrf_field() ?>
                                <button class="btn btn-primary btn-sm"><i class="fa-solid fa-check" aria-hidden="true"></i> <?= e(t('admin.claims.approve')) ?></button>
                            </form>
                            <form method="post" action="<?= e(url('admin/rivendicazioni/' . (int) $r['id'] . '/rifiuta')) ?>" class="admin-claim-reject">
                                <?= csrf_field() ?>
                                <input class="input input-sm" type="text" name="note" maxlength="500" placeholder="<?= e(t('admin.claims.reject_note_ph')) ?>">
                                <button class="btn btn-ghost btn-sm"><?= e(t('admin.claims.reject')) ?></button>
                            </form>
                        </div>
                    <?php else: ?>
                        <span class="admin-badge admin-badge-<?= $r['status'] === 'approved' ? 'active' : 'suspended' ?>"><?= e(t('claim.status.' . $r['status'])) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if ($pages > 1): ?>
    <nav class="admin-pager">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/rivendicazioni') . '?status=' . $status . '&page=' . ($page - 1)) ?>"><?= e(t('admin.prev')) ?></a><?php endif; ?>
        <span class="muted"><?= e(t('admin.page_of', ['a' => (string) $page, 'b' => (string) $pages])) ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/rivendicazioni') . '?status=' . $status . '&page=' . ($page + 1)) ?>"><?= e(t('admin.next')) ?></a><?php endif; ?>
    </nav>
<?php endif; ?>
