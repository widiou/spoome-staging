<?php
/**
 * Registrazione "per rivendicare" (nessun profilo creato). @var string|null $error @var array $old
 */
$old = $old ?? [];
$val = static fn(string $k): string => e($old[$k] ?? '');
?>
<main class="auth-wrap">
    <div class="auth-card">
        <div class="auth-head">
            <h1><?= e(t('claim.register.title')) ?></h1>
            <p><?= e(t('claim.register.subtitle')) ?></p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('registrati/rivendica')) ?>" novalidate>
            <?= csrf_field() ?>

            <div class="field">
                <label for="email"><?= e(t('auth.field.email')) ?> <span class="req">*</span></label>
                <input class="input" type="email" id="email" name="email"
                       value="<?= $val('email') ?>" required maxlength="190"
                       autocomplete="email" inputmode="email" placeholder="<?= e(t('auth.field.email_ph')) ?>">
            </div>

            <div class="field">
                <label for="password"><?= e(t('auth.field.password')) ?> <span class="req">*</span></label>
                <div class="input-group">
                    <input class="input" type="password" id="password" name="password"
                           required minlength="10" autocomplete="new-password"
                           placeholder="<?= e(t('auth.register.password_ph')) ?>" data-password>
                    <button type="button" class="input-toggle" data-toggle-password aria-label="<?= e(t('common.show')) ?>"><?= e(t('common.show')) ?></button>
                </div>
                <span class="field-help"><?= e(t('auth.password_help')) ?></span>
            </div>

            <div class="field">
                <label for="password_confirmation"><?= e(t('auth.field.password_confirm')) ?> <span class="req">*</span></label>
                <input class="input" type="password" id="password_confirmation" name="password_confirmation"
                       required autocomplete="new-password" placeholder="<?= e(t('auth.register.password_confirm_ph')) ?>">
            </div>

            <button type="submit" class="btn btn-accent btn-block btn-lg" data-submit><?= e(t('claim.register.submit')) ?></button>
        </form>

        <p class="auth-alt"><?= e(t('claim.register.new_profile')) ?> <a href="<?= e(url('registrati')) ?>"><?= e(t('nav.signup')) ?></a></p>
        <p class="auth-alt"><?= e(t('auth.register.have_account')) ?> <a href="<?= e(url('accedi')) ?>"><?= e(t('nav.login')) ?></a></p>
    </div>
</main>
