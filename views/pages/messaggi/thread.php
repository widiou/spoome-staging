<?php
/**
 * Thread di una conversazione 1:1.
 * @var array|null $other @var string $handle @var array $messages @var array|null $notice
 */
?>
<main class="site-main">
    <section class="container feed-wrap thread-wrap">
        <header class="thread-head">
            <a class="thread-back" href="<?= e(url('messaggi')) ?>" aria-label="<?= e(t('dm.back')) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
            <?php if ($other): ?>
                <a class="thread-peer" href="<?= e(profile_url($other)) ?>">
                    <span class="convo-avatar" aria-hidden="true">
                        <?php if (!empty($other['avatar_url'])): ?>
                            <img class="avatar-img" src="<?= e($other['avatar_url']) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <?= e(initials($other['display_name'])) ?>
                        <?php endif; ?>
                    </span>
                    <span class="thread-peer-name"><?= e($other['display_name']) ?></span>
                </a>
            <?php endif; ?>
        </header>

        <?php if (!empty($notice)): ?>
            <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
        <?php endif; ?>

        <?php $lastId = $messages ? (int) end($messages)['id'] : 0; ?>
        <ul class="msg-list" data-thread data-poll-url="<?= e(url('messaggi/' . $handle . '/nuovi')) ?>" data-last-id="<?= e((string) $lastId) ?>">
            <?php if (!$messages): ?>
                <li class="msg-empty muted"><?= e(t('dm.thread_empty')) ?></li>
            <?php endif; ?>
            <?php foreach ($messages as $m): ?>
                <li class="msg <?= $m['from_me'] ? 'msg-me' : 'msg-them' ?>" data-mid="<?= e((string) $m['id']) ?>">
                    <span class="msg-bubble"><?= nl2br(e($m['body'])) ?></span>
                    <span class="msg-time"><?= e(time_ago((string) $m['created_at'])) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>

        <form class="composer msg-composer" method="post" action="<?= e(url('messaggi/' . $handle)) ?>" data-async data-async-handler="dm">
            <?= csrf_field() ?>
            <label class="sr-only" for="body"><?= e(t('dm.placeholder')) ?></label>
            <textarea class="input" id="body" name="body" rows="2" maxlength="4000" placeholder="<?= e(t('dm.placeholder')) ?>" required></textarea>
            <div class="composer-actions">
                <button class="btn btn-primary" data-submit><?= e(t('dm.send')) ?></button>
            </div>
        </form>
    </section>
</main>
