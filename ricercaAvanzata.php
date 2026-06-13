<?php
$title = "Ricerca avanzata";
$counter = '';
require_once 'bootstrap.php';
require_once 'layout/_header.php';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = $_POST['title'] ?? '';
    $sport = $_POST['sport'] ?? '';
    $activity = $_POST['activity'] ?? '';
    $nationality = $_POST['nationality'] ?? '';
    $birthplace = $_POST['birthplace'] ?? '';
    $year = $_POST['year'] ?? '';
    $sex = $_POST['sex'] ?? '';
    $results = Athlete::advancedSearch($title, $sport, $activity, $nationality, $birthplace, $year, $sex);
    if (count($results) > 0) {
        $counter = count($results) . ' risultati trovati';
    }
}
?>
    <div class="container">
        <div class="row my-5">
            <?= getTitle('Ricerca avanzata ' . $counter) ?>
            <?php
            if (checkSessionLive()) {
            ?>
            <div class="col-12">
                <form class="row gy-3 form-floating" method="post" action="">
                    <div class="col-12">
                        <div class="form-floating">
                            <input class="form-control" type="text" name="title" id="title" maxlength="30"
                                   value="<?= $_POST['title'] ?? '' ?>">
                            <label for="title">Nome e Cognome</label>
                        </div>
                    </div>
                    <div class="col-6 col-md-6">
                        <div class="form-floating">
                            <input class="form-control" type="text" name="sport" id="sport" maxlength="30"
                                   value="<?= $_POST['sport'] ?? '' ?>">
                            <label for="sport">Sport</label>
                        </div>
                    </div>
                    <div class="col-6 col-md-6">
                        <div class="form-floating">
                            <input class="form-control" type="text" name="activity" id="activity" maxlength="30"
                                   value="<?= $_POST['activity'] ?? '' ?>">
                            <label for="activity">Professione</label>
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="form-floating">
                            <input class="form-control" type="text" name="nationality" id="nationality" maxlength="30"
                                   value="<?= $_POST['nationality'] ?? '' ?>">
                            <label for="nationality">Nazionalità</label>
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="form-floating">
                            <input class="form-control" type="text" name="birthplace" id="birthplace" maxlength="30"
                                   value="<?= $_POST['birthplace'] ?? '' ?>">
                            <label for="birthplace">Luogo di nascita</label>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="form-floating">
                            <input class="form-control" type="text" name="year" id="year" maxlength="4"
                                   value="<?= $_POST['year'] ?? '' ?>">
                            <label for="year">Classe</label>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="form-floating">
                            <input class="form-control" type="text" name="sex" id="sex" maxlength="1"
                                   value="<?= $_POST['sex'] ?? '' ?>">
                            <label for="sex">Sesso</label>
                            <datalist id="sexs">
                                <option value="M">Maschio</option>
                                <option value="F">Femmina</option>
                            </datalist>
                        </div>
                    </div>
                    <div class="col-12 mt-5 text-end">
                        <button type="submit" class="btn btn-spoome btn-slanted">
                            <span class="btn-slanted-content">Cerca</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="row">

            <?php
            if (count($results ?? []) > 0) {
                echo getTitle('Risultati della ricerca');
            } else {
                if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                    echo "<p>Nessun risultato trovato per la tua selezione</p>";
                }
            }
            foreach ($results ?? [] as $ra) {
                require 'widget/_card.php';
            }
            require_once 'widget/adv/_advAdvSearch.php';
            ?>
            <script src="./assets/js/searchAdvanced.js?<?= rand(0, 1000000) ?>"></script>

            <?php
            } else {
                ?>
                <div class="col-12 text-center  uac-container">
                    <H3>Per accedere a questa funzionalità devi registrarti a Spoome. <a class="link-spoome"
                                                                                         href="/network/uac/register.php">Registrati
                            ora!</a></H3>
                </div>
                <?php
            }
            ?>
        </div>
    </div>
<?php
require_once 'layout/_footer.php';
