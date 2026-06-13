<?php
$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';
$output = [];
if (checkAuthApi()) {
    $action = $_GET['action'];
    if ($action) {
        switch ($action) {
            case 'get':
                if (array_key_exists('q', $_GET)) {
                    $type = $_GET['t'] ?? '';
                    $query = $_GET['q'] ?? '';
                    $a = Athlete::findByTitle($query);
                    if ($a) {
                        switch ($type) {
                            case "news":
                                $output = getNews($a);
                                break;
                            case "video":
                                $output = getVideo($a);
                                break;
                            case "posts":
                                $output = getSocialStream($a);
                                break;
                            default:
                                $output[] = "Nessun risultato trovato";
                                break;
                        }
                    }
                }
                break;
            case 'live':
                $output = getLiveNews();
                break;
            default:
                $output[] = MSG_API_CALL_INVALID;
                break;
        }
    } else {
        $output[] = MSG_API_UNAUTHORIZED;
    }
} else {
    $output[] = MSG_API_CALL_INVALID;
}

header('Content-Type: application/json');
if ($output) {
    if ($output == "<p style='color: var(--gray)'>Nessun post trovato.</p>") {
        echo json_encode([["isEmpty" => true]]);
    } else {
        echo json_encode($output);
    }

} else {
    echo json_encode([["isEmpty" => true]]);
}
exit();