<?php
$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';
//showErrors();
$output = [];
$index = 0;
if (checkAuthApi()) {
    $action = $_GET['action'];
    if ($action) {
        switch ($action) {
            case 'allsport':
                $sports = Athlete::getAllSports('ALL', $_GET['per_page'] ?? 20, $_GET['page'] ?? 1);
                foreach ($sports as $s) {
                    $output[] = [
                        'id' => $index++,
                        'sport' => mb_strtoupper($s['sport'] ?? ''),
                        'total' => $s['athlete_count'],
                    ];
                }
                break;
            case 'allplaces':
                $output = Athlete::getAllPlaces($_GET['per_page'] ?? 20, $_GET['page'] ?? 1, $_GET['q'] ?? '');
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
echo json_encode($output, JSON_PRETTY_PRINT);
exit();