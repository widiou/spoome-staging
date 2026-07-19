<?php
/**
 * Lista follower / seguiti di un profilo. @var array $profile @var string $mode ('followers'|'following')
 * @var array $items @var int $total @var int $page @var int $pages
 */
use Spoome\Core\View;

$handle  = (string) $profile['handle'];
$backUrl = profile_url($profile);
$link = static function (int $p) use ($handle, $mode): string {
    $seg = $mode === 'followers' ? 'follower' : 'seguiti';
    return url('atleti/' . $handle . '/' . $seg) . ($p > 1 ? '?pagina=' . $p : '');
};
?>
<main class="site-main">
    <section class="container directory">
        <header class="directory-head">
            <p class="directory-sub"><a href="<?= e($backUrl) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> <?= e($profile['display_name']) ?></a></p>
            <h1><?= e(t('follow.' . $mode)) ?></h1>
        </header>

        <p class="directory-count"><?= e(t('follow.count', ['n' => (string) $total])) ?></p>

        <?php if ($items === []): ?>
            <div class="empty-state">
                <p><?= e(t('follow.empty_' . $mode)) ?></p>
            </div>
        <?php else: ?>
            <div class="pcard-grid">
                <?php foreach ($items as $p): ?>
                    <?= View::partial('profile-card', ['p' => $p]) ?>
                <?php endforeach; ?>
            </div>

            <?php if ($pages > 1): ?>
                <nav class="pagination" aria-label="<?= e(t('atleti.pagination.label')) ?>">
                    <?php if ($page > 1): ?>
                        <a class="btn btn-ghost" rel="prev" href="<?= e($link($page - 1)) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> <?= e(t('atleti.pagination.prev')) ?></a>
                    <?php endif; ?>
                    <span class="pagination-status"><?= e(t('atleti.pagination.status', ['page' => (string) $page, 'pages' => (string) $pages])) ?></span>
                    <?php if ($page < $pages): ?>
                        <a class="btn btn-ghost" rel="next" href="<?= e($link($page + 1)) ?>"><?= e(t('atleti.pagination.next')) ?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>
