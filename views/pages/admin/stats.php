<?php
/**
 * Statistiche avanzate. @var int $range @var array $ranges @var array $kpis
 * @var array $series @var array $breakdowns @var array $funnel @var array $leaderboards
 */
use Spoome\Domain\Admin\Chart;

$colors = ['users' => '#D8F21D', 'posts' => '#EDEFF2', 'messages' => '#9BA1A9'];

// Badge di variazione percentuale periodo-su-periodo.
$delta = static function (?float $d): string {
    if ($d === null) {
        return '<span class="admin-delta admin-delta-up">' . e(t('admin.stats.new')) . '</span>';
    }
    if ($d > 0)  return '<span class="admin-delta admin-delta-up">▲ ' . e((string) $d) . '%</span>';
    if ($d < 0)  return '<span class="admin-delta admin-delta-down">▼ ' . e((string) abs($d)) . '%</span>';
    return '<span class="admin-delta admin-delta-flat">–</span>';
};

$kpiMeta = [
    'users'       => ['label' => t('admin.stat.users'),       'series' => 'users'],
    'posts'       => ['label' => t('admin.stat.posts'),       'series' => 'posts'],
    'messages'    => ['label' => t('admin.stat.messages'),    'series' => 'messages'],
    'connections' => ['label' => t('admin.stat.connections'), 'series' => null],
];
?>
<header class="admin-head">
    <div>
        <h1 class="admin-title"><?= e(t('admin.nav.stats')) ?></h1>
        <p class="admin-subtitle"><?= e(t('admin.stats.subtitle', ['n' => (string) $range])) ?></p>
    </div>
    <div class="admin-range">
        <?php foreach ($ranges as $r): ?>
            <a href="<?= e(url('admin/statistiche') . '?range=' . $r) ?>" class="admin-range-btn<?= $range === $r ? ' is-active' : '' ?>"><?= e((string) $r) ?>g</a>
        <?php endforeach; ?>
    </div>
</header>

<div class="admin-stat-grid">
    <?php foreach ($kpiMeta as $key => $meta): $k = $kpis[$key]; ?>
        <div class="admin-stat">
            <span class="admin-stat-label"><?= e($meta['label']) ?></span>
            <span class="admin-stat-num"><?= e((string) $k['current']) ?></span>
            <span class="admin-stat-row">
                <?= $delta($k['delta']) ?>
                <span class="admin-stat-prev"><?= e(t('admin.stats.prev', ['n' => (string) $k['previous']])) ?></span>
            </span>
            <?php if ($meta['series'] !== null): ?>
                <?= Chart::spark($series['series'][$meta['series']], $colors[$meta['series']]) ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<section class="admin-panel">
    <div class="admin-panel-head">
        <h2 class="admin-panel-title"><?= e(t('admin.stats.trend')) ?></h2>
        <div class="admin-legend">
            <span class="admin-legend-item"><i style="background:<?= $colors['users'] ?>"></i><?= e(t('admin.stat.users')) ?></span>
            <span class="admin-legend-item"><i style="background:<?= $colors['posts'] ?>"></i><?= e(t('admin.stat.posts')) ?></span>
            <span class="admin-legend-item"><i style="background:<?= $colors['messages'] ?>"></i><?= e(t('admin.stat.messages')) ?></span>
        </div>
    </div>
    <div class="admin-chart">
        <?= Chart::line([
            ['name' => 'users',    'color' => $colors['users'],    'values' => $series['series']['users']],
            ['name' => 'posts',    'color' => $colors['posts'],    'values' => $series['series']['posts']],
            ['name' => 'messages', 'color' => $colors['messages'], 'values' => $series['series']['messages']],
        ], $series['labels']) ?>
    </div>
    <div class="admin-chart-axis">
        <span><?= e(date('d/m', strtotime($series['labels'][0]))) ?></span>
        <span><?= e(date('d/m', strtotime($series['labels'][count($series['labels']) - 1]))) ?></span>
    </div>
</section>

<div class="admin-cols">
    <section class="admin-panel">
        <h2 class="admin-panel-title"><?= e(t('admin.stats.funnel')) ?></h2>
        <p class="admin-hint muted"><?= e(t('admin.stats.funnel_hint')) ?></p>
        <?php $funnelMax = max(1, $funnel[0]['count']); ?>
        <ul class="admin-funnel">
            <?php foreach ($funnel as $i => $stage):
                $pct = round($stage['count'] / $funnelMax * 100);
                $conv = $i === 0 ? 100 : round($stage['count'] / $funnelMax * 100); ?>
                <li class="admin-funnel-row">
                    <span class="admin-funnel-label"><?= e(t('admin.' . $stage['label'])) ?></span>
                    <span class="admin-funnel-track"><span class="admin-funnel-fill" style="width: <?= e((string) max(3, $pct)) ?>%"></span></span>
                    <span class="admin-funnel-num"><?= e((string) $stage['count']) ?> <small><?= e((string) $conv) ?>%</small></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="admin-panel">
        <h2 class="admin-panel-title"><?= e(t('admin.stats.breakdowns')) ?></h2>
        <?php
        // 'trans' = tradurre la label via chiave stato; altrimenti è già leggibile (tipo/sport).
        $blocks = [
            ['title' => 'admin.stats.by_status', 'rows' => $breakdowns['users_by_status'],    'trans' => 'admin.status.'],
            ['title' => 'admin.stats.by_type',   'rows' => $breakdowns['profiles_by_type'],   'trans' => null],
            ['title' => 'admin.stats.by_sport',  'rows' => $breakdowns['profiles_by_sport'],  'trans' => null],
        ];
        foreach ($blocks as $block):
            $rows = $block['rows'];
            $tot = array_sum(array_column($rows, 'count')) ?: 1; ?>
            <h3 class="admin-subhead"><?= e(t($block['title'])) ?></h3>
            <?php if (!$rows): ?>
                <p class="muted admin-hint"><?= e(t('admin.dashboard.no_data')) ?></p>
            <?php else: ?>
                <ul class="admin-bars">
                    <?php foreach ($rows as $row):
                        $pct = round($row['count'] / $tot * 100);
                        $label = $block['trans'] ? t($block['trans'] . $row['label']) : $row['label']; ?>
                        <li class="admin-bar-row">
                            <span class="admin-bar-label"><?= e($label) ?></span>
                            <span class="admin-bar-track"><span class="admin-bar-fill" style="width: <?= e((string) max(4, $pct)) ?>%"></span></span>
                            <span class="admin-bar-num"><?= e((string) $row['count']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endforeach; ?>
    </section>
</div>

<div class="admin-cols">
    <?php
    $boards = [
        'admin.stats.top_followers' => $leaderboards['top_followers'],
        'admin.stats.top_posters'   => $leaderboards['top_posters'],
    ];
    foreach ($boards as $titleKey => $rows): ?>
        <section class="admin-panel">
            <h2 class="admin-panel-title"><?= e(t($titleKey)) ?></h2>
            <?php if (!$rows): ?>
                <p class="muted"><?= e(t('admin.dashboard.no_data')) ?></p>
            <?php else: ?>
                <ol class="admin-rank">
                    <?php foreach ($rows as $row): ?>
                        <li>
                            <a class="admin-link" href="<?= e(url('atleti/' . $row['handle'])) ?>" target="_blank" rel="noopener"><?= e($row['display_name']) ?></a>
                            <span class="admin-rank-num"><?= e((string) $row['c']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>
