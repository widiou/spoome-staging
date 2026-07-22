<?php
/**
 * Pagina pubblica del profilo. Server-rendered con dati strutturati JSON-LD (schema.org).
 * @var array $p riga arricchita @var string $location @var string $canonical
 */
use Spoome\Core\View;

$name     = (string) $p['display_name'];
$isOrg    = !empty($p['is_organization']);
$verified = !empty($p['verified_at']);
$attributes = $attributes ?? [];
$sections   = $sections ?? ['attributes' => false, 'skills' => true, 'experiences' => true, 'achievements' => true, 'links' => true, 'roster' => false, 'career' => false];
// P2 affiliazioni: roster (org) · militanza (atleta) · richieste in ingresso (solo per chi gestisce la pagina).
$roster    = $roster ?? [];
$militanza = $militanza ?? [];
$affPending = $affPending ?? [];
$canManageAff = $canManageAff ?? false;
// Vista proprietario (stile Instagram): barra "Modifica" + insight visite + sezione Post.
$canManage    = $canManage ?? false;
$insights     = $insights ?? null;
$profilePosts = $profilePosts ?? [];
$myHandle     = $myHandle ?? '';
$affReturn = 'atleti/' . $p['handle'];
$affSplit = static function (array $rows): array {
    $cur = $past = [];
    foreach ($rows as $r) { if (!empty($r['is_current'])) { $cur[] = $r; } else { $past[] = $r; } }
    return [$cur, $past];
};
// Copy del badge verificato per tipo: "Verificata" (entità ufficiale) vs "Verificato" (persona).
$verifiedLabel = $isOrg ? t('atleti.verified_org') : t('atleti.verified');
// M3 badge "verificato dalla società": DERIVATO (affiliazione confermata verso org verificata). Il service
// lo passa già escludendo lo staff badge (precedenza). Provenance nel tooltip (nome dell'org-ancora primaria),
// escapato all'output. Stato = icona + testo (mai solo colore, niente verde) → a11y.
$clubVerified  = $clubVerified ?? false;
$clubVerifiers = $clubVerifiers ?? [];
$clubVerifiedLabel = $isOrg ? t('atleti.verified_club_org') : t('atleti.verified_club');
$clubVerifierName  = (string) ($clubVerifiers[0]['org_name'] ?? '');
$clubVerifiedTitle = $clubVerifierName !== '' ? t('atleti.verified_club_by', ['org' => $clubVerifierName]) : $clubVerifiedLabel;

// Dati strutturati per la SEO (Person o Organization sportiva).
$ld = [
    '@context' => 'https://schema.org',
    '@type'    => $isOrg ? 'SportsOrganization' : 'Person',
    'name'     => $name,
    'url'      => $canonical,
];
if (!empty($p['headline'])) {
    $ld[$isOrg ? 'slogan' : 'jobTitle'] = $p['headline'];
}
if (!empty($p['bio'])) {
    $ld['description'] = $p['bio'];
}
if (!empty($p['sport_name'])) {
    $ld['sport'] = $p['sport_name'];
}
if (!empty($p['avatar_path'])) {
    $ld['image'] = Spoome\Core\Config::absoluteUrl((string) $p['avatar_path']);
}
if ($location !== '') {
    $ld['address'] = \array_filter([
        '@type'           => 'PostalAddress',
        'addressLocality' => $p['location_city'] ?? null,
        'addressRegion'   => $p['location_region'] ?? null,
        'addressCountry'  => $p['location_country'] ?? null,
    ]);
}
?>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>

