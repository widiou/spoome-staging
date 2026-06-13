<?php
if (!str_contains($_SERVER['REQUEST_URI'] ?? '', 'uac')) {
    require_once 'widget/_similarProfile.php';
}
require_once 'widget/_allSports.php';
?>
<div class="container-fluid mt-3 py-2" style="background: var(--black); font-size: .85em; color: hsla(0, 0%, 100%, .5);">
    <div class="row">
        <div class="col-12">
            <ul class="nav justify-content-center">
                <li class="nav-item">
                    <a class="nav-link" aria-current="page" href="https://widiou.com/">AGENZIA</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="#">SPORT NETWORK</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="https://spoome.it/informativa-sulla-privacy/">INFORMATIVA SULLA
                        PRIVACY</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="https://spoome.it/cookie-policy/">COOKIES
                        POLICY</a>
                </li>
            </ul>
        </div>
        <div class="col-12 text-center">
            Copyrights 2024 &copy; Spoome
        </div>
    </div>
</div>
</div>


<script src="<?= SUB_ROOT ?>/node_modules/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
<?php

if (!str_contains($_SERVER['REQUEST_URI'] ?? '', 'uac')) {
    require_once 'partials/_searchMedia.php';
    ?>
    <script src="<?= SUB_ROOT ?>/assets/js/profile.js?<?= rand(0, 1000000) ?>"></script>
    <script src="<?= SUB_ROOT ?>/assets/js/search.js?<?= rand(0, 1000000) ?>"></script>
    <?php
}

if (isset($current_page)) {
    require_once 'partials/_searchMedia.php';
    require_once 'partials/_tooltip.php';
}
?>
<script>
    function resizeImages() {
        const images = document.querySelectorAll('.profile-photo-circle');

        images.forEach(img => {
            // Trova il contenitore della colonna Bootstrap più vicina
            const container = img.closest('.col') || img.parentElement;
            if (container) {
                const size = Math.min(container.getBoundingClientRect().width, container.getBoundingClientRect().height || container.getBoundingClientRect().width);
                img.style.width = `${size}px`;
                img.style.height = `${size}px`;
                img.style.borderRadius = '50%';
                img.style.objectFit = 'contain';
                img.style.maxWidth = '100%';
                img.style.maxHeight = '100%';
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        resizeImages(); // Calcolo iniziale

        // Ricalcolo delle dimensioni durante il resize della finestra
        window.addEventListener('resize', resizeImages);
    });
</script>
<script src="<?= SUB_ROOT ?>/assets/js/cropper.min.js"></script>
<script src="<?= SUB_ROOT ?>/assets/js/cropper-setup.js"></script>


</body>
</html>
