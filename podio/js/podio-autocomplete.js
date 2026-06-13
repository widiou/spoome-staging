console.log("✅ podio-autocomplete.js caricato");

document.addEventListener('DOMContentLoaded', () => {
    const inputs = document.querySelectorAll('input[data-podio-app-reference]');

    inputs.forEach(input => {
        const appId = input.getAttribute('data-podio-app-reference');
        const appToken = input.getAttribute('data-podio-app-token');
        const hiddenId = input.id.replace('_label', '_hidden');
        const hiddenField = document.getElementById(hiddenId);

        if (!appId || !appToken || !hiddenField) {
            console.warn(`⚠️ Podio Autocomplete: input '${input.id}' mancano attributi o campo hidden`);
            return;
        }

        const datalistId = input.id + '_list';
        let datalist = document.getElementById(datalistId);
        if (!datalist) {
            datalist = document.createElement('datalist');
            datalist.id = datalistId;
            document.body.appendChild(datalist);
            input.setAttribute('list', datalist.id);
        }

        input.addEventListener('input', async () => {
            const q = input.value.trim();
            if (q.length < 2) return;

            try {
                const response = await fetch(`/network/podio/network/search-podio-items.php?app_id=${appId}&app_token=${appToken}&q=${encodeURIComponent(q)}`);
                const results = await response.json();

                datalist.innerHTML = '';

                if (Array.isArray(results)) {
                    results.forEach(item => {
                        const opt = document.createElement('option');
                        opt.value = item.label;
                        opt.dataset.id = item.id;
                        datalist.appendChild(opt);
                    });
                } else {
                    console.warn("⚠️ Podio Autocomplete: risposta inattesa", results);
                }
            } catch (err) {
                console.error("❌ Errore durante il fetch da Podio:", err);
            }
        });

        input.addEventListener('change', () => {
            const match = Array.from(datalist.options).find(opt => opt.value === input.value);
            hiddenField.value = match ? match.dataset.id : '';
        });
    });
});
