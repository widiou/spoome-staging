document.addEventListener("DOMContentLoaded", async () => {
    setLazyLoading();

    // Sostituzione automatica di nomi e cognomi con link agli atleti
    const bioSummaryElement = document.getElementById('bio-summary');
    if (bioSummaryElement) {
        bioSummaryElement.innerHTML = await trovaNomiCognomi(bioSummaryElement.innerHTML);
    }

    // Inizializzazione dello slider
    const slider = document.getElementById('slider-container');
    if (slider) initSlider(slider);
});

// ✅ Lazy Loading con IntersectionObserver
function setLazyLoading() {
    if ('IntersectionObserver' in window) {
        const lazyObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    const lazyElement = entry.target;

                    // ✅ Immagine <img> con fallback
                    if (lazyElement.dataset.src) {
                        lazyElement.onerror = function () {
                            this.onerror = null;
                            this.src = '/network/assets/spoome-placeholder.webp';
                        };
                        lazyElement.src = lazyElement.dataset.src;
                    }

                    // ✅ Immagine di sfondo con fallback
                    if (lazyElement.dataset.bg) {
                        const img = new Image();
                        img.onload = function () {
                            lazyElement.style.backgroundImage = `url('${lazyElement.dataset.bg}')`;
                        };
                        img.onerror = function () {
                            lazyElement.style.backgroundImage = `url('/network/assets/spoome-placeholder-cover.webp')`;
                        };
                        img.src = lazyElement.dataset.bg;
                    }

                    lazyElement.classList.remove("lazy");
                    lazyObserver.unobserve(lazyElement);
                }
            });
        });

        document.querySelectorAll('.lazy').forEach(element => lazyObserver.observe(element));
    }
}


// ✅ Regex per nomi e cognomi con lettere accentate
async function trovaNomiCognomi(test) {
    const pattern = /\b[A-ZÀ-Ú][a-zà-ú]+(?:\s[A-ZÀ-Ú][a-zà-ú]+)+\b/g;
    const matches = test.match(pattern);

    if (!matches) return test;

    const results = await Promise.all(matches.map(async nomeCognome => {
        try {
            const {exists, id} = await checkAthleteExistence(nomeCognome);
            if (exists) {
                const slug = nomeCognome
                    .toLowerCase()
                    .replace(/[^\w\s-]/g, '')
                    .replace(/\s+/g, '-')
                    .trim();

                const link = `<a class="link-spoome fw-bolder" href="/network/atleti/${id}-${slug}">${nomeCognome}</a>`;
                return {nomeCognome, link};
            }
        } catch (error) {

        }
        return null;
    }));

    results
        .filter(result => result !== null)
        .forEach(({nomeCognome, link}) => {
            test = test.replace(new RegExp(`\\b${nomeCognome}\\b`, 'g'), link);
        });

    return test;
}

// ✅ Verifica esistenza atleta via API
async function checkAthleteExistence(nomeCognome) {
    try {
        const response = await fetch(`/network/services/checkAthleteExistence.php?name=${encodeURIComponent(nomeCognome)}`);
        if (!response.ok) {

            return {exists: false};
        }
        const data = await response.json();
        return data.exists ? {exists: true, id: data.id} : {exists: false};
    } catch (error) {

        return {exists: false};
    }
}

// ✅ Slider migliorato con event listener dinamico
function initSlider(slider) {
    let isDown = false;
    let startX;
    let scrollLeft;

    slider.addEventListener('pointerdown', (e) => {
        isDown = true;
        slider.classList.add('active');
        startX = e.clientX;
        scrollLeft = slider.scrollLeft;
    });

    window.addEventListener('pointermove', (e) => {
        if (!isDown) return;
        e.preventDefault();
        const x = e.clientX;
        const walk = (x - startX) * 2; // Velocità di scorrimento
        slider.scrollLeft = scrollLeft - walk;
    });

    window.addEventListener('pointerup', () => {
        isDown = false;
        slider.classList.remove('active');
    });
}

