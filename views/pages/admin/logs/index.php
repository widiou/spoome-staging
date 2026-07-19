<?php
/**
 * Log raggruppati per fingerprint. @var array $groups @var int $total @var array $filters
 * @var array $levels @var array $channels @var array $counts @var int $page @var int $pages
 */
$qs = static function (array $over) use ($filters, $page): string {
    $p = array_merge(['level' => $filters['level'], 'channel' => $filters['channel'], 'q' => $filters['q'], 'page' => $page], $over);
    $p = array_filter($p, static fn($v) => $v !== '' && $v !== null);
    return $p ? '?' . http_build_query($p) : '';
};
$isErr = static fn(string $l): bool => in_array($l, ['error', 'critical', 'alert', 'emergency'], true);
?>
<header class="admin-head">
    <div>
        <h1 class="admin-title"><?= e(t('admin.nav.logs')) ?></h1>
        <p class="admin-subtitle"><?= e(t('admin.logs.subtitle', ['n' => (string) $total])) ?></p>
    </div>
</header>

<div class="admin-log-chips">
    <?php foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $lv):
        if (empty($counts[$lv])) continue; ?>
        <a href="<?= e(url('admin/log') . '?level=' . $lv) ?>" class="admin-log-chip admin-lv admin-lv-<?= e($lv) ?>">
            <?= e(t('admin.logs.lv.' . $lv)) ?> <strong><?= e((string) $counts[$lv]) ?></strong>
        </a>
    <?php endforeach; ?>
    <span class="admin-log-chips-note muted"><?= e(t('admin.logs.last24h')) ?></span>
</div>

<form class="admin-filters" method="get" action="<?= e(url('admin/log')) ?>">
    <input class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('admin.logs.search_ph')) ?>">
    <select class="input" name="level">
        <option value=""><?= e(t('admin.logs.all_levels')) ?></option>
        <?php foreach ($levels as $lv): ?>
            <option value="<?= e($lv) ?>"<?= $filters['level'] === $lv ? ' selected' : '' ?>><?= e(t('admin.logs.lv.' . $lv)) ?></option>
        <?php endforeach; ?>
    </select>
    <select class="input" name="channel">
        <option value=""><?= e(t('admin.logs.all_channels')) ?></option>
        <?php foreach ($channels as $ch): ?>
            <option value="<?= e($ch) ?>"<?= $filters['channel'] === $ch ? ' selected' : '' ?>><?= e($ch) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit"><?= e(t('admin.users.filter')) ?></button>
    <?php if ($filters['level'] || $filters['channel'] || $filters['q']): ?>
        <a class="btn btn-ghost" href="<?= e(url('admin/log')) ?>"><?= e(t('admin.logs.clear')) ?></a>
    <?php endif; ?>
</form>

<section class="admin-panel admin-panel-flush">
    <?php if (!$groups): ?>
        <p class="muted admin-empty"><?= e(t('admin.logs.none')) ?></p>
    <?php else: ?>
    <div class="admin-table-wrap">
    <table class="admin-table admin-log-table">
        <thead><tr>
            <th><?= e(t('admin.logs.col_level')) ?></th>
            <th><?= e(t('admin.logs.col_message')) ?></th>
            <th><?= e(t('admin.logs.col_count')) ?></th>
            <th><?= e(t('admin.logs.col_last')) ?></th>
            <th></th>
        </tr></thead>
        <tbody>
            <?php foreach ($groups as $g): ?>
                <tr class="<?= $isErr((string) $g['level']) ? 'admin-log-row-err' : '' ?>">
                    <td><span class="admin-lv admin-lv-<?= e($g['level']) ?>"><?= e(t('admin.logs.lv.' . $g['level'])) ?></span></td>
                    <td>
                        <span class="admin-log-msg"><?= e(mb_strimwidth((string) $g['message'], 0, 120, '…')) ?></span>
                        <?php if (!empty($g['file'])): ?>
                            <span class="admin-log-loc muted"><?= e(basename((string) $g['file'])) ?><?= $g['line'] ? ':' . e((string) $g['line']) : '' ?> · <?= e((string) $g['channel']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><span class="admin-log-count"><?= e((string) $g['occurrences']) ?></span></td>
                    <td class="muted admin-nowrap"><?= e(time_ago((string) $g['last_seen'])) ?></td>
                    <td class="admin-nowrap"><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/log/' . rawurlencode((string) $g['fingerprint']))) ?>"><?= e(t('admin.logs.detail')) ?></a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<?php if ($pages > 1): ?>
    <nav class="admin-pager">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/log') . $qs(['page' => $page - 1])) ?>"><?= e(t('admin.prev')) ?></a><?php endif; ?>
        <span class="muted"><?= e(t('admin.page_of', ['a' => (string) $page, 'b' => (string) $pages])) ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/log') . $qs(['page' => $page + 1])) ?>"><?= e(t('admin.next')) ?></a><?php endif; ?>
    </nav>
<?php endif; ?>
