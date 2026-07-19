<?php
/**
 * Layout admin minimale (senza sidebar) per la schermata di verifica step-up. SEMPRE noindex.
 * @var string $title @var string $content
 */
use Spoome\Core\Config;
$pageTitle = $title ?? Config::appName();
?><!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="base-path" content="<?= e(rtrim(Config::basePath(), '/')) ?>">
    <meta name="csrf-token" content="<?= e(Spoome\Core\Csrf::token()) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(asset('vendor/fonts/barlow.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="admin-body admin-body-plain">
    <main class="admin-plain-main">
        <?= $content ?>
    </main>
</body>
</html>
