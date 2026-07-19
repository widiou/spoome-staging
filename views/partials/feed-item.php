<?php
/**
 * Un elemento della timeline (post o attività). Sorgente UNICA: usata dal render iniziale del feed
 * e dal frammento async del composer (prepend del nuovo post) → escaping via e() su ogni campo.
 * Post = layout mobile stile Instagram (header · media edge-to-edge · azioni · like · commenti).
 * @var array $it elemento presentato (FeedPresenter::item) @var string $myHandle handle dell'utente corrente
 */
$kind = $it['kind'];
?>
<?php if ($kind === 'news'):
    // News di settore (RSS federazione/organismo), attribuita alla pagina fonte. Read-only, apre esterno.
    $n = $it['news']; $org = $it['org'];
    $orgUrl = ($org['handle'] ?? '') !== '' ? profile_url($org) : null;
?>
    <li class="feed-item feed-news" id="<?= e((string) $it['id']) ?>">
        <div class="post-head news-head">
            <?php if ($orgUrl): ?><a class="post-avatar" href="<?= e($orgUrl) ?>" aria-hidden="true" tabindex="-1"><?php else: ?><span class="post-avatar" aria-hidden="true"><?php endif; ?>
                <?php if (!empty($org['avatar_path'])): ?><img class="avatar-img" src="<?= e(url($org['avatar_path'])) ?>" alt="" loading="lazy"><?php else: ?><i class="fa-solid fa-newspaper" aria-hidden="true"></i><?php endif; ?>
            <?= $orgUrl ? '</a>' : '</span>' ?>
            <div class="post-id">
                <span class="post-name">
                    <span class="news-via"><?= e(t('news.via')) ?></span>
                    <?php if ($orgUrl): ?><a class="post-name-link" href="<?= e($orgUrl) ?>"><?= e($org['display_name']) ?></a><?php else: ?><?= e($org['display_name']) ?><?php endif; ?>
                    <?php if (!empty($org['verified'])): ?><span class="post-verified" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></span><?php endif; ?>
                </span>
                <span class="post-sub">
                    <?php if (!empty($n['sport'])): ?><span class="news-sport"><?= e($n['sport']) ?></span><span class="post-sub-sep" aria-hidden="true">·</span><?php endif; ?>
                    <?php if (!empty($it['created_at'])): ?><time class="post-sub-time"><?= e(time_ago((string) $it['created_at'])) ?></time><?php endif; ?>
                </span>
            </div>
            <span class="news-tag"><?= e(t('news.tag')) ?></span>
        </div>
        <a class="news-link" href="<?= e($n['url']) ?>" target="_blank" rel="noopener noreferrer nofollow" aria-label="<?= e(t('news.open') . ': ' . $n['title']) ?>">
            <?php /* Immagine esterna non renderizzata: bloccata dalla CSP (img-src 'self'). image_url resta
                     salvata per un futuro instradamento dall'image-proxy firmato same-origin. */ ?>
            <span class="news-body">
                <span class="news-title"><?= e($n['title']) ?></span>
                <?php if (!empty($n['summary'])): ?><span class="news-summary"><?= e($n['summary']) ?></span><?php endif; ?>
                <span class="news-src"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> <?= e($n['source']) ?></span>
            </span>
        </a>
    </li>
<?php else:
$a = $it['author'];
$isPost = $kind === 'post';
?>
<?php if (!$isPost): ?>
    <li class="feed-item feed-activity">
        <a class="feed-avatar" href="<?= e(profile_url($a)) ?>" aria-hidden="true">
            <?php if (!empty($a['avatar_url'])): ?>
                <img class="avatar-img" src="<?= e($a['avatar_url']) ?>" alt="" loading="lazy">
            <?php else: ?>
                <?= e(initials($a['display_name'])) ?>
            <?php endif; ?>
        </a>
        <div class="feed-main">
            <p class="feed-head">
                <a class="feed-author" href="<?= e(profile_url($a)) ?>"><?= e($a['display_name']) ?></a>
                <span class="feed-action"><?= e(t('activity.' . $it['activity']['type'], ['meta' => (string) $it['activity']['meta']])) ?></span>
                <span class="feed-time"><?= e(time_ago((string) $it['created_at'])) ?></span>
            </p>
        </div>
    </li>
<?php else:
    // Riga secondaria (ruolo · club/luogo): costruita dai campi profilo disponibili.
    $subA = $a['headline'] ?: ($a['type']['label'] ?? '');
    $subB = $a['location'] ?: ($a['sport']['name'] ?? '');
    $lp       = $it['link_preview'] ?? null;
    $textTrim = isset($it['text']) ? trim((string) $it['text']) : '';
    $hasText  = $textTrim !== '';
    // Post "solo-link": il corpo è unicamente l'URL già reso nella card di anteprima → non stampare l'URL grezzo.
    if ($hasText && $lp !== null && preg_match('~^https?://\S+$~i', $textTrim)) {
        $hasText = false;
    }
    $isMine  = $a['handle'] === $myHandle;
