<?php
/**
 * Feed ibrido: compositore post + timeline (post + attività).
 * @var array $feed @var bool $hasMore @var int $page @var string $myHandle @var array|null $notice
 */
use Spoome\Core\View;
?>
<main class="site-main">
    <section class="container feed-wrap">
        <h1 class="sr-only"><?= e(t('nav.feed')) ?></h1>

        <?php if (!empty($notice)): ?>
            <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
        <?php endif; ?>

        <form class="composer" method="post" action="<?= e(url('feed/post')) ?>" data-async data-async-handler="composer"
              data-unfurl="<?= e(url('feed/unfurl')) ?>">
            <?= csrf_field() ?>
            <label class="sr-only" for="body"><?= e(t('feed.compose.placeholder')) ?></label>
            <textarea class="input textarea" id="body" name="body" rows="3" maxlength="2000" placeholder="<?= e(t('feed.compose.placeholder')) ?>" required data-link-source></textarea>
            <input type="hidden" name="link_preview_url_hash" value="" data-link-hash>
            <div class="composer-preview" data-link-preview hidden></div>
            <div class="composer-actions">
                <button class="btn btn-primary" data-submit><?= e(t('feed.compose.submit')) ?></button>
            </div>
        </form>

        <?php if (!$feed): ?>
            <div class="empty-state">
                <p><?= e(t('feed.empty.text')) ?></p>
            </div>
            <?php if (!empty($suggested)): ?>
            <section class="suggested">
                <h2 class="suggested-title"><?= e(t('feed.suggested.title')) ?></h2>
                <ul class="suggested-list">
                    <?php foreach ($suggested as $s): ?>
                        <li class="suggested-card">
                            <a class="suggested-av" href="<?= e(profile_url($s)) ?>" aria-hidden="true">
                                <?php if (!empty($s['avatar_path'])): ?>
                                    <img class="avatar-img" src="<?= e(url($s['avatar_path'])) ?>" alt="" loading="lazy">
                                <?php else: ?><?= e(initials($s['display_name'])) ?><?php endif; ?>
                            </a>
                            <a class="suggested-name" href="<?= e(profile_url($s)) ?>"><?= e($s['display_name']) ?></a>
                            <span class="suggested-meta"><?= e($s['sport_name'] ?? $s['type_label']) ?></span>
                            <form class="follow-form" method="post" action="<?= e(url('atleti/' . $s['handle'] . '/segui')) ?>"
                                  data-async data-async-success="toggleState updateCount"
                                  data-state-key="following"
                                  data-toggle-action="<?= e(url('atleti/' . $s['handle'] . '/segui')) ?>|<?= e(url('atleti/' . $s['handle'] . '/nonseguire')) ?>"
                                  data-toggle-classes="btn-primary/btn-ghost" data-label-el=".follow-label"
                                  data-count-selector="[data-follow-followers]" data-count-scope="document" data-count-key="followers_count">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-primary btn-sm follow-btn" data-label-off="<?= e(t('follow.button.follow')) ?>" data-label-on="<?= e(t('follow.button.following')) ?>">
                                    <span class="follow-label"><?= e(t('follow.button.follow')) ?></span>
                                </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('atleti')) ?>"><?= e(t('connect.discover')) ?></a>
            </section>
            <?php endif; ?>
        <?php else: ?>
            <ul class="feed-list">
                <?php foreach ($feed as $it): ?><?= View::partial('feed-item', ['it' => $it, 'myHandle' => $myHandle]) ?><?php endforeach; ?>
            </ul>

            <?php if ($hasMore): ?>
                <nav class="pagination">
                    <a class="btn btn-ghost" href="<?= e(url('feed') . '?pagina=' . ($page + 1)) ?>"><?= e(t('feed.load_more')) ?></a>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>
