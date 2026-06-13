<?php
$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';
checkLoggedInAdmin();
if ($_POST) {
    $as = new Athlete();
    $as->setId($_POST['id']);
    $as->title = $_POST['title'];
    $as->photo = $_POST['photo'];
    $as->name = $_POST['name'];
    $as->surname = $_POST['surname'];
    $as->birthplace = $_POST['birthplace'];
    $as->birthdate = $_POST['birthdate'];
    $as->birthyear = $_POST['birthyear'];
    $as->activity = $_POST['activity'];
    $as->nationality = $_POST['nationality'];
    $as->bio = $_POST['bio'];
    $as->sport = $_POST['sport'];
    $as->sex = $_POST['sex'];
    $as->instagram = $_POST['instagram'];
    $as->facebook = $_POST['facebook'];
    $as->twitter = $_POST['twitter'];
    $as->linkedin = $_POST['linkedin'];
    $as->website = $_POST['website'];
    $as->linkedin = $_POST['linkedin'];
    $as->query = $_POST['query'];
    $as->save();
    $_POST = [];
}
$search = $_GET['a'] ?? 'Matteo';
$a = Athlete::findById($search);
$title = "Modifica " . $a->title;
require_once 'layout/header.php';
require_once 'layout/navbar.php';
if ($a) {
    ?>
    <div class="row mt-3 align-items-center">
        <div class="col-12">
            <div class="d-flex">
                <img class="rounded-circle me-5"
                     style="height: 80px; width: 80px; object-fit: cover; object-position: top;"
                     src="<?= SUB_ROOT ?>/<?= $a->photo ?>">
                <h3>
                    <?= $a->getId() ?><br>
                    <a class="link-warning text-decoration-none"
                       href="<?= getLinkAtleta($a->getId(), $a->Title) ?></a>
                </h3>
                <div class=" ms-auto">
                    <a class="ms-2" href="https://www.google.it/search?q=social+<?= $a->title ?>" target="_blank"><i
                                class="bi bi-search"></i></a>
                    <a class="ms-2" href="https://www.facebook.com/pages/?q=<?= $a->title ?>" target="_blank"><i
                                class="bi bi-facebook"></i></a>
                    <a class="ms-2" href="https://x.com/search?q=<?= $a->title ?>&src=typed_query&f=user"
                       target="_blank"><i class="bi bi-twitter-x"></i></a>
                    <a class="ms-2"
                       href="https://www.linkedin.com/results/people/?keywords=<?= $a->title ?>&origin=SWITCH_SEARCH_VERTICAL&sid=JB*"
                       target="_blank"><i class="bi bi-linkedin"></i></a>
            </div>
        </div>
    </div>
    </div>
    <hr>
    <form class="row gy-2 form-floating" method="post" action="">
        <div class="col-12 text-end">
            <button class="btn btn-outline-light">Salva</button>
        </div>
        <input type="hidden" name="id" value="<?= $a->id ?>">
        <div class="col-12 col-md-9">
            <div class="form-floating">
                <input type="text" class="form-control" name="title" id="title" value="<?= $a->title ?>">
                <label for="title">Titolo</label>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="form-floating">
                <input type="datetime-local" class="form-control" name="expire" id="expire" value="<?= $a->expire ?>">
                <label for="expire">Scadenza</label>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="form-floating">
                <input type="text" class="form-control" name="name" id="name" value="<?= $a->name ?>">
                <label for="name">Nome</label>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="form-floating">
                <input type="text" class="form-control" name="surname" id="surname" value="<?= $a->surname ?>">
                <label for="surname">Cognome</label>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="form-floating">
                <input type="text" class="form-control" name="birthplace" id="birthplace" value="<?= $a->birthplace ?>">
                <label for="birthplace">Luogo di nascita</label>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="form-floating">
                <input type="text" class="form-control" name="nationality" id="nationality"
                       value="<?= $a->nationality ?>">
                <label for="nationality">Nazionalità</label>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="form-floating">
                <input type="text" class="form-control" name="birthdate" id="birthdate" value="<?= $a->birthdate ?>">
                <label for="birthdate">Data di nascita</label>
            </div>
        </div>
        <div class="col-12 col-md-2">
            <div class="form-floating">
                <input type="text" class="form-control" name="birthyear" id="birthyear" value="<?= $a->birthyear ?>">
                <label for="birthyear">Anno di nascita</label>
            </div>
        </div>
        <div class="col-12 col-md-1">
            <div class="form-floating">
                <input type="text" class="form-control" name="sex" id="sex" value="<?= $a->sex ?>">
                <label for="sex">Sesso</label>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="form-floating">
                <input type="text" class="form-control" name="sport" id="sport" value="<?= $a->sport ?>">
                <label for="sport">Sport</label>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="form-floating">
                <input type="text" class="form-control" name="activity" id="activity" value="<?= $a->activity ?>">
                <label for="activity">Attività</label>
            </div>
        </div>


        <div class="col-12 col-md-4">
            <div class="form-floating">
                <input type="text" class="form-control" name="instagram" id="instagram" value="<?= $a->instagram ?>">
                <label for="instagram">Instagram</label>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="form-floating">
                <input type="text" class="form-control" name="facebook" id="facebook" value="<?= $a->facebook ?>">
                <label for="facebook">Facebook</label>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="form-floating">
                <input type="text" class="form-control" name="twitter" id="twitter" value="<?= $a->twitter ?>">
                <label for="twitter">Twitter</label>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="form-floating">
                <input type="text" class="form-control" name="linkedin" id="linkedin" value="<?= $a->linkedin ?>">
                <label for="linkedin">LinkedIN</label>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="form-floating">
                <input type="text" class="form-control" name="website" id="website" value="<?= $a->website ?>">
                <label for="website">Website</label>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="form-floating">
                <input type="text" class="form-control" name="query" id="query" value="<?= $a->query ?>">
                <label for="query">Query di ricerca</label>
            </div>
        </div>
        <div class="col-12">
            <div class="form-floating">
                <input type="text" class="form-control" name="photo" id="photo" value="<?= $a->photo ?>">
                <label for="photo">Path foto</label>
            </div>
        </div>
        <div class="col-12">
            <label for="bio" style="display: none">Bio</label>
            <textarea type="text" class="form-control" name="bio" id="bio" rows="15"><?= $a->bio ?></textarea>
        </div>
        <hr>
        <div class="col-12 text-end">
            <button class="btn btn-outline-light">Salva</button>
        </div>
    </form>
    <?php
} else {
    echo "nessun atleta trovato";
}
require_once 'layout/footer.php';
