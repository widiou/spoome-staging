<?php
/**
 * Form di pubblicazione opportunità (solo org). @var array $sports @var array $kinds @var array|null $notice
 * @var int|null $prefillSportId @var string $prefillRegion @var string $prefillCity prefill di visualizzazione
 * (R-Moat M5, #45 — onboarding Società step 3): valori solo-display, la validazione resta in OpportunityService.
 */
$prefillSportId = $prefillSportId ?? null;
$prefillRegion  = $prefillRegion ?? '';
$prefillCity    = $prefillCity ?? '';
?>
<main class="site-main">
    <section class="container narrow">
        <header class="page-head">
            <h1><?= e(t('opp.publish.title')) ?></h1>
            <p class="muted"><?= e(t('opp.publish.subtitle')) ?></p>
        </header>

        <?php if (!empty($notice)): ?>
            <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('opportunita')) ?>" class="opp-form">
            <?= csrf_field() ?>

            <label class="field">
                <span class="field-label"><?= e(t('opp.f.title')) ?></span>
                <input type="text" name="title" maxlength="160" required autocomplete="off">
            </label>

            <label class="field">
                <span class="field-label"><?= e(t('opp.f.kind')) ?></span>
                <select name="kind" required>
                    <?php foreach ($kinds as $k): ?>
                        <option value="<?= e($k) ?>"><?= e(t('opp.kind.' . $k)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span class="field-label"><?= e(t('opp.f.sport')) ?></span>
                <select name="sport_id">
                    <option value=""><?= e(t('opp.f.sport_none')) ?></option>
                    <?php foreach ($sports as $s): ?>
                        <option value="<?= e($s['id']) ?>" <?= $prefillSportId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="field-row">
                <label class="field">
                    <span class="field-label"><?= e(t('opp.f.region')) ?></span>
                    <input type="text" name="location_region" maxlength="80" autocomplete="off" value="<?= e($prefillRegion) ?>">
                </label>
                <label class="field">
                    <span class="field-label"><?= e(t('opp.f.city')) ?></span>
                    <input type="text" name="location_city" maxlength="120" autocomplete="off" value="<?= e($prefillCity) ?>">
                </label>
            </div>

            <label class="field">
                <span class="field-label"><?= e(t('opp.f.description')) ?></span>
                <textarea name="description" rows="6" maxlength="5000" required></textarea>
            </label>

            <div class="field-row">
                <label class="field">
                    <span class="field-label"><?= e(t('opp.f.event_date')) ?></span>
                    <input type="date" name="event_date">
                </label>
                <label class="field">
                    <span class="field-label"><?= e(t('opp.f.deadline')) ?></span>
                    <input type="date" name="deadline">
                </label>
            </div>

            <button type="submit" class="btn btn-primary"><?= e(t('opp.f.submit')) ?></button>
        </form>
    </section>
</main>
