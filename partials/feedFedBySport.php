<?php
require_once 'template/header.php';
$db = Database::getInstance()->getConnection();
const CACHE_TIME = 1800;
$news = getFeedStored($db, 250, $_GET['sport'] ?? '');
?>

<style>
    /* Nasconde la scrollbar ma permette lo scroll */
    html, body {
        background: #0a0a0a;
        font-family: 'Red Hat Display', sans-serif;
        overflow: auto;
        scrollbar-width: none; /* Nasconde la scrollbar in Firefox */
        -ms-overflow-style: none; /* Nasconde la scrollbar in IE 10+ */
    }

    /* Nasconde la scrollbar nei browser basati su WebKit (Chrome, Safari) */
    ::-webkit-scrollbar {
        display: none;
    }

    .container {
        position: relative;
        overflow-x: hidden;
        overflow-y: hidden;
        padding: 20px;
        border-radius: 10px;
    }

    .nws-rss {
        background: var(--black);
        border: none !important;
        border-bottom: 1px solid #3f3f3f !important;
    }

    .nws-source {
        color: #d8f21d;
    }

    .nws-link {
        font-weight: bold;
    }

</style>
<div class="container p-0 m-0">
    <div class="row">
        <div class="col-12">
            <ul id="news-list" class="list-group">
                <?php for ($i = 0; $i < min(50, count($news)); $i++):
                    $source = $news[$i]['source'];
                    $title = $news[$i]['title'] ?? '';
                    ?>
                    <li class="list-group-item nws-rss">
                        <small class="nws-source text-uppercase"><?= $news[$i]['pubDate']->format('d/m H:i') ?> <?= $source ?></small><br>
                        <a class="nws-link link-light text-decoration-none"
                           href="<?= $news[$i]['link'] ?>"
                           target="_blank"><?= $title ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
            <input type="hidden" id="news-offset" value="50">
            <div id="loading-spinner" class="text-center" style="display: none;">
                <div class="spinner-border text-spoome" role="status">
                </div>
            </div>
        </div>
    </div>
</div>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        let offset = parseInt(document.getElementById('news-offset').value);
        let totalNews = <?= count($news) ?>;
        let newsData = <?= json_encode($news) ?>;
        let loading = false;

        // Funzione per caricare nuove notizie
        function loadMoreNews() {
            if (loading) return;
            loading = true;

            let list = document.getElementById('news-list');
            document.getElementById('loading-spinner').style.display = 'block';

            setTimeout(function () {
                // Carica un massimo di 10 nuove notizie
                for (let i = offset; i < Math.min(offset + 10, totalNews); i++) {
                    let li = document.createElement('li');
                    li.className = 'list-group-item nws-rss';
                    li.innerHTML = `<small class="nws-source text-uppercase">${new Date(newsData[i].pubDate.date).toLocaleString('it-IT', {
                        day: '2-digit',
                        month: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit'
                    })} ${newsData[i].source}</small><br>
                <a href="${newsData[i].link}" target="_blank" class="nws-link link-light text-decoration-none text-capitalize">${newsData[i].title}</a>`;
                    list.appendChild(li);
                }

                // Aggiorna l'offset
                offset += 10;
                document.getElementById('news-offset').value = offset;

                // Nascondi lo spinner e imposta loading su false
                document.getElementById('loading-spinner').style.display = 'none';
                loading = false;
            }, 500);
        }

        // Funzione che controlla se l'utente ha scrollato di 500px
        function hasScrolled500px() {
            return window.scrollY > 500;
        }

        // Aggiungi l'evento di scroll per caricare nuove notizie quando l'utente ha scrollato oltre i 500px
        window.addEventListener('scroll', function () {
            if (hasScrolled500px() && offset < totalNews) {
                loadMoreNews();
            }
        });
    });


</script>
<?php
require_once 'template/footer.php';