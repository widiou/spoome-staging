<?php
/**
 * Elenco utenti admin. @var array $users @var int $total @var array $filters
 * @var int $page @var int $pages @var array|null $notice
 */
$qs = static function (array $over) use ($filters, $page): string {
    $p = array_merge(['q' => $filters['q'], 'status' => $filters['status'], 'role' => $filters['role'], 'page' => $page], $over);
    $p = array_filter($p, static fn($v) => $v !== '' && $v !== null);
    return $p ? '?' . http_build_query($p) : '';
};
?>
<header class="admin-head">
    <div>
        <h1 class="admin-title"><?= e(t('admin.nav.users')) ?></h1>
        <p class="admin-subtitle"><?= e(t('admin.users.count', ['n' => (string) $total])) ?></p>
    </div>
</header>

<?php if (!empty($notice)): ?>
    <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
<?php endif; ?>

<form class="admin-filters" method="get" action="<?= e(url('admin/utenti')) ?>">
    <input class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('admin.users.search_ph')) ?>">
    <select class="input" name="status">
        <option value=""><?= e(t('admin.users.all_status')) ?></option>
        <?php foreach (['active', 'pending', 'suspended'] as $st): ?>
            <option value="<?= e($st) ?>"<?= $filters['status'] === $st ? ' selected' : '' ?>><?= e(t('admin.status.' . $st)) ?></option>
        <?php endforeach; ?>
    </select>
    <select class="input" name="role">
        <option value=""><?= e(t('admin.users.all_roles')) ?></option>
        <?php foreach (['member', 'moderator', 'admin'] as $r): ?>
            <option value="<?= e($r) ?>"<?= $filters['role'] === $r ? ' selected' : '' ?>><?= e(t('admin.role.' . $r)) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit"><?= e(t('admin.users.filter')) ?></button>
</form>

<section class="admin-panel admin-panel-flush">
    <?php if (!$users): ?>
        <p class="muted admin-empty"><?= e(t('admin.users.none')) ?></p>
    <?php else: ?>
    <div class="admin-table-wrap">
    <table class="admin-table">
        <thead><tr>
            <th><?= e(t('admin.users.col_email')) ?></th>
            <th><?= e(t('admin.users.col_profile')) ?></th>
            <th><?= e(t('admin.users.col_role')) ?></th>
            <th><?= e(t('admin.users.col_status')) ?></th>
            <th><?= e(t('admin.users.col_last_login')) ?></th>
            <th></th>
        </tr></thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><a class="admin-link" href="<?= e(url('admin/utenti/' . (int) $u['id'])) ?>"><?= e($u['email']) ?></a></td>
                    <td class="muted">
                        <?php if (!empty($u['profile_handle'])): ?>
                            <?= e($u['profile_name']) ?> <span class="admin-handle">/<?= e($u['profile_handle']) ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><span class="admin-role admin-role-<?= e($u['role']) ?>"><?= e(t('admin.role.' . $u['role'])) ?></span></td>
                    <td><span class="admin-badge admin-badge-<?= e($u['status']) ?>"><?= e(t('admin.status.' . $u['status'])) ?></span></td>
                    <td class="muted admin-nowrap"><?= $u['last_login_at'] ? e(time_ago((string) $u['last_login_at'])) : '—' ?></td>
                    <td class="admin-nowrap"><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/utenti/' . (int) $u['id'])) ?>"><?= e(t('admin.users.manage')) ?></a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<?php if ($pages > 1): ?>
    <nav class="admin-pager">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/utenti') . $qs(['page' => $page - 1])) ?>"><?= e(t('admin.prev')) ?></a><?php endif; ?>
        <span class="muted"><?= e(t('admin.page_of', ['a' => (string) $page, 'b' => (string) $pages])) ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/utenti') . $qs(['page' => $page + 1])) ?>"><?= e(t('admin.next')) ?></a><?php endif; ?>
    </nav>
<?php endif; ?>
