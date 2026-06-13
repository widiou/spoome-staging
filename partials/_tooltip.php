<div id="tooltip" class="custom-tooltip">
    <div class="tooltip-inner bg-spoome p-2 rounded">
        <a href="#" class="link-dark text-decoration-none">Vai al link</a>
    </div>
</div>

<style>
    /* Stile aggiuntivo per il tooltip */
    .custom-tooltip {
        position: absolute;
        z-index: 1000;
        display: none;
    }
</style>
<script>
    document.addEventListener('mouseup', function (event) {
        setTimeout(handleTextSelection, 100);
    });

    document.addEventListener('touchend', function (event) {
        setTimeout(handleTextSelection, 100);
    });

    function handleTextSelection() {
        let selectedText = window.getSelection().toString();
        if (selectedText.length > 0) {
            showTooltip();
        }
    }

    function showTooltip() {
        let selection = window.getSelection();
        if (selection.rangeCount > 0) {
            let range = selection.getRangeAt(0).cloneRange();
            let rect = range.getBoundingClientRect();

            const tooltip = document.getElementById('tooltip');
            tooltip.style.left = `${rect.left + window.scrollX}px`;
            tooltip.style.top = `${rect.top + window.scrollY + rect.height + 10}px`;
            tooltip.style.display = 'block';

            const link = tooltip.querySelector('a');
            link.href = 'https://www.spoome.it/network/index.php?cerca=' + selection.toString().trim(); // Cambia questo link secondo necessità
            link.textContent = 'Cerca "' + selection.toString() + '"';

            document.addEventListener('click', hideTooltip);
        }
    }

    function hideTooltip(event) {
        const tooltip = document.getElementById('tooltip');
        tooltip.style.display = 'none';
        document.removeEventListener('click', hideTooltip);
    }
</script>