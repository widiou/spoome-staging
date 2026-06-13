<?php
$domain = $_SERVER['HTTP_HOST'];
if (!str_contains($domain, 'spoome.it')) {
    die;
}


$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';
?>

<!doctype html>
<html lang="it" xmlns="http://www.w3.org/1999/html" data-bs-theme="dark">
<head>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-35SJHX8G3T"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());

        gtag('config', 'G-35SJHX8G3T');
    </script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SPOOME</title>
    <link rel="icon" type="image/x-icon" href="<?= SUB_ROOT ?>/assets/favicon.ico">
    <link href="<?= SUB_ROOT ?>/node_modules/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= SUB_ROOT ?>/node_modules/bootstrap-icons/font/bootstrap-icons.min.css">
    <link href="<?= SUB_ROOT ?>/assets/css/spoome.css?<?= rand(0, 1000000) ?>" rel="stylesheet">
</head>
<style>
    body {
        background: #101218;
        font-family: 'Red Hat Display', sans-serif;
        overflow: hidden;
    }

    .container {
        padding: 0;
        margin: 0;
    }
</style>

<body>
