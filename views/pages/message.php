<?php
/**
 * Pagina messaggio generica (esito verifica, "controlla la tua email", ecc.).
 * @var string $heading @var string $message @var string $type @var string|null $actionUrl @var string|null $actionLabel
 */
$type = $type ?? 'info';
?>
<main class="auth-wrap">
    <div class="auth-card">
        <div class="auth-head">
            <h1><?= e($heading ?? 'Spoome') ?></h1>
        </div>
        <div class="alert alert-<?= e($type) ?>" role="status"><?= e($message ?? '') ?></div>
        <?php if (!empty($actionUrl)): ?>
            <a href="<?= e($actionUrl) ?>" class="btn btn-primary btn-block btn-lg"><?= e($actionLabel ?? 'Continua') ?></a>
        <?php endif; ?>
    </div>
</main>
