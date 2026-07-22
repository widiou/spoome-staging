<?php
/**
 * "Le mie opportunità" (org). @var array $items @var int $total @var array|null $notice
 */
use Spoome\Core\View;
?>
<main class="site-main">
    <section class="container">
        <header class="page-head">
            <div>
                <h1><?= e(t('opp.mine.title')) ?></h1>
                <p class="muted"><?= e(t('opp.mine.subtitle')) ?></p>
            </div>
            <a class="btn btn-primary" href="<?= e(url('opportunita/pubblica')) ?>">
                <i class="fa-solid fa-plus" aria-hidden="true"></i> <?= e(t('opp.index.publish_cta')) ?>
            </a>
        </header>

        <?php if (!empty($notice)): ?>
            <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
        <?php endif; ?>

        <?php if (!$items): ?>
            <div class="empty-state"><p><?= e(t('opp.mine.empty')) ?></p></div>
        <?php else: ?>
            <div class="opp-list">
                <?php foreach ($items as $o): ?>
                    <?= View::partial('opportunity-card', ['o' => $o, 'manage' => true]) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
