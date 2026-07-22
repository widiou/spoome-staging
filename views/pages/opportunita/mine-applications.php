<?php
/**
 * "Le mie candidature" (atleta). @var array $items @var int $total @var array|null $notice
 */
$statusLabels = ['submitted' => 'app.status.submitted', 'accepted' => 'app.status.accepted', 'rejected' => 'app.status.rejected'];
?>
<main class="site-main">
    <section class="container narrow">
        <header class="page-head">
            <h1><?= e(t('opp.my_apps.title')) ?></h1>
            <p class="muted"><?= e(t('opp.my_apps.subtitle')) ?></p>
        </header>

        <?php if (!empty($notice)): ?>
            <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
        <?php endif; ?>

        <?php if (!$items): ?>
            <div class="empty-state">
                <p><?= e(t('opp.my_apps.empty')) ?></p>
                <a class="btn btn-primary" href="<?= e(url('opportunita')) ?>"><?= e(t('opp.index.title')) ?></a>
            </div>
        <?php else: ?>
            <ul class="app-list">
                <?php foreach ($items as $a): ?>
                    <?php
                    $st       = (string) $a['status'];
                    $orgShape = ['handle' => $a['org_handle'] ?? '', 'type_key' => $a['org_type_key'] ?? null];
                    ?>
                    <li class="app-item">
                        <div class="app-main">
                            <div class="app-name">
                                <a href="<?= e(url('opportunita/' . (int) $a['opportunity_id'])) ?>"><?= e($a['opp_title']) ?></a>
                                <span class="app-badge app-badge-<?= e($st) ?>"><?= e(t($statusLabels[$st] ?? 'app.status.submitted')) ?></span>
                            </div>
                            <div class="app-meta muted">
                                <?= e(t('opp.show.published_by')) ?>:
                                <a href="<?= e(profile_url($orgShape)) ?>"><?= e($a['org_display_name']) ?></a>
                                · <?= e(t('opp.apps.sent', ['when' => time_ago((string) $a['created_at'])])) ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</main>
