<?php
require_once 'template/header.php';
?>
<div class="container-fluid">
    <div class="row p-0 m-0">
        <div class="col-12">
            <div class="search-container position-relative">
                <input type="text" id="searchInput" class="form-control form-control-lg"
                       placeholder="Cerca un atleta..." autocomplete="off"
                       onkeyup="searchAthlete()" style="height: 50px;">
                <div id="autocomplete-suggestions" class="results mt-2"></div>
            </div>
        </div>
    </div>
</div>

<style>
    .search-container {
        position: relative;
    }

    #autocomplete-suggestions {
        position: absolute;
        top: 100%;
        left: 0;
        width: 100%;
        max-height: 250px;
        overflow-y: auto;
        background: black;
        border: 1px solid #d8f21d;
        z-index: 9999;
        border-radius: 5px;
    }

    .result-item {
        padding: 10px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        cursor: pointer;
    }

    .result-item:last-child {
        border-bottom: none;
    }

    .result-item:hover {
        background-color: rgba(255, 255, 255, 0.1);
    }

    #searchInput {
        height: 50px;
    }
</style>

<script>
    function searchAthlete() {
        let query = document.getElementById("searchInput").value.trim();
        let resultsContainer = document.getElementById("autocomplete-suggestions");

        if (query.length < 3) {
            resultsContainer.innerHTML = "";
            resultsContainer.style.display = "none";
            return;
        }

        fetch(`https://www.spoome.it/network/api/search.php?q=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                let resultsHTML = "";
                if (!Array.isArray(data) || data.length === 0) {
                    resultsHTML = "<p class='text-light mt-2 p-2'>Nessun risultato trovato.</p>";
                } else {
                    data.forEach(atleta => {
                        resultsHTML += `<div class="result-item p-2">
                            <a href="https://www.spoome.it/network/atleti/${atleta.id}-${atleta.slug}"
                               class="d-flex align-items-center text-light text-decoration-none"
                               target="_blank">
                                <img src="${atleta.image}" class="me-2"
                                     style="width:40px; height:40px; border-radius:50%;"
                                     alt="${atleta.name}">
                                <span>${atleta.name}</span>
                            </a>
                        </div>`;
                    });
                }
                resultsContainer.innerHTML = resultsHTML;
                resultsContainer.style.display = "block";
            })
            .catch(error => console.error('Errore ricerca:', error));
    }
</script>

<?php
require_once 'template/footer.php';
?>
