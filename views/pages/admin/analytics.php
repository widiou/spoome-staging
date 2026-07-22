<?php
/**
 * M4 — Analytics d'uso (lettura on-demand). Vista MINIMA e funzionale: funnel per tipo, andamento
 * giornaliero dei due eventi attivi (search/profile_open) e conversione ricerca→apertura profilo.
 * Da rifinire lato UX/frontend (Bianca/Filippo) + mobile-overflow-check.
 *
 * @var int $range @var array $ranges @var array $funnel @var array $series @var array $conversion
 */
use Spoome\Domain\Admin\Chart;

$accent  = '#D8F21D'; // giallo, unico accento
$neutral = '#9BA1A9';

// Etichetta umana di un tipo di evento (fallback: il tipo grezzo).
$evLabel = static function (string $type): string {
    $key = 'admin.analytics.ev.' . $type;
    $label = t($key);
    return $label === $key ? $type : $label;
};
?>
<header class="admin-head">
    <div>
        <h1 class="admin-title"><?= e(t('admin.nav.analytics')) ?></h1>
        <p class="admin-subtitle"><?= e(t('admin.analytics.subtitle', ['n' => (string) $range])) ?></p>
    </div>
    <div class="admin-range">
        <?php foreach ($ranges as $r): ?>
            <a href="<?= e(url('admin/analytics') . '?range=' . $r) ?>" class="admin-range-btn<?= $range === $r ? ' is-active' : '' ?>"><?= e((string) $r) ?>g</a>
        <?php endforeach; ?>
    </div>
</header>

<section class="admin-panel">
    <div class="admin-panel-head">
        <h2 class="admin-panel-title"><?= e(t('admin.analytics.trend')) ?></h2>
        <div class="admin-legend">
            <span class="admin-legend-item"><i style="background:<?= $accent ?>"></i><?= e($evLabel('search')) ?></span>
            <span class="admin-legend-item"><i style="background:<?= $neutral ?>"></i><?= e($evLabel('profile_open')) ?></span>
        </div>
    </div>
    <div class="admin-chart">
        <?= Chart::line([
            ['name' => 'search',       'color' => $accent,  'values' => $series['search']],
            ['name' => 'profile_open', 'color' => $neutral, 'values' => $series['profile_open']],
        ], $series['labels']) ?>
    </div>
</section>

<section class="admin-panel">
    <div class="admin-panel-head">
        <h2 class="admin-panel-title"><?= e(t('admin.analytics.conversion')) ?></h2>
    </div>
    <p class="admin-subtitle"><?= e(t('admin.analytics.conv_hint')) ?></p>
    <div class="admin-stat-grid">
        <div class="admin-stat">
            <span class="admin-stat-label"><?= e(t('admin.analytics.searchers')) ?></span>
            <span class="admin-stat-num"><?= e((string) $conversion['from']) ?></span>
        </div>
        <div class="admin-stat">
            <span class="admin-stat-label"><?= e(t('admin.analytics.openers')) ?></span>
            <span class="admin-stat-num"><?= e((string) $conversion['to']) ?></span>
        </div>
        <div class="admin-stat">
            <span class="admin-stat-label">%</span>
            <span class="admin-stat-num"><?= $conversion['rate'] === null ? '&ndash;' : e((string) $conversion['rate']) . '%' ?></span>
        </div>
    </div>
</section>

<section class="admin-panel">
    <div class="admin-panel-head">
        <h2 class="admin-panel-title"><?= e(t('admin.analytics.counts')) ?></h2>
    </div>
    <?php if ($funnel === []): ?>
        <p class="admin-subtitle"><?= e(t('admin.analytics.empty')) ?></p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th><?= e(t('admin.analytics.event')) ?></th>
                    <th style="text-align:right"><?= e(t('admin.analytics.total')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($funnel as $type => $count): ?>
                    <tr>
                        <td><?= e($evLabel((string) $type)) ?> <span class="muted">(<?= e((string) $type) ?>)</span></td>
                        <td style="text-align:right"><?= e((string) $count) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
