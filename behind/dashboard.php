<?php
$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';
checkLoggedInAdmin();
$search = $_GET['a'] ?? '';
$sport = $_GET['s'] ?? '';
$nation = $_GET['n'] ?? '';
$page = $_GET['page'] ?? 1;
if (strlen($search) >= 4) {
    $athletes = Athlete::search("title", $search);
} elseif ($sport) {
    $athletes = Athlete::search("sport", $sport);
} elseif ($nation) {
    $athletes = Athlete::search("nationality", $nation);
} else {
    if ($page != 0) {
        $athletes = Athlete::getLastTen('', '', '', '', $page);
    } else {
        $athletes = Athlete::getLastTen();
    }

}
$sports = Athlete::getAllSports("ALL");
$nationalities = Athlete::getAllNationality("ALL");
$totAthletes = Athlete::getTotAthletes();
$totPages = $totAthletes / 30;
if (count($athletes) === 1) {
    header('Location: editProfile.php?a=' . $athletes[0]->id);
    exit();
}
require_once 'layout/header.php';
require_once 'layout/navbar.php';
?>
    <div class="row my-5">
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle">
                <thead>
                <tr>
                    <th class="text-center">#</th>
                    <th class="text-center">Foto</th>
                    <th>Titolo</th>
                    <th>Nato</th>
                    <th>Sport</th>
                    <th>Query</th>
                    <th class="text-center">Azioni</th>
                </tr>
                </thead>
                <tbody>
                <?php
                foreach ($athletes as $a) {
                    ?>
                    <tr>
                        <td class="text-center"><?= $a->id ?></td>
                        <td class="text-center">
                            <img class="img-fluid rounded-circle" src="<?= SUB_ROOT ?>/<?= $a->photo ?>"
                                 style="width: 48px; height: 48px; object-fit: cover; object-position: top">
                        </td>
                        <th><a class="text-light text-decoration-none"
                               href="editProfile.php?a=<?= $a->id ?>"><?= $a->title ?></a><br>
                            <a class="text-decoration-none <?= $a->instagram ? 'link-warning' : 'link-secondary' ?>"
                               href="<?= $a->instagram ?? '#' ?>" target="_blank"><i class="bi bi-instagram"></i></a>
                            <a class="text-decoration-none <?= $a->facebook ? 'link-warning' : 'link-secondary' ?>"
                               href="<?= $a->facebook ?? '#' ?>" target="_blank"><i class="bi bi-facebook"></i> </a>
                            <a class="text-decoration-none <?= $a->twitter ? 'link-warning' : 'link-secondary' ?>"
                               href="<?= $a->twitter ?? '#' ?>" target="_blank"><i class="bi bi-twitter-x"></i> </a>
                            <a class="text-decoration-none <?= $a->website ? 'link-warning' : 'link-secondary' ?>"
                               href="<?= $a->website ?? '#' ?>" target="_blank"><i class="bi bi-wordpress"></i> </a>
                        </th>
                        <td>
                            <?= $a->birthdate . " " . $a->birthyear ?><br>
                            <?= $a->birthplace ?></td>
                        <td><?= $a->sport ?><br>
                            <?= $a->activity ?>
                        </td>

                        <td><?= $a->query ?></td>
                        <td class="text-center">
                            <a href="editProfile.php?a=<?= $a->id ?>"> <i class="bi bi-pen-fill text-warning me-2"></i></a>
                            <a href="deleteProfile.php?a=<?= $a->id ?>"><i class="bi bi-trash me-2 text-danger"></i></a>
                        </td>
                    </tr>
                    <?php
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="row mb-5">
        <div class="col-12">
            <nav aria-label="Page navigation example">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?= $page == 1 ? 'disabled' : '' ?>">
                        <a class="page-link"
                           href="dashboard.php?page=<?= ($page - 1) > 0 ? $page - 1 : '' ?>">Previous</a>
                    </li>
                    <li class="page-item">
                        <a class="page-link" href="dashboard.php?page=<?= ($page + 1) <= $totPages ? $page + 1 : '' ?>">Next</a>
                    </li>
                </ul>
            </nav>
        </div>
    </div>
<?php
require_once 'layout/footer.php';
