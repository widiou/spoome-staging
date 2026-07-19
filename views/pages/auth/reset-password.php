<?php
/** Reimposta password. @var string $token @var string|null $error */
?>
<main class="auth-wrap">
    <div class="auth-card">
        <div class="auth-head">
            <h1><?= e(t('auth.reset.title')) ?></h1>
            <p><?= e(t('auth.reset.subtitle')) ?></p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('reimposta')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token ?? '') ?>">

            <div class="field">
                <label for="password"><?= e(t('auth.reset.new_password')) ?></label>
                <div class="input-group">
                    <input class="input" type="password" id="password" name="password"
                           required minlength="10" autocomplete="new-password"
                           placeholder="<?= e(t('auth.register.password_ph')) ?>" data-password>
                    <button type="button" class="input-toggle" data-toggle-password aria-label="<?= e(t('common.show')) ?>"><?= e(t('common.show')) ?></button>
                </div>
                <span class="field-help"><?= e(t('auth.password_help')) ?></span>
            </div>

            <div class="field">
                <label for="password_confirmation"><?= e(t('auth.field.password_confirm')) ?></label>
                <input class="input" type="password" id="password_confirmation" name="password_confirmation"
                       required autocomplete="new-password" placeholder="<?= e(t('auth.register.password_confirm_ph')) ?>">
            </div>

            <button type="submit" class="btn btn-accent btn-block btn-lg" data-submit><?= e(t('auth.reset.submit')) ?></button>
        </form>
    </div>
</main>