<main class="site-main">
    <?php if (!empty($notice)): ?>
        <div class="container"><div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div></div>
    <?php endif; ?>
    <article class="profile<?= !empty($p['cover_path']) ? ' has-cover' : '' ?>">
        <?php if (!empty($p['cover_path'])): ?>
            <div class="profile-cover"><img class="cover-img" src="<?= e(url($p['cover_path'])) ?>" alt="" fetchpriority="high" decoding="async"></div>
        <?php endif; ?>
        <header class="profile-hero">
            <span class="profile-avatar" aria-hidden="true">
                <?php if (!empty($p['avatar_path'])): ?>
                    <img class="avatar-img" src="<?= e(url($p['avatar_path'])) ?>" alt="">
                <?php else: ?>
                    <?= e(initials($name)) ?>
                <?php endif; ?>
            </span>
            <div class="profile-id">
                <h1 class="profile-name">
                    <?= e($name) ?>
                    <?php if ($verified): ?>
                        <span class="profile-verified" title="<?= e($verifiedLabel) ?>"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> <?= e($verifiedLabel) ?></span>
                    <?php elseif ($clubVerified): ?>
                        <span class="profile-verified profile-verified-club" title="<?= e($clubVerifiedTitle) ?>"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> <?= e($clubVerifiedLabel) ?></span>
                    <?php endif; ?>
                </h1>
                <p class="profile-type"><?= e($p['type_label']) ?></p>
                <?php if (!empty($p['headline'])): ?>
                    <p class="profile-headline"><?= e($p['headline']) ?></p>
                <?php endif; ?>
                <ul class="profile-facts">
                    <?php if (!empty($p['sport_name'])): ?>
                        <li><span class="chip chip-sport"><i class="<?= e(sport_icon($p['sport_slug'] ?? null, $p['sport_category'] ?? null)) ?>" aria-hidden="true"></i> <?= e($p['sport_name']) ?></span></li>
                    <?php endif; ?>
                    <?php if ($location !== ''): ?>
                        <li class="profile-loc"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?= e($location) ?></li>
                    <?php endif; ?>
                </ul>

                <?php $claim = $claim ?? ['is_unclaimed' => false, 'authenticated' => false, 'has_profile' => false, 'can_request' => false, 'pending' => false]; ?>
                <?php if ($claim['is_unclaimed']): ?>
                <div class="profile-claim">
                    <div class="profile-claim-head">
                        <i class="fa-solid fa-id-badge" aria-hidden="true"></i>
                        <div>
                            <strong><?= e(t('claim.panel.title')) ?></strong>
                            <span class="muted"><?= e(t('claim.panel.subtitle')) ?></span>
                        </div>
                    </div>
                    <?php if ($claim['pending']): ?>
                        <p class="claim-state"><i class="fa-solid fa-clock" aria-hidden="true"></i> <?= e(t('claim.panel.pending')) ?></p>
                    <?php elseif ($claim['can_request']): ?>
                        <form class="claim-form" method="post" action="<?= e(url('atleti/' . $p['handle'] . '/rivendica')) ?>" data-async data-async-success="reload">
                            <?= csrf_field() ?>
                            <label class="sr-only" for="claim-msg"><?= e(t('claim.panel.msg_label')) ?></label>
                            <textarea class="input" id="claim-msg" name="message" rows="2" maxlength="1000" placeholder="<?= e(t('claim.panel.msg_ph')) ?>"></textarea>
                            <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-hand" aria-hidden="true"></i> <?= e(t('claim.panel.button')) ?></button>
                        </form>
                    <?php elseif (!$claim['authenticated']): ?>
                        <p class="muted"><?= e(t('claim.panel.login')) ?></p>
                        <a class="btn btn-primary btn-sm" href="<?= e(url('registrati/rivendica')) ?>"><?= e(t('claim.panel.register')) ?></a>
                    <?php elseif ($claim['has_profile']): ?>
                        <p class="muted"><?= e(t('claim.panel.has_profile')) ?></p>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <?php $follow = $follow ?? ['count_followers' => 0, 'count_following' => 0, 'authenticated' => false, 'is_own' => false, 'is_following' => false, 'can_follow' => false]; ?>
                <div class="profile-social">
                    <a class="stat" href="<?= e(url('atleti/' . $p['handle'] . '/follower')) ?>">
                        <strong data-follow-followers><?= e((string) $follow['count_followers']) ?></strong>
                        <span class="stat-label"><?= e(t('follow.followers')) ?></span>
                    </a>
                    <a class="stat" href="<?= e(url('atleti/' . $p['handle'] . '/seguiti')) ?>">
                        <strong data-follow-following><?= e((string) $follow['count_following']) ?></strong>
                        <span class="stat-label"><?= e(t('follow.following')) ?></span>
                    </a>
                    <?php if ($follow['can_follow']): ?>
                        <form class="follow-form" method="post"
                              action="<?= e(url('atleti/' . $p['handle'] . ($follow['is_following'] ? '/nonseguire' : '/segui'))) ?>"
                              data-async data-async-success="toggleState updateCount"
                              data-state-key="following"
                              data-toggle-action="<?= e(url('atleti/' . $p['handle'] . '/segui')) ?>|<?= e(url('atleti/' . $p['handle'] . '/nonseguire')) ?>"
                              data-toggle-classes="btn-primary/btn-ghost" data-label-el=".follow-label"
                              data-count-selector="[data-follow-followers]" data-count-scope="document" data-count-key="followers_count">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn <?= $follow['is_following'] ? 'btn-ghost' : 'btn-primary' ?> btn-sm follow-btn" data-label-off="<?= e(t('follow.button.follow')) ?>" data-label-on="<?= e(t('follow.button.following')) ?>">
                                <span class="follow-label"><?= e($follow['is_following'] ? t('follow.button.following') : t('follow.button.follow')) ?></span>
                            </button>
                        </form>
                    <?php elseif ($follow['is_own'] && empty($canManage)): ?>
                        <a class="btn btn-ghost btn-sm" href="<?= e(url('profilo/modifica')) ?>"><?= e(t('follow.edit_own')) ?></a>
                    <?php elseif (!$follow['authenticated']): ?>
                        <a class="btn btn-primary btn-sm" href="<?= e(url('accedi')) ?>"><?= e(t('follow.button.follow')) ?></a>
                    <?php endif; ?>

                    <?php
                    $connection = $connection ?? ['count' => 0, 'status' => 'none', 'can_connect' => false];
                    // Blocco connessione in un partial condiviso: il render iniziale e il frammento async
                    // (replaceHtml dopo connect/disconnect) usano la STESSA sorgente → nessuna divergenza.
                    echo Spoome\Core\View::partial('connection-actions', ['connection' => $connection, 'h' => (string) $p['handle']]);
                    ?>
                </div>
                <?php endif; // /claim vs social ?>
            </div>
        </header>

        <?php if ($canManage): ?>
            <div class="profile-owner">
                <div class="profile-owner-actions">
                    <a class="btn btn-primary btn-sm" href="<?= e(url('profilo/modifica')) ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i> <?= e(t('profile.owner.edit')) ?></a>
                    <button type="button" class="btn btn-ghost btn-sm" data-share-url="<?= e($canonical) ?>" data-copied="<?= e(t('feed.link_copied')) ?>"><i class="fa-solid fa-share-nodes" aria-hidden="true"></i> <?= e(t('profile.owner.share')) ?></button>
                </div>
                <?php if (!empty($insights)): ?>
                    <a class="profile-insight" href="<?= e(url('profilo/modifica')) ?>#visite" title="<?= e(t('pviews.title')) ?>">
                        <span class="profile-insight-num"><?= e((string) $insights['views7d']) ?></span>
                        <span class="profile-insight-lbl"><?= e(t('profile.owner.views7d')) ?></span>
                        <?php if (!empty($insights['recentViewers'])): ?>
                            <span class="faces" aria-hidden="true">
                                <?php foreach (array_slice($insights['recentViewers'], 0, 5) as $rv): ?>
                                    <span class="face"><?php if (!empty($rv['avatar_path'])): ?><img src="<?= e(url($rv['avatar_path'])) ?>" alt="" loading="lazy"><?php else: ?><?= e(initials((string) $rv['display_name'])) ?><?php endif; ?></span>
                                <?php endforeach; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php
        // Nav a sezioni: ancore che funzionano senza JS (progressive). Ogni tab punta all'id
        // della prima sezione corrispondente presente; ne mostriamo la barra solo se ≥ 2 tab.
        $experiences  = $experiences ?? [];
        $achievements = $achievements ?? [];
        $skills       = $skills ?? [];
        $hasInfo   = !empty($p['bio']) || (!empty($sections['attributes']) && !empty($attributes));
        $hasCareer = !empty($sections['experiences']) && !empty($experiences);
        $hasPalm   = !empty($sections['achievements']) && !empty($achievements);
        $hasSkills = !empty($sections['skills']) && !empty($skills);
        // Raccomandazioni: SOLO per le persone (non-org). La sezione appare se ci sono testimonial visibili
        // oppure se il visitatore (connesso) può scriverne uno.
        $recommendations = $recommendations ?? [];
        $canRecommend    = $canRecommend ?? false;
        $hasReco   = !$isOrg && (!empty($recommendations) || $canRecommend);
        $hasPosts  = !empty($profilePosts);
        $hasRoster = !empty($sections['roster']) && !empty($roster);
        $hasAff    = (!empty($sections['career']) && !empty($militanza))
                  || (!empty($sections['org_career']) && !empty($militanza))
                  || ($canManageAff && !empty($affPending));
        $navTabs = [];
        if ($hasInfo)   { $navTabs[] = ['sez-info', 'fa-solid fa-circle-info', t('atleti.tab.info')]; }
        if ($hasCareer) { $navTabs[] = ['sez-carriera', 'fa-solid fa-briefcase', t('atleti.tab.career')]; }
        if ($hasPalm)   { $navTabs[] = ['sez-palmares', 'fa-solid fa-trophy', t('atleti.tab.palmares')]; }
        if ($hasSkills) { $navTabs[] = ['competenze', 'fa-solid fa-ranking-star', t('atleti.tab.skills')]; }
        if ($hasReco)   { $navTabs[] = ['sez-raccomandazioni', 'fa-solid fa-comment-dots', t('atleti.tab.recommendations')]; }
        if ($hasRoster) { $navTabs[] = ['sez-roster', 'fa-solid fa-people-group', t('atleti.tab.roster')]; }
        if ($hasAff)    { $navTabs[] = ['sez-affiliazioni', 'fa-solid fa-handshake-angle', t('atleti.tab.affiliations')]; }
        if ($hasPosts)  { $navTabs[] = ['sez-post', 'fa-solid fa-table-cells', t('atleti.tab.posts')]; }
        ?>
        <?php if (count($navTabs) >= 2): ?>
            <nav class="tabs profile-tabs" aria-label="<?= e(t('atleti.tab.sections')) ?>">
                <?php foreach ($navTabs as $i => $tb): ?>
                    <a class="tab<?= $i === 0 ? ' is-active' : '' ?>" href="#<?= e($tb[0]) ?>"><i class="<?= e($tb[1]) ?>" aria-hidden="true"></i> <?= e($tb[2]) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <?php if (!empty($p['bio'])): ?>
            <section class="profile-section" id="sez-info">
                <h2><?= e(t('atleti.show.about')) ?></h2>
                <p class="profile-bio"><?= nl2br(e($p['bio'])) ?></p>
            </section>
        <?php endif; ?>

        <?php if (!empty($profilePosts)): ?>
            <section class="profile-section profile-posts" id="sez-post">
                <div class="sec-head"><h2><?= e(t('profile.posts.title')) ?></h2></div>
                <ul class="feed-list">
                    <?php foreach ($profilePosts as $it): ?><?= View::partial('feed-item', ['it' => $it, 'myHandle' => $myHandle]) ?><?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if (!empty($sections['attributes']) && !empty($attributes)): ?>
            <section class="profile-section"<?= empty($p['bio']) ? ' id="sez-info"' : '' ?>>
                <h2><?= e(t('atleti.show.info')) ?></h2>
                <dl class="attr-list">
                    <?php foreach ($attributes as $a): ?>
                        <div class="attr-row">
                            <dt><?= e($a['label']) ?></dt>
                            <dd>
                                <?php if ($a['type'] === 'url'): ?>
                                    <a href="<?= e($a['value']) ?>" target="_blank" rel="noopener nofollow ugc"><?= e($a['value']) ?></a>
                                <?php else: ?>
                                    <?= e($a['value']) ?>
                                <?php endif; ?>
                            </dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </section>
        <?php endif; ?>

        <?php
        $skills      = $skills ?? [];
        $canEndorse  = $canEndorse ?? false;
        $endorsedIds = array_flip($endorsedIds ?? []);
        $endorsers   = $endorsers ?? [];
        if (!empty($sections['skills']) && !empty($skills)):
            $totalEndorse = array_sum(array_map(static fn($s) => (int) $s['endorsements_count'], $skills));
            $h = (string) $p['handle'];
        ?>
            <section class="profile-section" id="competenze">
                <div class="sec-head">
                    <h2><?= e(t('skill.section.title')) ?></h2>
                    <span class="sec-count"><?= e(t('skill.section.count', ['skills' => (string) count($skills), 'endorsements' => (string) $totalEndorse])) ?></span>
                </div>
                <ul class="skill-list">
                    <?php foreach ($skills as $s): $sid = (int) $s['id']; $cnt = (int) $s['endorsements_count']; ?>
                        <?php
                        $mine = isset($endorsedIds[$sid]);
                        $faces = $endorsers[$sid] ?? [];
                        $shown = array_slice($faces, 0, 2);
                        $more  = max(0, $cnt - count($shown));
                        ?>
                        <li class="skill<?= $cnt > 0 ? ' is-top' : '' ?>">
                            <div class="skill-main">
                                <span class="skill-name"><?= e($s['label']) ?></span>
                                <?php if ($faces): ?>
                                    <span class="skill-proof">
                                        <span class="faces" aria-hidden="true">
                                            <?php foreach ($shown as $f): ?>
                                                <span class="face">
                                                    <?php if (!empty($f['avatar_path'])): ?><img src="<?= e(url($f['avatar_path'])) ?>" alt=""><?php else: ?><?= e(initials($f['display_name'])) ?><?php endif; ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </span>
                                        <?php $firstName = explode(' ', trim((string) $faces[0]['display_name']))[0]; ?>
                                        <?= e(t('skill.proof.confirmed_by')) ?> <b><?= e($firstName) ?></b><?php if ($more > 0): ?> +<?= e((string) $more) ?><?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <span class="skill-count" title="<?= e(t('skill.count_label')) ?>"><?= e((string) $cnt) ?></span>
                            <?php if ($canEndorse): ?>
                                <form class="endorse-form" method="post"
                                      action="<?= e(url('atleti/' . $h . '/competenze/' . $sid . ($mine ? '/rimuovi' : '/endorsa'))) ?>"
                                      data-async data-async-success="toggleState updateCount"
                                      data-state-key="endorsed"
                                      data-toggle-action="<?= e(url('atleti/' . $h . '/competenze/' . $sid . '/endorsa')) ?>|<?= e(url('atleti/' . $h . '/competenze/' . $sid . '/rimuovi')) ?>"
                                      data-count-selector=".skill-count" data-count-scope=".skill" data-count-key="count" data-count-flag=".skill">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="endorse<?= $mine ? ' is-on' : '' ?>"
                                            data-label-on="<?= e(t('skill.endorse.remove')) ?>" data-label-off="<?= e(t('skill.endorse.add')) ?>"
                                            aria-pressed="<?= $mine ? 'true' : 'false' ?>"
                                            aria-label="<?= e($mine ? t('skill.endorse.remove') : t('skill.endorse.add')) ?>">
                                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if ($hasReco): $rh = (string) $p['handle']; ?>
            <section class="profile-section" id="sez-raccomandazioni">
                <div class="sec-head">
                    <h2><?= e(t('atleti.tab.recommendations')) ?></h2>
                    <?php if (!empty($recommendations)): ?><span class="sec-count"><?= e(t('reco.count', ['count' => (string) count($recommendations)])) ?></span><?php endif; ?>
                </div>
                <?php if ($canRecommend): ?>
                    <details class="reco-write">
                        <summary class="btn btn-ghost btn-sm reco-write-toggle"><i class="fa-solid fa-comment-dots" aria-hidden="true"></i> <?= e(t('reco.write.button')) ?></summary>
                        <form class="reco-form" method="post" action="<?= e(url('atleti/' . $rh . '/raccomanda')) ?>"
                              data-async data-async-success="resetForm toast" data-toast-ok="<?= e(t('reco.write.sent')) ?>">
                            <?= csrf_field() ?>
                            <p class="muted reco-write-hint"><?= e(t('reco.write.hint', ['name' => $name])) ?></p>
                            <label class="sr-only" for="reco-body"><?= e(t('reco.write.body_label')) ?></label>
                            <textarea class="input textarea" id="reco-body" name="body" rows="4" maxlength="1000" required placeholder="<?= e(t('reco.write.body_ph')) ?>"></textarea>
                            <label class="sr-only" for="reco-rel"><?= e(t('reco.write.rel_label')) ?></label>
                            <input class="input" id="reco-rel" name="relationship" maxlength="80" placeholder="<?= e(t('reco.write.rel_ph')) ?>">
                            <div class="form-actions"><button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> <?= e(t('reco.write.submit')) ?></button></div>
                        </form>
                    </details>
                <?php endif; ?>
                <?php if (!empty($recommendations)): ?>
                    <ul class="reco-list">
                        <?php foreach ($recommendations as $r): $ah = (string) ($r['author_handle'] ?? ''); $aname = (string) ($r['author_display_name'] ?? ''); $ahref = url('atleti/' . $ah); ?>
                            <li class="reco-item">
                                <a class="pv-avatar reco-avatar" href="<?= e($ahref) ?>">
                                    <?php if (!empty($r['author_avatar_path'])): ?><img src="<?= e(url($r['author_avatar_path'])) ?>" alt="" loading="lazy"><?php else: ?><?= e(initials($aname)) ?><?php endif; ?>
                                </a>
                                <div class="reco-main">
                                    <div class="reco-head">
                                        <a class="reco-author" href="<?= e($ahref) ?>"><?= e($aname) ?></a>
                                        <?php if (!empty($r['relationship'])): ?><span class="reco-rel"><?= e($r['relationship']) ?></span><?php endif; ?>
                                    </div>
                                    <p class="reco-text"><?= nl2br(e((string) $r['body'])) ?></p>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php elseif ($canRecommend): ?>
                    <p class="muted"><?= e(t('reco.empty_can_write', ['name' => $name])) ?></p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (!empty($sections['experiences']) && !empty($experiences)): ?>
            <section class="profile-section" id="sez-carriera">
                <h2><?= e(t('public.experiences')) ?></h2>
                <ul class="cv-list">
                    <?php foreach ($experiences as $x): ?>
                        <li class="cv-item">
                            <div class="cv-head">
                                <strong><?= e($x['role']) ?></strong>
                                <span class="cv-org"><?= e($x['org_name']) ?></span>
                            </div>
                            <?php
                            $yr = trim((string) ($x['start_year'] ?? ''));
                            if (!empty($x['is_current'])) { $yr .= ($yr !== '' ? '–' : '') . t('profile.exp.present'); }
                            elseif (!empty($x['end_year'])) { $yr .= '–' . $x['end_year']; }
                            $meta = array_filter([$x['location'] ?? '', $yr]);
                            ?>
                            <?php if ($meta): ?><span class="cv-meta"><?= e(implode(' · ', $meta)) ?></span><?php endif; ?>
                            <?php if (!empty($x['description'])): ?><p class="cv-desc"><?= nl2br(e($x['description'])) ?></p><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php $affId = ' id="sez-affiliazioni"'; // ancora "Affiliazioni": la consuma la prima sezione presente ?>
        <?php if ($canManageAff && !empty($affPending)): ?>
            <section class="profile-section"<?= $affId ?><?php $affId = ''; ?>>
                <h2><?= e(t('affil.pending.title')) ?></h2>
                <p class="muted aff-hint"><?= e(t('affil.pending.hint')) ?></p>
                <ul class="pv-list aff-list">
                    <?php foreach ($affPending as $a): ?>
                        <?= View::partial('affiliation-card', ['a' => $a, 'manage' => true, 'return' => $affReturn]) ?>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if (!empty($sections['roster'])): [$rCur, $rPast] = $affSplit($roster); ?>
            <section class="profile-section" id="sez-roster">
                <h2><?= e(($p['type_key'] ?? '') === 'federazione' ? t('affil.affiliates.title') : t('affil.roster.title')) ?></h2>
                <?php if (empty($roster)): ?>
                    <p class="muted"><?= e(t('affil.roster.empty')) ?></p>
                <?php else: ?>
                    <?php if ($rCur): ?>
                        <p class="aff-subhead"><?= e(t('affil.roster.current')) ?></p>
                        <ul class="pv-list aff-list">
                            <?php foreach ($rCur as $a): ?><?= View::partial('affiliation-card', ['a' => $a, 'manage' => $canManageAff, 'return' => $affReturn]) ?><?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($rPast): ?>
                        <p class="aff-subhead"><?= e(t('affil.roster.past')) ?></p>
                        <ul class="pv-list aff-list">
                            <?php foreach ($rPast as $a): ?><?= View::partial('affiliation-card', ['a' => $a, 'manage' => $canManageAff, 'return' => $affReturn]) ?><?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (!empty($sections['career']) && !empty($militanza)): [$cCur, $cPast] = $affSplit($militanza); ?>
            <section class="profile-section"<?= $affId ?><?php $affId = ''; ?>>
                <h2><?= e(t('affil.career.title')) ?></h2>
                <?php if ($cCur): ?>
                    <p class="aff-subhead"><?= e(t('affil.career.current')) ?></p>
                    <ul class="pv-list aff-list">
                        <?php foreach ($cCur as $a): ?><?= View::partial('affiliation-card', ['a' => $a, 'manage' => false]) ?><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($cPast): ?>
                    <p class="aff-subhead"><?= e(t('affil.career.past')) ?></p>
                    <ul class="pv-list aff-list">
                        <?php foreach ($cPast as $a): ?><?= View::partial('affiliation-card', ['a' => $a, 'manage' => false]) ?><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (!empty($sections['org_career']) && !empty($militanza)): [$oCur, $oPast] = $affSplit($militanza); ?>
            <section class="profile-section"<?= $affId ?><?php $affId = ''; ?>>
                <h2><?= e(t('affil.orgcareer.title')) ?></h2>
                <?php if ($oCur): ?>
                    <p class="aff-subhead"><?= e(t('affil.orgcareer.current')) ?></p>
                    <ul class="pv-list aff-list">
                        <?php foreach ($oCur as $a): ?><?= View::partial('affiliation-card', ['a' => $a, 'manage' => false]) ?><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($oPast): ?>
                    <p class="aff-subhead"><?= e(t('affil.orgcareer.past')) ?></p>
                    <ul class="pv-list aff-list">
                        <?php foreach ($oPast as $a): ?><?= View::partial('affiliation-card', ['a' => $a, 'manage' => false]) ?><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (!empty($sections['achievements']) && !empty($achievements)): ?>
            <section class="profile-section" id="sez-palmares">
                <h2><?= e(t('public.achievements')) ?></h2>
                <ul class="cv-list">
                    <?php foreach ($achievements as $a): ?>
                        <li class="cv-item">
                            <div class="cv-head">
                                <strong><?= e($a['title']) ?></strong>
                                <?php if (!empty($a['year'])): ?><span class="cv-year"><?= e($a['year']) ?></span><?php endif; ?>
                            </div>
                            <?php if (!empty($a['description'])): ?><span class="cv-meta"><?= e($a['description']) ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if (!empty($sections['links']) && !empty($links)): ?>
            <section class="profile-section">
                <h2><?= e(t('public.links')) ?></h2>
                <ul class="link-list">
                    <?php foreach ($links as $l): ?>
                        <li>
                            <a class="link-chip" href="<?= e($l['url']) ?>"<?= str_starts_with((string) $l['url'], 'mailto:') ? '' : ' target="_blank" rel="noopener nofollow ugc"' ?>>
                                <i class="<?= e(link_icon($l['kind'])) ?>" aria-hidden="true"></i>
                                <span><?= e($l['label'] ?: link_kind_label($l['kind'])) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <p class="profile-back"><a href="<?= e(url('atleti')) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> <?= e(t('atleti.show.back_to_directory')) ?></a></p>
    </article>
</main>
