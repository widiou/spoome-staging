<?php
/**
 * Inbox messaggi: elenco conversazioni con ultimo messaggio e non letti.
 * @var array $conversations @var array|null $notice
 */
?>
<main class="site-main">
    <section class="container feed-wrap">
        <header class="directory-head"><h1><?= e(t('nav.messages')) ?></h1></header>

        <?php if (!empty($notice)): ?>
            <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
        <?php endif; ?>

        <?php if (!$conversations): ?>
            <div class="empty-state">
                <p><?= e(t('dm.empty')) ?></p>
                <a class="btn btn-accent" href="<?= e(url('rete')) ?>"><?= e(t('nav.network')) ?></a>
            </div>
        <?php else: ?>
            <ul class="convo-list">
                <?php foreach ($conversations as $c): $o = $c['other']; ?>
                    <li>
                        <a class="convo<?= $c['unread'] > 0 ? ' is-unread' : '' ?>" href="<?= e(url('messaggi/' . $o['handle'])) ?>">
                            <span class="convo-avatar" aria-hidden="true">
                                <?php if (!empty($o['avatar_url'])): ?>
                                    <img class="avatar-img" src="<?= e($o['avatar_url']) ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <?= e(initials($o['display_name'])) ?>
                                <?php endif; ?>
                            </span>
                            <span class="convo-main">
                                <span class="convo-name"><?= e($o['display_name']) ?></span>
                                <?php if ($c['last']): ?>
                                    <span class="convo-preview"><?php if ($c['last']['from_me']): ?><?= e(t('dm.you_prefix')) ?> <?php endif; ?><?= e(mb_strimwidth($c['last']['body'], 0, 64, '…')) ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="convo-meta">
                                <?php if ($c['last']): ?><span class="convo-time"><?= e(time_ago((string) $c['last']['created_at'])) ?></span><?php endif; ?>
                                <?php if ($c['unread'] > 0): ?><span class="nav-badge"><?= e((string) $c['unread']) ?></span><?php endif; ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</main>
