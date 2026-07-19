<?php
/**
 * "Le mie rivendicazioni". @var array $requests @var bool $hasProfile @var array|null $notice
 */
$labels = ['pending' => 'claim.status.pending', 'approved' => 'claim.status.approved', 'rejected' => 'claim.status.rejected'];
?>
<main class="site-main">
    <section class="container narrow">
        <header class="page-head">
            <h1><?= e(t('claim.mine.title')) ?></h1>
            <p class="muted"><?= e(t('claim.mine.subtitle')) ?></p>
        </header>

        <?php if (!empty($notice)): ?>
            <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
        <?php endif; ?>

        <?php if ($hasProfile): ?>
            <div class="alert alert-success" role="status">
                <?= e(t('claim.mine.now_owner')) ?> <a href="<?= e(url('profilo')) ?>"><?= e(t('nav.my_profile')) ?></a>
            </div>
        <?php endif; ?>

        <?php if (!$requests): ?>
            <div class="empty-state">
                <p><?= e(t('claim.mine.empty')) ?></p>
                <a class="btn btn-primary" href="<?= e(url('atleti')) ?>"><?= e(t('claim.mine.browse')) ?></a>
            </div>
        <?php else: ?>
            <ul class="claim-list">
                <?php foreach ($requests as $r): ?>
                    <li class="claim-item">
                        <div class="claim-item-main">
                            <a class="claim-item-name" href="<?= e(url('atleti/' . $r['profile_handle'])) ?>"><?= e($r['profile_name']) ?></a>
                            <span class="claim-badge claim-badge-<?= e($r['status']) ?>"><?= e(t($labels[$r['status']] ?? 'claim.status.pending')) ?></span>
                        </div>
                        <div class="claim-item-meta muted">
                            <?= e(t('claim.mine.sent', ['when' => time_ago((string) $r['created_at'])])) ?>
                            <?php if (!empty($r['review_note']) && $r['status'] === 'rejected'): ?>
                                — <?= e(t('claim.mine.note')) ?>: <?= e($r['review_note']) ?>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</main>
