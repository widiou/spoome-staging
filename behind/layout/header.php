<?php


// Usa questa funzione nelle pagine che richiedono l'autenticazione
?>
<!doctype html>
<html lang="it" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title ?? "Amministrazione" ?></title>
    <link href="../node_modules/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../node_modules/bootstrap-icons/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="d-flex align-items-center py-4 bg-body-tertiary">
<div class="container-fluid mx-1 mx-md-5">