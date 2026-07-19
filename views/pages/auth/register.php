<?php
/**
 * Registrazione. @var string|null $error @var array $old @var array $types (id,key,label,is_organization)
 */
$old = $old ?? [];
$types = $types ?? [];
$val = static fn(string $k): string => e($old[$k] ?? '');
?>
<main class="auth-wrap">
    <div class="auth-card">
        <div class="auth-head">
            <h1><?= e(t('auth.register.title')) ?></h1>
            <p><?= e(t('auth.register.subtitle')) ?></p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('registrati')) ?>" novalidate>
            <?= csrf_field() ?>

            <div class="field">
                <label for="display_name"><?= e(t('auth.register.display_name')) ?> <span class="req">*</span></label>
                <input class="input" type="text" id="display_name" name="display_name"
                       value="<?= $val('display_name') ?>" required maxlength="160"
                       autocomplete="name" placeholder="<?= e(t('auth.register.display_name_ph')) ?>">
            </div>

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

            <div class="field">
                <label><?= e(t('auth.register.profile_type')) ?> <span class="req">*</span></label>
                <div class="choice-grid" role="radiogroup" aria-label="<?= e(t('auth.register.profile_type')) ?>">
                    <?php foreach ($types as $tp): ?>
                        <label class="choice">
                            <input type="radio" name="profile_type" value="<?= e($tp['key']) ?>"
                                   <?= (($old['profile_type'] ?? 'atleta') === $tp['key']) ? 'checked' : '' ?>>
                            <span><?= e($tp['label']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="field">
                <label for="sport"><?= e(t('auth.register.sport')) ?></label>
                <?php
                $sports = $sports ?? [];
                $sportsByCat = [];
                foreach ($sports as $s) { $sportsByCat[$s['category']][] = $s; }
                $curSport = (string) ($old['sport'] ?? '');
                ?>
                <select class="input" id="sport" name="sport">
                    <option value=""><?= e(t('auth.register.sport_none')) ?></option>
                    <?php foreach ($sportsByCat as $cat => $list): ?>
                        <optgroup label="<?= e($cat) ?>">
                            <?php foreach ($list as $s): ?>
                                <option value="<?= e($s['slug']) ?>"<?= $curSport === $s['slug'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <span class="field-help"><?= e(t('auth.register.sport_help')) ?></span>
            </div>

            <button type="submit" class="btn btn-accent btn-block btn-lg" data-submit><?= e(t('auth.register.submit')) ?></button>
        </form>

        <p class="auth-alt"><?= e(t('auth.register.have_account')) ?> <a href="<?= e(url('accedi')) ?>"><?= e(t('nav.login')) ?></a></p>
    </div>
</main>
