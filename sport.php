<?php
require_once 'bootstrap.php';
require_once 'models/Athlete.php';

if (array_key_exists('sport', $_GET)) {
    $sport = filter_var($_GET['sport']);
    $sport = str_replace("-", " ", $sport);
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $title = htmlspecialchars($sport);
    $news_filter = htmlspecialchars($sport);
    $totAthletes = Athlete::getTotAthletes('sport', $sport);
    $totPages = ceil($totAthletes / 30);
    $athletes = Athlete::getLastTen($sport, '', '', '', $page);

    require_once 'layout/_header.php';
    ?>

    <div class="container mt-5">
        <div class="row mb-3">
            <?= getTitle(ucfirst($sport)) ?>
        </div>
        <div class="row mb-5">
            <?php
            foreach ($athletes as $ra) {
                require 'widget/_card.php';
            }
            ?>
        </div>

        <?php if ($totPages > 1): ?>
            <div class="row mb-5">
                <div class="col-12">
                    <nav aria-label="Page navigation example">
                        <ul class="pagination justify-content-center">
                            <!-- Link per pagina precedente -->
                            <li class="page-item <?= $page == 1 ? 'disabled' : '' ?>">
                                <a class="page-link link-spoome"
                                   href="<?= $page > 1 ? '/network/sport/' . urlencode($sport) . '?page=' . ($page - 1) : '#' ?>">Indietro</a>
                            </li>

                            <!-- Link per pagina successiva -->
                            <li class="page-item <?= $page >= $totPages ? 'disabled' : '' ?>">
                                <a class="page-link link-spoome"
                                   href="<?= $page < $totPages ? '/network/sport/' . urlencode($sport) . '?page=' . ($page + 1) : '#' ?>">Avanti</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php
    require_once 'widget/_newsFeedFiltered.php';
    require_once 'widget/adv/_advMain.php';
    require_once 'layout/_footer.php';

} else {
    http_response_code(404);
    echo "<h1>Sport non trovato</h1>";
}
