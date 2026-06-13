<?php
session_start();
$title = "Completa il Profilo";
chdir(__DIR__ . '/../');
require_once 'bootstrap.php';

require_once 'models/UserSessionUtils.php';
require_once 'models/ProfiloUtils.php';
require_once 'models/ImageUploader.php';

UserSessionUtils::checkAuthenticated();
$userId = $_SESSION['user_id'];
$tipo = $_SESSION['user_tipo'];

$pdo = Database::getInstance()->getConnection();
$profilo = ProfiloUtils::getOrCreateProfilo($pdo, $tipo, $userId);

// Recupera dati già salvati da profili_base
$stmt = $pdo->prepare("SELECT * FROM profili_base WHERE user_id = ?");
$stmt->execute([$userId]);
$datiBase = $stmt->fetch(PDO::FETCH_ASSOC);

// Recupera lista sport
$sports = $pdo->query("SELECT id, nome FROM sports ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

$success = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $sesso = $_POST['sesso'] ?? '';
        $data_nascita = $_POST['data_nascita'] ?? '';
        $telefono = $_POST['telefono'] ?? '';
        $sport_id = $_POST['sport_id'] ?? null;
        $area_geografica = $_POST['area_geografica'] ?? '';
        $fotoProfilo = $_POST['foto_profilo'] ?? '';
        $immagineCover = $_POST['immagine_cover'] ?? '';

        $pathFoto = $fotoProfilo ? ImageUploader::saveAndOptimizeImage($fotoProfilo, 'profilo_', $userId, 800, 800, 1) : ($datiBase['foto_profilo'] ?? null);
        $pathCover = $immagineCover ? ImageUploader::saveAndOptimizeImage($immagineCover, 'cover_', $userId, 1920, 1080, 16/9) : ($datiBase['immagine_cover'] ?? null);

        ProfiloUtils::updateProfiloBaseCompleto($pdo, $userId, [
            'sesso' => $sesso,
            'data_nascita' => $data_nascita,
            'telefono' => $telefono,
            'sport_id' => $sport_id,
            'area_geografica' => $area_geografica,
            'foto_profilo' => $pathFoto,
            'immagine_cover' => $pathCover
        ]);

        header("Location: /network/profilo/dashboard.php");
        exit;
    } catch (Exception $e) {
        $error = "Errore durante il salvataggio: " . $e->getMessage();
    }
}
require_once 'layout/_header.php';
?>

<div class="container my-5 uac-container">
    <h2>Completa il tuo Profilo</h2>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">

        <div class="mb-3">
            <label>Foto profilo</label>
            <?php if (!empty($datiBase['foto_profilo'])): ?>
                <div class="mb-2"><img src="/<?= $datiBase['foto_profilo'] ?>" width="120" class="rounded-circle" alt="Preview"></div>
            <?php endif; ?>
            <input type="hidden" name="foto_profilo" id="foto_profilo">
            <input type="file" accept="image/*" onchange="previewBase64(this, 'foto_profilo')">
        </div>

        <div class="mb-3">
            <label>Immagine cover</label>
            <?php if (!empty($datiBase['immagine_cover'])): ?>
                <div class="mb-2"><img src="/<?= $datiBase['immagine_cover'] ?>" class="img-fluid" alt="Cover preview" style="max-height: 200px;"></div>
            <?php endif; ?>
            <input type="hidden" name="immagine_cover" id="immagine_cover">
            <input type="file" accept="image/*" onchange="previewBase64(this, 'immagine_cover')">
        </div>

        <div class="mb-3">
            <label>Sesso *</label>
            <select name="sesso" class="form-control" required>
                <option value="">Seleziona</option>
                <option value="maschio" <?= $datiBase['sesso'] === 'maschio' ? 'selected' : '' ?>>Maschio</option>
                <option value="femmina" <?= $datiBase['sesso'] === 'femmina' ? 'selected' : '' ?>>Femmina</option>
                <option value="altro" <?= $datiBase['sesso'] === 'altro' ? 'selected' : '' ?>>Altro</option>
            </select>
        </div>

        <div class="mb-3">
            <label>Data di nascita *</label>
            <input type="date" name="data_nascita" class="form-control" required value="<?= htmlspecialchars($datiBase['data_nascita'] ?? '') ?>">
        </div>

        <div class="mb-3">
            <label>Telefono *</label>
            <input type="text" name="telefono" class="form-control" required value="<?= htmlspecialchars($datiBase['telefono'] ?? '') ?>">
        </div>

        <div class="mb-3">
            <label>Sport Principale *</label>
            <select name="sport_id" class="form-control" required>
                <option value="">Seleziona sport...</option>
                <?php foreach ($sports as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $datiBase['sport_id'] == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-3">
            <label>Area Geografica *</label>
            <select name="area_geografica" class="form-control" required>
                <option value="">Seleziona area...</option>
                <?php
                foreach (['nord', 'centro', 'sud', 'isole'] as $area) {
                    $selected = ($datiBase['area_geografica'] === $area) ? 'selected' : '';
                    echo "<option value=\"$area\" $selected>" . ucfirst($area) . "</option>";
                }
                ?>
            </select>
        </div>



        <button type="submit" class="btn btn-spoome">Salva e Continua</button>
    </form>
</div>

<script>
    function previewBase64(input, targetId) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById(targetId).value = e.target.result;
        };
        if (input.files[0]) reader.readAsDataURL(input.files[0]);
    }
</script>

<?php require_once 'layout/_footer.php'; ?>
