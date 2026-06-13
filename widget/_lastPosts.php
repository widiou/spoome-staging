<?php
if (isset($obja)) {
    ?>
    <div class="row mb-5" id="social-container">
        <div class="col-12">
            <p class="mb-3 fw-bold">Dicono di <?= $obja->query ?? $obja->title ?> sui social</p>
        </div>
    </div>
    <?php
}
?>
