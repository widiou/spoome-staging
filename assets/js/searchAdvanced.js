const rootPath = 'network/';

document.addEventListener('DOMContentLoaded', () => {

    // ✅ Funzione per recuperare i dati di autocomplete
    async function fetchAutocompleteData(attr, term) {
        try {
            const response = await fetch(`${rootPath}/services/searchAttribute.php?attr=${attr}&term=${encodeURIComponent(term)}`);
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            const data = await response.json();
            return attr === 'atleti'
                ? data.map(athlete => ({ text: athlete.title, id: athlete.id }))
                : data.map(item => ({ text: item }));
        } catch (error) {

            return [];
        }
    }

    // ✅ Funzione per generare slug SEO-friendly
    function createSlug(text) {
        return text
            .normalize('NFD') // Decompone caratteri accentati
            .replace(/[\u0300-\u036f]/g, '') // Rimuove accenti
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-') // Sostituisce caratteri speciali con "-"
            .replace(/^-+|-+$/g, ''); // Rimuove "-" iniziali e finali
    }

    // ✅ Funzione principale per autocomplete
    function autocomplete(input, attr) {
        let currentFocus = -1;
        let suggestionsContainer;

        // ✅ Event handler per l'input
        input.addEventListener('input', async () => {
            const value = input.value.trim();
            if (!value) return closeAllLists();

            const data = await fetchAutocompleteData(attr, value);
            if (!data.length) return closeAllLists();

            closeAllLists(); // Chiude eventuali liste aperte

            // ✅ Creazione container per suggerimenti
            suggestionsContainer = document.createElement('div');
            suggestionsContainer.setAttribute('class', 'autocomplete-suggestions');
            input.parentNode.appendChild(suggestionsContainer);

            data.forEach(item => {
                const suggestionItem = document.createElement('div');
                suggestionItem.setAttribute('class', 'autocomplete-suggestion');
                suggestionItem.textContent = item.text;

                // ✅ Click su suggerimento
                suggestionItem.addEventListener('click', () => {
                    if (attr === 'atleti' && item.id) {
                        const slug = createSlug(item.text);
                        window.location.href = `${window.SPOOME_BASE}/atleti/${item.id}-${slug}`;
                    } else {
                        input.value = item.text;
                    }
                    closeAllLists();
                });

                suggestionsContainer.appendChild(suggestionItem);
            });
        });

        // ✅ Event handler per tasti freccia e invio
        input.addEventListener('keydown', (e) => {
            if (!suggestionsContainer) return;

            const items = suggestionsContainer.getElementsByClassName('autocomplete-suggestion');
            if (e.key === 'ArrowDown') {
                currentFocus++;
                updateActive(items);
            } else if (e.key === 'ArrowUp') {
                currentFocus--;
                updateActive(items);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (currentFocus > -1) {
                    items[currentFocus].click();
                }
            }
        });

        // ✅ Attiva suggerimento selezionato
        function updateActive(items) {
            if (!items) return;
            removeActive(items);
            if (currentFocus >= items.length) currentFocus = 0;
            if (currentFocus < 0) currentFocus = items.length - 1;
            items[currentFocus]?.classList.add('autocomplete-active');
        }

        // ✅ Rimuove classe "active" da tutti i suggerimenti
        function removeActive(items) {
            Array.from(items).forEach(item => item.classList.remove('autocomplete-active'));
        }

        // ✅ Chiude la lista dei suggerimenti
        function closeAllLists() {
            if (suggestionsContainer) {
                suggestionsContainer.remove();
                suggestionsContainer = null;
                currentFocus = -1;
            }
        }

        // ✅ Chiudi suggerimenti al di fuori del campo input
        document.addEventListener('click', (e) => {
            if (e.target !== input) {
                closeAllLists();
            }
        });
    }

    // ✅ Inizializza gli input di autocomplete
    ['sport', 'activity', 'nationality', 'year', 'birthplace'].forEach(attr => {
        const input = document.getElementById(attr);
        if (input) autocomplete(input, attr);
    });
});
