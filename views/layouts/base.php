<?php
/**
 * Layout base mobile-first. Variabili passate da View::render().
 * @var string $title
 * @var string $content
 */
use Spoome\Core\Config;
use Spoome\Core\View;
$pageTitle = $title ?? Config::appName();
$desc = $description ?? 'Il professional network dello sport: atleti, società, associazioni, federazioni e fan.';
$bodyClass = $bodyClass ?? '';
$ogType = $ogType ?? 'website';
$ogImage = $ogImage ?? null;
$canonical = $canonical ?? (Config::baseUrl() . strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
?><!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#101218">
    <meta name="base-path" content="<?= e(rtrim(Config::basePath(), '/')) ?>">
    <?php if (auth_id() !== null): // token CSRF per gli AJAX web solo agli autenticati (niente sessioni agli anonimi) ?>
    <meta name="csrf-token" content="<?= e(Spoome\Core\Csrf::token()) ?>">
    <?php $actingPid = acting_profile_id(); if ($actingPid !== null): ?>
    <meta name="acting-profile" content="<?= e((string) $actingPid) ?>">
    <?php endif; ?>
    <?php endif; ?>
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($desc) ?>">
    <link rel="canonical" href="<?= e($canonical) ?>">
    <?php if (!Config::isProduction()): ?>
    <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
    <meta property="og:title" content="<?= e($pageTitle) ?>">
    <meta property="og:description" content="<?= e($desc) ?>">
    <meta property="og:type" content="<?= e($ogType) ?>">
    <meta property="og:url" content="<?= e($canonical) ?>">
    <meta property="og:site_name" content="<?= e(Config::appName()) ?>">
    <meta property="og:locale" content="it_IT">
    <?php if ($ogImage): ?>
    <meta property="og:image" content="<?= e($ogImage) ?>">
    <meta name="twitter:image" content="<?= e($ogImage) ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
    <meta name="twitter:title" content="<?= e($pageTitle) ?>">
    <meta name="twitter:description" content="<?= e($desc) ?>">
    <!-- Font e icone self-hosted: zero richieste a CDN esterni (velocità + CSP chiusa + privacy/GDPR). -->
    <link rel="stylesheet" href="<?= e(asset('vendor/fonts/barlow.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="<?= e($bodyClass) ?>">
    <header class="site-header">
        <a class="brand" href="<?= e(url('')) ?>">
            <span class="brand-mark">S</span>
            <span class="brand-name"><?= e(Config::appName()) ?></span>
        </a>
        <form class="nav-search" method="get" action="<?= e(url('atleti')) ?>" role="search">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input type="search" name="q" value="<?= e((string) ($_GET['q'] ?? '')) ?>" placeholder="<?= e(t('nav.search_ph')) ?>" aria-label="<?= e(t('nav.search_ph')) ?>" maxlength="80" data-suggest autocomplete="off" aria-autocomplete="list">
        </form>
        <a class="nav-search-icon" href="<?= e(url('atleti')) ?>" aria-label="<?= e(t('nav.search_ph')) ?>"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></a>
        <nav class="site-nav">
            <a class="nav-primary" href="<?= e(url('atleti')) ?>"><?= e(t('nav.athletes')) ?></a>
            <?php if (auth_id() !== null): ?>
                <a class="nav-primary" href="<?= e(url('feed')) ?>"><?= e(t('nav.feed')) ?></a>
                <a class="nav-primary" href="<?= e(url('rete')) ?>"><?= e(t('nav.network')) ?></a>
                <?php $dmUnread = dm_unread(); ?>
                <a class="nav-primary" data-nav="dm" href="<?= e(url('messaggi')) ?>"><?= e(t('nav.messages')) ?><?php if ($dmUnread > 0): ?> <span class="nav-badge"><?= e((string) $dmUnread) ?></span><?php endif; ?></a>
                <?php $notifUnread = notif_unread(); ?>
                <a class="nav-bell" data-nav="notif" data-label="<?= e(t('notif.title')) ?>" href="<?= e(url('notifiche')) ?>" title="<?= e(t('notif.title')) ?>" aria-label="<?= e(t('notif.title')) ?><?= $notifUnread > 0 ? ' (' . (int) $notifUnread . ')' : '' ?>"><i class="fa-solid fa-bell" aria-hidden="true"></i><?php if ($notifUnread > 0): ?> <span class="nav-badge"><?= e((string) $notifUnread) ?></span><?php endif; ?></a>
                <a class="nav-dm" data-nav="dm" href="<?= e(url('messaggi')) ?>" title="<?= e(t('nav.messages')) ?>" aria-label="<?= e(t('nav.messages')) ?><?= $dmUnread > 0 ? ' (' . (int) $dmUnread . ')' : '' ?>"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i><?php if ($dmUnread > 0): ?> <span class="nav-badge"><?= e((string) $dmUnread) ?></span><?php endif; ?></a>
                <a class="nav-primary" href="<?= e(url('profilo')) ?>"><?= e(t('nav.my_profile')) ?></a>
                <?php $sw = acting_switcher_data(); if ($sw !== null): ?>
                    <?= View::partial('acting-switcher', ['sw' => $sw]) ?>
                <?php endif; ?>
                <?php if (is_admin()): ?>
                    <a href="<?= e(url('admin')) ?>" class="nav-admin-link" title="<?= e(t('admin.console')) ?>" aria-label="<?= e(t('admin.console')) ?>"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></a>
                <?php endif; ?>
                <form class="nav-logout" method="post" action="<?= e(url('esci')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm nav-logout-btn" aria-label="<?= e(t('nav.logout')) ?>"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i><span class="nav-logout-label"><?= e(t('nav.logout')) ?></span></button>
                </form>
            <?php else: ?>
                <a href="<?= e(url('accedi')) ?>"><?= e(t('nav.login')) ?></a>
                <a href="<?= e(url('registrati')) ?>" class="btn btn-primary"><?= e(t('nav.signup')) ?></a>
            <?php endif; ?>
        </nav>
    </header>

    <?= $content ?>

    <?php if (auth_id() !== null):
        $dmU = $dmUnread ?? dm_unread(); $ntU = $notifUnread ?? notif_unread(); ?>
    <nav class="bottom-nav" aria-label="<?= e(t('nav.primary')) ?>">
        <a href="<?= e(url('feed')) ?>"><i class="fa-solid fa-house" aria-hidden="true"></i><span><?= e(t('nav.feed')) ?></span></a>
        <a href="<?= e(url('rete')) ?>"><i class="fa-solid fa-user-group" aria-hidden="true"></i><span><?= e(t('nav.network')) ?></span></a>
        <button type="button" class="bn-create" data-composer-open aria-label="<?= e(t('feed.compose.submit')) ?>"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>
        <a href="<?= e(url('notifiche')) ?>" data-bn="notif"><i class="fa-solid fa-bell" aria-hidden="true"></i><?php if ($ntU > 0): ?><span class="bn-dot"></span><?php endif; ?><span><?= e(t('notif.title')) ?></span></a>
        <a href="<?= e(url('profilo')) ?>"><i class="fa-solid fa-user" aria-hidden="true"></i><span><?= e(t('nav.my_profile')) ?></span></a>
    </nav>

    <!-- Composer a bottom-sheet (mobile, stile Instagram): il "+" della bottom-nav lo apre. -->
    <div class="sheet-backdrop" data-composer-close hidden></div>
    <div class="sheet composer-sheet" id="composer-sheet" role="dialog" aria-modal="true" aria-label="<?= e(t('feed.compose.placeholder')) ?>" hidden>
        <div class="sheet-handle" aria-hidden="true"></div>
        <div class="sheet-head">
            <button type="button" class="btn btn-ghost btn-sm" data-composer-close><?= e(t('common.cancel')) ?></button>
            <strong><?= e(t('feed.compose.submit')) ?></strong>
            <span></span>
        </div>
        <form class="composer composer-in-sheet" method="post" action="<?= e(url('feed/post')) ?>" data-async data-async-handler="composer" data-unfurl="<?= e(url('feed/unfurl')) ?>">
            <?= csrf_field() ?>
            <label class="sr-only" for="sheet-body"><?= e(t('feed.compose.placeholder')) ?></label>
            <textarea class="input textarea" id="sheet-body" name="body" rows="4" maxlength="2000" placeholder="<?= e(t('feed.compose.placeholder')) ?>" required data-link-source></textarea>
            <input type="hidden" name="link_preview_url_hash" value="" data-link-hash>
            <div class="composer-preview" data-link-preview hidden></div>
            <div class="composer-actions">
                <button class="btn btn-primary" data-submit><?= e(t('feed.compose.submit')) ?></button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <script src="<?= e(asset('js/app.js')) ?>" defer></script>

    <footer class="site-footer">
        <p>© <?= date('Y') ?> <?= e(Config::appName()) ?> — <?= e(t('app.claim')) ?></p>
    </footer>
</body>
</html>
