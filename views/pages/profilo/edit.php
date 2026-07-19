<?php
/**
 * Editor del proprio profilo (area autenticata). @var array $v valori correnti (riga arricchita)
 * @var array $sports @var string|null $error @var array|null $notice @var array $visibilities @var array|null $profile
 */
use Spoome\Core\View;

$val = static fn(string $k): string => e((string) ($v[$k] ?? ''));

// Raggruppa gli sport per categoria per gli <optgroup>.
$sportsByCat = [];
foreach ($sports as $s) {
    $sportsByCat[$s['category']][] = $s;
}
$currentSport = (string) ($v['sport_slug'] ?? '');
$currentVis   = (string) ($v['visibility'] ?? 'public');
$handle       = (string) ($v['handle'] ?? '');
?>
<main class="site-main">
    <section class="container form-page">
        <header class="form-page-head">
            <div>
                <h1><?= e(t('profile.edit.title')) ?></h1>
                <p class="muted"><?= e(t('profile.edit.subtitle')) ?></p>
            </div>
            <div class="head-actions" style="display:flex;gap:var(--sp-2);flex-wrap:wrap">
                <?php if (!empty($profile['is_organization']) && $handle !== ''): ?>
                    <a class="btn btn-ghost" href="<?= e(url('pagine/' . $handle . '/membri')) ?>">
                        <i class="fa-solid fa-users" aria-hidden="true"></i> <?= e(t('member.manage.link')) ?>
                    </a>
                <?php endif; ?>
                <?php if ($handle !== ''): ?>
                    <a class="btn btn-ghost" href="<?= e(profile_url($v)) ?>">
                        <i class="fa-solid fa-up-right-from-square" aria-hidden="true"></i> <?= e(t('profile.edit.view_public')) ?>
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <?php if (!empty($notice)): ?>
            <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($completeness)): $pct = (int) $completeness['pct']; ?>
            <div class="profile-meter">
                <div class="profile-meter-head">
                    <span class="profile-meter-label"><?= e(t('profile.complete.title')) ?></span>
                    <strong class="profile-meter-pct"><?= e((string) $pct) ?>%</strong>
                </div>
                <div class="profile-meter-track" role="progressbar" aria-valuenow="<?= e((string) $pct) ?>" aria-valuemin="0" aria-valuemax="100">
                    <span class="profile-meter-fill" style="width: <?= e((string) max(3, $pct)) ?>%"></span>
                </div>
                <?php if (!empty($completeness['missing'])): ?>
                    <p class="profile-meter-hint muted">
                        <?= e(t('profile.complete.missing')) ?>
                        <?php foreach ($completeness['missing'] as $i => $mk): ?><?= $i ? ', ' : ' ' ?><?= e($mk) ?><?php endforeach; ?>.
                    </p>
                <?php else: ?>
                    <p class="profile-meter-hint muted"><?= e(t('profile.complete.done')) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- CHI HA VISTO IL TUO PROFILO (F3) -->
        <?php
        $recentViewers = $recentViewers ?? [];
        $viewsCount7d  = (int) ($viewsCount7d ?? 0);
        $viewsTrend    = $viewsTrend ?? [];
        ?>
        <section class="pv-widget" id="visite" aria-labelledby="pv-title">
            <div class="pv-card">
                <div class="pv-eyebrow">
                    <i class="fa-solid fa-eye" aria-hidden="true"></i> <?= e(t('pviews.eyebrow')) ?>
                </div>
                <div class="pv-row">
                    <div class="pv-num">
                        <b><?= e((string) $viewsCount7d) ?></b>
                        <span class="pv-lbl"><?= e(t($viewsCount7d === 1 ? 'pviews.count_label_one' : 'pviews.count_label')) ?></span>
                    </div>
                    <?php if (array_sum($viewsTrend) > 0): ?>
                        <div class="pv-spark" aria-hidden="true"><?= \Spoome\Domain\Admin\Chart::spark($viewsTrend, '#D8F21D') ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <h2 id="pv-title" class="pv-heading"><?= e(t('pviews.recent')) ?></h2>
            <?php if ($recentViewers === []): ?>
                <div class="pv-empty">
                    <i class="fa-regular fa-eye-slash" aria-hidden="true"></i>
                    <p class="muted"><?= e(t('pviews.empty')) ?></p>
                </div>
            <?php else: ?>
                <ul class="pv-list">
                    <?php foreach ($recentViewers as $rv):
                        $rvName = (string) $rv['display_name'];
                        $rvHead = trim((string) ($rv['headline'] ?? '')) !== ''
                            ? (string) $rv['headline'] : (string) ($rv['type_label'] ?? '');
                        ?>
                        <li class="pv-item">
                            <a class="pv-viewer" href="<?= e(profile_url($rv)) ?>">
                                <span class="pv-avatar" aria-hidden="true">
                                    <?php if (!empty($rv['avatar_path'])): ?>
                                        <img src="<?= e(url($rv['avatar_path'])) ?>" alt="" loading="lazy">
                                    <?php else: ?>
                                        <?= e(initials($rvName)) ?>
                                    <?php endif; ?>
                                </span>
                                <span class="pv-id">
                                    <span class="pv-name">
                                        <?= e($rvName) ?>
                                        <?php if (!empty($rv['verified_at'])): ?><i class="fa-solid fa-circle-check pv-verified" title="<?= e(t('atleti.verified')) ?>" aria-hidden="true"></i><?php endif; ?>
                                    </span>
                                    <?php if ($rvHead !== ''): ?><span class="pv-head"><?= e($rvHead) ?></span><?php endif; ?>
                                </span>
                                <time class="pv-time" datetime="<?= e((string) $rv['last_viewed_at']) ?>"><?= e(time_ago((string) $rv['last_viewed_at'])) ?></time>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php
        $avatarPath = (string) ($v['avatar_path'] ?? '');
        $coverPath  = (string) ($v['cover_path'] ?? '');
        $avatarName = (string) ($v['display_name'] ?? '');
        $csrf = Spoome\Core\Csrf::token();
        $accept = 'image/jpeg,image/png,image/webp';
        $avatarI18n = json_encode([
            'title' => t('avatar.crop.title'), 'hint' => t('avatar.crop.hint'),
            'confirm' => t('avatar.crop.confirm'), 'cancel' => t('avatar.crop.cancel'),
            'upload' => t('profile.avatar.upload'), 'change' => t('profile.avatar.change'),
            'error' => t('avatar.error.invalid'),
        ], JSON_UNESCAPED_UNICODE);
        $coverI18n = json_encode([
            'title' => t('profile.cover.crop_title'), 'hint' => t('avatar.crop.hint'),
            'confirm' => t('avatar.crop.confirm'), 'cancel' => t('avatar.crop.cancel'),
            'upload' => t('profile.cover.upload'), 'change' => t('profile.cover.change'),
            'error' => t('avatar.error.invalid'), 'empty' => t('profile.cover.empty'),
        ], JSON_UNESCAPED_UNICODE);
        ?>
        <!-- COPERTINA -->
        <div class="media-uploader cover-editor"
             data-upload-url="<?= e(url('profilo/cover')) ?>" data-delete-url="<?= e(url('profilo/cover/elimina')) ?>"
             data-csrf="<?= e($csrf) ?>" data-i18n="<?= e($coverI18n) ?>"
             data-aspect="3" data-out-w="1500" data-out-h="500" data-round="0">
            <div class="media-preview cover-preview<?= $coverPath !== '' ? ' has-image' : '' ?>">
                <?php if ($coverPath !== ''): ?><img class="cover-img" src="<?= e(url($coverPath)) ?>" alt=""><?php endif; ?>
                <span class="cover-placeholder"><i class="fa-solid fa-panorama" aria-hidden="true"></i><?= e(t('profile.cover.empty')) ?></span>
            </div>
            <div class="media-actions">
                <span class="avatar-label"><?= e(t('profile.cover.title')) ?></span>
                <div class="avatar-buttons">
                    <button type="button" class="media-pick btn btn-ghost btn-sm">
                        <i class="fa-solid fa-image" aria-hidden="true"></i>
                        <?= $coverPath !== '' ? e(t('profile.cover.change')) : e(t('profile.cover.upload')) ?>
                    </button>
                    <button type="button" class="media-remove btn btn-ghost btn-sm"<?= $coverPath === '' ? ' hidden' : '' ?>><?= e(t('profile.cover.remove')) ?></button>
                </div>
                <input type="file" class="media-file" accept="<?= e($accept) ?>" hidden>
                <p class="field-help"><?= e(t('profile.cover.hint')) ?></p>
            </div>
        </div>

        <!-- AVATAR -->
        <div class="media-uploader avatar-editor"
             data-upload-url="<?= e(url('profilo/avatar')) ?>" data-delete-url="<?= e(url('profilo/avatar/elimina')) ?>"
             data-csrf="<?= e($csrf) ?>" data-i18n="<?= e($avatarI18n) ?>"
             data-aspect="1" data-out-w="512" data-out-h="512" data-round="1">
            <div class="media-preview avatar-preview<?= $avatarPath !== '' ? ' has-image' : '' ?>" data-initials="<?= e(initials($avatarName)) ?>">
                <?php if ($avatarPath !== ''): ?>
                    <img class="avatar-img" src="<?= e(url($avatarPath)) ?>" alt="">
                <?php else: ?>
                    <span class="avatar-initials"><?= e(initials($avatarName)) ?></span>
                <?php endif; ?>
            </div>
            <div class="avatar-actions media-actions">
                <span class="avatar-label"><?= e(t('profile.avatar.title')) ?></span>
                <div class="avatar-buttons">
                    <button type="button" class="media-pick btn btn-ghost btn-sm">
                        <i class="fa-solid fa-camera" aria-hidden="true"></i>
                        <?= $avatarPath !== '' ? e(t('profile.avatar.change')) : e(t('profile.avatar.upload')) ?>
                    </button>
                    <button type="button" class="media-remove btn btn-ghost btn-sm"<?= $avatarPath === '' ? ' hidden' : '' ?>><?= e(t('profile.avatar.remove')) ?></button>
                </div>
                <input type="file" class="media-file" accept="<?= e($accept) ?>" hidden>
                <p class="field-help"><?= e(t('profile.avatar.hint')) ?></p>
            </div>
        </div>

        <form class="form-card" method="post" action="<?= e(url('profilo')) ?>" novalidate>
            <?= csrf_field() ?>

            <div class="field">
                <label for="display_name"><?= e(t('profile.field.display_name')) ?> <span class="req">*</span></label>
                <input class="input" type="text" id="display_name" name="display_name" maxlength="160" required value="<?= $val('display_name') ?>">
            </div>

            <div class="field">
                <label for="handle"><?= e(t('profile.field.handle')) ?> <span class="req">*</span></label>
                <input class="input" type="text" id="handle" name="handle" maxlength="30" required
                       pattern="[a-zA-Z0-9_]{3,30}" autocapitalize="none" spellcheck="false" value="<?= $val('handle') ?>">
                <span class="field-help"><?= e(t('profile.field.handle_help', ['url' => rtrim(Spoome\Core\Config::basePath(), '/') . '/atleti/'])) ?></span>
            </div>

            <div class="field">
                <label for="headline"><?= e(t('profile.field.headline')) ?></label>
                <input class="input" type="text" id="headline" name="headline" maxlength="200"
                       placeholder="<?= e(t('profile.field.headline_ph')) ?>" value="<?= $val('headline') ?>">
            </div>

            <div class="field">
                <label for="bio"><?= e(t('profile.field.bio')) ?></label>
                <textarea class="input textarea" id="bio" name="bio" rows="6" maxlength="5000"
                          placeholder="<?= e(t('profile.field.bio_ph')) ?>"><?= $val('bio') ?></textarea>
            </div>

            <div class="field">
                <label for="sport"><?= e(t('profile.field.sport')) ?></label>
                <select class="select" id="sport" name="sport">
                    <option value=""><?= e(t('profile.field.sport_none')) ?></option>
                    <?php foreach ($sportsByCat as $cat => $list): ?>
                        <optgroup label="<?= e($cat) ?>">
                            <?php foreach ($list as $s): ?>
                                <option value="<?= e($s['slug']) ?>"<?= $currentSport === $s['slug'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>

            <fieldset class="field-row">
                <div class="field">
                    <label for="location_city"><?= e(t('profile.field.city')) ?></label>
                    <input class="input" type="text" id="location_city" name="location_city" maxlength="120" value="<?= $val('location_city') ?>">
                </div>
                <div class="field">
                    <label for="location_region"><?= e(t('profile.field.region')) ?></label>
                    <input class="input" type="text" id="location_region" name="location_region" maxlength="120" value="<?= $val('location_region') ?>">
                </div>
                <div class="field">
                    <label for="location_country"><?= e(t('profile.field.country')) ?></label>
                    <input class="input" type="text" id="location_country" name="location_country" maxlength="120" value="<?= $val('location_country') ?>">
                </div>
            </fieldset>

            <div class="field">
                <label for="visibility"><?= e(t('profile.field.visibility')) ?></label>
                <select class="select" id="visibility" name="visibility">
                    <?php foreach ($visibilities as $vis): ?>
                        <option value="<?= e($vis) ?>"<?= $currentVis === $vis ? ' selected' : '' ?>><?= e(t('profile.visibility.' . $vis)) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="field-help"><?= e(t('profile.field.visibility_help')) ?></span>
            </div>

            <?php
            // Campi descrittivi type-specific (org): generati dallo schema del tipo, prefill da profiles.attributes.
            $schemaFields = $schemaFields ?? [];
            $attrValues   = $attrValues ?? [];
            if ($schemaFields !== []):
                $typeLabel = (string) ($profile['type_label'] ?? '');
            ?>
            <fieldset class="attr-fields">
                <legend><?= e(t('profile.attr.title')) ?><?= $typeLabel !== '' ? ' ' . e($typeLabel) : '' ?></legend>
                <?php foreach ($schemaFields as $f):
                    $fk  = $f['key'];
                    $cur = (string) ($attrValues[$fk] ?? '');
                    $id  = 'attr_' . preg_replace('/[^a-z0-9_]/i', '', $fk);
                ?>
                    <div class="field">
                        <label for="<?= e($id) ?>"><?= e($f['label']) ?></label>
                        <?php if ($f['type'] === 'select'): ?>
                            <select class="select" id="<?= e($id) ?>" name="attr[<?= e($fk) ?>]">
                                <option value=""><?= e(t('profile.attr.select_none')) ?></option>
                                <?php foreach ($f['options'] as $opt): ?>
                                    <option value="<?= e($opt) ?>"<?= $cur === (string) $opt ? ' selected' : '' ?>><?= e($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($f['type'] === 'year'): ?>
                            <input class="input" type="number" id="<?= e($id) ?>" name="attr[<?= e($fk) ?>]"
                                   min="1800" max="2100" inputmode="numeric" value="<?= e($cur) ?>">
                        <?php elseif ($f['type'] === 'url'): ?>
                            <input class="input" type="url" id="<?= e($id) ?>" name="attr[<?= e($fk) ?>]"
                                   maxlength="<?= e((string) $f['maxlen']) ?>" placeholder="https://…" value="<?= e($cur) ?>">
                        <?php else: ?>
                            <input class="input" type="text" id="<?= e($id) ?>" name="attr[<?= e($fk) ?>]"
                                   maxlength="<?= e((string) $f['maxlen']) ?>" value="<?= e($cur) ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </fieldset>
            <?php endif; ?>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= e(t('profile.edit.save')) ?></button>
            </div>
        </form>

        <?php $sections = $sections ?? ['skills' => true, 'experiences' => true, 'achievements' => true, 'links' => true]; ?>

        <!-- ESPERIENZE -->
        <?php if (!empty($sections['experiences'])): ?>
        <details class="edit-section acc" id="esperienze" open>
            <summary class="acc-head"><span class="acc-ico"><i class="fa-solid fa-briefcase" aria-hidden="true"></i></span><span class="acc-t"><?= e(t('profile.exp.title')) ?></span><i class="fa-solid fa-chevron-down acc-chev" aria-hidden="true"></i></summary>
            <div class="acc-body">
            <?php if ($experiences === []): ?>
                <p class="muted empty-row"><?= e(t('profile.details.empty')) ?></p>
            <?php endif; ?>
            <ul class="item-list" data-detail-list="esperienze">
                <?php foreach ($experiences as $x): ?><?= View::partial('detail-experience-item', ['x' => $x]) ?><?php endforeach; ?>
            </ul>
            <form method="post" action="<?= e(url('profilo/esperienze')) ?>" class="add-form" data-async data-async-success="appendHtml resetForm" data-target="[data-detail-list='esperienze']">
                <?= csrf_field() ?>
                <div class="field-row">
                    <div class="field"><label><?= e(t('profile.exp.role')) ?> <span class="req">*</span></label><input class="input" type="text" name="role" maxlength="160" required></div>
                    <div class="field"><label><?= e(t('profile.exp.org')) ?> <span class="req">*</span></label><input class="input" type="text" name="org_name" maxlength="160" required></div>
                    <div class="field"><label><?= e(t('profile.exp.location')) ?></label><input class="input" type="text" name="location" maxlength="160"></div>
                </div>
                <div class="field-row">
                    <div class="field"><label><?= e(t('profile.exp.start')) ?></label><input class="input" type="number" name="start_year" min="1900" max="2100" inputmode="numeric"></div>
                    <div class="field"><label><?= e(t('profile.exp.end')) ?></label><input class="input" type="number" name="end_year" min="1900" max="2100" inputmode="numeric"></div>
                    <div class="field field-check"><label class="check"><input type="checkbox" name="is_current" value="1"> <?= e(t('profile.exp.current')) ?></label></div>
                </div>
                <div class="field"><label><?= e(t('profile.exp.description')) ?></label><textarea class="input textarea" name="description" rows="2" maxlength="1000"></textarea></div>
                <div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit"><i class="fa-solid fa-plus" aria-hidden="true"></i> <?= e(t('profile.details.add')) ?></button></div>
            </form>
            </div>
        </details>
        <?php endif; ?>

        <!-- AFFILIAZIONI (roster / militanza) -->
        <?php if (!empty($sections['roster']) || !empty($sections['career'])):
            $affRoster    = $affRoster ?? [];
            $affMilitanza = $affMilitanza ?? [];
            $affPending   = $affPending ?? [];
            $affOutgoing  = $affOutgoing ?? [];
            $isOrgEditor  = !empty($sections['roster']);
            $confirmedAff = $isOrgEditor ? $affRoster : $affMilitanza;
        ?>
        <details class="edit-section acc" id="affiliazioni" open>
            <summary class="acc-head"><span class="acc-ico"><i class="fa-solid fa-handshake-angle" aria-hidden="true"></i></span><span class="acc-t"><?= e($isOrgEditor ? t('affil.roster.title') : t('affil.career.title')) ?></span><i class="fa-solid fa-chevron-down acc-chev" aria-hidden="true"></i></summary>
            <div class="acc-body">

            <?php if (!empty($affPending)): ?>
                <p class="aff-subhead"><?= e(t('affil.pending.title')) ?></p>
                <p class="muted field-help"><?= e(t('affil.pending.hint')) ?></p>
                <ul class="pv-list aff-list">
                    <?php foreach ($affPending as $a): ?><?= View::partial('affiliation-card', ['a' => $a, 'manage' => true, 'return' => 'profilo']) ?><?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php /* Richieste IN USCITA (create da me), in attesa di conferma della controparte. Contenitore
                     dedicato: l'append async e la rimozione dell'empty-row restano isolati da questa lista. */ ?>
            <div class="aff-outgoing">
                <p class="aff-subhead"><?= e(t('affil.outgoing.title')) ?></p>
                <p class="muted field-help"><?= e(t('affil.outgoing.hint')) ?></p>
                <?php if ($affOutgoing === []): ?>
                    <p class="muted empty-row"><?= e(t('affil.outgoing.empty')) ?></p>
                <?php endif; ?>
                <ul class="pv-list aff-list" data-aff-outgoing>
                    <?php foreach ($affOutgoing as $a): ?><?= View::partial('affiliation-card', ['a' => $a, 'manage' => true, 'outgoing' => true, 'return' => 'profilo']) ?><?php endforeach; ?>
                </ul>
            </div>

            <?php if (!empty($confirmedAff)): ?>
                <p class="aff-subhead"><?= e($isOrgEditor ? t('affil.roster.current') : t('affil.career.current')) ?></p>
                <ul class="pv-list aff-list">
                    <?php foreach ($confirmedAff as $a): ?><?= View::partial('affiliation-card', ['a' => $a, 'manage' => true, 'return' => 'profilo']) ?><?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="muted empty-row"><?= e(t('profile.details.empty')) ?></p>
            <?php endif; ?>

            <p class="muted field-help"><?= e($isOrgEditor ? t('affil.edit.member_hint') : t('affil.edit.request_hint')) ?></p>
            <form method="post" action="<?= e(url('profilo/affiliazioni')) ?>" class="add-form" data-async data-async-success="appendHtml resetForm toast" data-target="[data-aff-outgoing]" data-toast-ok="<?= e(t('affil.flash.requested')) ?>">
                <?= csrf_field() ?>
                <div class="field-row">
                    <div class="field"><label><?= e(t('affil.f.handle')) ?> <span class="req">*</span></label><input class="input" type="text" name="handle" maxlength="120" required placeholder="es. asd-rivarolo"></div>
                    <div class="field"><label><?= e(t('affil.f.role')) ?></label><input class="input" type="text" name="role" maxlength="80"></div>
                    <div class="field"><label><?= e(t('affil.f.team')) ?></label><input class="input" type="text" name="team" maxlength="80"></div>
                </div>
                <div class="field-row">
                    <div class="field"><label><?= e(t('affil.f.jersey')) ?></label><input class="input" type="text" name="jersey" maxlength="10" inputmode="numeric"></div>
                    <div class="field"><label><?= e(t('affil.f.start_year')) ?></label><input class="input" type="number" name="start_year" min="1900" max="2100" inputmode="numeric"></div>
                    <div class="field"><label><?= e(t('affil.f.end_year')) ?></label><input class="input" type="number" name="end_year" min="1900" max="2100" inputmode="numeric"></div>
                </div>
                <div class="field field-check"><label class="check"><input type="checkbox" name="is_current" value="1" checked> <?= e(t('affil.f.current')) ?></label></div>
                <div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit"><i class="fa-solid fa-plus" aria-hidden="true"></i> <?= e($isOrgEditor ? t('affil.action.add') : t('affil.action.request')) ?></button></div>
            </form>

            <?php if (!empty($sections['org_career'])): // Società/associazione → affiliazione a una FEDERAZIONE ?>
                <p class="aff-subhead" style="margin-top:var(--sp-5)"><?= e(t('affil.orgcareer.title')) ?></p>
                <?php if (!empty($affMilitanza)): ?>
                    <ul class="pv-list aff-list">
                        <?php foreach ($affMilitanza as $a): ?><?= View::partial('affiliation-card', ['a' => $a, 'manage' => true, 'return' => 'profilo']) ?><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <p class="muted field-help"><?= e(t('affil.edit.fed_hint')) ?></p>
                <form method="post" action="<?= e(url('profilo/affiliazioni')) ?>" class="add-form" data-async data-async-success="appendHtml resetForm toast" data-target="[data-aff-outgoing]" data-toast-ok="<?= e(t('affil.flash.requested')) ?>">
                    <?= csrf_field() ?>
                    <div class="field-row">
                        <div class="field"><label><?= e(t('affil.f.fed_handle')) ?> <span class="req">*</span></label><input class="input" type="text" name="handle" maxlength="120" required placeholder="es. lnd-dilettanti"></div>
                        <div class="field"><label><?= e(t('affil.f.start_year')) ?></label><input class="input" type="number" name="start_year" min="1900" max="2100" inputmode="numeric"></div>
                    </div>
                    <div class="field field-check"><label class="check"><input type="checkbox" name="is_current" value="1" checked> <?= e(t('affil.f.current')) ?></label></div>
                    <div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit"><i class="fa-solid fa-plus" aria-hidden="true"></i> <?= e(t('affil.action.request_fed')) ?></button></div>
                </form>
            <?php endif; ?>
            </div>
        </details>
        <?php endif; ?>

        <!-- PALMARÈS -->
        <?php if (!empty($sections['achievements'])): ?>
        <details class="edit-section acc" id="palmares" open>
            <summary class="acc-head"><span class="acc-ico"><i class="fa-solid fa-trophy" aria-hidden="true"></i></span><span class="acc-t"><?= e(t('profile.ach.title')) ?></span><i class="fa-solid fa-chevron-down acc-chev" aria-hidden="true"></i></summary>
            <div class="acc-body">
            <?php if ($achievements === []): ?>
                <p class="muted empty-row"><?= e(t('profile.details.empty')) ?></p>
            <?php endif; ?>
            <ul class="item-list" data-detail-list="palmares">
                <?php foreach ($achievements as $a): ?><?= View::partial('detail-achievement-item', ['a' => $a]) ?><?php endforeach; ?>
            </ul>
            <form method="post" action="<?= e(url('profilo/palmares')) ?>" class="add-form" data-async data-async-success="appendHtml resetForm" data-target="[data-detail-list='palmares']">
                <?= csrf_field() ?>
                <div class="field-row">
                    <div class="field" style="grid-column: span 2"><label><?= e(t('profile.ach.name')) ?> <span class="req">*</span></label><input class="input" type="text" name="title" maxlength="200" required></div>
                    <div class="field"><label><?= e(t('profile.ach.year')) ?></label><input class="input" type="number" name="year" min="1900" max="2100" inputmode="numeric"></div>
                </div>
                <div class="field"><label><?= e(t('profile.ach.description')) ?></label><input class="input" type="text" name="description" maxlength="500"></div>
                <div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit"><i class="fa-solid fa-plus" aria-hidden="true"></i> <?= e(t('profile.details.add')) ?></button></div>
            </form>
            </div>
        </details>
        <?php endif; ?>

        <!-- LINK -->
        <?php if (!empty($sections['links'])): ?>
        <details class="edit-section acc" id="link" open>
            <summary class="acc-head"><span class="acc-ico"><i class="fa-solid fa-link" aria-hidden="true"></i></span><span class="acc-t"><?= e(t('profile.link.title')) ?></span><i class="fa-solid fa-chevron-down acc-chev" aria-hidden="true"></i></summary>
            <div class="acc-body">
            <?php if ($links === []): ?>
                <p class="muted empty-row"><?= e(t('profile.details.empty')) ?></p>
            <?php endif; ?>
            <ul class="item-list" data-detail-list="link">
                <?php foreach ($links as $l): ?><?= View::partial('detail-link-item', ['l' => $l, 'linkKinds' => $linkKinds]) ?><?php endforeach; ?>
            </ul>
            <form method="post" action="<?= e(url('profilo/link')) ?>" class="add-form" data-async data-async-success="appendHtml resetForm" data-target="[data-detail-list='link']">
                <?= csrf_field() ?>
                <div class="field-row">
                    <div class="field"><label><?= e(t('profile.link.kind')) ?></label>
                        <select class="select" name="kind">
                            <?php foreach ($linkKinds as $k): ?>
                                <option value="<?= e($k) ?>"><?= e(link_kind_label($k)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field"><label><?= e(t('profile.link.label')) ?></label><input class="input" type="text" name="label" maxlength="120"></div>
                    <div class="field"><label><?= e(t('profile.link.url')) ?> <span class="req">*</span></label><input class="input" type="text" name="url" maxlength="500" required placeholder="https://…"></div>
                </div>
                <div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit"><i class="fa-solid fa-plus" aria-hidden="true"></i> <?= e(t('profile.details.add')) ?></button></div>
            </form>
            </div>
        </details>
        <?php endif; ?>

        <!-- COMPETENZE -->
        <?php $skills = $skills ?? []; ?>
        <?php if (!empty($sections['skills'])): ?>
        <details class="edit-section acc" id="competenze" open>
            <summary class="acc-head"><span class="acc-ico"><i class="fa-solid fa-ranking-star" aria-hidden="true"></i></span><span class="acc-t"><?= e(t('skill.section.title')) ?></span><i class="fa-solid fa-chevron-down acc-chev" aria-hidden="true"></i></summary>
            <div class="acc-body">
            <p class="muted field-help"><?= e(t('skill.edit.hint')) ?></p>
            <?php if ($skills === []): ?>
                <p class="muted empty-row"><?= e(t('profile.details.empty')) ?></p>
            <?php endif; ?>
            <ul class="edit-skill-list" data-detail-list="competenze">
                <?php foreach ($skills as $s): ?><?= View::partial('skill-edit-item', ['s' => $s]) ?><?php endforeach; ?>
            </ul>
            <?php if (count($skills) < 20): ?>
                <form method="post" action="<?= e(url('profilo/competenze')) ?>" class="add-form add-skill-form" data-async data-async-success="appendHtml resetForm" data-target="[data-detail-list='competenze']">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label><?= e(t('skill.field.label')) ?> <span class="req">*</span></label>
                        <input class="input" type="text" name="label" maxlength="60" required placeholder="<?= e(t('skill.field.placeholder')) ?>">
                    </div>
                    <div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit"><i class="fa-solid fa-plus" aria-hidden="true"></i> <?= e(t('skill.edit.add')) ?></button></div>
                </form>
            <?php else: ?>
                <p class="muted empty-row"><?= e(t('skill.edit.max_reached')) ?></p>
            <?php endif; ?>
            </div>
        </details>
        <?php endif; ?>

        <!-- RACCOMANDAZIONI RICEVUTE -->
        <?php
        $recoPending = $recoPending ?? [];
        $recoVisible = $recoVisible ?? [];
        if (empty($profile['is_organization'])):
        ?>
        <details class="edit-section acc" id="raccomandazioni" open>
            <summary class="acc-head"><span class="acc-ico"><i class="fa-solid fa-comment-dots" aria-hidden="true"></i></span><span class="acc-t"><?= e(t('reco.manage.title')) ?><?php if (!empty($recoPending)): ?> <span class="aff-badge aff-badge-pending"><?= e((string) count($recoPending)) ?></span><?php endif; ?></span><i class="fa-solid fa-chevron-down acc-chev" aria-hidden="true"></i></summary>
            <div class="acc-body">
            <p class="muted field-help"><?= e(t('reco.manage.hint')) ?></p>
            <?php if (empty($recoPending) && empty($recoVisible)): ?>
                <p class="muted empty-row"><?= e(t('reco.manage.empty')) ?></p>
            <?php endif; ?>
            <?php if (!empty($recoPending)): ?>
                <p class="aff-subhead"><?= e(t('reco.manage.pending')) ?></p>
                <ul class="reco-list reco-manage-list">
                    <?php foreach ($recoPending as $r): ?><?= View::partial('reco-manage-item', ['r' => $r, 'pending' => true]) ?><?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (!empty($recoVisible)): ?>
                <p class="aff-subhead"><?= e(t('reco.manage.visible')) ?></p>
                <ul class="reco-list reco-manage-list">
                    <?php foreach ($recoVisible as $r): ?><?= View::partial('reco-manage-item', ['r' => $r, 'pending' => false]) ?><?php endforeach; ?>
                </ul>
            <?php endif; ?>
            </div>
        </details>
        <?php endif; ?>
    </section>
</main>
<script src="<?= e(asset('js/cropper.js')) ?>" defer></script>
