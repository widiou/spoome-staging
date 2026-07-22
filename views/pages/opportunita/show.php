<?php
/**
 * Dettaglio opportunità. @var array $opp @var bool $isOwner @var bool $hasApplied @var bool $canApply
 * @var array|null $notice
 */
$state = \Spoome\Domain\Opportunities\OpportunityPresenter::state($opp);
$oppId = (int) $opp['id'];
$loc   = implode(', ', array_filter([
    trim((string) ($opp['location_city'] ?? '')),
    trim((string) ($opp['location_region'] ?? '')),
]));
$orgShape = ['handle' => $opp['org_handle'] ?? '', 'type_key' => $opp['org_type_key'] ?? null];
?>
<main class="site-main">
    <section class="container narrow">
        <?php if (!empty($notice)): ?>
            <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
        <?php endif; ?>

        <article class="opp-detail">
            <header class="opp-detail-head">
                <span class="opp-state opp-state-<?= e($state) ?>"><?= e(t('opp.state.' . $state)) ?></span>
                <h1><?= e($opp['title']) ?></h1>
                <p class="opp-kind muted"><i class="fa-solid fa-briefcase" aria-hidden="true"></i> <?= e(t('opp.kind.' . $opp['kind'])) ?></p>
            </header>

            <dl class="opp-detail-facts">
                <div>
                    <dt><?= e(t('opp.show.published_by')) ?></dt>
                    <dd>
                        <a href="<?= e(profile_url($orgShape)) ?>"><?= e($opp['org_display_name']) ?></a>
                        <?php if (!empty($opp['org_verified_at'])): ?><i class="fa-solid fa-circle-check" title="Verificato" aria-hidden="true"></i><?php endif; ?>
                    </dd>
                </div>
                <?php if (!empty($opp['sport_slug'])): ?>
                    <div>
                        <dt><?= e(t('opp.show.discipline')) ?></dt>
                        <dd><i class="<?= e(sport_icon($opp['sport_slug'], $opp['sport_category'] ?? null)) ?>" aria-hidden="true"></i> <?= e($opp['sport_name']) ?></dd>
                    </div>
                <?php endif; ?>
                <?php if ($loc !== ''): ?>
                    <div><dt><?= e(t('opp.show.zone')) ?></dt><dd><?= e($loc) ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($opp['event_date'])): ?>
                    <div><dt><?= e(t('opp.show.event_date')) ?></dt><dd><?= e($opp['event_date']) ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($opp['deadline'])): ?>
                    <div><dt><?= e(t('opp.show.deadline')) ?></dt><dd><?= e($opp['deadline']) ?></dd></div>
                <?php endif; ?>
            </dl>

            <section class="opp-detail-body">
                <h2><?= e(t('opp.show.requirements')) ?></h2>
                <p><?= nl2br(e($opp['description'])) ?></p>
            </section>

            <footer class="opp-detail-actions">
                <?php if ($isOwner): ?>
                    <a class="btn btn-primary" href="<?= e(url('opportunita/' . $oppId . '/candidature')) ?>">
                        <i class="fa-solid fa-inbox" aria-hidden="true"></i>
                        <?= e(t('opp.show.manage')) ?> (<?= e((int) ($opp['applications_count'] ?? 0)) ?>)
                    </a>
                    <?php if ($state === 'open'): ?>
                        <form method="post" action="<?= e(url('opportunita/' . $oppId . '/chiudi')) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-ghost"><?= e(t('opp.show.close_cta')) ?></button>
                        </form>
                    <?php endif; ?>
                <?php elseif ($hasApplied): ?>
                    <p class="alert alert-info" role="status"><i class="fa-solid fa-check" aria-hidden="true"></i> <?= e(t('opp.show.applied')) ?></p>
                <?php elseif ($canApply && $state === 'open'): ?>
                    <form method="post" action="<?= e(url('opportunita/' . $oppId . '/candidati')) ?>" class="opp-apply-form">
                        <?= csrf_field() ?>
                        <label class="field">
                            <span class="field-label"><?= e(t('opp.show.cover_message')) ?></span>
                            <textarea name="cover_message" rows="4" maxlength="1000" placeholder="<?= e(t('opp.show.cover_message_hint')) ?>"></textarea>
                        </label>
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> <?= e(t('opp.show.apply_cta')) ?>
                        </button>
                    </form>
                <?php elseif (!auth_id()): ?>
                    <a class="btn btn-primary" href="<?= e(url('accedi')) ?>"><?= e(t('opp.show.login_to_apply')) ?></a>
                <?php endif; ?>
            </footer>
        </article>
    </section>
</main>
