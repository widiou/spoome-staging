<?php
/**
 * Dashboard admin. @var array $metrics @var array $audit @var array|null $notice
 */
$u = $metrics['users']; $p = $metrics['profiles']; $c = $metrics['content'];
$g = $metrics['graph']; $h = $metrics['health'];
?>
<header class="admin-head">
    <div>
        <h1 class="admin-title"><?= e(t('admin.nav.dashboard')) ?></h1>
        <p class="admin-subtitle"><?= e(t('admin.dashboard.subtitle')) ?></p>
    </div>
</header>

<?php if (!empty($notice)): ?>
    <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
<?php endif; ?>

<?php if ($h['errors_24h'] > 0): ?>
    <a class="admin-health-alert" href="<?= e(url('admin/log')) ?>">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        <?= e(t('admin.dashboard.errors_alert', ['n' => (string) $h['errors_24h']])) ?>
    </a>
<?php endif; ?>

<div class="admin-stat-grid">
    <div class="admin-stat">
        <span class="admin-stat-num"><?= e((string) $u['total']) ?></span>
        <span class="admin-stat-label"><?= e(t('admin.stat.users')) ?></span>
        <span class="admin-stat-sub"><?= e(t('admin.stat.new_7d', ['n' => (string) $u['new_7d']])) ?></span>
    </div>
    <div class="admin-stat">
        <span class="admin-stat-num"><?= e((string) $u['active']) ?></span>
        <span class="admin-stat-label"><?= e(t('admin.stat.active')) ?></span>
        <span class="admin-stat-sub"><?= e($u['pending']) ?> <?= e(t('admin.stat.pending')) ?> · <?= e($u['suspended']) ?> <?= e(t('admin.stat.suspended')) ?></span>
    </div>
    <div class="admin-stat">
        <span class="admin-stat-num"><?= e((string) $p['total']) ?></span>
        <span class="admin-stat-label"><?= e(t('admin.stat.profiles')) ?></span>
        <span class="admin-stat-sub"><?= e((string) $u['staff']) ?> <?= e(t('admin.stat.staff')) ?></span>
    </div>
    <div class="admin-stat">
        <span class="admin-stat-num"><?= e((string) $c['posts_total']) ?></span>
        <span class="admin-stat-label"><?= e(t('admin.stat.posts')) ?></span>
        <span class="admin-stat-sub">+<?= e((string) $c['posts_7d']) ?> <?= e(t('admin.stat.last_7d')) ?></span>
    </div>
    <div class="admin-stat">
        <span class="admin-stat-num"><?= e((string) $c['messages_total']) ?></span>
        <span class="admin-stat-label"><?= e(t('admin.stat.messages')) ?></span>
        <span class="admin-stat-sub">+<?= e((string) $c['messages_7d']) ?> <?= e(t('admin.stat.last_7d')) ?></span>
    </div>
    <div class="admin-stat <?= $h['errors_24h'] > 0 ? 'admin-stat-warn' : '' ?>">
        <span class="admin-stat-num"><?= e((string) $h['errors_24h']) ?></span>
        <span class="admin-stat-label"><?= e(t('admin.stat.errors_24h')) ?></span>
        <span class="admin-stat-sub"><?= e((string) $h['warnings_24h']) ?> <?= e(t('admin.stat.warnings')) ?></span>
    </div>
</div>

<div class="admin-cols">
    <section class="admin-panel">
        <h2 class="admin-panel-title"><?= e(t('admin.dashboard.profiles_by_type')) ?></h2>
        <?php if (!$p['by_type']): ?>
            <p class="muted"><?= e(t('admin.dashboard.no_data')) ?></p>
        <?php else: ?>
            <ul class="admin-bars">
                <?php foreach ($p['by_type'] as $row):
                    $pct = $p['total'] > 0 ? round($row['count'] / $p['total'] * 100) : 0; ?>
                    <li class="admin-bar-row">
                        <span class="admin-bar-label"><?= e($row['label']) ?></span>
                        <span class="admin-bar-track"><span class="admin-bar-fill" style="width: <?= e((string) max(4, $pct)) ?>%"></span></span>
                        <span class="admin-bar-num"><?= e((string) $row['count']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h2 class="admin-panel-title admin-panel-title-sep"><?= e(t('admin.dashboard.graph')) ?></h2>
        <ul class="admin-kv">
            <li><span><?= e(t('admin.stat.follows')) ?></span><strong><?= e((string) $g['follows']) ?></strong></li>
            <li><span><?= e(t('admin.stat.connections')) ?></span><strong><?= e((string) $g['connections']) ?></strong></li>
            <li><span><?= e(t('admin.stat.connections_pending')) ?></span><strong><?= e((string) $g['connections_pending']) ?></strong></li>
        </ul>
    </section>

    <section class="admin-panel">
        <h2 class="admin-panel-title"><?= e(t('admin.dashboard.recent_signups')) ?></h2>
        <?php if (!$metrics['recent_signups']): ?>
            <p class="muted"><?= e(t('admin.dashboard.no_data')) ?></p>
        <?php else: ?>
            <ul class="admin-feed-list">
                <?php foreach ($metrics['recent_signups'] as $s): ?>
                    <li>
                        <a href="<?= e(url('admin/utenti/' . (int) $s['id'])) ?>"><?= e($s['email']) ?></a>
                        <span class="admin-badge admin-badge-<?= e($s['status']) ?>"><?= e(t('admin.status.' . $s['status'])) ?></span>
                        <span class="admin-feed-time"><?= e(time_ago((string) $s['created_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h2 class="admin-panel-title admin-panel-title-sep"><?= e(t('admin.dashboard.recent_errors')) ?></h2>
        <?php if (!$metrics['recent_errors']): ?>
            <p class="muted"><?= e(t('admin.dashboard.no_errors')) ?></p>
        <?php else: ?>
            <ul class="admin-feed-list admin-errors">
                <?php foreach ($metrics['recent_errors'] as $err): ?>
                    <li>
                        <span class="admin-err-msg"><?= e(mb_strimwidth((string) $err['message'], 0, 90, '…')) ?></span>
                        <span class="admin-err-meta"><?= e((string) ($err['path'] ?? '')) ?> · <?= e(time_ago((string) $err['created_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<section class="admin-panel">
    <h2 class="admin-panel-title"><?= e(t('admin.dashboard.audit')) ?></h2>
    <?php if (!$audit): ?>
        <p class="muted"><?= e(t('admin.dashboard.no_audit')) ?></p>
    <?php else: ?>
        <table class="admin-table">
            <thead><tr>
                <th><?= e(t('admin.audit.when')) ?></th>
                <th><?= e(t('admin.audit.admin')) ?></th>
                <th><?= e(t('admin.audit.action')) ?></th>
                <th><?= e(t('admin.audit.target')) ?></th>
                <th><?= e(t('admin.audit.ip')) ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($audit as $a): ?>
                    <tr>
                        <td class="admin-nowrap"><?= e(time_ago((string) $a['created_at'])) ?></td>
                        <td><?= e($a['admin_email']) ?></td>
                        <td><code class="admin-code"><?= e($a['action']) ?></code></td>
                        <td class="muted"><?= e(trim(($a['target_type'] ?? '') . ' ' . ($a['target_id'] ? '#' . $a['target_id'] : ''))) ?></td>
                        <td class="muted admin-nowrap"><?= e((string) ($a['ip'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
