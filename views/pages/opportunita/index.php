<?php
/**
 * Bacheca pubblica delle opportunità. @var array $items @var int $total @var array $sports
 * @var string $selectedSport @var string $selectedRegion @var bool $canPublish @var array|null $notice
 */
use Spoome\Core\View;
?>
<main class="site-main">
    <section class="container">
        <header class="page-head">
            <div>
                <h1><?= e(t('opp.index.title')) ?></h1>
                <p class="muted"><?= e(t('opp.index.subtitle')) ?></p>
            </div>
            <?php if ($canPublish): ?>
                <a class="btn btn-primary" href="<?= e(url('opportunita/pubblica')) ?>">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i> <?= e(t('opp.index.publish_cta')) ?>
                </a>
            <?php endif; ?>
        </header>

        <?php if (!empty($notice)): ?>
            <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
        <?php endif; ?>

        <form class="opp-filters" method="get" action="<?= e(url('opportunita')) ?>" role="search">
            <label class="field">
                <span class="field-label"><?= e(t('opp.index.filter.sport')) ?></span>
                <select name="sport">
                    <option value=""><?= e(t('opp.index.filter.all_sports')) ?></option>
                    <?php foreach ($sports as $s): ?>
                        <option value="<?= e($s['slug']) ?>" <?= $selectedSport === $s['slug'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label"><?= e(t('opp.index.filter.region')) ?></span>
                <input type="text" name="region" value="<?= e($selectedRegion) ?>" maxlength="80" autocomplete="off">
            </label>
            <button type="submit" class="btn"><?= e(t('opp.index.filter.apply')) ?></button>
        </form>

        <?php if (!$items): ?>
            <div class="empty-state">
                <p><?= e(t('opp.index.empty')) ?></p>
                <p class="muted"><?= e(t('opp.index.empty_hint')) ?></p>
            </div>
        <?php else: ?>
            <div class="opp-list">
                <?php foreach ($items as $o): ?>
                    <?= View::partial('opportunity-card', ['o' => $o]) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
