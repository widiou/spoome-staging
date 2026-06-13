document.addEventListener("DOMContentLoaded", async () => {
    const searchInput = document.getElementById('searchInput');
    const searchForm = document.getElementById('search-form');
    const suggestionsContainer = document.getElementById('autocomplete-suggestions');
    let currentRequest = null;

    // Leggi query da URL
    const searchQuery = new URLSearchParams(window.location.search).get('cerca');
    if (searchQuery) {
        searchInput.value = searchQuery;
        await loadSuggestions(searchQuery);
    }

    // Eventi su input e invio form
    searchInput?.addEventListener('input', async (event) => {
        await loadSuggestions(event.target.value.trim());
    });

    searchInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') event.preventDefault();
    });

    searchForm?.addEventListener('submit', (event) => {
        if (!searchInput.value.trim()) event.preventDefault();
    });

    // Funzione per caricare suggerimenti
    async function loadSuggestions(query) {
        if (query.length <= 2) {
            suggestionsContainer.innerHTML = '';
            return;
        }

        if (currentRequest) currentRequest.abort();
        currentRequest = new AbortController();

        try {
            const athletesFromDB = await fetchAthleteFromDatabase(query);
            if (athletesFromDB.length > 0) {
                updateSuggestions(athletesFromDB.map(a => ({ text: a.title, pageid: a.id })));
            } else {
                const suggestions = await fetchWikidata(query);
                updateSuggestions(suggestions);
            }
        } catch (error) {

        } finally {
            currentRequest = null;
        }
    }

    // Chiamata database locale
    async function fetchAthleteFromDatabase(query) {
        const url = `${window.SPOOME_BASE}/services/searchAthlete.php?q=${encodeURIComponent(query)}`;
        try {
            const response = await fetchWithTimeout(url, { timeout: 5000 });
            return await response.json();
        } catch {
            return [];
        }
    }

    // Chiamata combinata a Wikidata (nome e nome invertito)
    async function fetchWikidata(query) {
        const parts = query.split(' ');
        const reversedQuery = parts.length >= 2 ? parts.reverse().join(' ') : null;

        const urls = [
            `https://www.wikidata.org/w/api.php?action=wbsearchentities&search=${encodeURIComponent(query)}&language=it&type=item&limit=10&format=json&origin=*`,
            reversedQuery ? `https://www.wikidata.org/w/api.php?action=wbsearchentities&search=${encodeURIComponent(reversedQuery)}&language=it&type=item&limit=10&format=json&origin=*` : null
        ].filter(Boolean);

        const results = await Promise.all(urls.map(url =>
            fetchWithTimeout(url, { timeout: 5000 }).then(res => res.json()).then(data => data.search || [])
        ));

        return await filterPeopleWithSport([...results.flat()]);
    }

    // Filtra solo le persone con sport
    async function filterPeopleWithSport(entities) {
        const results = await Promise.all(entities.map(async (entity) => {
            const url = `https://www.wikidata.org/w/api.php?action=wbgetentities&ids=${encodeURIComponent(entity.id)}&props=sitelinks/urls|claims&languages=it&format=json&origin=*`;
            try {
                const data = await fetchWithTimeout(url, { timeout: 5000 }).then(res => res.json());
                const claims = data.entities[entity.id]?.claims;
                const sitelinks = data.entities[entity.id]?.sitelinks;

                if (
                    sitelinks?.itwiki &&
                    claims?.P31?.some(claim => claim.mainsnak.datavalue.value.id === 'Q5') && // Persona
                    claims?.P641 // Sport
                ) {
                    return { text: entity.match.text, pageid: entity.id };
                }
            } catch {
                return null;
            }
            return null;
        }));

        return results.filter(Boolean);
    }

    // Aggiorna suggerimenti
    function updateSuggestions(suggestions) {
        suggestionsContainer.innerHTML = '';
        if (!suggestions.length) return;

        suggestions.forEach(({ text, pageid }) => {
            const suggestionItem = document.createElement('div');
            suggestionItem.classList.add('autocomplete-suggestion');
            suggestionItem.textContent = text;

            suggestionItem.addEventListener('click', async () => {
                suggestionsContainer.innerHTML = '';
                if (pageid && !isNaN(pageid)) {
                    const slug = slugifyJS(text);
                    window.location.href = `${window.SPOOME_BASE}/atleti/${pageid}-${slug}`;
                } else {
                    await createAthlete(text);
                }
            });

            suggestionsContainer.appendChild(suggestionItem);
        });
    }

    // Crea atleta se non esiste
    async function createAthlete(name) {
        try {
            const response = await fetch(`${window.SPOOME_BASE}/services/create-athlete.php?name=${encodeURIComponent(name)}`);
            const result = await response.json();
            if (result.success) {
                window.location.href = result.redirect;
            }
        } catch {}
    }

    // Funzione per creare slug
    function slugifyJS(text) {
        return text.normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '') // Rimuove accenti
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-') // Sostituisce caratteri speciali
            .replace(/^-+|-+$/g, ''); // Rimuove - iniziali e finali
    }

    // Wrapper per fetch con timeout
    async function fetchWithTimeout(resource, options = {}) {
        const { timeout = 5000 } = options;
        const controller = new AbortController();
        const id = setTimeout(() => controller.abort(), timeout);
        const response = await fetch(resource, {
            ...options,
            signal: controller.signal
        });
        clearTimeout(id);
        return response;
    }
});
