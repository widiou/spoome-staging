<?php
/**
 * Occorrenze di un fingerprint. @var string $fingerprint @var array $rows
 */
$first = $rows[0] ?? null;
?>
<header class="admin-head">
    <div>
        <a class="admin-back" href="<?= e(url('admin/log')) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> <?= e(t('admin.nav.logs')) ?></a>
        <h1 class="admin-title"><?= $first ? e(t('admin.logs.lv.' . $first['level'])) : e(t('admin.nav.logs')) ?></h1>
        <p class="admin-subtitle admin-code"><?= e($fingerprint) ?></p>
    </div>
</header>

<?php if (!$rows): ?>
    <section class="admin-panel"><p class="muted"><?= e(t('admin.logs.none')) ?></p></section>
<?php else: ?>
    <section class="admin-panel">
        <h2 class="admin-panel-title"><?= e($first['message']) ?></h2>
        <?php if (!empty($first['file'])): ?>
            <p class="muted admin-code-block"><?= e((string) $first['file']) ?><?= $first['line'] ? ':' . e((string) $first['line']) : '' ?></p>
        <?php endif; ?>
        <p class="admin-subtitle"><?= e(t('admin.logs.occurrences', ['n' => (string) count($rows)])) ?></p>
    </section>

    <section class="admin-panel admin-panel-flush">
        <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr>
                <th><?= e(t('admin.audit.when')) ?></th>
                <th><?= e(t('admin.logs.col_req')) ?></th>
                <th><?= e(t('admin.audit.ip')) ?></th>
                <th><?= e(t('admin.logs.col_ctx')) ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="admin-nowrap"><?= e(time_ago((string) $r['created_at'])) ?></td>
                        <td class="muted"><?= e((string) ($r['method'] ?? '')) ?> <?= e((string) ($r['path'] ?? '')) ?></td>
                        <td class="muted admin-nowrap"><?= e((string) ($r['ip'] ?? '')) ?></td>
                        <td class="muted">
                            <?php if (!empty($r['context'])): ?>
                                <code class="admin-code"><?= e(mb_strimwidth((string) $r['context'], 0, 100, '…')) ?></code>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
<?php endif; ?>
