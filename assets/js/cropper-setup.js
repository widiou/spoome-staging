function setupCropper(inputId, canvasId, hiddenInputId, aspectRatio, maxWidth = 800, maxHeight = 800) {
    const input = document.getElementById(inputId);
    const canvas = document.getElementById(canvasId);
    const hiddenInput = document.getElementById(hiddenInputId);
    let cropper;

    input.addEventListener('change', function (e) {
        const file = e.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function (event) {
            const image = new Image();
            image.onload = function () {
                // Mostra il canvas una sola volta
                canvas.classList.remove('d-none');

                const ctx = canvas.getContext('2d');
                canvas.width = image.width;
                canvas.height = image.height;
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(image, 0, 0);

                // Distruggi il cropper esistente se ce n'è uno
                if (cropper) {
                    cropper.destroy();
                }

                // Inizializza il nuovo cropper
                cropper = new Cropper(canvas, {
                    aspectRatio: aspectRatio,
                    viewMode: 1,
                    autoCropArea: 1,
                    responsive: true,
                    cropend: function () {
                        const croppedCanvas = cropper.getCroppedCanvas({
                            width: maxWidth,
                            height: maxHeight
                        });
                        hiddenInput.value = croppedCanvas.toDataURL('image/jpeg', 0.9);
                    }
                });
            };
            image.src = event.target.result;
        };
        reader.readAsDataURL(file);
    });
}
