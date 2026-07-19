<?php
/**
 * Card di un profilo nella directory. @var array $p riga arricchita (ProfileRepository::SELECT_ENRICHED).
 */
$name     = (string) $p['display_name'];
$location = \implode(', ', \array_filter([$p['location_city'] ?? null, $p['location_region'] ?? null]));
$verified = !empty($p['verified_at']);
?>
<a class="pcard" href="<?= e(profile_url($p)) ?>">
    <span class="pcard-avatar" aria-hidden="true">
        <?php if (!empty($p['avatar_path'])): ?>
            <img class="avatar-img" src="<?= e(url($p['avatar_path'])) ?>" alt="" loading="lazy">
        <?php else: ?>
            <?= e(initials($name)) ?>
        <?php endif; ?>
    </span>
    <span class="pcard-body">
        <span class="pcard-name">
            <?= e($name) ?>
            <?php if ($verified): ?><i class="fa-solid fa-circle-check pcard-badge" title="<?= e(t('atleti.verified')) ?>" aria-hidden="true"></i><?php endif; ?>
        </span>
        <span class="pcard-type"><?= e($p['type_label']) ?></span>
        <?php if (!empty($p['headline'])): ?>
            <span class="pcard-headline"><?= e($p['headline']) ?></span>
        <?php endif; ?>
        <span class="pcard-meta">
            <?php if (!empty($p['sport_name'])): ?><span class="chip chip-sport"><i class="<?= e(sport_icon($p['sport_slug'] ?? null, $p['sport_category'] ?? null)) ?>" aria-hidden="true"></i> <?= e($p['sport_name']) ?></span><?php endif; ?>
            <?php if ($location !== ''): ?><span class="pcard-loc"><?= e($location) ?></span><?php endif; ?>
        </span>
    </span>
</a>
