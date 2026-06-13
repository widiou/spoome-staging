<?php

if (isset($obja) and $obja->title != '') {
    $titleTerms = $obja->title ?? '';
    $sportTerms = $obja->sport ?? '';
    ?>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const loadState = {
                news: false,
                video: false,
                posts: false
            };

            function fetchData(endpoint, containerId, type = 'default') {
                const cacheKey = `cache-${endpoint}`;
                const cacheExpiryKey = `cache-expiry-${endpoint}`;
                const now = Date.now();
                const cachedData = localStorage.getItem(cacheKey);
                const cacheExpiry = Number(localStorage.getItem(cacheExpiryKey));
                if (cachedData && cacheExpiry && now < cacheExpiry) {
                    document.getElementById(containerId).innerHTML = cachedData;
                    if (type === 'video') attachVideoClickEvent(containerId);

                    return;
                }
                fetch(endpoint)
                    .then(response => response.json())
                    .then(data => {
                        let content = '';
                        const container = document.getElementById(containerId);

                        if (!Array.isArray(data) || data.length === 0) {
                            content += "<p class='mb-5'>Nessun contenuto trovato</p>";
                            container.innerHTML = content;
                            return;
                        }

                        data.forEach(m => {
                            const title = m.title;
                            const description = m.snippet.length > 100
                                ? m.snippet.substring(0, m.snippet.lastIndexOf(" ", 100)) + '...'
                                : m.snippet;
                            const link = m.link;
                            const source = m.source;
                            const newsdate = m.newsdate;
                            const icon = m.icon;
                            const thumb = m.thumb;

                            const isVideo = (type === 'video');
                            const videoClass = isVideo ? 'video-thumbnail' : '';

                            content += `
                    <div class="col-12 col-md-3">
                        <div class="card my-2">
                            <a class="text-decoration-none ${videoClass}" href="${link}" target="_blank">
                                <img src="${thumb}" loading="lazy" class="card-img-top news-photo" alt="Foto news sportiva">
                                ${isVideo ? '<i class="bi bi-play-circle-fill play-button"></i>' : ''}
                            </a>
                            <div class="card-body text-light">
                                <p class="text-small"><i class="bi ${icon} me-1 text-secondary"></i>${source}</p>
                                <a class="link-light text-decoration-none fw-bold" href="${link}" target="_blank">
                                    ${title}
                                </a>

                                <div class="text-end text-secondary">
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
                            </div>
                        </div>
                    </div>`;
                        });

                        container.innerHTML = content;
                        safeSetCache(cacheKey, content, cacheExpiryKey, 12 * 60 * 60 * 1000); // 12 ore


                        if (type === 'video') attachVideoClickEvent(containerId);
                    })
                    .catch(error => {
                        console.error('❌ Errore nel fetch:', error);
                    });
            }


            function attachVideoClickEvent(containerId) {
                document.querySelectorAll(`#${containerId} .video-thumbnail`).forEach(element => {
                    element.addEventListener("click", function () {
                        const videoId = this.dataset.videoid;
                        if (!videoId) return;

                        this.innerHTML = `
                <iframe width="100%" height="200" src="https://www.youtube.com/embed/${videoId}?autoplay=1"
                        frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen></iframe>`;
                    });
                });
            }

            function safeSetCache(key, value, expiryKey, expiryTimeMs) {
                try {
                    localStorage.setItem(key, value);
                    localStorage.setItem(expiryKey, String(Date.now() + expiryTimeMs));
                } catch (e) {
                    if (e.name === 'QuotaExceededError' || e.name === 'NS_ERROR_DOM_QUOTA_REACHED') {
                        clearOldCache();
                        try {
                            localStorage.setItem(key, value);
                            localStorage.setItem(expiryKey, String(Date.now() + expiryTimeMs));
                        } catch (e2) {
                            console.warn("⚠️ Cache disabilitata (quota superata):", e2.message);
                        }
                    }
                }
            }

            function clearOldCache() {
                const keys = Object.keys(localStorage).filter(k => k.startsWith('cache-'));
                keys.sort((a, b) => {
                    const aExp = Number(localStorage.getItem(`cache-expiry-${a}`)) || 0;
                    const bExp = Number(localStorage.getItem(`cache-expiry-${b}`)) || 0;
                    return aExp - bExp; // più vecchi prima
                });

                keys.slice(0, 5).forEach(k => {
                    localStorage.removeItem(k);
                    localStorage.removeItem(`cache-expiry-${k}`);
                });
            }


            function setLocalStorageItem(key, value) {
                try {
                    localStorage.setItem(key, value);
                } catch (e) {
                    if (e.name === 'QuotaExceededError' || e.name === 'NS_ERROR_DOM_QUOTA_REACHED') {
                        clearOldCache();
                        localStorage.setItem(key, value);
                    }
                }
            }


            // Lazy loading sui tab
            const tabs = [
                { id: 'news-tab', type: 'news', container: 'news-container' },
                { id: 'video-tab', type: 'video', container: 'video-container' },
                { id: 'social-tab', type: 'posts', container: 'social-container' }
            ];

            tabs.forEach(tab => {
                document.getElementById(tab.id)?.addEventListener('click', () => {
                    if (!loadState[tab.type]) {
                        fetchData(`<?= SUB_ROOT ?>/services/searchMedia.php?q=<?= urlencode($titleTerms) ?>&t=${tab.type}`, tab.container, tab.type);
                        loadState[tab.type] = true;
                    }
                });
            });

            fetchData(`<?= SUB_ROOT ?>/services/searchMedia.php?q=<?= urlencode($titleTerms) ?>&t=news`, 'news-container', 'news');
            loadState.news = true;
        });
    </script>

    <?php
}
?>
