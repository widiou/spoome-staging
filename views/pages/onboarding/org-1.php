<?php
/**
 * Onboarding Società/Federazione · step 1/3 — la pagina in breve (disciplina, città/sede, logo).
 * @var array $profile @var array $sports
 *
 * Riusa integralmente POST /profilo (MyProfileController::update). Federazioni multi-sport possono
 * lasciare la disciplina vuota — campo singolo, coerente col resto del sito (nota di Bianca).
 */
$csrf = Spoome\Core\Csrf::token();
$logoPath = (string) ($profile['avatar_path'] ?? '');
// Fix P1 (review di Paolo): ProfileService::update() RISCRIVE SEMPRE profiles.attributes dal solo
// input attr[...] inviato. Le org hanno campi type-specific (es. anno fondazione) — senza rispedirli
// come campi nascosti, questo step (che espone solo sport/città) li azzererebbe silenziosamente al
// primo salvataggio. Stesso pattern di views/pages/profilo/edit.php:261, ma sui valori grezzi (non
// serve lo schema qui: sanitize() in ProfileService ignora comunque ogni chiave non più nello schema
// del tipo — solo view, zero impatto sul write path condiviso).
$attrValues = \Spoome\Domain\Profiles\ProfileAttributes::values($profile['attributes'] ?? null);
$accept = 'image/jpeg,image/png,image/webp';
$logoI18n = json_encode([
    'title' => t('avatar.crop.title'), 'hint' => t('avatar.crop.hint'),
    'confirm' => t('avatar.crop.confirm'), 'cancel' => t('avatar.crop.cancel'),
    'upload' => t('profile.avatar.upload'), 'change' => t('profile.avatar.change'),
    'error' => t('avatar.error.invalid'),
], JSON_UNESCAPED_UNICODE);
?>
<main class="site-main">
    <section class="container narrow onboard-step">
        <p class="onboard-kicker"><?= e(t('onboard.step_of', ['n' => 1])) ?></p>
        <header class="form-page-head">
            <div>
                <h1><?= e(t('onboard.org.setup.title')) ?></h1>
                <p class="muted"><?= e(t('onboard.org.setup.sub')) ?></p>
            </div>
        </header>

        <!-- Logo (facoltativo): stesso componente/JS dell'avatar-editor di /profilo/modifica. -->
        <div class="media-uploader avatar-editor"
             data-upload-url="<?= e(url('profilo/avatar')) ?>" data-delete-url="<?= e(url('profilo/avatar/elimina')) ?>"
             data-csrf="<?= e($csrf) ?>" data-i18n="<?= e($logoI18n) ?>"
             data-aspect="1" data-out-w="512" data-out-h="512" data-round="1">
            <div class="media-preview avatar-preview<?= $logoPath !== '' ? ' has-image' : '' ?>" data-initials="<?= e(initials((string) $profile['display_name'])) ?>">
                <?php if ($logoPath !== ''): ?>
                    <img class="avatar-img" src="<?= e(url($logoPath)) ?>" alt="">
                <?php else: ?>
                    <span class="avatar-initials"><?= e(initials((string) $profile['display_name'])) ?></span>
                <?php endif; ?>
            </div>
            <div class="avatar-actions media-actions">
                <span class="avatar-label"><?= e(t('onboard.org.setup.logo_label')) ?></span>
                <div class="avatar-buttons">
                    <button type="button" class="media-pick btn btn-ghost btn-sm">
                        <i class="fa-solid fa-camera" aria-hidden="true"></i>
                        <?= $logoPath !== '' ? e(t('profile.avatar.change')) : e(t('profile.avatar.upload')) ?>
                    </button>
                    <button type="button" class="media-remove btn btn-ghost btn-sm"<?= $logoPath === '' ? ' hidden' : '' ?>><?= e(t('profile.avatar.remove')) ?></button>
                </div>
                <input type="file" class="media-file" accept="<?= e($accept) ?>" hidden>
            </div>
        </div>

        <form class="form-card" method="post" action="<?= e(url('profilo')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="onboarding/societa/verifica">
            <input type="hidden" name="display_name" value="<?= e($profile['display_name']) ?>">
            <input type="hidden" name="handle" value="<?= e($profile['handle']) ?>">
            <input type="hidden" name="headline" value="<?= e((string) ($profile['headline'] ?? '')) ?>">
            <input type="hidden" name="bio" value="<?= e((string) ($profile['bio'] ?? '')) ?>">
            <input type="hidden" name="location_region" value="<?= e((string) ($profile['location_region'] ?? '')) ?>">
            <input type="hidden" name="location_country" value="<?= e((string) ($profile['location_country'] ?? '')) ?>">
            <input type="hidden" name="visibility" value="<?= e((string) ($profile['visibility'] ?? 'public')) ?>">
            <?php foreach ($attrValues as $attrKey => $attrVal): ?>
                <?php if (is_scalar($attrVal)): ?>
                    <input type="hidden" name="attr[<?= e((string) $attrKey) ?>]" value="<?= e((string) $attrVal) ?>">
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="field">
                <label for="sport"><?= e(t('profile.field.sport')) ?></label>
                <select class="input" id="sport" name="sport">
                    <option value=""><?= e(t('opp.f.sport_none')) ?></option>
                    <?php foreach ($sports as $s): ?>
                        <option value="<?= e($s['slug']) ?>" <?= ($profile['sport_slug'] ?? '') === $s['slug'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="location_city"><?= e(t('profile.field.city')) ?></label>
                <input class="input" type="text" id="location_city" name="location_city" maxlength="120"
                       value="<?= e((string) ($profile['location_city'] ?? '')) ?>">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= e(t('common.continue')) ?></button>
            </div>
        </form>
    </section>
</main>
