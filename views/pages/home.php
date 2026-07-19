<?php
/** Landing. @var array $recent profili recenti (righe arricchite) */
use Spoome\Core\View;
?>
<main class="site-main">
    <section class="hero">
        <span class="hero-kicker"><?= e(t('home.kicker')) ?></span>
        <h1 class="hero-title"><?= e(t('home.title.a')) ?><br><?= e(t('home.title.b')) ?> <strong><?= e(t('home.title.c')) ?></strong></h1>
        <p class="hero-sub"><?= e(t('home.subtitle')) ?></p>
        <div class="hero-actions">
            <a href="<?= e(url('registrati')) ?>" class="btn btn-accent btn-lg"><?= e(t('home.cta_primary')) ?></a>
            <a href="<?= e(url('atleti')) ?>" class="btn btn-ghost btn-lg"><?= e(t('home.cta_secondary')) ?></a>
        </div>
    </section>

    <?php if (!empty($recent)): ?>
        <section class="container home-recent">
            <div class="section-head">
                <h2><?= e(t('home.recent.title')) ?></h2>
                <a href="<?= e(url('atleti')) ?>" class="section-link"><?= e(t('home.recent.all')) ?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
            </div>
            <div class="pcard-grid">
                <?php foreach ($recent as $p): ?>
                    <?= View::partial('profile-card', ['p' => $p]) ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</main>
