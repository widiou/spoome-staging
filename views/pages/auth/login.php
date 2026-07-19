<?php
/**
 * Login. @var string|null $error @var array{message:string,type:string}|null $notice @var array $old
 */
$old = $old ?? [];
?>
<main class="auth-wrap">
    <div class="auth-card">
        <div class="auth-head">
            <h1><?= e(t('auth.login.title')) ?></h1>
            <p><?= e(t('auth.login.subtitle')) ?></p>
        </div>

        <?php if (!empty($notice)): ?>
            <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('accedi')) ?>" novalidate>
            <?= csrf_field() ?>

            <div class="field">
                <label for="email"><?= e(t('auth.field.email')) ?></label>
                <input class="input" type="email" id="email" name="email"
                       value="<?= e($old['email'] ?? '') ?>" required
                       autocomplete="email" inputmode="email" placeholder="<?= e(t('auth.field.email_ph')) ?>">
            </div>

            <div class="field">
                <label for="password"><?= e(t('auth.field.password')) ?></label>
                <div class="input-group">
                    <input class="input" type="password" id="password" name="password"
                           required autocomplete="current-password" placeholder="<?= e(t('auth.login.password_ph')) ?>" data-password>
                    <button type="button" class="input-toggle" data-toggle-password aria-label="<?= e(t('common.show')) ?>"><?= e(t('common.show')) ?></button>
                </div>
                <a class="field-help" href="<?= e(url('recupera-password')) ?>"><?= e(t('auth.login.forgot')) ?></a>
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg" data-submit><?= e(t('auth.login.submit')) ?></button>
        </form>

        <p class="auth-alt"><?= e(t('auth.login.no_account')) ?> <a href="<?= e(url('registrati')) ?>"><?= e(t('nav.signup')) ?></a></p>
    </div>
</main>
