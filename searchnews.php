<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ✅ Imposta la tua API Key
$apitube_api_key = "api_live_WhqLb1C86T52fgsdSdrARuCc1mN3JrdeCS4L2QJZDgqUz";
$query = "Cristina Chiuso";
$published_start = date('Y-m-d', strtotime('-2 years'));

// ✅ Endpoint corretto
$endpoint = 'https://api.apitube.io/v1/news/everything';

// ✅ Parametri
$params = [
    'title' => $query,  // usa 'q' se 'person.name' fallisce
    'category.id' => "medtop:15000000",
    'language.code' => 'it',
    'published_at.start' => $published_start,
    'sort.by' => 'published_at',
    'sort.order' => 'desc',
];

// ✅ Query string RFC3986
$url = $endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

// ✅ CURL setup
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_HTTPHEADER => [
        "X-API-KEY: $apitube_api_key",
        "Accept: application/json"
    ],
]);

$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

if ($err) {
    die("<div class='alert alert-danger'>Errore API: $err</div>");
}

// ✅ Decodifica la risposta
$data = json_decode($response, true);
$results = $data['results'] ?? [];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Notizie su <?= htmlspecialchars($query) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light py-4">
<div class="container">
    <h1 class="mb-4">Ultime notizie su <strong><?= htmlspecialchars($query) ?></strong></h1>

    <?php if (empty($results)): ?>
        <div class="alert alert-warning">
            Nessun risultato trovato o formato risposta non valido.
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($results as $article): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 shadow-sm">
                        <?php if (!empty($article['image'])): ?>
                            <img src="<?= htmlspecialchars($article['image']) ?>" class="card-img-top" alt="immagine">
                        <?php endif; ?>
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title"><?= htmlspecialchars($article['title']) ?></h5>
                            <p class="card-text text-muted small">
                                Pubblicato il <?= date('d/m/Y H:i', strtotime($article['published_at'])) ?><br>
                                Autore: <?= htmlspecialchars($article['author']['name'] ?? '-') ?>
                            </p>
                            <a href="<?= htmlspecialchars($article['href']) ?>" target="_blank" class="btn btn-primary mt-auto">Leggi l'articolo</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
