<?php
/**
 * Scheda opportunità (browse pubblico + "le mie opportunità"). Riga arricchita org_* + sport_*.
 * Ogni output via e(). Nessuna logica di dominio qui.
 * @var array $o       riga opportunità arricchita
 * @var bool  $manage  se true, mostra le azioni di gestione (candidature / chiudi) per l'org owner
 */
$manage = $manage ?? false;
$state  = \Spoome\Domain\Opportunities\OpportunityPresenter::state($o);
$oppId  = (int) $o['id'];
$loc    = implode(', ', array_filter([
    trim((string) ($o['location_city'] ?? '')),
    trim((string) ($o['location_region'] ?? '')),
]));
$orgShape = ['handle' => $o['org_handle'] ?? '', 'type_key' => $o['org_type_key'] ?? null];
?>
<article class="opp-card" data-async-card>
    <div class="opp-card-head">
        <a class="opp-card-title" href="<?= e(url('opportunita/' . $oppId)) ?>"><?= e($o['title']) ?></a>
        <span class="opp-state opp-state-<?= e($state) ?>"><?= e(t('opp.state.' . $state)) ?></span>
    </div>

    <ul class="opp-card-meta muted">
        <li><i class="fa-solid fa-briefcase" aria-hidden="true"></i> <?= e(t('opp.kind.' . $o['kind'])) ?></li>
        <?php if (!empty($o['sport_slug'])): ?>
            <li><i class="<?= e(sport_icon($o['sport_slug'], $o['sport_category'] ?? null)) ?>" aria-hidden="true"></i> <?= e($o['sport_name']) ?></li>
        <?php endif; ?>
        <?php if ($loc !== ''): ?>
            <li><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?= e($loc) ?></li>
        <?php endif; ?>
        <?php if (!empty($o['deadline'])): ?>
            <li><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i> <?= e(t('opp.show.deadline')) ?>: <?= e($o['deadline']) ?></li>
        <?php endif; ?>
    </ul>

    <div class="opp-card-org muted">
        <?= e(t('opp.show.published_by')) ?>:
        <a href="<?= e(profile_url($orgShape)) ?>"><?= e($o['org_display_name']) ?></a>
        <?php if (!empty($o['org_verified_at'])): ?>
            <i class="fa-solid fa-circle-check" title="Verificato" aria-label="Verificato"></i>
        <?php endif; ?>
    </div>

    <?php if ($manage): ?>
        <div class="opp-card-actions">
            <a class="btn btn-sm" href="<?= e(url('opportunita/' . $oppId . '/candidature')) ?>">
                <i class="fa-solid fa-inbox" aria-hidden="true"></i>
                <?= e(t('opp.mine.manage_apps')) ?> (<?= e((int) ($o['applications_count'] ?? 0)) ?>)
            </a>
            <?php if ($state === 'open'): ?>
                <form method="post" action="<?= e(url('opportunita/' . $oppId . '/chiudi')) ?>" class="inline-form" data-async>
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-ghost"><?= e(t('opp.show.close_cta')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="opp-card-foot muted"><?= e(t('opp.index.count', ['n' => (int) ($o['applications_count'] ?? 0)])) ?></div>
    <?php endif; ?>
</article>
