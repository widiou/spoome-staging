<div class="container-fluid py-5 my-5 mx-0">
    <div class="container">
        <div class="row">
            <div class="col-12 text-center">
                <a role="button" href="https://spoome.it/notizie-in-diretta-dalle-federazioni-sportive/" target="_blank"
                   class="btn btn-spoome">Notizie dalle federazioni sportive</a>
            </div>
        </div>
    </div>
</div>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        function fetchData(endpoint, containerId) {
            fetch(endpoint)
                .then(response => response.json())
                .then(data => {
                    let content = '';
                    const container = document.getElementById(containerId);
                    if (!Array.isArray(data)) {
                        //container.style.display = 'none';
                        content += "<p class='mb-5'>Nessun contenuto trovato<p>";
                        container.innerHTML += content;
                        return;
                    }

                    data.forEach(m => {
                        const title = m.title;
                        const link = m.link;
                        const source = m.source;
                        const icon = m.icon;
                        content += `
    <div class="col-12 col-md-4">
        <div class="d-flex align-middle">
            <a class="text-decoration-none text-secondary" href="${source}" target="_blank">
                <i class="bi ${icon} me-1 text-secondary"></i> ${source} <i class="bi bi-dot"></i> <span>Olimpiadi</span>
            </a>
            <i class="bi bi-three-dots ms-auto text-secondary"></i>
        </div>
        <div class="card my-2" >
            <a class="text-decoration-none" href="${link}" target="_blank" >
                <img onerror="this.onerror=null; this.src='<?=SQUARE_PLACEHOLDER?>';" src="${m.thumb}" class="card-img-top news-photo"  alt="Foto news sportiva">
                </div>
            <div class="card-body">
                <div class="d-flex text-light" style="margin-bottom: 8px">
                    <a class="link-secondary text-decoration-none" href="https://www.facebook.com/sharer/sharer.php?u=${link}" target="_blank">
                        <i class="bi bi-facebook me-3"></i>
                    </a>
                    <a class="link-secondary text-decoration-none" href="https://api.whatsapp.com/send?text=Leggi questo articolo: ${link}" target="_blank">
                        <i class="bi bi-whatsapp me-3"></i>
                    </a>
                    <a role="button" class="link-secondary text-decoration-none" onclick="navigator.clipboard.writeText('${link}');">
                        <i class="bi bi-copy me-3"></i>
                    </a>
                </div>
                <a class="link-light text-decoration-none" href="${link}" target="_blank">
                    ${title}
                </a>
            </div>
        </div>
    </div>
`;

                    });
                    container.innerHTML += content;
                })
                .catch(error => {
                    console.error('Errore:', error);
                });
        }

        fetchData(`<?= SUB_ROOT ?>/services/searchMedia.php?q=olimpiadi&t=livenews`, 'livenews-container');
    });
</script>

