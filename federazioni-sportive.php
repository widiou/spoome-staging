<?php
require_once 'bootstrap.php';
require_once 'layout/_header.php';

$db = Database::getInstance()->getConnection();
const CACHE_TIME = 1800;
$news = getFeeds($db, RSS_SOURCES_FES);

?>

<style>
    .nws-rss {
        background: var(--black);
        border: none !important;
        border-bottom: 1px solid var(--gray) !important;
    }
</style>
<div class="container mb-5">
    <div class="row mb-3">
        <?= getTitle("Notizie dalle federazioni sportive") ?>
    </div>
    <div class="row">
        <div class="col-12">
            <ul id="news-list" class="list-group">
                <?php for ($i = 0; $i < min(10, count($news)); $i++): ?>
                    <li class="list-group-item nws-rss">
                        <small class="text-muted"><?= $news[$i]['pubDate']->format('d/m H:i') ?> <?= $news[$i]['source'] ?></small><br>
                        <a class="link-light text-decoration-none" href="<?= $news[$i]['link'] ?>"
                           target="_blank"><?= $news[$i]['title'] ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
            <!-- Indichiamo il numero di notizie già caricate -->
            <input type="hidden" id="news-offset" value="10">
            <!-- Spinner per indicare che stiamo caricando altre notizie -->
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
                    li.innerHTML = `<small class="text-muted">${new Date(newsData[i].pubDate.date).toLocaleString('it-IT', {
                        day: '2-digit',
                        month: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit'
                    })} ${newsData[i].source}</small><br>
                    <a href="${newsData[i].link}" target="_blank" class="link-light text-decoration-none">${newsData[i].title}</a>`;
                    list.appendChild(li);
                }

                // Aggiorna l'offset
                offset += 10;
                document.getElementById('news-offset').value = offset;

                // Nascondi lo spinner e imposta loading su false
                document.getElementById('loading-spinner').style.display = 'none';
                loading = false;  // Permetti nuovi caricamenti
            }, 500);  // Simulazione di caricamento (500ms)
        }

        // Funzione che controlla se l'utente è vicino al fondo della lista 'news-list'
        function isNearBottomOfList() {
            const list = document.getElementById('news-list');
            const listBottom = list.getBoundingClientRect().bottom;
            const viewportHeight = window.innerHeight;
            return listBottom - viewportHeight < 100;  // Se siamo vicini al fondo di 100px
        }

        // Aggiungi l'evento di scroll per caricare nuove notizie quando siamo vicino alla fine di 'news-list'
        window.addEventListener('scroll', function () {
            if (isNearBottomOfList() && offset < totalNews) {
                loadMoreNews();
            }
        });
    });

</script>

<?php
require_once 'layout/_footer.php';
?>
