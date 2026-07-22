<?php
/**
 * Candidature ricevute su un'opportunità (lato org). @var array $opp @var array $items @var int $total
 * @var array|null $notice
 */
$oppId  = (int) $opp['id'];
$return = 'opportunita/' . $oppId . '/candidature';
$statusLabels = ['submitted' => 'app.status.submitted', 'accepted' => 'app.status.accepted', 'rejected' => 'app.status.rejected'];
?>
<main class="site-main">
    <section class="container narrow">
        <header class="page-head">
            <div>
                <h1><?= e(t('opp.apps.title')) ?></h1>
                <p class="muted"><a href="<?= e(url('opportunita/' . $oppId)) ?>"><?= e($opp['title']) ?></a></p>
            </div>
        </header>

        <?php if (!empty($notice)): ?>
            <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
        <?php endif; ?>

        <?php if (!$items): ?>
            <div class="empty-state"><p><?= e(t('opp.apps.empty')) ?></p></div>
        <?php else: ?>
            <ul class="app-list">
                <?php foreach ($items as $a): ?>
                    <?php
                    $apName   = (string) $a['ap_display_name'];
                    $apShape  = ['handle' => $a['ap_handle'] ?? '', 'type_key' => $a['ap_type_key'] ?? null];
                    $st       = (string) $a['status'];
                    $appId    = (int) $a['id'];
                    ?>
                    <li class="app-item" data-async-card>
                        <a class="app-avatar" href="<?= e(profile_url($apShape)) ?>">
                            <?php if (!empty($a['ap_avatar_path'])): ?>
                                <img src="<?= e(url($a['ap_avatar_path'])) ?>" alt="" loading="lazy">
                            <?php else: ?><?= e(initials($apName)) ?><?php endif; ?>
                        </a>
                        <div class="app-main">
                            <div class="app-name">
                                <a href="<?= e(profile_url($apShape)) ?>"><?= e($apName) ?></a>
                                <?php if (!empty($a['ap_verified_at'])): ?><i class="fa-solid fa-circle-check" title="Verificato" aria-hidden="true"></i><?php endif; ?>
                                <span class="app-badge app-badge-<?= e($st) ?>"><?= e(t($statusLabels[$st] ?? 'app.status.submitted')) ?></span>
                            </div>
                            <?php if (!empty($a['ap_headline'])): ?>
                                <div class="app-headline muted"><?= e($a['ap_headline']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($a['cover_message'])): ?>
                                <p class="app-message"><?= nl2br(e($a['cover_message'])) ?></p>
                            <?php endif; ?>
                            <div class="app-meta muted"><?= e(t('opp.apps.sent', ['when' => time_ago((string) $a['created_at'])])) ?></div>

                            <?php if ($st === 'submitted'): ?>
                                <div class="app-actions">
                                    <form method="post" action="<?= e(url('candidature/' . $appId . '/accetta')) ?>" class="inline-form" data-async>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="return" value="<?= e($return) ?>">
                                        <button type="submit" class="btn btn-sm btn-primary"><?= e(t('opp.apps.accept')) ?></button>
                                    </form>
                                    <form method="post" action="<?= e(url('candidature/' . $appId . '/rifiuta')) ?>" class="inline-form" data-async>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="return" value="<?= e($return) ?>">
                                        <button type="submit" class="btn btn-sm btn-ghost"><?= e(t('opp.apps.reject')) ?></button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</main>
