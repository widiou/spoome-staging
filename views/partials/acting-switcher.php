<?php
/**
 * Switcher "Agisci come" (nav). @var array{current:?int,options:array<int,array<string,mixed>>} $sw
 * JS-free (details/summary), dark + accento giallo, no emoji. Ogni scelta è un form CSRF POST /agisci-come.
 */
$options = $sw['options'] ?? [];
if ($options === []) {
    return;
}
// Identità corrente (per la summary) + percorso di ritorno (whitelisted lato server).
$current = null;
foreach ($options as $o) {
    if (!empty($o['current'])) { $current = $o; break; }
}
$current ??= $options[0];

$base = rtrim(\Spoome\Core\Config::basePath(), '/');
$reqPath = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
if ($base !== '' && str_starts_with((string) $reqPath, $base)) {
    $reqPath = substr((string) $reqPath, strlen($base));
}
$return = ltrim((string) $reqPath, '/');
if ($return === '' || !preg_match('#^[a-z0-9/_-]+$#i', $return)) {
    $return = 'feed';
}

$avatar = static function (array $o): string {
    if (!empty($o['avatar_path'])) {
        return '<img class="avatar-img" src="' . e(url($o['avatar_path'])) . '" alt="">';
    }
    return e(initials((string) $o['name']));
};
?>
<details class="acting-switcher">
    <summary class="acting-current" title="<?= e(t('act.label')) ?>">
        <span class="acting-avatar" aria-hidden="true"><?= $avatar($current) ?></span>
        <span class="acting-name"><?= e((string) $current['name']) ?></span>
        <i class="fa-solid fa-chevron-down acting-caret" aria-hidden="true"></i>
    </summary>
    <div class="acting-menu" role="menu">
        <p class="acting-menu-head"><?= e(t('act.label')) ?></p>
        <?php foreach ($options as $o): ?>
            <form method="post" action="<?= e(url('agisci-come')) ?>" role="none">
                <?= csrf_field() ?>
                <input type="hidden" name="profile_id" value="<?= e((string) $o['id']) ?>">
                <input type="hidden" name="return" value="<?= e($return) ?>">
                <button type="submit" class="acting-option<?= !empty($o['current']) ? ' is-current' : '' ?>" role="menuitem">
                    <span class="acting-avatar" aria-hidden="true"><?= $avatar($o) ?></span>
                    <span class="acting-option-body">
                        <span class="acting-option-name"><?= e((string) $o['name']) ?></span>
                        <span class="acting-option-kind"><?= e($o['is_org'] ? '@' . $o['handle'] : t('act.personal')) ?></span>
                    </span>
                    <?php if (!empty($o['current'])): ?><i class="fa-solid fa-check" aria-hidden="true"></i><?php endif; ?>
                </button>
            </form>
        <?php endforeach; ?>
        <a class="acting-create" href="<?= e(url('pagine/nuova')) ?>">
            <i class="fa-solid fa-plus" aria-hidden="true"></i> <?= e(t('act.create_page')) ?>
        </a>
        <a class="acting-create" href="<?= e(url('pagine/inviti')) ?>">
            <i class="fa-solid fa-envelope-open-text" aria-hidden="true"></i> <?= e(t('member.inbox.nav')) ?>
        </a>
    </div>
</details>
