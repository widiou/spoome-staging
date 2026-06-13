<?php
require_once 'bootstrap.php';
$pdo = Database::getInstance()->getConnection();

// Esecuzione della query per recuperare i dati
$sql = "SELECT 
        nation, 
        gold, 
        silver, 
        bronze, 
        (gold + silver + bronze) AS total 
    FROM 
        medalstable
    ORDER BY 
        gold DESC, 
        silver DESC, 
        bronze DESC
    LIMIT 10";
$stmt = $pdo->prepare($sql);
$stmt->execute();

// Costruzione dell'array di risultati
$results = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $results[] = [
        'nation' => $row['nation'],
        'gold' => (int)$row['gold'],
        'silver' => (int)$row['silver'],
        'bronze' => (int)$row['bronze'],
        'total' => (int)$row['total']
    ];
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spoome - Medagliere Olimpico</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background-color: #1a1c45;
            color: white;
        }

        .slider-container {
            position: relative;
            overflow-x: hidden;
            overflow-y: hidden;
            white-space: nowrap;
            padding: 20px;
            border-radius: 10px;
        }

        .slider-item {
            display: inline-block;
            min-width: 150px;
            margin: 0 10px;
            vertical-align: top;
            text-align: center;
            padding: 10px;
            background-color: #27285a;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .slider-item h5 {
            font-size: 1.2rem;
            margin-bottom: 10px;
        }

        .slider-item p {
            margin: 0;
            font-size: 0.9rem;
        }

        .slider-button {
            border: none;
            cursor: pointer;
            z-index: 100;

            background: none;
        }

        .slider-button.left {
            left: 10px;

        }

        .slider-button.right {
            right: 10px;
        }

        @media (max-width: 600px) {
            .slider-item {
                min-width: 120px;
            }
        }
    </style>
</head>
<body>
<div class="container m-0">
    <div class="slider-container" id="slider-container">
        <?php foreach ($results as $result) { ?>
            <div class="slider-item " style="width: 250px; height: 100px">
                <div class="align-middle">
                    <h6 class="text-truncate"><?= htmlspecialchars($result['nation'], ENT_QUOTES, 'UTF-8') ?></h6>
                    <div class="d-flex justify-content-around">
                        <div>
                            <i style="color:#fac861;" class="bi bi-circle-fill"></i><br><?= $result['gold'] ?>
                        </div>
                        <div>
                            <i style="color:#e5e5e5;" class="bi bi-circle-fill"></i><br><?= $result['silver'] ?>
                        </div>
                        <div>
                            <i style="color:#dcb386;" class="bi bi-circle-fill"></i><br><?= $result['bronze'] ?>
                        </div>
                        <div>
                            <i class="bi bi-circle-fill text-danger"></i><br><?= $result['total'] ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php } ?>

    </div>
    <div class="row">
        <div class="col-12">
            <p class="text-end mt-1 ">Medagliere #parigi2024 | Elaborato da <a
                        href="https://www.spoome.it" class="link-light fw-bold" target="_blank">
                    spoome.it </a></p>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const sliderContainer = document.getElementById('slider-container');
        let isDown = false;
        let startX;
        let scrollLeft;

        // Per il trascinamento con il mouse
        sliderContainer.addEventListener('mousedown', (e) => {
            isDown = true;
            sliderContainer.classList.add('active');
            startX = e.pageX - sliderContainer.offsetLeft;
            scrollLeft = sliderContainer.scrollLeft;
        });

        sliderContainer.addEventListener('mouseleave', () => {
            isDown = false;
            sliderContainer.classList.remove('active');
        });

        sliderContainer.addEventListener('mouseup', () => {
            isDown = false;
            sliderContainer.classList.remove('active');
        });

        sliderContainer.addEventListener('mousemove', (e) => {
            if (!isDown) return;
            e.preventDefault();
            const x = e.pageX - sliderContainer.offsetLeft;
            const walk = (x - startX) * 2; // il moltiplicatore *2 aumenta la velocità di scorrimento
            sliderContainer.scrollLeft = scrollLeft - walk;
        });

        // Per il trascinamento con il tocco (mobile)
        sliderContainer.addEventListener('touchstart', (e) => {
            isDown = true;
            startX = e.touches[0].pageX - sliderContainer.offsetLeft;
            scrollLeft = sliderContainer.scrollLeft;
        });

        sliderContainer.addEventListener('touchmove', (e) => {
            if (!isDown) return;
            e.preventDefault();
            const x = e.touches[0].pageX - sliderContainer.offsetLeft;
            const walk = (x - startX) * 2; // il moltiplicatore *2 aumenta la velocità di scorrimento
            sliderContainer.scrollLeft = scrollLeft - walk;
        });

        sliderContainer.addEventListener('touchend', () => {
            isDown = false;
        });

        // Per lo scorrimento con la rotellina del mouse
        sliderContainer.addEventListener('wheel', (e) => {
            e.preventDefault();
            sliderContainer.scrollLeft += e.deltaY;
        });
    });
</script>


</body>
</html>
