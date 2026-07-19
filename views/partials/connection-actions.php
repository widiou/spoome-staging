<?php
/**
 * Blocco azioni di connessione sul profilo pubblico (conteggio + stato + bottoni per ogni stato).
 * Sorgente UNICA: render iniziale della pagina profilo e frammento async restituito da
 * ConnectionController (connect/disconnect) → il client fa replaceHtml di [data-conn-block].
 * Tutti i campi dinamici passano da e(); nessun markup costruito da input client.
 * @var array $connection ['count'=>int,'status'=>string,'can_connect'=>bool] @var string $h handle target
 */
$connection = $connection ?? ['count' => 0, 'status' => 'none', 'can_connect' => false];
?>
<div class="conn-actions" data-conn-block>
    <span class="stat stat-static">
        <strong><?= e((string) $connection['count']) ?></strong>
        <span class="stat-label"><?= e(t('connect.connections')) ?></span>
    </span>
    <?php if ($connection['can_connect']): ?>
        <?php if ($connection['status'] === 'connected'): ?>
            <span class="conn-state"><i class="fa-solid fa-user-check" aria-hidden="true"></i> <?= e(t('connect.state.connected')) ?></span>
            <a class="btn btn-primary btn-sm" href="<?= e(url('messaggi/' . $h)) ?>"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> <?= e(t('dm.message_btn')) ?></a>
            <form class="conn-form" method="post" action="<?= e(url('atleti/' . $h . '/disconnetti')) ?>" data-async data-async-success="replaceHtml" data-target="[data-conn-block]"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" data-submit><?= e(t('connect.action.remove')) ?></button></form>
        <?php elseif ($connection['status'] === 'pending_out'): ?>
            <span class="conn-state"><i class="fa-solid fa-clock" aria-hidden="true"></i> <?= e(t('connect.state.pending_out')) ?></span>
            <form class="conn-form" method="post" action="<?= e(url('atleti/' . $h . '/disconnetti')) ?>" data-async data-async-success="replaceHtml" data-target="[data-conn-block]"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" data-submit><?= e(t('connect.action.cancel')) ?></button></form>
        <?php elseif ($connection['status'] === 'pending_in'): ?>
            <form class="conn-form" method="post" action="<?= e(url('atleti/' . $h . '/connetti')) ?>" data-async data-async-success="replaceHtml" data-target="[data-conn-block]"><?= csrf_field() ?><button class="btn btn-primary btn-sm" data-submit><?= e(t('connect.action.accept')) ?></button></form>
            <form class="conn-form" method="post" action="<?= e(url('atleti/' . $h . '/disconnetti')) ?>" data-async data-async-success="replaceHtml" data-target="[data-conn-block]"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" data-submit><?= e(t('connect.action.reject')) ?></button></form>
        <?php else: ?>
            <form class="conn-form" method="post" action="<?= e(url('atleti/' . $h . '/connetti')) ?>" data-async data-async-success="replaceHtml" data-target="[data-conn-block]"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" data-submit><i class="fa-solid fa-user-plus" aria-hidden="true"></i> <?= e(t('connect.action.connect')) ?></button></form>
        <?php endif; ?>
    <?php endif; ?>
</div>
