<?php
// /podio/network/search-podio-items.php
header('Content-Type: application/json');

if (empty($_GET['app_id']) || empty($_GET['app_token']) || empty($_GET['q'])) {
    echo json_encode(["error" => "app_id, app_token e q sono obbligatori"]);
    exit;
}

$appId = (int)$_GET['app_id'];
$appToken = $_GET['app_token'];
$query = $_GET['q'];

require_once __DIR__ . '/../PodioClient.php';

class PodioSearchClient extends PodioClient {
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
            throw new Exception("Autenticazione fallita: " . json_encode($response));
        }

        $this->accessToken = $response["access_token"];
    }
}

try {
    $client = new PodioSearchClient($appId, $appToken);

    $response = $client->request("search/app/{$appId}/", [
        'query' => $query
    ], "POST");

    $results = is_array($response) ? $response : [];

    $suggestions = [];
    foreach ($results as $item) {
        $suggestions[] = [
            'label' => $item['title'] ?? '(senza titolo)',
            'id' => $item['item']['item_id'] ?? null
        ];
    }

    echo json_encode($suggestions);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