?>
    <li class="feed-item feed-post" id="post-<?= e((string) $it['id']) ?>" data-post-card>
        <!-- header -->
        <div class="post-head">
            <a class="post-avatar" href="<?= e(profile_url($a)) ?>" aria-hidden="true" tabindex="-1">
                <?php if (!empty($a['avatar_url'])): ?>
                    <img class="avatar-img" src="<?= e($a['avatar_url']) ?>" alt="" loading="lazy">
                <?php else: ?>
                    <?= e(initials($a['display_name'])) ?>
                <?php endif; ?>
            </a>
            <div class="post-id">
                <span class="post-name">
                    <a class="post-name-link" href="<?= e(profile_url($a)) ?>"><?= e($a['display_name']) ?></a>
                    <?php if (!empty($a['verified'])): ?>
                        <span class="post-verified" title="<?= e(t('feed.verified')) ?>" aria-label="<?= e(t('feed.verified')) ?>"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
                    <?php endif; ?>
                </span>
                <span class="post-sub">
                    <?php if ($subA !== ''): ?><span class="post-sub-role"><?= e($subA) ?></span><?php endif; ?>
                    <?php if ($subB !== ''): ?><span class="post-sub-sep" aria-hidden="true">·</span><?= e($subB) ?><?php endif; ?>
                    <span class="post-sub-sep" aria-hidden="true">·</span><time class="post-sub-time"><?= e(time_ago((string) $it['created_at'])) ?></time>
                </span>
            </div>
            <?php if ($isMine): ?>
                <details class="post-menu">
                    <summary class="post-menu-btn" aria-label="<?= e(t('feed.menu')) ?>"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></summary>
                    <div class="post-menu-pop" role="menu">
                        <form class="del-form" method="post" action="<?= e(url('feed/post/' . $it['id'] . '/elimina')) ?>"
                              data-async data-async-success="removeCard" data-target="[data-post-card]"
                              data-async-confirm="<?= e(t('feed.delete_confirm')) ?>">
                            <?= csrf_field() ?>
                            <button class="post-menu-item post-menu-danger" type="submit" role="menuitem">
                                <i class="fa-solid fa-trash-can" aria-hidden="true"></i> <?= e(t('feed.delete')) ?>
                            </button>
                        </form>
                    </div>
                </details>
            <?php endif; ?>
        </div>

        <!-- caption (nome autore + testo, troncata con "… altro") -->
        <?php if ($hasText): ?>
            <div class="post-caption" data-caption>
                <p class="post-caption-body">
                    <a class="post-caption-name" href="<?= e(profile_url($a)) ?>"><?= e($a['display_name']) ?></a>
                    <span class="post-caption-text"><?= nl2br(e($it['text'])) ?></span>
                </p>
                <button type="button" class="post-caption-more" data-caption-more hidden><?= e(t('feed.more')) ?></button>
            </div>
        <?php endif; ?>

        <!-- media edge-to-edge = link card / video del post ($lp calcolato in testa) -->
        <?php if ($lp): ?>
            <div class="post-media">
                <?php if (($lp['type'] ?? 'link') === 'video' && !empty($lp['embed_url'])): ?>
                    <div class="link-video" data-link-embed
                         data-embed-url="<?= e($lp['embed_url']) ?>"
                         data-embed-title="<?= e($lp['title'] ?? $lp['provider'] ?? '') ?>">
                        <a class="link-video-poster" href="<?= e($lp['url']) ?>" target="_blank" rel="noopener noreferrer nofollow">
                            <?php if (!empty($lp['image'])): ?>
                                <img src="<?= e($lp['image']) ?>" alt="" loading="lazy">
                            <?php endif; ?>
                            <span class="link-play" aria-label="<?= e(t('link.play')) ?>"><i class="fa-solid fa-play" aria-hidden="true"></i></span>
                        </a>
                        <div class="link-video-meta">
                            <?php if (!empty($lp['provider'])): ?><span class="link-provider"><?= e($lp['provider']) ?></span><?php endif; ?>
                            <?php if (!empty($lp['title'])): ?><span class="link-title"><?= e($lp['title']) ?></span><?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <a class="link-card" href="<?= e($lp['url']) ?>" target="_blank" rel="noopener noreferrer nofollow">
                        <?php if (!empty($lp['image'])): ?>
                            <span class="link-card-media"><img src="<?= e($lp['image']) ?>" alt="" loading="lazy"></span>
                        <?php endif; ?>
                        <span class="link-card-body">
                            <span class="link-card-site"><i class="fa-solid fa-link" aria-hidden="true"></i> <?= e($lp['site_name'] ?: ($lp['domain'] ?? '')) ?></span>
                            <?php if (!empty($lp['title'])): ?><span class="link-card-title"><?= e($lp['title']) ?></span><?php endif; ?>
                            <?php if (!empty($lp['description'])): ?><span class="link-card-desc"><?= e($lp['description']) ?></span><?php endif; ?>
                        </span>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- barra azioni: like · commenta · condividi | salva -->
        <div class="post-actions">
            <form class="like-form" method="post" action="<?= e(url('feed/post/' . $it['id'] . '/like')) ?>"
                  data-async data-async-success="toggleState updateCount"
                  data-state-key="liked" data-count-selector="[data-like-count]" data-count-scope=".feed-item"
                  data-count-reveal=".post-likes" data-count-key="count">
                <?= csrf_field() ?>
                <button type="submit" class="post-act like-btn<?= $it['liked'] ? ' is-on' : '' ?>" aria-pressed="<?= $it['liked'] ? 'true' : 'false' ?>" aria-label="<?= e(t('feed.like')) ?>">
                    <i class="fa-solid fa-heart" aria-hidden="true"></i>
                </button>
            </form>
            <button type="button" class="post-act" data-comments-toggle="<?= e((string) $it['id']) ?>" aria-label="<?= e(t('feed.comment')) ?>">
                <i class="fa-solid fa-comment" aria-hidden="true"></i>
            </button>
            <button type="button" class="post-act" data-share-url="<?= e(url('feed')) ?>#post-<?= e((string) $it['id']) ?>" data-copied="<?= e(t('feed.link_copied')) ?>" aria-label="<?= e(t('feed.share')) ?>">
                <i class="fa-solid fa-share-nodes" aria-hidden="true"></i>
            </button>
            <span class="post-act-spacer"></span>
            <button type="button" class="post-act post-act-save" disabled aria-disabled="true" title="<?= e(t('feed.save_soon')) ?>" aria-label="<?= e(t('feed.save')) ?>">
                <i class="fa-solid fa-bookmark" aria-hidden="true"></i>
            </button>
        </div>

        <!-- riga "Mi piace" (contatore) -->
        <p class="post-likes"<?= (int) $it['likes_count'] > 0 ? '' : ' hidden' ?>>
            <b data-like-count><?= e((string) $it['likes_count']) ?></b> <?= e(t('feed.like')) ?>
        </p>

        <!-- commenti + timestamp + composer inline -->
        <div class="post-comments" data-comments-for="<?= e((string) $it['id']) ?>">
            <button type="button" class="post-comments-toggle" data-comments-toggle="<?= e((string) $it['id']) ?>"<?= (int) $it['comments_count'] > 0 ? '' : ' hidden' ?>>
                <?= e(t('feed.view_comments')) ?> <span class="comment-count" data-comment-count="<?= e((string) $it['id']) ?>"><?= e((string) $it['comments_count']) ?></span> <?= e(t('feed.comments_word')) ?>
            </button>
            <ul class="comment-list" data-comment-list="<?= e((string) $it['id']) ?>">
                <?php foreach ($it['comments'] as $c):
                    $canDel = $c['handle'] === $myHandle || $a['handle'] === $myHandle || is_admin(); ?>
                    <li class="comment" data-comment-item>
                        <a class="comment-author" href="<?= e(profile_url($c)) ?>"><?= e($c['display_name']) ?></a>
                        <span class="comment-body"><?= nl2br(e((string) $c['body'])) ?></span>
                        <span class="comment-time"><?= e(time_ago((string) $c['created_at'])) ?></span>
                        <?php if ($canDel): ?>
                            <form class="comment-del" method="post" action="<?= e(url('feed/commento/' . $c['id'] . '/elimina')) ?>"
                                  data-async data-async-success="updateCount removeCard" data-target="[data-comment-item]"
                                  data-count-selector="[data-comment-count]" data-count-scope=".feed-item" data-count-delta="-1">
                                <?= csrf_field() ?>
                                <button class="icon-btn icon-btn-sm" title="<?= e(t('feed.delete')) ?>" aria-label="<?= e(t('feed.delete')) ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <span class="post-ts"><?= e(time_ago((string) $it['created_at'])) ?></span>
            <form class="comment-form" method="post" data-async data-async-handler="comment" data-post="<?= e((string) $it['id']) ?>" action="<?= e(url('feed/post/' . $it['id'] . '/commenta')) ?>">
                <?= csrf_field() ?>
                <input class="post-comment-input" type="text" name="body" maxlength="1000" placeholder="<?= e(t('feed.comment_ph')) ?>" aria-label="<?= e(t('feed.comment_ph')) ?>" required>
                <button class="post-publish" type="submit" data-submit><?= e(t('feed.compose.submit')) ?></button>
            </form>
        </div>
    </li>
<?php endif; ?>
<?php endif; // /news vs (activity|post) ?>
