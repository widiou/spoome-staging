<?php
/**
 * Creazione pagina organizzazione. @var string|null $error @var array $old @var string[] $types
 */
$old = $old ?? [];
$types = $types ?? [];
$val = static fn(string $k): string => e($old[$k] ?? '');
$curType = (string) ($old['type'] ?? 'societa');
$typeLabels = [
    'societa'      => t('page.new.type_societa'),
    'associazione' => t('page.new.type_associazione'),
    'federazione'  => t('page.new.type_federazione'),
];
?>
<main class="auth-wrap">
    <div class="auth-card">
        <div class="auth-head">
            <h1><?= e(t('page.new.title')) ?></h1>
            <p><?= e(t('page.new.lead')) ?></p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('pagine')) ?>" novalidate>
            <?= csrf_field() ?>

            <div class="field">
                <label><?= e(t('page.new.type')) ?> <span class="req">*</span></label>
                <div class="choice-grid" role="radiogroup" aria-label="<?= e(t('page.new.type')) ?>">
                    <?php foreach ($types as $tp): ?>
                        <label class="choice">
                            <input type="radio" name="type" value="<?= e($tp) ?>" <?= $curType === $tp ? 'checked' : '' ?>>
                            <span><?= e($typeLabels[$tp] ?? $tp) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="field">
                <label for="display_name"><?= e(t('page.new.name')) ?> <span class="req">*</span></label>
                <input class="input" type="text" id="display_name" name="display_name"
                       value="<?= $val('display_name') ?>" required minlength="2" maxlength="160"
                       placeholder="<?= e(t('page.new.name_ph')) ?>">
            </div>

            <div class="field">
                <label for="handle"><?= e(t('page.new.handle')) ?></label>
                <input class="input" type="text" id="handle" name="handle"
                       value="<?= $val('handle') ?>" maxlength="30"
                       placeholder="<?= e(t('page.new.handle_ph')) ?>">
                <span class="field-help"><?= e(t('page.new.handle_hint')) ?></span>
            </div>

            <button type="submit" class="btn btn-accent btn-block btn-lg" data-submit><?= e(t('page.new.submit')) ?></button>
            <p class="field-help" style="margin-top:.75rem"><?= e(t('page.new.unverified')) ?></p>
        </form>
    </div>
</main>
