<?php
/**
 * Onboarding Atleta · step 3/3 — opportunità pre-filtrate dal profilo (disciplina + regione).
 * @var array $profile @var array $items
 *
 * "Non è un link da cliccare: /opportunita pre-filtrata È questo step" (spec Bianca) — le card sono
 * le STESSE del browse pubblico (partial opportunity-card), incorporate qui, non un link verso /opportunita.
 */
use Spoome\Core\View;

$sportName = (string) ($profile['sport_name'] ?? '');
$sportSlug = (string) ($profile['sport_slug'] ?? '');
$location  = trim((string) ($profile['location_region'] ?? ($profile['location_city'] ?? '')));
?>
<main class="site-main">
    <section class="container narrow onboard-step">
        <p class="onboard-kicker"><?= e(t('onboard.step_of', ['n' => 3])) ?></p>
        <header class="form-page-head">
            <div>
                <h1><?= e(t('onboard.athlete.opps.title')) ?></h1>
                <?php if ($sportName !== ''): ?>
                    <p class="muted"><?= e($sportName) ?><?= $location !== '' ? ' · ' . e($location) : '' ?></p>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($sportName === ''): ?>
            <div class="empty-state">
                <p><?= e(t('onboard.athlete.opps.no_sport')) ?></p>
                <a class="btn btn-primary" href="<?= e(url('profilo/modifica')) ?>"><?= e(t('onboard.athlete.opps.no_sport_cta')) ?></a>
            </div>
        <?php elseif ($items !== []): ?>
            <div class="opp-list">
                <?php foreach ($items as $o): ?>
                    <?= View::partial('opportunity-card', ['o' => $o]) ?>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <p><?= e(t('opp.index.empty')) ?></p>
                <a class="btn btn-primary" href="<?= e(url('atleti?sport=' . rawurlencode($sportSlug))) ?>">
                    <?= e(t('onboard.athlete.opps.follow_cta', ['sport' => $sportName])) ?>
                </a>
            </div>
        <?php endif; ?>

        <div class="onboard-actions">
            <a class="btn btn-primary" href="<?= e(url('profilo')) ?>">
                <i class="fa-solid fa-flag-checkered" aria-hidden="true"></i> <?= e(t('onboard.athlete.opps.finish')) ?>
            </a>
        </div>
    </section>
</main>
