<?php
$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';
$output = [];
if (checkAuthApi()) {
    $action = $_GET['action'];
    if ($action) {
        switch ($action) {
            case 'get':
                $ea = Athlete::findById($_GET['id']);
                if ($ea) {
                    $output = [
                        'id' => $ea->id,
                        'title' => $ea->title ?? '',
                        'birthplace' => $ea->birthplace ?? '',
                        'birthdate' => $ea->birthdate ?? '',
                        'birthyear' => $ea->birthyear ?? '',
                        "photo" => $ea->photo ?? '',
                        "sport" => ucfirst($ea->sport ?? ''),
                        "activity" => ucfirst($ea->activity ?? ''),
                        "nationality" => $ea->nationality ?? '',
                        "bio" => $ea->bio ?? '',
                        "shortbio" => extractShortBio($ea->bio ?? ''),
                        "facebook" => $ea->facebook ?? '',
                        "instagram" => $ea->instagram ?? '',
                    ];
                }
                break;
            case 'random':
                $result = Athlete::getLastTen();
                foreach ($result as $row) {
                    $output[] = [
                        'id' => $row->id,
                        'title' => $row->title ?? '',
                        'photo' => $row->photo,
                    ];
                }
                break;
            case 'birthdays':
                $today = getTodayDate();
                $result = Athlete::getLastTen('', '', $today, '');
                foreach ($result as $row) {
                    $output[] = [
                        'id' => $row->id,
                        'title' => $row->title,
                        'photo' => $row->photo,
                    ];
                }
                break;
            case 'evidence':
                $ea = Athlete::getRandom();
                if ($ea) {
                    $output = [
                        'id' => $ea->getId(),
                        'title' => $ea->title ?? '',
                        'birthplace' => $ea->birthplace ?? '',
                        'birthdate' => $ea->birthdate ?? '',
                        'birthyear' => $ea->birthyear ?? '',
                        "photo" => $ea->photo ?? '',
                        "sport" => $ea->sport ? $ea->sport . ' | ' : '',
                        "activity" => $ea->activity ?? '',
                        "nationality" => $ea->nationality ?? '',
                        "bio" => str_replace("<br>", "", extractShortBio($ea->bio ?? '')),
                    ];
                }
                break;
            case 'latest':
                $result = Athlete::getLast24();
                foreach ($result as $row) {
                    $output[] = [
                        'id' => $row->id,
                        'title' => $row->title,
                        'photo' => $row->photo,
                    ];
                }
                break;
            case 'search':
                $query = $_GET['q'] ?? '';
                $output = Athlete::fetchAthleteFromDatabase($query, $_GET['per_page'] ?? 20, $_GET['page'] ?? 1);
                break;
            case 'searchByProperty':
                $query = $_GET['q'] ?? '';
                $property = $_GET['p'] ?? '';
                if ($property == 'birthdate') {
                    $query = formattaData($query);
                }
                $output = Athlete::simpleSearchByAttribute($property, $query, $_GET['per_page'] ?? 20, $_GET['page'] ?? 1);
                break;
            case 'searchFromTitle':
                $result = Athlete::findByTitle($_GET['q']);
                $output = [
                    "id" => $result->getId(),
                ];
                break;
            case 'searchByDate':

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
echo json_encode($output);
exit();