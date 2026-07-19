<?php
/**
 * Centro notifiche. @var array $items
 */
$icon = static fn(string $type): string => [
    'claim_approved'      => 'fa-circle-check',
    'claim_rejected'      => 'fa-circle-xmark',
    'follow'              => 'fa-user-plus',
    'connection_request'  => 'fa-user-clock',
    'connection_accepted' => 'fa-user-check',
    'affiliation_requested' => 'fa-user-clock',
    'affiliation_confirmed' => 'fa-id-badge',
    'new_message'         => 'fa-envelope',
    'profile_verified'    => 'fa-shield-halved',
][$type] ?? 'fa-bell';
?>
<main class="site-main">
    <section class="container narrow">
        <header class="page-head">
            <h1><?= e(t('notif.title')) ?></h1>
            <p class="muted"><?= e(t('notif.subtitle')) ?></p>
        </header>

        <?php if (!$items): ?>
            <div class="empty-state">
                <p><?= e(t('notif.empty')) ?></p>
            </div>
        <?php else: ?>
            <ul class="notif-list">
                <?php foreach ($items as $n): ?>
                    <li class="notif-item<?= $n['read_at'] === null ? ' is-unread' : '' ?> notif-<?= e($n['type']) ?>">
                        <span class="notif-icon"><i class="fa-solid <?= e($icon((string) $n['type'])) ?>" aria-hidden="true"></i></span>
                        <div class="notif-body">
                            <?php if (!empty($n['url'])): ?>
                                <a class="notif-title" href="<?= e(url((string) $n['url'])) ?>"><?= e($n['title']) ?></a>
                            <?php else: ?>
                                <span class="notif-title"><?= e($n['title']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($n['body'])): ?>
                                <p class="notif-text"><?= e($n['body']) ?></p>
                            <?php endif; ?>
                            <span class="notif-time"><?= e(time_ago((string) $n['created_at'])) ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</main>
