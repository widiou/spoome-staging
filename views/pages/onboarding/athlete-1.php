<?php
/**
 * Onboarding Atleta · step 1/3 — promemoria dedup contro profili non rivendicati.
 * @var array $profile @var array $candidates @var bool $searchFailed
 *
 * TODO (fuori scope M5): $candidates riusa la ricerca generale esistente (ProfileRepository::listPublic),
 * non una vera ricerca per similarità/typo-tolerant — vedi nota nel controller. Il merge di due profili
 * non esiste: qui si rimanda al claim esistente, che assegna la proprietà, non fonde i dati.
 */
?>
<main class="site-main">
    <section class="container narrow onboard-step">
        <p class="onboard-kicker"><?= e(t('onboard.step_of', ['n' => 1])) ?></p>
        <header class="form-page-head">
            <div>
                <h1><?= e(t('onboard.athlete.dedup.title')) ?></h1>
                <p class="muted"><?= e(t('onboard.athlete.dedup.sub')) ?></p>
            </div>
        </header>

        <?php if ($searchFailed): ?>
            <div class="alert alert-info" role="status">
                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                <?= e(t('onboard.athlete.dedup.err_fallback')) ?>
            </div>
        <?php elseif ($candidates !== []): ?>
            <ul class="onboard-candidates" aria-label="<?= e(t('onboard.athlete.dedup.title')) ?>">
                <?php foreach ($candidates as $c): ?>
                    <li class="onboard-candidate">
                        <span class="onboard-candidate-av" aria-hidden="true">
                            <?php if (!empty($c['avatar_path'])): ?>
                                <img src="<?= e(url($c['avatar_path'])) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <span><?= e(initials((string) $c['display_name'])) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="onboard-candidate-id">
                            <span class="onboard-candidate-name"><?= e($c['display_name']) ?></span>
                            <span class="onboard-candidate-meta muted">
                                <span class="badge-unclaimed"><i class="fa-solid fa-user-slash" aria-hidden="true"></i> <?= e(t('onboard.athlete.dedup.unclaimed')) ?></span>
                                <?php if (!empty($c['sport_name'])): ?><span><?= e($c['sport_name']) ?></span><?php endif; ?>
                                <?php if (!empty($c['location_city'])): ?><span><?= e($c['location_city']) ?></span><?php endif; ?>
                            </span>
                        </span>
                        <a class="btn btn-sm" href="<?= e(profile_url($c)) ?>" aria-label="<?= e(t('onboard.athlete.dedup.claim_cta') . ' — ' . (string) $c['display_name']) ?>"><?= e(t('onboard.athlete.dedup.claim_cta')) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="onboard-actions">
            <a class="btn btn-ghost" href="<?= e(url('atleti?q=' . rawurlencode((string) $profile['display_name']))) ?>">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> <?= e(t('onboard.athlete.dedup.search_cta')) ?>
            </a>
            <a class="btn btn-primary" href="<?= e(url('onboarding/atleta/profilo')) ?>"><?= e(t('onboard.athlete.dedup.none')) ?></a>
        </div>
    </section>
</main>
