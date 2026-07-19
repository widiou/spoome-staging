<?php
/**
 * Directory pubblica dei profili. Server-rendered (SEO). Filtri per tipo e sport, paginazione.
 * @var array $items @var int $total @var array $types @var array $sports
 * @var string $filterType @var string $filterSport @var string $filterQuery @var int $page @var int $pages
 */
use Spoome\Core\View;

/** Costruisce l'URL della directory con i filtri correnti (i vuoti vengono omessi). */
$link = static function (array $over = []) use ($filterType, $filterSport, $filterQuery): string {
    $params = \array_filter([
        'q'      => $over['q']      ?? $filterQuery,
        'tipo'   => $over['tipo']   ?? $filterType,
        'sport'  => $over['sport']  ?? $filterSport,
        'pagina' => $over['pagina'] ?? null,
    ], static fn($v) => $v !== null && $v !== '');
    $qs = $params ? '?' . \http_build_query($params) : '';
    return url('atleti') . $qs;
};

// Raggruppa gli sport per categoria per gli <optgroup>.
$sportsByCat = [];
foreach ($sports as $s) {
    $sportsByCat[$s['category']][] = $s;
}
?>
<main class="site-main">
    <section class="container directory">
        <header class="directory-head">
            <h1><?= e(t('atleti.index.title')) ?></h1>
            <p class="directory-sub"><?= e(t('atleti.index.subtitle')) ?></p>
        </header>

        <form class="search-bar" method="get" action="<?= e(url('atleti')) ?>" role="search">
            <?php if ($filterType !== ''): ?><input type="hidden" name="tipo" value="<?= e($filterType) ?>"><?php endif; ?>
            <?php if ($filterSport !== ''): ?><input type="hidden" name="sport" value="<?= e($filterSport) ?>"><?php endif; ?>
            <label class="sr-only" for="q"><?= e(t('atleti.search.label')) ?></label>
            <div class="search-input">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input class="input" type="search" id="q" name="q" maxlength="80" value="<?= e($filterQuery) ?>" placeholder="<?= e(t('atleti.search.placeholder')) ?>" data-suggest autocomplete="off" aria-autocomplete="list">
            </div>
            <button class="btn btn-primary" type="submit"><?= e(t('atleti.search.submit')) ?></button>
            <?php if ($filterQuery !== ''): ?>
                <a class="btn btn-ghost" href="<?= e($link(['q' => ''])) ?>"><?= e(t('atleti.search.clear')) ?></a>
            <?php endif; ?>
        </form>

        <div class="directory-filters">
            <nav class="filter-chips" aria-label="<?= e(t('atleti.filter.by_type')) ?>">
                <a class="chip<?= $filterType === '' ? ' is-active' : '' ?>" href="<?= e($link(['tipo' => '', 'sport' => $filterSport])) ?>"><?= e(t('atleti.filter.all')) ?></a>
                <?php foreach ($types as $tp): ?>
                    <a class="chip<?= $filterType === $tp['key'] ? ' is-active' : '' ?>"
                       href="<?= e($link(['tipo' => $tp['key']])) ?>"><?= e($tp['label']) ?></a>
                <?php endforeach; ?>
            </nav>

            <form class="filter-sport" method="get" action="<?= e(url('atleti')) ?>">
                <?php if ($filterType !== ''): ?><input type="hidden" name="tipo" value="<?= e($filterType) ?>"><?php endif; ?>
                <?php if ($filterQuery !== ''): ?><input type="hidden" name="q" value="<?= e($filterQuery) ?>"><?php endif; ?>
                <label class="sr-only" for="sport"><?= e(t('atleti.filter.by_sport')) ?></label>
                <select class="select" id="sport" name="sport" data-autosubmit>
                    <option value=""><?= e(t('atleti.filter.all_sports')) ?></option>
                    <?php foreach ($sportsByCat as $cat => $list): ?>
                        <optgroup label="<?= e($cat) ?>">
                            <?php foreach ($list as $s): ?>
                                <option value="<?= e($s['slug']) ?>"<?= $filterSport === $s['slug'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="btn btn-ghost" type="submit"><?= e(t('atleti.filter.apply')) ?></button></noscript>
            </form>
        </div>

        <?php $discovery = $discovery ?? []; if (!empty($discovery)): ?>
            <?php foreach ($discovery as $sec): ?>
                <section class="disco-section">
                    <div class="sec-head">
                        <h2><?= e($sec['label']) ?></h2>
                        <a class="disco-all" href="<?= e($link(['tipo' => $sec['key'], 'sport' => '', 'q' => ''])) ?>"><?= e(t('atleti.discovery.see_all')) ?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                    </div>
                    <div class="pcard-grid">
                        <?php foreach ($sec['items'] as $p): ?><?= View::partial('profile-card', ['p' => $p]) ?><?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php else: ?>
        <p class="directory-count"><?= e(t('atleti.index.count', ['n' => (string) $total])) ?></p>

        <?php if ($items === []): ?>
            <div class="empty-state">
                <p><?= e(t('atleti.index.empty')) ?></p>
                <a class="btn btn-accent" href="<?= e(url('registrati')) ?>"><?= e(t('atleti.index.empty_cta')) ?></a>
            </div>
        <?php else: ?>
            <div class="pcard-grid">
                <?php foreach ($items as $p): ?>
                    <?= View::partial('profile-card', ['p' => $p]) ?>
                <?php endforeach; ?>
            </div>

            <?php if ($pages > 1): ?>
                <nav class="pagination" aria-label="<?= e(t('atleti.pagination.label')) ?>">
                    <?php if ($page > 1): ?>
                        <a class="btn btn-ghost" rel="prev" href="<?= e($link(['pagina' => $page - 1])) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> <?= e(t('atleti.pagination.prev')) ?></a>
                    <?php endif; ?>
                    <span class="pagination-status"><?= e(t('atleti.pagination.status', ['page' => (string) $page, 'pages' => (string) $pages])) ?></span>
                    <?php if ($page < $pages): ?>
                        <a class="btn btn-ghost" rel="next" href="<?= e($link(['pagina' => $page + 1])) ?>"><?= e(t('atleti.pagination.next')) ?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
        <?php endif; // /discovery vs griglia piatta ?>
    </section>
</main>
