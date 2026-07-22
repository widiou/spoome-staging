<?php
/**
 * Onboarding Atleta · step 2/3 — completa il profilo (città, obbligatoria) + foto (facoltativa).
 * @var array $profile @var bool $claimPending
 *
 * Riusa integralmente POST /profilo (MyProfileController::update, stessa validazione/authz di sempre):
 * campi nascosti portano i valori CORRENTI del profilo, l'unico campo esposto è la città. Il parametro
 * `next` incatena lo step 3 dopo il salvataggio (MyProfileController::nextAfterSave, whitelist relativa).
 */
$csrf = Spoome\Core\Csrf::token();
$avatarPath = (string) ($profile['avatar_path'] ?? '');
// Fix P1 (review di Paolo): ProfileService::update() RISCRIVE SEMPRE profiles.attributes dal solo
// input attr[...] inviato. Questo step espone solo la città → senza rispedire gli attributi CORRENTI
// come campi nascosti, il salvataggio li azzererebbe silenziosamente. Stesso pattern di
// views/pages/profilo/edit.php:261, ma sui valori grezzi (non serve lo schema qui: sanitize() in
// ProfileService ignora comunque ogni chiave non più nello schema del tipo — solo view, zero impatto
// sul write path condiviso).
$attrValues = \Spoome\Domain\Profiles\ProfileAttributes::values($profile['attributes'] ?? null);
$accept = 'image/jpeg,image/png,image/webp';
$avatarI18n = json_encode([
    'title' => t('avatar.crop.title'), 'hint' => t('avatar.crop.hint'),
    'confirm' => t('avatar.crop.confirm'), 'cancel' => t('avatar.crop.cancel'),
    'upload' => t('profile.avatar.upload'), 'change' => t('profile.avatar.change'),
    'error' => t('avatar.error.invalid'),
], JSON_UNESCAPED_UNICODE);
?>
<main class="site-main">
    <section class="container narrow onboard-step">
        <p class="onboard-kicker"><?= e(t('onboard.step_of', ['n' => 2])) ?></p>
        <header class="form-page-head">
            <div>
                <h1><?= e(t('onboard.athlete.complete.title')) ?></h1>
                <p class="muted"><?= e(t('onboard.athlete.complete.sub')) ?></p>
            </div>
        </header>

        <?php if ($claimPending): ?>
            <div class="alert alert-info" role="status">
                <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
                <?= e(t('claim.panel.pending')) ?>
            </div>
            <div class="onboard-actions">
                <a class="btn btn-primary" href="<?= e(url('onboarding/atleta/opportunita')) ?>"><?= e(t('onboard.athlete.complete.continue_anyway')) ?></a>
            </div>
        <?php else: ?>
            <!-- Foto profilo (facoltativa): stesso componente/JS di /profilo/modifica, stessi endpoint. -->
            <div class="media-uploader avatar-editor"
                 data-upload-url="<?= e(url('profilo/avatar')) ?>" data-delete-url="<?= e(url('profilo/avatar/elimina')) ?>"
                 data-csrf="<?= e($csrf) ?>" data-i18n="<?= e($avatarI18n) ?>"
                 data-aspect="1" data-out-w="512" data-out-h="512" data-round="1">
                <div class="media-preview avatar-preview<?= $avatarPath !== '' ? ' has-image' : '' ?>" data-initials="<?= e(initials((string) $profile['display_name'])) ?>">
                    <?php if ($avatarPath !== ''): ?>
                        <img class="avatar-img" src="<?= e(url($avatarPath)) ?>" alt="">
                    <?php else: ?>
                        <span class="avatar-initials"><?= e(initials((string) $profile['display_name'])) ?></span>
                    <?php endif; ?>
                </div>
                <div class="avatar-actions media-actions">
                    <span class="avatar-label"><?= e(t('onboard.athlete.complete.photo_label')) ?></span>
                    <div class="avatar-buttons">
                        <button type="button" class="media-pick btn btn-ghost btn-sm">
                            <i class="fa-solid fa-camera" aria-hidden="true"></i>
                            <?= $avatarPath !== '' ? e(t('profile.avatar.change')) : e(t('profile.avatar.upload')) ?>
                        </button>
                        <button type="button" class="media-remove btn btn-ghost btn-sm"<?= $avatarPath === '' ? ' hidden' : '' ?>><?= e(t('profile.avatar.remove')) ?></button>
                    </div>
                    <input type="file" class="media-file" accept="<?= e($accept) ?>" hidden>
                </div>
            </div>

            <form class="form-card" method="post" action="<?= e(url('profilo')) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="next" value="onboarding/atleta/opportunita">
                <!-- Valori correnti invariati: questo step espone SOLO la città. -->
                <input type="hidden" name="display_name" value="<?= e($profile['display_name']) ?>">
                <input type="hidden" name="handle" value="<?= e($profile['handle']) ?>">
                <input type="hidden" name="headline" value="<?= e((string) ($profile['headline'] ?? '')) ?>">
                <input type="hidden" name="bio" value="<?= e((string) ($profile['bio'] ?? '')) ?>">
                <input type="hidden" name="sport" value="<?= e((string) ($profile['sport_slug'] ?? '')) ?>">
                <input type="hidden" name="location_region" value="<?= e((string) ($profile['location_region'] ?? '')) ?>">
                <input type="hidden" name="location_country" value="<?= e((string) ($profile['location_country'] ?? '')) ?>">
                <input type="hidden" name="visibility" value="<?= e((string) ($profile['visibility'] ?? 'public')) ?>">
                <?php foreach ($attrValues as $attrKey => $attrVal): ?>
                    <?php if (is_scalar($attrVal)): ?>
                        <input type="hidden" name="attr[<?= e((string) $attrKey) ?>]" value="<?= e((string) $attrVal) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>

                <div class="field">
                    <label for="location_city"><?= e(t('profile.field.city')) ?> <span class="req">*</span></label>
                    <input class="input" type="text" id="location_city" name="location_city" maxlength="120" required
                           value="<?= e((string) ($profile['location_city'] ?? '')) ?>">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= e(t('common.continue')) ?></button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</main>
