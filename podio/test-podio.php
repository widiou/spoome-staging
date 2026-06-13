<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../podio/PodioClient.php';
require_once __DIR__ . '/../podio/PodioFormatter.php';
require_once __DIR__ . '/../podio/PodioFileUploader.php';

class PodioProfiliTokenClient extends PodioClient
{
    protected function authenticate(): void
    {
        $appId = 30264697;
        $appToken = "86bdb5fb1e67c71bc3a9a728887ff323";

        $response = $this->request("oauth/token", [
            "grant_type" => "app",
            "app_id" => $appId,
            "app_token" => $appToken
        ], "POST");

        if (isset($response["access_token"])) {
            $this->accessToken = $response["access_token"];
        } else {
            throw new Exception("Errore autenticazione con app_token: " . json_encode($response));
        }
    }
}

try {
    $appId = 30264697;

    $client = new PodioProfiliTokenClient();
    $formatter = new PodioFormatter($client);
    $formatter->loadSchema($appId);

    $uploader = new PodioFileUploader($client);

    // Upload immagini da URL
    $fotoProfiloId = $uploader->uploadFromUrl('https://picsum.photos/id/1027/300/300', 'profilo.jpg');
    $immagineCoverId = $uploader->uploadFromUrl('https://picsum.photos/id/1003/1200/600', 'cover.jpg');

    // Input formattabile
    $input = [
        "titolo" => "Luca Verdi",
        "data-di-nascita" => "1988-03-15",
        "sesso" => "Maschio",
        "area-geografica" => "Centro",
        "telefono" => [
            "type" => "mobile",
            "value" => "+393482223344"
        ],
        "foto-profilo" => $fotoProfiloId,
        "immagine-cover" => $immagineCoverId
    ];

    $payload = $formatter->formatFieldsBlock($input);

    echo "<h3>📦 Payload con immagini:</h3><pre>";
    print_r($payload);
    echo "</pre>";

    $created = $client->request("item/app/{$appId}/", $payload, "POST");

    echo "<h3>✅ Item creato:</h3><pre>";
    print_r($created);
    echo "</pre>";

} catch (Exception $e) {
    echo "<h3>❌ Errore:</h3>" . $e->getMessage();
}
