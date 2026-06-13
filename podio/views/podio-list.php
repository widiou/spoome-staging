<?php
if (!isset($appId) || !isset($appToken)) {
    echo "<div class='alert alert-danger'>❌ Variabili <code>\$appId</code> e <code>\$appToken</code> non definite.</div>";
    return;
}

require_once __DIR__ . '/../PodioClient.php';

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;
$search = $_GET['search'] ?? '';
$offset = ($page - 1) * $limit;

// Campi visibili in tabella (external_id). Override dall'esterno se necessario.
$visibleFields = $visibleFields ?? ['titolo'];

class PodioGenericClient extends PodioClient {
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

// Funzione per estrarre valore dal campo Podio
function getFieldValue(array $item, string $externalId): string {
    if (!isset($item['fields'])) return '';

    foreach ($item['fields'] as $field) {
        if ($field['external_id'] === $externalId && !empty($field['values'])) {
            $type = $field['type'];
            $value = $field['values'][0];

            switch ($type) {
                case 'text':
                    return $value['value'];
                case 'date':
                    return $value['start'] ?? '';
                case 'category':
                    return $value['value']['text'] ?? '';
                case 'phone':
                    return $value['value'] ?? '';
                case 'image':
                    return isset($value['thumbnail_link']) ? "<img src='{$value['thumbnail_link']}' alt='' height='50'>" : '';
                default:
                    return is_string($value) ? $value : json_encode($value);
            }
        }
    }

    return '';
}

try {
    $client = new PodioGenericClient($appId, $appToken);

    $filterData = [
        "limit" => $limit,
        "offset" => $offset
    ];

    if ($search) {
        $filterData['filters'] = [
            'title' => ['value' => $search]
        ];
    }

    $items = $client->request("item/app/{$appId}/filter", $filterData, "POST");

    // 🔎 Form ricerca + limiti
    echo "<form method='GET' class='row mb-4'>";
    echo "<input type='hidden' name='app_id' value='$appId'>";
    echo "<input type='hidden' name='app_token' value='$appToken'>";

    echo "<div class='col-md-6'>";
    echo "<input type='text' name='search' value='" . htmlspecialchars($search) . "' class='form-control' placeholder='🔍 Cerca nel titolo...'>";
    echo "</div>";

    echo "<div class='col-md-2'>";
    echo "<select name='limit' class='form-select' onchange='this.form.submit()'>";
    foreach ([5, 10, 25, 50] as $opt) {
        $sel = $limit == $opt ? 'selected' : '';
        echo "<option value='$opt' $sel>$opt per pagina</option>";
    }
    echo "</select>";
    echo "</div>";

    echo "<div class='col-md-2'>";
    echo "<button type='submit' class='btn btn-primary w-100'>Applica</button>";
    echo "</div>";
    echo "</form>";

    // 📋 Tabella dinamica
    if (!empty($items['items'])) {
        echo "<div class='table-responsive'>";
        echo "<table class='table table-striped align-middle'>";
        echo "<thead><tr>
        <th>Titolo</th>
        <th>Item ID</th>
        <th>Azioni</th>
      </tr></thead><tbody>";

        foreach ($items['items'] as $item) {
            $title = $item['title'] ?? '(senza titolo)';
            $itemId = $item['item_id'];

            $editUrl = "test-app.php?app_id={$appId}&app_token={$appToken}&item_id={$itemId}";

            echo "<tr>";
            echo "<td>" . htmlspecialchars($title) . "</td>";
            echo "<td>$itemId</td>";
            echo "<td>
            <a href='$editUrl' class='btn btn-sm btn-warning'>✏️ Modifica</a>
          </td>";
            echo "</tr>";
        }

        echo "</tbody></table>";
        echo "</div>";


        // Paginazione
        $baseUrl = strtok($_SERVER["REQUEST_URI"], '?');
        $query = http_build_query([
            'app_id' => $appId,
            'app_token' => $appToken,
            'limit' => $limit,
            'search' => $search
        ]);
        $prevUrl = "$baseUrl?$query&page=" . max(1, $page - 1);
        $nextUrl = "$baseUrl?$query&page=" . ($page + 1);

        echo "<div class='mt-4'>";
        echo "<a href='$prevUrl' class='btn btn-outline-secondary me-2'>&laquo; Prev</a>";
        echo "<a href='$nextUrl' class='btn btn-outline-primary'>Next &raquo;</a>";
        echo "</div>";
    } else {
        echo "<div class='alert alert-warning'>Nessun item trovato.</div>";
    }
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>❌ Errore: " . $e->getMessage() . "</div>";
}
?>
