<?php
session_start();
$title = "Modifica profilo professionista";

chdir(__DIR__ . '/../');
require_once 'bootstrap.php';
require_once 'models/User.php';
require_once 'layout/_header.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_tipo'] !== 'professionista') {
    die("Accesso non autorizzato.");
}

$userId = $_SESSION['user_id'];
$pdo = Database::getInstance()->getConnection();

// Funzione per salvataggio immagini base64
function saveBase64Image(string $base64, string $prefix, int $userId, string $path = '/uploads/profili/'): string {
    $data = explode(',', $base64);
    if (count($data) !== 2) return '';
    $decoded = base64_decode($data[1]);
    $filename = $prefix . $userId . '_' . time() . '.jpg';
    $filepath = $_SERVER['DOCUMENT_ROOT'] . $path . $filename;
    if (!is_dir(dirname($filepath))) {
        mkdir(dirname($filepath), 0755, true);
    }
    if (file_put_contents($filepath, $decoded)) {
        return ltrim($path, '/') . $filename;
    }
    return '';
}

// Recupero dati attuali
$stmt = $pdo->prepare("SELECT * FROM professionisti WHERE user_id = ?");
$stmt->execute([$userId]);
$profilo = $stmt->fetch(PDO::FETCH_ASSOC);

// Se non esiste ancora una riga, la creiamo vuota
if (!$profilo) {
    $stmt = $pdo->prepare("INSERT INTO professionisti (user_id) VALUES (?)");
    $stmt->execute([$userId]);
    $profilo = ['qualifica' => '', 'settore' => '', 'descrizione' => '', 'esperienza' => '', 'certificazioni' => '', 'linkedin' => '', 'sito_web' => '', 'foto_profilo' => '', 'immagine_cover' => ''];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $qualifica = $_POST['qualifica'];
        $settore = $_POST['settore'];
        $bio = $_POST['bio'];
        $esperienza = $_POST['esperienza'];
        $certificazioni = $_POST['certificazioni'];
        $linkedin = $_POST['linkedin'] ?? null;
        $sito_web = $_POST['sito_web'] ?? null;

        $fotoProfilo = isset($_POST['foto_profilo']) && $_POST['foto_profilo'] ? saveBase64Image($_POST['foto_profilo'], 'profilo_', $userId) : $profilo['foto_profilo'];
        $immagineCover = isset($_POST['immagine_cover']) && $_POST['immagine_cover'] ? saveBase64Image($_POST['immagine_cover'], 'cover_', $userId) : $profilo['immagine_cover'];

        $stmt = $pdo->prepare("
            UPDATE professionisti SET 
                qualifica = ?, settore = ?, descrizione = ?, esperienza = ?, certificazioni = ?, linkedin = ?, sito_web = ?, 
                foto_profilo = ?, immagine_cover = ?
            WHERE user_id = ?
        ");
        $stmt->execute([
            $qualifica, $settore, $bio, $esperienza, $certificazioni, $linkedin, $sito_web,
            $fotoProfilo, $immagineCover, $userId
        ]);

        echo "<div class='container my-5'><div class='alert alert-success'>Modifiche salvate con successo!</div>";
        echo "<a href='" . SUB_ROOT . "/profilo/dashboard.php' class='btn btn-spoome mt-3'>Torna alla dashboard</a></div>";
        require_once 'layout/_footer.php';
        exit;

    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>Errore: " . $e->getMessage() . "</div>";
    }
}
?>

<div class="container my-5 uac-container">
    <h3 class="mb-4">Modifica il tuo profilo professionista</h3>
    <form method="post">
        <div class="mb-3"><label>Qualifica</label><input type="text" name="qualifica" class="form-control" value="<?= htmlspecialchars($profilo['qualifica']) ?>" required></div>
        <div class="mb-3"><label>Settore</label><input type="text" name="settore" class="form-control" value="<?= htmlspecialchars($profilo['settore']) ?>" required></div>
        <div class="mb-3"><label>Biografia</label><textarea name="bio" class="form-control" rows="4"><?= htmlspecialchars($profilo['descrizione']) ?></textarea></div>
        <div class="mb-3"><label>Esperienza</label><textarea name="esperienza" class="form-control" rows="3"><?= htmlspecialchars($profilo['esperienza']) ?></textarea></div>
        <div class="mb-3"><label>Certificazioni</label><input type="text" name="certificazioni" class="form-control" value="<?= htmlspecialchars($profilo['certificazioni']) ?>"></div>
        <div class="mb-3"><label>LinkedIn</label><input type="url" name="linkedin" class="form-control" value="<?= htmlspecialchars($profilo['linkedin']) ?>"></div>
        <div class="mb-3"><label>Sito Web</label><input type="url" name="sito_web" class="form-control" value="<?= htmlspecialchars($profilo['sito_web']) ?>"></div>

        <div class="mb-3">
            <label class="form-label">Foto profilo</label><br>
            <?php if ($profilo['foto_profilo']): ?>
                <img src="/<?= $profilo['foto_profilo'] ?>" alt="Foto profilo" class="img-thumbnail mb-2" width="120">
            <?php endif; ?>
            <input type="file" name="foto_profilo_raw" id="foto_profilo_raw" accept="image/*" class="form-control">
            <canvas id="preview_profilo" class="d-none mt-3 border" width="300" height="300"></canvas>
            <input type="hidden" name="foto_profilo" id="foto_profilo">
        </div>

        <div class="mb-3">
            <label class="form-label">Immagine di copertina</label><br>
            <?php if ($profilo['immagine_cover']): ?>
                <img src="/<?= $profilo['immagine_cover'] ?>" alt="Copertina" class="img-fluid mb-2" style="max-height: 150px;">
            <?php endif; ?>
            <input type="file" name="immagine_cover_raw" id="immagine_cover_raw" accept="image/*" class="form-control">
            <canvas id="preview_cover" class="d-none mt-3 border" width="600" height="200"></canvas>
            <input type="hidden" name="immagine_cover" id="immagine_cover">
        </div>

        <button type="submit" class="btn btn-success mt-3">Salva modifiche</button>
    </form>
</div>

<!-- CropperJS -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<script>
    function setupCropper(inputId, canvasId, hiddenInputId, aspectRatio) {
        const input = document.getElementById(inputId);
        const canvas = document.getElementById(canvasId);
        const hiddenInput = document.getElementById(hiddenInputId);
        let cropper;

        input.addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function (event) {
                const image = new Image();
                image.onload = function () {
                    canvas.classList.remove('d-none');
                    canvas.width = image.width;
                    canvas.height = image.height;
                    const ctx = canvas.getContext('2d');
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    ctx.drawImage(image, 0, 0);

                    if (cropper) cropper.destroy();
                    cropper = new Cropper(canvas, {
                        aspectRatio: aspectRatio,
                        viewMode: 1,
                        cropend: function () {
                            const croppedCanvas = cropper.getCroppedCanvas();
                            hiddenInput.value = croppedCanvas.toDataURL('image/jpeg');
                        }
                    });
                };
                image.src = event.target.result;
            };
            reader.readAsDataURL(file);
        });
    }

    setupCropper('foto_profilo_raw', 'preview_profilo', 'foto_profilo', 1);
    setupCropper('immagine_cover_raw', 'preview_cover', 'immagine_cover', 3);
</script>

<?php require_once 'layout/_footer.php'; ?>
