<?php
/**
 * Inbox degli inviti a gestire una pagina, ricevuti dall'utente. Async-first (fetch + reload),
 * fallback CSRF no-JS. Ogni output via e(). Nessuna stringa hardcoded.
 *
 * @var array $invites [{id, profile_id, role, page_handle, page_name, invited_by_user_id, created_at}]
 * @var array|null $notice flash
 */
$invites = $invites ?? [];
$roleLabel = static fn(string $r): string => t('member.role.' . $r);
?>
<main class="site-main">
    <section class="container form-page">
        <header class="form-page-head">
            <div>
                <h1><?= e(t('member.inbox.title')) ?></h1>
                <p class="muted"><?= e(t('member.inbox.subtitle')) ?></p>
            </div>
        </header>

        <?php if (!empty($notice)): ?>
            <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
        <?php endif; ?>

        <?php if ($invites === []): ?>
            <p class="muted empty-row"><?= e(t('member.inbox.empty')) ?></p>
        <?php else: ?>
        <ul class="pv-list aff-list">
            <?php foreach ($invites as $inv):
                $invId   = (int) $inv['id'];
                $invRole = (string) $inv['role'];
                $pageName = (string) ($inv['page_name'] ?? '');
                $pageHandle = (string) ($inv['page_handle'] ?? '');
            ?>
            <li class="pv-viewer aff-item" data-async-card>
                <span class="pv-avatar aff-avatar" aria-hidden="true"><?= e(initials($pageName !== '' ? $pageName : $pageHandle)) ?></span>
                <span class="pv-id">
                    <span class="pv-name"><?= e($pageName !== '' ? $pageName : ('@' . $pageHandle)) ?></span>
                    <span class="pv-head aff-meta"><?= e(t('member.inbox.role', ['role' => $roleLabel($invRole)])) ?></span>
                </span>
                <span class="aff-actions">
                    <form method="post" action="<?= e(url('pagine/inviti/' . $invId . '/accetta')) ?>" class="aff-act" data-async data-async-success="removeCard toast" data-toast-ok="<?= e(t('member.flash.accepted')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-primary btn-sm" type="submit"><?= e(t('member.action.accept')) ?></button>
                    </form>
                    <form method="post" action="<?= e(url('pagine/inviti/' . $invId . '/rifiuta')) ?>" class="aff-act" data-async data-async-success="removeCard toast" data-toast-ok="<?= e(t('member.flash.declined')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-ghost btn-sm" type="submit"><?= e(t('member.action.decline')) ?></button>
                    </form>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
</main>
