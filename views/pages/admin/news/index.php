<?php
/**
 * Admin — fonti news (RSS/Atom). @var array $sources @var array $sports @var array|null $notice
 * Ogni fonte: nome, feed, attribuzione org (federazione) opzionale, intervallo, sport di match, stato.
 */
// Form riusabile (aggiungi / modifica). $s = fonte esistente o null.
$form = static function (?array $s) use ($sports): void {
    $action = $s ? url('admin/news/' . (int) $s['id']) : url('admin/news');
    $sel = [];
    foreach (($s['sports'] ?? []) as $sp) { $sel[(int) $sp['id']] = true; }
    ?>
    <form class="admin-news-form" method="post" action="<?= e($action) ?>">
        <?= csrf_field() ?>
        <div class="anf-grid">
            <label class="anf-field"><span><?= e(t('admin.news.f.name')) ?></span>
                <input class="input" type="text" name="name" maxlength="120" required value="<?= e((string) ($s['name'] ?? '')) ?>"></label>
            <label class="anf-field"><span><?= e(t('admin.news.f.url')) ?></span>
                <input class="input" type="url" name="feed_url" maxlength="500" required placeholder="https://…/feed" value="<?= e((string) ($s['feed_url'] ?? '')) ?>"></label>
            <label class="anf-field"><span><?= e(t('admin.news.f.org')) ?></span>
                <input class="input" type="text" name="org_handle" maxlength="60" placeholder="<?= e(t('admin.news.f.org_ph')) ?>"></label>
            <label class="anf-field anf-narrow"><span><?= e(t('admin.news.f.refresh')) ?></span>
                <input class="input" type="number" name="refresh_minutes" min="5" max="1440" value="<?= e((string) ($s['refresh_minutes'] ?? 60)) ?>"></label>
        </div>
        <fieldset class="anf-sports">
            <legend><?= e(t('admin.news.f.sports')) ?></legend>
            <div class="anf-chips">
                <?php foreach ($sports as $sp): ?>
                    <label class="anf-chip">
                        <input type="checkbox" name="sports[]" value="<?= e((string) $sp['id']) ?>"<?= isset($sel[(int) $sp['id']]) ? ' checked' : '' ?>>
                        <span><?= e($sp['name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <div class="anf-foot">
            <label class="anf-active"><input type="checkbox" name="active" value="1"<?= (!$s || !empty($s['active'])) ? ' checked' : '' ?>> <?= e(t('admin.news.f.active')) ?></label>
            <button class="btn btn-accent btn-sm" type="submit"><?= e($s ? t('admin.news.save') : t('admin.news.add')) ?></button>
        </div>
    </form>
    <?php
};
?>
<header class="admin-head">
    <div>
        <h1 class="admin-title"><?= e(t('admin.news.title')) ?></h1>
        <p class="admin-subtitle"><?= e(t('admin.news.subtitle')) ?></p>
    </div>
    <form method="post" action="<?= e(url('admin/news/aggiorna')) ?>">
        <?= csrf_field() ?>
        <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-rotate" aria-hidden="true"></i> <?= e(t('admin.news.fetch_now')) ?></button>
    </form>
</header>

<?php if (!empty($notice)): ?>
    <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
<?php endif; ?>

<p class="admin-hint muted"><?= e(t('admin.news.cron_hint')) ?></p>

<section class="admin-panel">
    <h2 class="admin-panel-title"><?= e(t('admin.news.add')) ?></h2>
    <?php $form(null); ?>
</section>

<section class="admin-panel admin-panel-flush">
    <?php if (!$sources): ?>
        <p class="muted admin-empty"><?= e(t('admin.news.none')) ?></p>
    <?php else: ?>
        <ul class="admin-news-list">
            <?php foreach ($sources as $s): ?>
                <li class="admin-news-item<?= empty($s['active']) ? ' is-off' : '' ?>">
                    <div class="ani-main">
                        <div class="ani-head">
                            <strong><?= e($s['name']) ?></strong>
                            <?php if (!empty($s['org_handle'])): ?>
                                <span class="ani-org"><i class="fa-solid fa-link" aria-hidden="true"></i> <?= e((string) $s['org_name']) ?></span>
                            <?php else: ?>
                                <span class="ani-org muted"><?= e(t('admin.news.third_party')) ?></span>
                            <?php endif; ?>
                            <span class="ani-meta muted"><?= e((string) $s['refresh_minutes']) ?>′ · <?= e((string) $s['item_count']) ?> <?= e(t('admin.news.items')) ?><?php if (!empty($s['last_fetched_at'])): ?> · <?= e(time_ago((string) $s['last_fetched_at'])) ?><?php endif; ?></span>
                        </div>
                        <a class="ani-url admin-link" href="<?= e((string) $s['feed_url']) ?>" target="_blank" rel="noopener nofollow"><?= e((string) $s['feed_url']) ?></a>
                        <?php if (!empty($s['sports'])): ?>
                            <div class="ani-sports"><?php foreach ($s['sports'] as $sp): ?><span class="chip chip-sport"><?= e($sp['name']) ?></span><?php endforeach; ?></div>
                        <?php endif; ?>
                        <details class="ani-edit">
                            <summary><i class="fa-solid fa-pen" aria-hidden="true"></i> <?= e(t('admin.news.edit')) ?></summary>
                            <?php $form($s); ?>
                        </details>
                    </div>
                    <div class="ani-actions">
                        <form method="post" action="<?= e(url('admin/news/' . (int) $s['id'] . '/attiva')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn-ghost btn-sm" type="submit" title="<?= e($s['active'] ? t('admin.news.disable') : t('admin.news.enable')) ?>"><i class="fa-solid <?= $s['active'] ? 'fa-toggle-on' : 'fa-toggle-off' ?>" aria-hidden="true"></i></button>
                        </form>
                        <form method="post" action="<?= e(url('admin/news/aggiorna')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="source_id" value="<?= e((string) $s['id']) ?>">
                            <button class="btn btn-ghost btn-sm" type="submit" title="<?= e(t('admin.news.fetch_now')) ?>"><i class="fa-solid fa-rotate" aria-hidden="true"></i></button>
                        </form>
                        <form method="post" action="<?= e(url('admin/news/' . (int) $s['id'] . '/elimina')) ?>" data-confirm="<?= e(t('admin.news.confirm_delete')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn-danger btn-sm" type="submit" title="<?= e(t('admin.news.delete')) ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
