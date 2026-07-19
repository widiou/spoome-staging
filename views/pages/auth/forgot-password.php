<?php
/** Richiesta reset password. @var array{message:string,type:string}|null $notice */
?>
<main class="auth-wrap">
    <div class="auth-card">
        <div class="auth-head">
            <h1><?= e(t('auth.forgot.title')) ?></h1>
            <p><?= e(t('auth.forgot.subtitle')) ?></p>
        </div>

        <?php if (!empty($notice)): ?>
            <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('recupera-password')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="email"><?= e(t('auth.field.email')) ?></label>
                <input class="input" type="email" id="email" name="email" required
                       autocomplete="email" inputmode="email" placeholder="<?= e(t('auth.field.email_ph')) ?>">
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-lg" data-submit><?= e(t('auth.forgot.submit')) ?></button>
        </form>

        <p class="auth-alt"><a href="<?= e(url('accedi')) ?>"><?= e(t('auth.forgot.back')) ?></a></p>
    </div>
</main>
