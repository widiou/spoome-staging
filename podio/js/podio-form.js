document.addEventListener("DOMContentLoaded", () => {
    const form = document.querySelector("form[action*='PodioSubmit']");
    const feedbackEl = document.querySelector("#podio-feedback");

    if (!form || !feedbackEl) return;

    form.addEventListener("submit", async (e) => {
        e.preventDefault();
        feedbackEl.innerHTML = ''; // reset messaggi

        const formData = new FormData(form);
        const isUpdate = formData.has("item_id");

        try {
            const response = await fetch(form.action, {
                method: "POST",
                body: formData,
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || `Errore HTTP ${response.status}`);
            }

            feedbackEl.innerHTML = `
        <div class="alert alert-success">
          ✅ Item ${isUpdate ? 'aggiornato' : 'creato'} con successo!
        </div>
      `;
        } catch (err) {
            feedbackEl.innerHTML = `
        <div class="alert alert-danger">
          ❌ Errore nel salvataggio: ${err.message}
        </div>
      `;
        }
    });
});
