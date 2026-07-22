<?php
/**
 * Onboarding Società/Federazione · step 2/3 — gate di pubblicazione (verifica).
 * @var array $profile @var bool $verified @var string $publishUrl
 *
 * TODO (fuori scope M5, decisione dell'orchestratore): niente richiesta di verifica self-serve — resta
 * admin-manuale da /admin/profili (Verification-da-club, M3, live). Costruirla è raccomandato da Bianca
 * (mirror del dominio Claims: request → pending → admin approva/rifiuta) ma non in questa iterazione.
 * Le chiavi 'onboard.org.verify.evidence'/'submit' sono già in lang/it.php, pronte per quel lavoro.
 */
?>
<main class="site-main">
    <section class="container narrow onboard-step">
        <p class="onboard-kicker"><?= e(t('onboard.step_of', ['n' => 2])) ?></p>

        <?php if ($verified): ?>
            <header class="form-page-head">
                <div>
                    <h1><i class="fa-solid fa-circle-check" aria-hidden="true"></i> <?= e(t('onboard.org.verify.confirmed')) ?></h1>
                </div>
            </header>
            <div class="onboard-actions">
                <a class="btn btn-primary" href="<?= e($publishUrl) ?>"><?= e(t('onboard.org.verify.go_publish')) ?></a>
            </div>
        <?php else: ?>
            <header class="form-page-head">
                <div>
                    <h1><?= e(t('onboard.org.verify.title')) ?></h1>
                    <p class="muted"><?= e(t('onboard.org.verify.why')) ?></p>
                </div>
            </header>
            <div class="alert alert-info" role="status">
                <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
                <?= e(t('onboard.org.verify.admin_only')) ?>
            </div>
            <div class="onboard-actions">
                <a class="btn btn-ghost" href="<?= e(url('profilo/modifica')) ?>"><?= e(t('profile.owner.edit')) ?></a>
            </div>
        <?php endif; ?>
    </section>
</main>
