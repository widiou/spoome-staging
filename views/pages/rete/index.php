<?php
/**
 * Pagina Rete: suggerimenti 2° grado + richieste di connessione in entrata + connessioni accettate.
 * @var array $suggestions @var int|null $meSportId @var string|null $meCity
 * @var array $connections @var int $connectionsTotal @var array $requests @var int $requestsTotal @var array|null $notice
 */
use Spoome\Core\View;
?>
<main class="site-main">
    <section class="container">
        <header class="directory-head">
            <h1><?= e(t('nav.network')) ?></h1>
        </header>

        <?php if (!empty($notice)): ?>
            <div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div>
        <?php endif; ?>

        <?php if (!empty($suggestions)): ?>
            <section class="net-section">
                <h2><?= e(t('suggest.title')) ?></h2>
                <div class="pcard-grid" data-suggest-grid>
                    <?php foreach ($suggestions as $s): ?>
                        <?php
                        $sname   = (string) $s['display_name'];
                        $isOrg   = !empty($s['is_organization']);
                        $mutual  = isset($s['mutual_count']) ? (int) $s['mutual_count'] : 0;
                        // Riga "in comune": connessioni in comune (2° grado) o affinità (fallback).
                        if ($mutual > 0) {
                            $commonText = $mutual === 1
                                ? t('suggest.mutual_one')
                                : t('suggest.mutual', ['n' => (string) $mutual]);
                        } else {
                            $parts = [];
                            if ($meSportId !== null && (int) ($s['sport_id'] ?? 0) === (int) $meSportId) {
                                $parts[] = t('suggest.same_sport');
                            }
                            if ($meCity !== null && !empty($s['location_city']) && $s['location_city'] === $meCity) {
                                $parts[] = (string) $s['location_city'];
                            }
                            $commonText = $parts ? implode(t('suggest.affinity_sep'), $parts) : '';
                        }
                        ?>
                        <article class="sug-card" data-suggest-card data-handle="<?= e($s['handle']) ?>">
                            <div class="sug-top">
                                <a class="sug-avatar<?= $isOrg ? ' is-org' : '' ?>" href="<?= e(profile_url($s)) ?>" aria-hidden="true" tabindex="-1">
                                    <?php if (!empty($s['avatar_path'])): ?>
                                        <img class="avatar-img" src="<?= e(url($s['avatar_path'])) ?>" alt="" loading="lazy">
                                    <?php else: ?>
                                        <?= e(initials($sname)) ?>
                                    <?php endif; ?>
                                </a>
                                <div class="sug-id">
                                    <a class="sug-name" href="<?= e(profile_url($s)) ?>">
                                        <?= e($sname) ?>
                                        <?php if (!empty($s['verified_at'])): ?><i class="fa-solid fa-circle-check sug-vf" title="<?= e(t('atleti.verified')) ?>" aria-hidden="true"></i><?php endif; ?>
                                    </a>
                                    <?php
                                    $headline = (string) ($s['headline'] ?? '');
                                    if ($headline === '' && !empty($s['sport_name'])) {
                                        $headline = (string) $s['type_label'] . ' · ' . (string) $s['sport_name'];
                                    } elseif ($headline === '') {
                                        $headline = (string) $s['type_label'];
                                    }
                                    ?>
                                    <div class="sug-head"><?= e($headline) ?></div>
                                    <?php if ($commonText !== ''): ?>
                                        <div class="sug-common"><i class="fa-solid fa-user-group" aria-hidden="true"></i><?= e($commonText) ?></div>
                                    <?php endif; ?>
                                </div>
                                <form class="sug-x" method="post" action="<?= e(url('rete/suggerimenti/' . $s['handle'] . '/ignora')) ?>" data-async data-async-success="removeCard" data-target="[data-suggest-card]">
                                    <?= csrf_field() ?>
                                    <button class="sug-dismiss" type="submit" data-submit aria-label="<?= e(t('suggest.action.dismiss')) ?>" title="<?= e(t('suggest.action.dismiss')) ?>">
                                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                    </button>
                                </form>
                            </div>
                            <div class="sug-actions">
                                <form method="post" action="<?= e(url('atleti/' . $s['handle'] . '/connetti')) ?>" data-async data-async-handler="connectSuggest" data-label-sent="<?= e(t('connect.state.pending_out')) ?>">
                                    <?= csrf_field() ?><input type="hidden" name="return" value="rete">
                                    <button class="btn btn-connect btn-block" type="submit" data-submit>
                                        <i class="fa-solid fa-user-plus" aria-hidden="true"></i> <?= e(t('suggest.action.connect')) ?>
                                    </button>
                                </form>
                                <form method="post" action="<?= e(url('rete/suggerimenti/' . $s['handle'] . '/ignora')) ?>" data-async data-async-success="removeCard" data-target="[data-suggest-card]">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-ghost btn-sm" type="submit" data-submit><?= e(t('suggest.action.dismiss')) ?></button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($requests): ?>
            <section class="net-section">
                <h2><?= e(t('connect.requests_title', ['n' => (string) $requestsTotal])) ?></h2>
                <ul class="req-list">
                    <?php foreach ($requests as $r): ?>
                        <li class="req-item" data-req-item>
                            <a class="req-id" href="<?= e(profile_url($r)) ?>">
                                <span class="pcard-avatar" aria-hidden="true">
                                    <?php if (!empty($r['avatar_path'])): ?>
                                        <img class="avatar-img" src="<?= e(url($r['avatar_path'])) ?>" alt="" loading="lazy">
                                    <?php else: ?>
                                        <?= e(initials($r['display_name'])) ?>
                                    <?php endif; ?>
                                </span>
                                <span class="req-name"><?= e($r['display_name']) ?><span class="req-type"><?= e($r['type_label']) ?></span></span>
                            </a>
                            <div class="req-actions">
                                <form method="post" action="<?= e(url('atleti/' . $r['handle'] . '/connetti')) ?>" data-async data-async-success="removeCard" data-target="[data-req-item]">
                                    <?= csrf_field() ?><input type="hidden" name="return" value="rete">
                                    <button class="btn btn-primary btn-sm" data-submit><?= e(t('connect.action.accept')) ?></button>
                                </form>
                                <form method="post" action="<?= e(url('atleti/' . $r['handle'] . '/disconnetti')) ?>" data-async data-async-success="removeCard" data-target="[data-req-item]">
                                    <?= csrf_field() ?><input type="hidden" name="return" value="rete">
                                    <button class="btn btn-ghost btn-sm" data-submit><?= e(t('connect.action.reject')) ?></button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <section class="net-section">
            <h2><?= e(t('connect.connections_title', ['n' => (string) $connectionsTotal])) ?></h2>
            <?php if (!$connections): ?>
                <div class="empty-state">
                    <p><?= e(t('connect.empty')) ?></p>
                    <a class="btn btn-accent" href="<?= e(url('atleti')) ?>"><?= e(t('connect.discover')) ?></a>
                </div>
            <?php else: ?>
                <div class="pcard-grid">
                    <?php foreach ($connections as $p): ?>
                        <?= View::partial('profile-card', ['p' => $p]) ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </section>
</main>
