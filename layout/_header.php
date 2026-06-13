<?php
require_once 'bootstrap.php';
require_once 'settings/default.php';
$db = Database::getInstance();
$connection = $db->getConnection();
$current_page = basename($_SERVER['SCRIPT_NAME']);

?>
    <!doctype html>
    <html lang="it" xmlns="http://www.w3.org/1999/html" data-bs-theme="dark">
    <head>
        <script async src="https://www.googletagmanager.com/gtag/js?id=G-35SJHX8G3T"></script>
        <script>
          window.dataLayer = window.dataLayer || [];
          function gtag(){dataLayer.push(arguments);}
          gtag('js', new Date());
          gtag('config', 'G-35SJHX8G3T');
        </script>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <script>
          // Base path/URL dell'app (varia per ambiente: /network in prod, /beta in staging)
          window.SPOOME_BASE = '<?= SUB_ROOT ?>';
          window.SPOOME_URL  = '<?= rtrim(BASE_URL, "/") ?>';
        </script>
        <?php
        if (isset($title)) {
            ?>
            <title><?= $title ?> <?=T_TITLE_EXT?></title>
            <?php
        }
        ?>
        <link rel="icon" type="image/x-icon" href="<?=SUB_ROOT?>/assets/favicon.ico">
        <link rel="preload" href="<?=SUB_ROOT?>/node_modules/bootstrap/dist/css/bootstrap.min.css" as="style">
        <link rel="stylesheet" href="<?=SUB_ROOT?>/node_modules/bootstrap/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="<?=SUB_ROOT?>/node_modules/bootstrap-icons/font/bootstrap-icons.css">
        <!-- Libreria Cropper -->
        <link href="<?=SUB_ROOT?>/assets/css/cropper.css" rel="stylesheet" />
        <link href="<?=SUB_ROOT?>/assets/css/spoome.css" rel="stylesheet">

        <?php
       if (isset($obja)) {
    $isEventPage = strpos($_SERVER['REQUEST_URI'], 'evento') !== false;

    if ($isEventPage) {
        // Se è una pagina evento
        addMetaTagsEvent(
            $obja->title ?? '',
            $descriptionEvent ?? '',
            $obja->title ?? '',
            $event->photo ?? '',
            $obja->photo ?? ''
        );
    } else {
        // Se è una pagina atleta
        addMetaTags(
            $obja->title ?? '',
            $obja->id ?? 0,
            $obja->title ?? '',
            $obja->photo ?? ''
        );
    }
}
        ?>
        <meta name="facebook-domain-verification" content="4q0sro965yhqef537jgpn3mgfhfby4" />
        <?php

 ?>

    <!--<script id="cookieyes" type="text/javascript" src="https://cdn-cookieyes.com/client_data/d5c0ba23151e92d5d5acaafb/script.js"></script>-->

    <!--<meta name="google-adsense-account" content="ca-pub-4953366398675210">-->
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-4953366398675210"
     crossorigin="anonymous"></script>
</head>
<body>

<?php
require_once 'layout/_navbar.php';
?>
    <div class="container-fluid px-0" style="margin-top: 90px">
<?php
if(!str_contains($_SERVER['REQUEST_URI'] ?? '', 'uac')){
require_once 'widget/_lastSearchBar.php';
}
if(!str_contains($_SERVER['REQUEST_URI'] ?? '', 'ricercaAvanzata.php') and !str_contains($_SERVER['REQUEST_URI'] ?? '', 'uac')){
?>
<div class="container">
    <form id="search-form" class="row mb-4 " method="get" action="<?= SUB_ROOT ?>/atleta.php">
        <div class="col-12">
            <input class="form-control form-control-lg" type="search" name="a" id="searchInput"
                   placeholder="<?= T_SEARCHBOX?>"
                   autocomplete="off"
                   value="<?=$_GET['cerca'] ?? ''?>">
            <div id="autocomplete-suggestions"></div>
        </div>
    </form>
</div>
<?php
}