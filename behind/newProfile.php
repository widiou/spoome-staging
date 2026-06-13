<?php
$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';
checkLoggedInAdmin();
$title = "Nuovo atleta";
require_once 'layout/header.php';
if ($_POST) {
    $as = new Athlete();
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
    $as->save();
}
require_once 'layout/navbar.php';

?>
    <div class="row mt-5 align-items-center">
        <div class="col-12">
            <h3>
                Nuovo profilo
            </h3>
        </div>
    </div>
    <hr>
    <form class="row gy-3" method="post" action="">
        <div class="col-12 col-md-9">
            <label for="title" class="form-label">Titolo</label>
            <input type="text" class="form-control" name="title" id="title" value="">
        </div>
        <div class="col-12 col-md-3">
            <label for="expire" class="form-label">Scadenza</label>
            <input type="datetime-local" class="form-control" name="expire" id="expire" value="">
        </div>
        <div class="col-12 col-md-6">
            <label for="name" class="form-label">Nome</label>
            <input type="text" class="form-control" name="name" id="name" value="">
        </div>
        <div class="col-12 col-md-6">
            <label for="surname" class="form-label">Cognome</label>
            <input type="text" class="form-control" name="surname" id="surname" value="">
        </div>
        <div class="col-12 col-md-3">
            <label for="birthplace" class="form-label">Luogo di nascita</label>
            <input type="text" class="form-control" name="birthplace" id="birthplace" value="">
        </div>
        <div class="col-12 col-md-3">
            <label for="nationality" class="form-label">Nazionalità</label>
            <input type="text" class="form-control" name="nationality" id="nationality"
                   value="">
        </div>
        <div class="col-12 col-md-3">
            <label for="birthdate" class="form-label">Data di nascita</label>
            <input type="text" class="form-control" name="birthdate" id="birthdate" value="">
        </div>
        <div class="col-12 col-md-2">
            <label for="birthyear" class="form-label">Anno di nascita</label>
            <input type="text" class="form-control" name="birthyear" id="birthyear" value="">
        </div>
        <div class="col-12 col-md-1">
            <label for="sex" class="form-label">Sesso</label>
            <input type="text" class="form-control" name="sex" id="sex" value="">
        </div>
        <div class="col-12 col-md-6">
            <label for="sport" class="form-label">Sport</label>
            <input type="text" class="form-control" name="sport" id="sport" value="">
        </div>
        <div class="col-12 col-md-6">
            <label for="activity" class="form-label">Attività</label>
            <input type="text" class="form-control" name="activity" id="activity" value="">
        </div>
        <div class="col-12">
            <label for="bio" class="form-label">Bio</label>
            <textarea type="text" class="form-control" name="bio" id="bio" rows="20"></textarea>
        </div>
        <div class="col-12">
            <label for="photo" class="form-label">Path foto</label>
            <input type="text" class="form-control" name="photo" id="photo" value="">
        </div>
        <div class="col-12">
            <label for="instagram" class="form-label">Hashtag</label>
            <input type="text" class="form-control" name="instagram" id="instagram" value="">
        </div>
        <hr>
        <div class="col-12 text-end">
            <button class="btn btn-outline-light">Salva</button>
        </div>
    </form>

<?php
require_once 'layout/footer.php';
