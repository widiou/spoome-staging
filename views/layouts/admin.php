<?php
/**
 * Layout dell'area amministrativa: shell con sidebar. SEMPRE noindex.
 * @var string $title @var string $content @var string $active (voce di nav attiva)
 */
use Spoome\Core\Config;
$pageTitle = $title ?? Config::appName();
$active = $active ?? '';
$nav = [
    'dashboard'  => ['url' => 'admin',            'icon' => 'fa-gauge-high',      'label' => t('admin.nav.dashboard')],
    'stats'      => ['url' => 'admin/statistiche', 'icon' => 'fa-chart-line',      'label' => t('admin.nav.stats')],
    'analytics'  => ['url' => 'admin/analytics',   'icon' => 'fa-chart-simple',    'label' => t('admin.nav.analytics')],
    'users'      => ['url' => 'admin/utenti',     'icon' => 'fa-users',           'label' => t('admin.nav.users')],
    'claims'     => ['url' => 'admin/rivendicazioni', 'icon' => 'fa-id-badge',    'label' => t('admin.nav.claims')],
    'profiles'   => ['url' => 'admin/profili',    'icon' => 'fa-building-shield', 'label' => t('admin.nav.profiles')],
    'moderation' => ['url' => 'admin/contenuti',  'icon' => 'fa-flag',            'label' => t('admin.nav.moderation')],
    'news'       => ['url' => 'admin/news',        'icon' => 'fa-rss',             'label' => t('admin.nav.news')],
    'logs'       => ['url' => 'admin/log',        'icon' => 'fa-triangle-exclamation', 'label' => t('admin.nav.logs')],
];
?><!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#101218">
    <meta name="base-path" content="<?= e(rtrim(Config::basePath(), '/')) ?>">
    <meta name="csrf-token" content="<?= e(Spoome\Core\Csrf::token()) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(asset('vendor/fonts/barlow.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="admin-body">
    <div class="admin-shell">
        <aside class="admin-sidebar">
            <a class="admin-brand" href="<?= e(url('admin')) ?>">
                <span class="brand-mark">S</span>
                <span class="admin-brand-text"><?= e(Config::appName()) ?> <small><?= e(t('admin.console')) ?></small></span>
            </a>
            <?php /* $pendingClaims è iniettato dal controller admin (AdminController::renderAdmin): la view non tocca il DB. */ $pendingClaims = $pendingClaims ?? 0; ?>
            <nav class="admin-nav">
                <?php foreach ($nav as $key => $item): ?>
                    <a href="<?= e(url($item['url'])) ?>" class="admin-nav-link<?= $active === $key ? ' is-active' : '' ?>">
                        <i class="fa-solid <?= e($item['icon']) ?>" aria-hidden="true"></i>
                        <span><?= e($item['label']) ?></span>
                        <?php if ($key === 'claims' && $pendingClaims > 0): ?>
                            <span class="admin-nav-count"><?= e((string) $pendingClaims) ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="admin-sidebar-foot">
                <a class="admin-nav-link" href="<?= e(url('')) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> <span><?= e(t('admin.back_to_site')) ?></span></a>
                <form method="post" action="<?= e(url('esci')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="admin-nav-link admin-logout"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> <span><?= e(t('nav.logout')) ?></span></button>
                </form>
            </div>
        </aside>
        <main class="admin-main">
            <?= $content ?>
        </main>
    </div>
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
