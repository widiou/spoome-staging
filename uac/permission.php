<?php
$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';
require_once 'layout/_header.php';
?>
    <div class="container my-5 uac-container">
        <div class="row">
            <div class="col-12 col-md-6 offset-md-3">
                <h1 class="text-center"><i class="bi bi-exclamation-triangle-fill d-inline text-spoome mb-3"></i><br>Non
                    hai i permessi per accedere a questa funzione!</h1>
            </div>
        </div>
    </div>
<?php

require_once 'layout/_footer.php';

