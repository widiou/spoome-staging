<?php
/**
 * Elenco PAGINE-organizzazione per la verifica (ancora del badge "verificato dalla società", M3).
 * @var array $orgs @var int $total @var array $filters @var int $page @var int $pages @var array|null $notice
 */
$qs = static function (array $over) use ($filters, $page): string {
    $p = array_merge(['q' => $filters['q'], 'verified' => $filters['verified'], 'page' => $page], $over);
    $p = array_filter($p, static fn ($v) => $v !== '' && $v !== null);
    return $p ? '?' . http_build_query($p) : '';
};
?>
<header class="admin-head">
    <div>
        <h1 class="admin-title"><?= e(t('admin.nav.profiles')) ?></h1>
        <p class="admin-subtitle"><?= e(t('admin.profiles.count', ['n' => (string) $total])) ?></p>
    </div>
</header>

<?php if (!empty($notice)): ?>
    <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
<?php endif; ?>

<p class="muted admin-hint"><?= e(t('admin.profiles.hint')) ?></p>

<form class="admin-filters" method="get" action="<?= e(url('admin/profili')) ?>">
    <input class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('admin.profiles.search_ph')) ?>">
    <select class="input" name="verified">
        <option value=""><?= e(t('admin.profiles.all')) ?></option>
        <option value="verified"<?= $filters['verified'] === 'verified' ? ' selected' : '' ?>><?= e(t('admin.profiles.only_verified')) ?></option>
        <option value="unverified"<?= $filters['verified'] === 'unverified' ? ' selected' : '' ?>><?= e(t('admin.profiles.only_unverified')) ?></option>
    </select>
    <button class="btn btn-primary" type="submit"><?= e(t('admin.profiles.filter')) ?></button>
</form>

<section class="admin-panel admin-panel-flush">
    <?php if (!$orgs): ?>
        <p class="muted admin-empty"><?= e(t('admin.profiles.none')) ?></p>
    <?php else: ?>
    <div class="admin-table-wrap">
    <table class="admin-table">
        <thead><tr>
            <th><?= e(t('admin.profiles.col_name')) ?></th>
            <th><?= e(t('admin.profiles.col_type')) ?></th>
            <th><?= e(t('admin.profiles.col_status')) ?></th>
            <th></th>
        </tr></thead>
        <tbody>
            <?php foreach ($orgs as $o): $isVerified = !empty($o['verified_at']); ?>
                <tr>
                    <td>
                        <a class="admin-link" href="<?= e(url('atleti/' . $o['handle'])) ?>" target="_blank" rel="noopener"><?= e($o['display_name']) ?> <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>
                        <span class="admin-handle">/<?= e($o['handle']) ?></span>
                        <?php if (($o['claim_status'] ?? '') === 'unclaimed'): ?>
                            <span class="admin-badge"><?= e(t('admin.profiles.unclaimed')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="muted"><?= e((string) ($o['type_label'] ?? '')) ?></td>
                    <td>
                        <?php if ($isVerified): ?>
                            <span class="admin-badge admin-badge-active"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> <?= e(t('admin.profiles.verified')) ?></span>
                        <?php else: ?>
                            <span class="admin-badge"><?= e(t('admin.profiles.not_verified')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="admin-nowrap">
                        <?php if ($isVerified): ?>
                            <form method="post" action="<?= e(url('admin/profili/' . (int) $o['id'] . '/rimuovi-verifica')) ?>" onsubmit="return confirm('<?= e(t('admin.profiles.confirm_unverify')) ?>');">
                                <?= csrf_field() ?>
                                <button class="btn btn-ghost btn-sm"><i class="fa-solid fa-xmark" aria-hidden="true"></i> <?= e(t('admin.profiles.unverify')) ?></button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= e(url('admin/profili/' . (int) $o['id'] . '/verifica')) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn-primary btn-sm"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> <?= e(t('admin.profiles.verify')) ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<?php if ($pages > 1): ?>
    <nav class="admin-pager">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/profili') . $qs(['page' => $page - 1])) ?>"><?= e(t('admin.prev')) ?></a><?php endif; ?>
        <span class="muted"><?= e(t('admin.page_of', ['a' => (string) $page, 'b' => (string) $pages])) ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/profili') . $qs(['page' => $page + 1])) ?>"><?= e(t('admin.next')) ?></a><?php endif; ?>
    </nav>
<?php endif; ?>
