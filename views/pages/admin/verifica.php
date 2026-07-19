<?php
/**
 * Step-up: reinserimento password per accedere all'area admin.
 * @var array|null $notice
 */
?>
<section class="admin-stepup">
    <div class="admin-stepup-card">
        <div class="admin-stepup-icon"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></div>
        <h1><?= e(t('admin.stepup.title')) ?></h1>
        <p class="muted"><?= e(t('admin.stepup.intro')) ?></p>

        <?php if (!empty($notice)): ?>
            <div class="alert alert-<?= e($notice['type']) ?>" role="alert"><?= e($notice['message']) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('admin/verifica')) ?>" class="admin-stepup-form">
            <?= csrf_field() ?>
            <label class="field">
                <span class="field-label"><?= e(t('admin.stepup.password_label')) ?></span>
                <input class="input" type="password" name="password" autocomplete="current-password" required autofocus>
            </label>
            <button type="submit" class="btn btn-primary btn-block"><?= e(t('admin.stepup.submit')) ?></button>
        </form>
        <a class="admin-stepup-back" href="<?= e(url('')) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> <?= e(t('admin.back_to_site')) ?></a>
    </div>
</section>
