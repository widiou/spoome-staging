<?php
// views/podio-form.php

require_once __DIR__ . '/../PodioClient.php';
require_once __DIR__ . '/../PodioFormBuilder.php';

// Lettura variabili
if (!isset($appId) || !isset($appToken)) {
    echo "<div class='alert alert-danger'>❌ app_id e app_token mancanti nella URL.</div>";
    return;
}

$itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : null;

if (!$appId || !$appToken) {
    echo "<div class='alert alert-danger'>❌ app_id e app_token mancanti nella URL.</div>";
    return;
}

// ✅ Client dinamico autenticato su app
class PodioFormGenericClient extends PodioClient {
    private int $appId;
    private string $appToken;

    public function __construct(int $appId, string $appToken) {
        $this->appId = $appId;
        $this->appToken = $appToken;
        parent::__construct();
    }

    protected function authenticate(): void {
        $response = $this->request("oauth/token", [
            "grant_type" => "app",
            "app_id" => $this->appId,
            "app_token" => $this->appToken
        ], "POST");

        if (!isset($response["access_token"])) {
            throw new Exception("❌ Errore autenticazione: " . json_encode($response));
        }

        $this->accessToken = $response["access_token"];
    }
}

$client = new PodioFormGenericClient($appId, $appToken);
$formBuilder = new PodioFormBuilder($client);

// ✅ Output del form
echo $formBuilder->render($appId, '/network/podio/PodioSubmit.php', $appToken, $itemId);

// ✅ Contenitore messaggi feedback
echo "<div id='podio-feedback' class='mt-4'></div>";
?>
