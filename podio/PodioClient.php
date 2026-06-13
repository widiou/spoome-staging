<?php
// podio/PodioClient.php

class PodioClient
{
    protected string $accessToken;
    private string $clientId;
    private string $clientSecret;
    private string $username;
    private string $password;
    private string $apiBase = "https://api.podio.com";

    public function __construct()
    {
        $configPath = __DIR__ . "/credentials.php";
        if (!file_exists($configPath)) {
            throw new Exception("File di configurazione non trovato: $configPath");
        }

        $config = require $configPath;

        $this->clientId = $config["client_id"] ?? '';
        $this->clientSecret = $config["client_secret"] ?? '';
        $this->username = $config["username"] ?? '';
        $this->password = $config["password"] ?? '';

        if (!$this->clientId || !$this->clientSecret || !$this->username || !$this->password) {
            throw new Exception("Credenziali Podio mancanti o incomplete.");
        }

        $this->authenticate();
    }

    private function authenticate(): void
    {
        $response = $this->request("oauth/token", [
            "grant_type" => "password",
            "client_id" => $this->clientId,
            "client_secret" => $this->clientSecret,
            "username" => $this->username,
            "password" => $this->password
        ], "POST");

        if (isset($response["access_token"])) {
            $this->accessToken = $response["access_token"];
        } else {
            throw new Exception("Errore nell'autenticazione Podio: " . json_encode($response));
        }
    }

    public function request(string $endpoint, array $data = [], string $method = "GET")
    {
        $url = $this->apiBase . "/" . $endpoint;
        $isOAuth = ($endpoint === "oauth/token");

        $headers = [
            $isOAuth ? "Content-Type: application/x-www-form-urlencoded" : "Content-Type: application/json"
        ];

        if (!$isOAuth) {
            $headers[] = "Authorization: OAuth2 {$this->accessToken}";
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method
        ];

        if ($method !== "GET") {
            $options[CURLOPT_POSTFIELDS] = $isOAuth ? http_build_query($data) : json_encode($data);
        }

        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new Exception("Errore cURL: " . curl_error($ch));
        }

        curl_close($ch);
        return json_decode($response, true);
    }
}
