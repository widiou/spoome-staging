<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../layout/_header.php';

$appId = 30215147;
$appToken = '6023f0b3594514fe21882c31b385a225';
$visibleFields = ['titolo', 'tipologia', 'website'];
?>
    <div class="container py-5">
        <h2>📋 Elenco dinamico da Podio</h2>
        <?php include __DIR__ . '/views/list.php'; ?>
    </div>
<?php
require_once __DIR__ . '/../layout/_footer.php';
