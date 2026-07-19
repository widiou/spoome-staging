<?php
/**
 * Riga di affiliazione (roster o militanza). Controparte arricchita in cp_*. Ogni output via e().
 * @var array  $a        riga affiliazione (cp_* + role/team/jersey/start_year/end_year/is_current/status/id)
 * @var bool   $manage   se true, mostra le azioni (conferma/rifiuta se pending, altrimenti rimuovi)
 * @var bool   $outgoing se true, la card è una richiesta IN USCITA (creata da me): badge "In attesa" + "Annulla",
 *                       non "Conferma/Rifiuta" (quelli spettano alla controparte destinataria).
 * @var string $return   path di ripiego no-JS per il redirect dopo l'azione
 */
$manage   = $manage ?? false;
$outgoing = $outgoing ?? false;
$return   = $return ?? 'profilo';
$cpName  = (string) $a['cp_display_name'];
$verified = !empty($a['cp_verified_at']);
$isCurrent = !empty($a['is_current']);
$sy = trim((string) ($a['start_year'] ?? ''));
$ey = trim((string) ($a['end_year'] ?? ''));
$sep = t('affil.years.sep');
$years = '';
if ($sy !== '' && $ey !== '') { $years = $sy . $sep . $ey; }
elseif ($sy !== '') { $years = $sy . $sep; }
elseif ($ey !== '') { $years = $sep . $ey; }
$jersey = trim((string) ($a['jersey'] ?? ''));
$metaBits = array_filter([
    trim((string) ($a['role'] ?? '')),
    trim((string) ($a['team'] ?? '')),
    $jersey !== '' ? '#' . $jersey : '',
]);
$href = profile_url($a);
$affId = (int) $a['id'];
$pending = ($a['status'] ?? '') === 'pending';
?>
<li class="pv-viewer aff-item" data-async-card>
    <a class="pv-avatar aff-avatar" href="<?= e($href) ?>">
        <?php if (!empty($a['cp_avatar_path'])): ?>
            <img src="<?= e(url($a['cp_avatar_path'])) ?>" alt="" loading="lazy">
        <?php else: ?><?= e(initials($cpName)) ?><?php endif; ?>
    </a>
    <span class="pv-id">
        <span class="pv-name">
            <a href="<?= e($href) ?>"><?= e($cpName) ?></a>
            <?php if ($verified): ?><i class="fa-solid fa-circle-check pv-verified" title="<?= e(t('atleti.verified')) ?>" aria-hidden="true"></i><?php endif; ?>
            <?php if ($isCurrent): ?><span class="aff-badge"><?= e(t('affil.badge.current')) ?></span><?php endif; ?>
            <?php if ($outgoing && $pending): ?><span class="aff-badge aff-badge-pending"><?= e(t('affil.badge.pending')) ?></span><?php endif; ?>
        </span>
        <?php if ($metaBits): ?><span class="pv-head aff-meta"><?= e(implode(' · ', $metaBits)) ?></span><?php endif; ?>
        <?php if ($years !== ''): ?><span class="aff-years"><?= e($years) ?></span><?php endif; ?>
    </span>
    <?php if ($manage): ?>
        <span class="aff-actions">
            <?php if ($outgoing): // richiesta IN USCITA: annullabile da me (endpoint elimina esistente) ?>
                <form method="post" action="<?= e(url('profilo/affiliazioni/' . $affId . '/elimina')) ?>" class="aff-act" data-async data-async-success="removeCard">
                    <?= csrf_field() ?><input type="hidden" name="return" value="<?= e($return) ?>">
                    <button class="btn btn-ghost btn-sm" type="submit"><?= e(t('affil.action.cancel')) ?></button>
                </form>
            <?php elseif ($pending): ?>
                <form method="post" action="<?= e(url('profilo/affiliazioni/' . $affId . '/conferma')) ?>" class="aff-act" data-async data-async-success="reload">
                    <?= csrf_field() ?><input type="hidden" name="return" value="<?= e($return) ?>">
                    <button class="btn btn-primary btn-sm" type="submit"><?= e(t('affil.action.confirm')) ?></button>
                </form>
                <form method="post" action="<?= e(url('profilo/affiliazioni/' . $affId . '/rifiuta')) ?>" class="aff-act" data-async data-async-success="reload">
                    <?= csrf_field() ?><input type="hidden" name="return" value="<?= e($return) ?>">
                    <button class="btn btn-ghost btn-sm" type="submit"><?= e(t('affil.action.reject')) ?></button>
                </form>
            <?php else: ?>
                <form method="post" action="<?= e(url('profilo/affiliazioni/' . $affId . '/elimina')) ?>" class="aff-act" data-async data-async-success="reload">
                    <?= csrf_field() ?><input type="hidden" name="return" value="<?= e($return) ?>">
                    <button class="icon-btn" type="submit" title="<?= e(t('affil.action.remove')) ?>" aria-label="<?= e(t('affil.action.remove')) ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </form>
            <?php endif; ?>
        </span>
    <?php endif; ?>
</li>
