<?php
die;
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'layout/_header.php';

// Carica i dati dell'atleta esistente
$athleteId = $_GET['id'] ?? null;
$athlete = null;
if ($athleteId) {
    $connection = Database::getInstance()->getConnection();
    $query = "SELECT * FROM athletes WHERE id = :id";
    $stmt = $connection->prepare($query);
    $stmt->execute(['id' => $athleteId]);
    $athlete = $stmt->fetch();
}

// Salva le modifiche al profilo atleta
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [
        'title' => $_POST['title'],
        'photo' => $_POST['photo'],
        'name' => $_POST['name'],
        'surname' => $_POST['surname'],
        'birthplace' => $_POST['birthplace'],
        'birthdate' => $_POST['birthdate'],
        'birthyear' => $_POST['birthyear'],
        'activity' => $_POST['activity'],
        'nationality' => $_POST['nationality'],
        'bio' => $_POST['bio'],
        'expire' => $_POST['expire'],
        'sport' => $_POST['sport'],
        'sex' => $_POST['sex'],
        'instagram' => $_POST['instagram'],
    ];

    if ($athleteId) {
        $fields['id'] = $athleteId;
        $query = "UPDATE athletes SET title = :title, photo = :photo, name = :name, surname = :surname, birthplace = :birthplace, birthdate = :birthdate, birthyear = :birthyear, activity = :activity, nationality = :nationality, bio = :bio, expire = :expire, sport = :sport, sex = :sex, instagram = :instagram WHERE id = :id";
    } else {
        $query = "INSERT INTO athletes (title, photo, name, surname, birthplace, birthdate, birthyear, activity, nationality, bio, expire, sport, sex, instagram) VALUES (:title, :photo, :name, :surname, :birthplace, :birthdate, :birthyear, :activity, :nationality, :bio, :expire, :sport, :sex, :instagram)";
    }

    $stmt = $connection->prepare($query);
    $stmt->execute($fields);

    header('Location: athletes_list.php'); // Reindirizza alla lista degli atleti dopo il salvataggio
    exit;
}

?>
    <div class="container mt-5">
        <h1><?= $athleteId ? 'Modifica' : 'Aggiungi' ?> Atleta</h1>
        <form method="POST" action="">
            <div class="mb-3">
                <label for="title" class="form-label">Titolo</label>
                <input type="text" class="form-control" id="title" name="title"
                       value="<?= htmlspecialchars($athlete['title'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label for="photo" class="form-label">Foto URL</label>
                <input type="text" class="form-control" id="photo" name="photo"
                       value="<?= htmlspecialchars($athlete['photo'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label for="name" class="form-label">Nome</label>
                <input type="text" class="form-control" id="name" name="name"
                       value="<?= htmlspecialchars($athlete['name'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label for="surname" class="form-label">Cognome</label>
                <input type="text" class="form-control" id="surname" name="surname"
                       value="<?= htmlspecialchars($athlete['surname'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label for="birthplace" class="form-label">Luogo di Nascita</label>
                <input type="text" class="form-control" id="birthplace" name="birthplace"
                       value="<?= htmlspecialchars($athlete['birthplace'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label for="birthdate" class="form-label">Data di Nascita</label>
                <input type="date" class="form-control" id="birthdate" name="birthdate"
                       value="<?= htmlspecialchars($athlete['birthdate'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label for="birthyear" class="form-label">Anno di Nascita</label>
                <input type="text" class="form-control" id="birthyear" name="birthyear"
                       value="<?= htmlspecialchars($athlete['birthyear'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label for="activity" class="form-label">Attività</label>
                <input type="text" class="form-control" id="activity" name="activity"
                       value="<?= htmlspecialchars($athlete['activity'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label for="nationality" class="form-label">Nazionalità</label>
                <input type="text" class="form-control" id="nationality" name="nationality"
                       value="<?= htmlspecialchars($athlete['nationality'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label for="bio" class="form-label">Biografia</label>
                <textarea class="form-control" id="bio" name="bio"
                          rows="5"><?= htmlspecialchars($athlete['bio'] ?? '') ?></textarea>
            </div>
            <div class="mb-3">
                <label for="expire" class="form-label">Data di Scadenza</label>
                <input type="datetime-local" class="form-control" id="expire" name="expire"
                       value="<?= htmlspecialchars($athlete['expire'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label for="sport" class="form-label">Sport</label>
                <input type="text" class="form-control" id="sport" name="sport"
                       value="<?= htmlspecialchars($athlete['sport'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label for="sex" class="form-label">Sesso</label>
                <select class="form-select" id="sex" name="sex">
                    <option value="M" <?= isset($athlete['sex']) && $athlete['sex'] === 'M' ? 'selected' : '' ?>>
                        Maschio
                    </option>
                    <option value="F" <?= isset($athlete['sex']) && $athlete['sex'] === 'F' ? 'selected' : '' ?>>
                        Femmina
                    </option>
                </select>
            </div>
            <div class="mb-3">
                <label for="instagram" class="form-label">Instagram</label>
                <input type="text" class="form-control" id="instagram" name="instagram"
                       value="<?= htmlspecialchars($athlete['instagram'] ?? '') ?>">
            </div>
            <button type="submit"
                    class="btn btn-primary"><?= $athleteId ? 'Salva Modifiche' : 'Aggiungi Atleta' ?></button>
        </form>
    </div>

<?php
require_once 'layout/_footer.php';
