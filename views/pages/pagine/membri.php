<?php
/**
 * Gestione membri di una pagina org (owner/admin). Async-first (fetch + reload), fallback CSRF no-JS.
 * Ogni output via e(). Nessuna stringa hardcoded.
 *
 * @var array  $page         riga enriched della pagina (display_name/handle/is_organization…)
 * @var string $handle       handle della pagina (per le action URL)
 * @var array  $members      roster: [{user_id, role, handle, display_name, avatar_media_id, created_at}]
 * @var array  $pending      inviti pendenti: [{id, invited_user_id, role, handle, display_name}]
 * @var string $actingRole   ruolo dell'utente corrente su questa pagina (owner|admin)
 * @var int    $actingUserId id utente corrente (per marcare la propria riga)
 * @var string[] $roles      ruoli assegnabili (admin|editor)
 * @var array|null $notice   flash
 */
$page   = $page ?? [];
$roles  = $roles ?? ['admin', 'editor'];
$isOwner = ($actingRole ?? '') === 'owner';
$roleLabel = static fn(string $r): string => t('member.role.' . $r);

/** Riga membro/invito riusabile (initials avatar, nome, badge ruolo). */
$rowHead = static function (?string $name, ?string $handle, string $role) use ($roleLabel): string {
    $display = ($name !== null && $name !== '') ? $name : '—';
    $out  = '<a class="pv-avatar aff-avatar"' . ($handle ? ' href="' . e(url(profile_path(['handle' => $handle, 'is_organization' => 0]))) . '"' : '') . '>'
          . e(initials($display)) . '</a>';
    $out .= '<span class="pv-id"><span class="pv-name">';
    if ($handle) {
        $out .= '<a href="' . e(url(profile_path(['handle' => $handle, 'is_organization' => 0]))) . '">' . e($display) . '</a>';
    } else {
        $out .= e($display);
    }
    $out .= ' <span class="aff-badge">' . e($roleLabel($role)) . '</span></span>';
    if ($handle) {
        $out .= '<span class="pv-head aff-meta">@' . e($handle) . '</span>';
    }
    $out .= '</span>';
    return $out;
};
?>
<main class="site-main">
    <section class="container form-page">
        <header class="form-page-head">
            <div>
                <h1><?= e(t('member.manage.title')) ?></h1>
                <p class="muted"><?= e((string) ($page['display_name'] ?? '')) ?> — <?= e(t('member.manage.subtitle')) ?></p>
            </div>
            <a class="btn btn-ghost" href="<?= e(url(profile_path($page))) ?>">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> <?= e((string) ($page['display_name'] ?? '')) ?>
            </a>
        </header>

        <?php if (!empty($notice)): ?>
            <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
        <?php endif; ?>

        <!-- INVITA -->
        <details class="edit-section acc" id="invita" open>
            <summary class="acc-head"><span class="acc-ico"><i class="fa-solid fa-user-plus" aria-hidden="true"></i></span><span class="acc-t"><?= e(t('member.invite.title')) ?></span><i class="fa-solid fa-chevron-down acc-chev" aria-hidden="true"></i></summary>
            <div class="acc-body">
                <form method="post" action="<?= e(url('pagine/' . $handle . '/membri/invita')) ?>" class="add-form" data-async data-async-success="reload">
                    <?= csrf_field() ?>
                    <div class="field-row">
                        <div class="field">
                            <label for="inv-handle"><?= e(t('member.invite.handle_label')) ?> <span class="req">*</span></label>
                            <input class="input" type="text" id="inv-handle" name="handle" maxlength="120" required placeholder="<?= e(t('member.invite.handle_ph')) ?>" autocomplete="off">
                        </div>
                        <div class="field">
                            <label for="inv-role"><?= e(t('member.invite.role_label')) ?></label>
                            <select class="input" id="inv-role" name="role">
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= e($r) ?>"<?= $r === 'editor' ? ' selected' : '' ?>><?= e($roleLabel($r)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <p class="muted field-help"><?= e(t('member.invite.handle_hint')) ?></p>
                    <div class="form-actions">
                        <button class="btn btn-primary btn-sm" type="submit" data-submit><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> <?= e(t('member.invite.submit')) ?></button>
                    </div>
                </form>
            </div>
        </details>

        <!-- ROSTER -->
        <details class="edit-section acc" id="membri" open>
            <summary class="acc-head"><span class="acc-ico"><i class="fa-solid fa-users" aria-hidden="true"></i></span><span class="acc-t"><?= e(t('member.roster.title')) ?></span><i class="fa-solid fa-chevron-down acc-chev" aria-hidden="true"></i></summary>
            <div class="acc-body">
                <?php if ($members === []): ?>
                    <p class="muted empty-row"><?= e(t('member.roster.empty')) ?></p>
                <?php else: ?>
                <ul class="pv-list aff-list">
                    <?php foreach ($members as $m):
                        $mUid   = (int) $m['user_id'];
                        $mRole  = (string) $m['role'];
                        $isSelf = $mUid === (int) $actingUserId;
                        // Un admin non tocca un owner; nessuno gestisce sé stesso da qui.
                        $canManage = !$isSelf && ($mRole !== 'owner' || $isOwner);
                    ?>
                    <li class="pv-viewer aff-item">
                        <?= $rowHead($m['display_name'] ?? null, $m['handle'] ?? null, $mRole) ?>
                        <?php if ($isSelf): ?>
                            <span class="aff-badge aff-badge-pending"><?= e(t('member.you')) ?></span>
                        <?php elseif ($canManage): ?>
                            <span class="aff-actions">
                                <?php if ($mRole === 'editor'): ?>
                                    <form method="post" action="<?= e(url('pagine/' . $handle . '/membri/' . $mUid . '/ruolo')) ?>" class="aff-act" data-async data-async-success="reload">
                                        <?= csrf_field() ?><input type="hidden" name="role" value="admin">
                                        <button class="btn btn-ghost btn-sm" type="submit"><?= e(t('member.action.promote_admin')) ?></button>
                                    </form>
                                <?php else: /* admin o owner → declassa a editor */ ?>
                                    <form method="post" action="<?= e(url('pagine/' . $handle . '/membri/' . $mUid . '/ruolo')) ?>" class="aff-act" data-async data-async-success="reload" data-async-confirm="<?= e(t('member.confirm.demote')) ?>">
                                        <?= csrf_field() ?><input type="hidden" name="role" value="editor">
                                        <button class="btn btn-ghost btn-sm" type="submit"><?= e(t('member.action.make_editor')) ?></button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?= e(url('pagine/' . $handle . '/membri/' . $mUid . '/rimuovi')) ?>" class="aff-act" data-async data-async-success="reload" data-async-confirm="<?= e(t('member.confirm.remove')) ?>">
                                    <?= csrf_field() ?>
                                    <button class="icon-btn" type="submit" title="<?= e(t('member.action.remove')) ?>" aria-label="<?= e(t('member.action.remove')) ?>"><i class="fa-solid fa-user-xmark" aria-hidden="true"></i></button>
                                </form>
                            </span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </details>

        <!-- INVITI PENDENTI -->
        <details class="edit-section acc" id="pendenti" open>
            <summary class="acc-head"><span class="acc-ico"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i></span><span class="acc-t"><?= e(t('member.pending.title')) ?></span><i class="fa-solid fa-chevron-down acc-chev" aria-hidden="true"></i></summary>
            <div class="acc-body">
                <?php if ($pending === []): ?>
                    <p class="muted empty-row"><?= e(t('member.pending.empty')) ?></p>
                <?php else: ?>
                <ul class="pv-list aff-list">
                    <?php foreach ($pending as $inv):
                        $invId  = (int) $inv['id'];
                        $invRole = (string) $inv['role'];
                    ?>
                    <li class="pv-viewer aff-item">
                        <?= $rowHead($inv['display_name'] ?? null, $inv['handle'] ?? null, $invRole) ?>
                        <span class="aff-actions">
                            <form method="post" action="<?= e(url('pagine/inviti/' . $invId . '/revoca')) ?>" class="aff-act" data-async data-async-success="reload" data-async-confirm="<?= e(t('member.confirm.revoke')) ?>">
                                <?= csrf_field() ?><input type="hidden" name="return" value="<?= e('pagine/' . $handle . '/membri') ?>">
                                <button class="btn btn-ghost btn-sm" type="submit"><?= e(t('member.action.revoke')) ?></button>
                            </form>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </details>
    </section>
</main>
