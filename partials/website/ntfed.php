<?php
require_once 'template/header.php';
$db = Database::getInstance()->getConnection();
const CACHE_TIME = 1800;
$news = getFeedStored($db);

?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Red+Hat+Display:ital,wght@0,300..900;1,300..900&display=swap');

        body {
            background: #101218;
            font-family: 'Red Hat Display', sans-serif;
            overflow: hidden;
        }

        .news-ticker {
            background-color: #101218;
            overflow: hidden;
            white-space: nowrap;
            width: 100%;
            height: 47px;
            margin: 0;
            padding: 0;
            box-shadow: 0 4px 2px -2px gray;
        }

        .ticker-wrapper {
            display: inline-block;
            animation: scroll-left 15s linear infinite;
            animation-play-state: running;
        }

        @keyframes scroll-left {
            0% {
                transform: translateX(0); /* Partenza dall'inizio */
            }
            100% {
                transform: translateX(-100%); /* Esce dallo schermo a sinistra */
            }
        }

        .ticker-text {
            display: inline-block;
            font-size: 0.90rem;
        }

        .ticker-text a {
            text-decoration: none;
            color: #f2f2f2;
        }

        .news-source {
            color: #d8f21d;
            font-weight: bold;
            text-transform: uppercase;
        }

        .news-date {
            color: #d8f21d;
        }

        .dot {
            margin-left: 15px;
            margin-right: 15px;
            color: #d8f21d;
            font-size: 1rem;
        }

        .news-title {
            font-weight: 600;
        }
    </style>
    <div class="news-ticker">
        <div class="ticker-wrapper">
            <p class="ticker-text pt-1">
                <?php for ($i = 0; $i < count($news); $i++):
                    $source = $news[$i]['source'];
                    $title = $news[$i]['title'] ?? '';
                    ?>
                    <a href="https://spoome.it/diretta-sport/" target="_blank">
                        <span class="news-source"><?= $source ?></span>
                        <span class="news-date"><?= $news[$i]['pubDate']->format('d/m H:i') ?>: </span>
                        <span class="news-title"><?= strtoupper($title) ?></span>
                        <span class="dot">>>><span>
                    </a>
                <?php endfor; ?>
            </p>
        </div>
    </div>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const tickerWrapper = document.querySelector('.ticker-wrapper');
            const tickerText = document.querySelector('.ticker-text');
            const textWidth = tickerText.offsetWidth;
            const containerWidth = document.querySelector('.news-ticker').offsetWidth;
            // Imposta la durata dell'animazione in base alla lunghezza del testo
            const animationDuration = textWidth / containerWidth * 20; // Regola la velocità in modo dinamico
            tickerWrapper.style.animationDuration = `${animationDuration}s`;
        });
    </script>
<?php
require_once 'template/footer.php';