(function () {
    const spoomeSearchWidget = document.createElement("div");
    spoomeSearchWidget.innerHTML = `             
            <input type="text" id="spoome-search-input" class="spoome-search-input" placeholder="Cerca un atleta...">
            <div id="spoome-search-results" class="spoome-search-results"></div>        
    `;
    document.body.appendChild(spoomeSearchWidget);

    const input = document.getElementById("spoome-search-input");
    const resultsContainer = document.getElementById("spoome-search-results");

    input.addEventListener("input", async function () {
        const query = input.value.trim();
        if (query.length < 2) {
            resultsContainer.style.display = "none";
            return;
        }

        try {
            const response = await fetch(`https://www.spoome.it/network/api/search.php?q=${encodeURIComponent(query)}`);
            const data = await response.json();

            resultsContainer.innerHTML = "";
            if (data.length === 0) {
                resultsContainer.style.display = "none";
                return;
            }

            data.forEach(atleta => {
                const resultItem = document.createElement("div");
                resultItem.classList.add("spoome-search-result");
                resultItem.innerHTML = `
                    <img src="${atleta.photo}" onerror="this.src='https://www.spoome.it/network/assets/spoome-placeholder.webp'" alt="${atleta.name}">
                    <span>${atleta.name}</span>
                `;
                resultItem.addEventListener("click", () => {
                    window.location.href = `https://www.spoome.it/network/atleti/${atleta.id}-${atleta.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
                });
                resultsContainer.appendChild(resultItem);
            });

            resultsContainer.style.display = "block";
        } catch (error) {
            console.error("Errore nella ricerca:", error);
        }
    });

    document.addEventListener("click", (event) => {
        if (!spoomeSearchWidget.contains(event.target)) {
            resultsContainer.style.display = "none";
        }
    });
})();
