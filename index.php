<?php
$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';
if (isset($_GET['cerca']) && !empty(trim($_GET['cerca']))) {
    $nomeAtleta = trim($_GET['cerca']);
    $athlete = Athlete::findByTitle($nomeAtleta);
    if ($athlete) {
        $slug = strtolower(str_replace(" ", "-", $athlete->title));
        $redirectUrl = SUB_ROOT . "/atleti/{$athlete->getId()}-{$slug}";
        header("Location: $redirectUrl");
        exit;
    }
}
$title = T_HEADLINE;
require_once 'layout/_header.php';
require_once 'widget/_birthdays.php';
require_once 'widget/_evidence.php';
require_once 'widget/adv/_advMain.php';
require_once 'widget/_lastProfiles.php';
require_once 'layout/_footer.php';

