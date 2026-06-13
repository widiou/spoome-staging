<?php
// core/PodioSubmit.php

require_once 'PodioFormatter.php';
require_once 'PodioFileUploader.php';

class PodioSubmit {
    private PodioClient $client;
    private PodioFormatter $formatter;
    private PodioFileUploader $uploader;
    private string $logFile;
    private bool $debug;

    public function __construct(PodioClient $client, bool $debug = true) {
        $this->client = $client;
        $this->formatter = new PodioFormatter($client);
        $this->uploader = new PodioFileUploader($client);
        $this->debug = $debug;
        $this->logFile = __DIR__ . '/../logs/podio-debug.log';
    }

    public function handle(array $post, array $files, int $appId): ?array {
        if ($this->debug) $this->log("📥 POST ricevuto");

        $this->formatter->loadSchema($appId);
        if ($this->debug) $this->log("📘 Schema caricato per app_id $appId");

        $input = $post;

        foreach ($files as $field => $fileData) {
            if ($fileData['error'] === UPLOAD_ERR_OK) {
                $filename = $fileData['name'];
                $tmpPath = $fileData['tmp_name'];
                $fileId = $this->uploader->uploadFromPath($tmpPath, $filename);
                $input[$field] = $fileId;

                if ($this->debug) $this->log("📎 Upload file per campo: $field (ID $fileId)");
            }
        }

        $payload = $this->formatter->formatFieldsBlock($input);
        if ($this->debug) $this->log("📦 Payload:\n" . json_encode($payload, JSON_PRETTY_PRINT));

        try {
            if (!empty($post['item_id'])) {
                $itemId = (int)$post['item_id'];
                $response = $this->client->request("item/{$itemId}", $payload, "PUT");
                if ($this->debug) $this->log("✅ Risposta update:\n" . json_encode($response, JSON_PRETTY_PRINT));
                return ['success' => true, 'message' => '✅ Item aggiornato con successo.', 'item_id' => $itemId];
            } else {
                $response = $this->client->request("item/app/{$appId}/", $payload, "POST");
                $itemId = $response['item_id'] ?? null;
                if ($this->debug) $this->log("✅ Risposta creazione:\n" . json_encode($response, JSON_PRETTY_PRINT));
                return ['success' => true, 'message' => '✅ Item creato con successo.', 'item_id' => $itemId];
            }
        } catch (Exception $e) {
            if ($this->debug) $this->log("❌ Errore API: " . $e->getMessage());
            throw $e;
        }
    }

    private function log(string $msg): void {
        file_put_contents($this->logFile, $msg . "\n", FILE_APPEND);
    }
}

// ⬇️ Interfaccia eseguibile
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'PodioClient.php';

    class PodioAutoClient extends PodioClient {
        protected function authenticate(): void {
            $appId = $_POST['app_id'] ?? null;
            $appToken = $_POST['app_token'] ?? null;

            $response = $this->request("oauth/token", [
                "grant_type" => "app",
                "app_id" => $appId,
                "app_token" => $appToken
            ], "POST");

            if (!isset($response["access_token"])) {
                throw new Exception("Autenticazione fallita: " . json_encode($response));
            }

            $this->accessToken = $response["access_token"];
        }
    }

    try {
        if (empty($_POST['app_id']) || empty($_POST['app_token'])) {
            throw new Exception("⚠️ Parametri app_id e app_token richiesti.");
        }

        $appId = (int)$_POST['app_id'];
        $client = new PodioAutoClient();
        $submit = new PodioSubmit($client, true);
        $result = $submit->handle($_POST, $_FILES, $appId);

        header('Content-Type: application/json');
        echo json_encode($result);
    } catch (Exception $e) {
        header('Content-Type: application/json', true, 400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
}
