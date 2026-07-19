<?php
/**
 * Scheda utente admin. @var array $u @var array $roles @var int $me @var array|null $notice
 */
$isSelf = (int) $u['id'] === $me;
?>
<header class="admin-head">
    <div>
        <a class="admin-back" href="<?= e(url('admin/utenti')) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> <?= e(t('admin.nav.users')) ?></a>
        <h1 class="admin-title"><?= e($u['email']) ?></h1>
        <p class="admin-subtitle">
            <span class="admin-role admin-role-<?= e($u['role']) ?>"><?= e(t('admin.role.' . $u['role'])) ?></span>
            <span class="admin-badge admin-badge-<?= e($u['status']) ?>"><?= e(t('admin.status.' . $u['status'])) ?></span>
            <?php if ($isSelf): ?><span class="admin-badge"><?= e(t('admin.users.you')) ?></span><?php endif; ?>
        </p>
    </div>
</header>

<?php if (!empty($notice)): ?>
    <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
<?php endif; ?>

<div class="admin-cols">
    <section class="admin-panel">
        <h2 class="admin-panel-title"><?= e(t('admin.users.details')) ?></h2>
        <ul class="admin-kv">
            <li><span><?= e(t('admin.users.id')) ?></span><strong>#<?= e((string) $u['id']) ?></strong></li>
            <li><span><?= e(t('admin.users.email_verified')) ?></span><strong><?= $u['email_verified_at'] ? e(time_ago((string) $u['email_verified_at'])) : t('admin.users.not_verified') ?></strong></li>
            <li><span><?= e(t('admin.users.registered')) ?></span><strong><?= e(time_ago((string) $u['created_at'])) ?></strong></li>
            <li><span><?= e(t('admin.users.last_login')) ?></span><strong><?= $u['last_login_at'] ? e(time_ago((string) $u['last_login_at'])) : '—' ?></strong></li>
            <li><span><?= e(t('admin.users.col_profile')) ?></span><strong>
                <?php if (!empty($u['profile_handle'])): ?>
                    <a class="admin-link" href="<?= e(url('atleti/' . $u['profile_handle'])) ?>" target="_blank" rel="noopener"><?= e($u['profile_name']) ?> ↗</a>
                <?php else: ?>—<?php endif; ?>
            </strong></li>
        </ul>
    </section>

    <section class="admin-panel">
        <h2 class="admin-panel-title"><?= e(t('admin.users.actions')) ?></h2>
        <?php if ($isSelf): ?>
            <p class="muted"><?= e(t('admin.users.self_note')) ?></p>
        <?php else: ?>
            <div class="admin-actions">
                <?php if ($u['status'] === 'suspended'): ?>
                    <form method="post" action="<?= e(url('admin/utenti/' . (int) $u['id'] . '/riattiva')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-primary btn-block"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> <?= e(t('admin.users.reactivate')) ?></button>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?= e(url('admin/utenti/' . (int) $u['id'] . '/sospendi')) ?>" onsubmit="return confirm('<?= e(t('admin.users.confirm_suspend')) ?>');">
                        <?= csrf_field() ?>
                        <button class="btn btn-danger btn-block"><i class="fa-solid fa-ban" aria-hidden="true"></i> <?= e(t('admin.users.suspend')) ?></button>
                    </form>
                <?php endif; ?>

                <?php if (!$u['email_verified_at']): ?>
                    <form method="post" action="<?= e(url('admin/utenti/' . (int) $u['id'] . '/verifica')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-ghost btn-block"><i class="fa-solid fa-envelope-circle-check" aria-hidden="true"></i> <?= e(t('admin.users.verify')) ?></button>
                    </form>
                <?php endif; ?>

                <?php if (!empty($u['profile_handle'])): $pv = !empty($u['profile_verified_at']); ?>
                    <form method="post" action="<?= e(url('admin/utenti/' . (int) $u['id'] . '/verifica-profilo')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn <?= $pv ? 'btn-ghost' : 'btn-primary' ?> btn-block"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> <?= e($pv ? t('admin.users.unverify_profile') : t('admin.users.verify_profile')) ?></button>
                    </form>
                <?php endif; ?>

                <form method="post" action="<?= e(url('admin/utenti/' . (int) $u['id'] . '/ruolo')) ?>" class="admin-role-form">
                    <?= csrf_field() ?>
                    <label class="field-label"><?= e(t('admin.users.change_role')) ?></label>
                    <div class="admin-inline">
                        <select class="input" name="role">
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= e($r) ?>"<?= $u['role'] === $r ? ' selected' : '' ?>><?= e(t('admin.role.' . $r)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-primary"><?= e(t('admin.users.apply')) ?></button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </section>
</div>
