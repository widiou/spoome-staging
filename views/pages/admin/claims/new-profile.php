<?php
/**
 * Crea un profilo non rivendicato (seed). @var array $types @var array $sports @var array|null $notice
 */
?>
<header class="admin-head">
    <div>
        <a class="admin-back" href="<?= e(url('admin/rivendicazioni')) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> <?= e(t('admin.nav.claims')) ?></a>
        <h1 class="admin-title"><?= e(t('admin.claims.create_title')) ?></h1>
        <p class="admin-subtitle"><?= e(t('admin.claims.create_subtitle')) ?></p>
    </div>
</header>

<?php if (!empty($notice)): ?>
    <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
<?php endif; ?>

<section class="admin-panel admin-form-panel">
    <form method="post" action="<?= e(url('admin/rivendicazioni/nuovo')) ?>">
        <?= csrf_field() ?>
        <div class="field">
            <label class="field-label" for="display_name"><?= e(t('admin.claims.f_name')) ?></label>
            <input class="input" type="text" id="display_name" name="display_name" required minlength="2" maxlength="160" placeholder="<?= e(t('admin.claims.f_name_ph')) ?>">
        </div>
        <div class="field">
            <label class="field-label" for="profile_type"><?= e(t('admin.claims.f_type')) ?></label>
            <select class="input" id="profile_type" name="profile_type" required>
                <?php foreach ($types as $tp): ?>
                    <option value="<?= e($tp['key']) ?>"><?= e($tp['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="field-label" for="sport_id"><?= e(t('admin.claims.f_sport')) ?></label>
            <select class="input" id="sport_id" name="sport_id">
                <option value="0"><?= e(t('admin.claims.f_sport_none')) ?></option>
                <?php foreach ($sports as $s): ?>
                    <option value="<?= e((string) $s['id']) ?>"><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="field-label" for="headline"><?= e(t('admin.claims.f_headline')) ?></label>
            <input class="input" type="text" id="headline" name="headline" maxlength="200" placeholder="<?= e(t('admin.claims.f_headline_ph')) ?>">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus" aria-hidden="true"></i> <?= e(t('admin.claims.create_submit')) ?></button>
    </form>
</section>
