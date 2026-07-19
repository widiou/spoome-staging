# Frontend Plan — Checkpoint 1 (core product)

Documento di sola **specifica**. Nessuna modifica a `views/`, `public/assets/css/*`, `public/assets/js/*`: il fork sta già lavorando sulle fondamenta del frontend. Questa spec è pronta da implementare al gate, in un branch dedicato, con deploy+curl per ogni modifica atomica.

Vincoli non negoziabili (da CLAUDE.md): dark, bianco/nero + **giallo** unico accento, **niente verde**, **niente emoji** (icone Font Awesome flat), progressive enhancement, sicurezza MASSIMA (output via `e()`), nessuna regressione visibile.

Stato osservato:
- CSS: `app.css` (~34 KB), `admin.css` (~17 KB). Token colore in `:root`.
- JS: `app.js` (~7,6 KB, `defer`), `cropper.js`.
- Icone: **solo `fa-solid`** (50 occorrenze, 0 `far`/`fab`). Font Awesome Free 6.5.2 vendorizzato: CSS `all.min.css` (~100 KB) + 4 webfont (~440 KB totali) di cui **3 mai usati**.
- Font testo: Barlow / Barlow Condensed self-hosted (`vendor/fonts/barlow.css`), `font-display: swap`.

---

## 1. Consolidamento design-system

### 1.1 Subset Font Awesome (il grande spreco attuale)

Oggi si caricano 4 webfont FA ma si usa **solo lo stile solid**. Peso morto scaricabile subito:

| Webfont | Peso | Usato? |
|---|---|---|
| `fa-solid-900.woff2` | 156 KB | Sì (unico) |
| `fa-brands-400.woff2` | 118 KB | **No** |
| `fa-regular-400.woff2` | 25 KB | **No** |
| `fa-v4compatibility.woff2` | 4,8 KB | **No** |

**Icone solid effettivamente usate nelle view (31 uniche, l'intero fabbisogno del prodotto core):**

`arrow-left`, `arrow-right`, `plus`, `check`, `circle-check`, `circle-xmark`, `triangle-exclamation`, `pen`, `trash`, `trash-can`, `shield-halved`, `id-badge`, `clock`, `bell`, `users`, `user`, `user-plus`, `user-check`, `up-right-from-square`, `right-from-bracket`, `paper-plane`, `magnifying-glass`, `location-dot`, `image`, `hand`, `gauge-high`, `flag`, `envelope-circle-check`, `chart-line`, `camera`, `ban`.

**Piano (due opzioni, in ordine di preferenza):**

- **A — Subset webfont dedicato (consigliato).** Generare un unico `fa-subset.woff2` con solo i 31 glifi solid (via `fantasticon`/`glyphhanger` o subset FA Pro-style con `pyftsubset` sul solid-900) + un `fa-subset.css` minimale che dichiara la sola `@font-face` solid e le 31 regole `.fa-*::before`. Risparmio atteso: da ~540 KB (css+4 font) a **< 30 KB** totali. Il resto (`all.min.css`, i 3 font inutili) va rimosso da `base.php` e dal deploy manifest.
- **B — Minimo sforzo (se A slitta).** Tenere `all.min.css` ma **cancellare i 3 webfont inutilizzati** dai `webfonts/` e dal manifest: `all.min.css` fa fallback graceful, i solid restano. Risparmio immediato ~148 KB senza toccare markup.

Governance: introdurre una **allowlist icone** documentata (questo elenco). Nessuna nuova classe `fa-*` in una view senza aggiornarla + rigenerare il subset (opzione A). CI/pre-deploy: grep `fa-[a-z-]+` sulle view vs allowlist per intercettare drift.

### 1.2 Classe base `.avatar` (unificare 5 varianti divergenti)

Oggi 5 selettori quasi identici ripetono le stesse ~8 proprietà: `.pcard-avatar`, `.profile-avatar`, `.feed-avatar`, `.convo-avatar`, `.avatar-preview` (tutti: cerchio, grid center, surface-2, border-hi, font-head 700, overflow hidden). Divergono solo per dimensione.

**Spec:** una base + modificatori size, tenendo le classi legacy come alias durante la transizione.

```css
.avatar { display:grid; place-items:center; flex:none; overflow:hidden;
  border-radius:50%; background:var(--c-surface-2); border:1px solid var(--c-border-hi);
  font-family:var(--font-head); font-weight:700; color:var(--c-heading); }
.avatar--xs { width:36px; height:36px; font-size:.9rem; }
.avatar--sm { width:46px; height:46px; font-size:1rem; }   /* feed, convo */
.avatar--md { width:52px; height:52px; font-size:1.2rem; } /* pcard */
.avatar--lg { width:96px; height:96px; font-size:2.2rem; } /* profilo, editor */
```
`.avatar-img` resta invariata. Migrazione: le 5 classi attuali diventano `.avatar.avatar--<size>`; per una transizione senza rischio, ridefinire i selettori legacy con `@extend`-style (regola condivisa) finché tutte le view non sono migrate.

### 1.3 Partial condiviso `avatar` (blocco duplicato in 7 view)

Il pattern "img se `avatar_path`, altrimenti `initials()`" è copiato **7 volte**: `partials/profile-card.php`, `pages/rete/index.php`, `pages/feed/index.php`, `pages/messaggi/inbox.php`, `pages/messaggi/thread.php`, `pages/atleti/show.php`, `pages/profilo/edit.php`.

**Spec `views/partials/avatar.php`** (input: `$src` opzionale, `$name`, `$size` = xs|sm|md|lg, `$tag` = span|a, `$href` opz.):
```php
<?php /** @var ?string $src @var string $name @var string $size @var string $tag @var ?string $href */ ?>
<<?= $tag ?> class="avatar avatar--<?= e($size) ?>"<?= isset($href) ? ' href="'.e($href).'"' : '' ?> aria-hidden="true">
  <?php if (!empty($src)): ?>
    <img class="avatar-img" src="<?= e($src) ?>" alt="" loading="lazy">
  <?php else: ?><?= e(initials($name)) ?><?php endif; ?>
</<?= $tag ?>>
```
Sostituisce i 7 blocchi con `<?= partial('avatar', [...]) ?>`. Nota: alcune sorgenti sono `avatar_path` (da `url()`) altre `avatar_url` già assoluto — normalizzare a monte passando sempre l'URL finale al partial.

### 1.4 Partial condiviso `follow` / `connect` (bottoni azione duplicati)

Follow e connect sono ripetuti in `atleti/show.php`, `rete/index.php`, `atleti/follow-list.php`, `feed/index.php`, con markup form/CSRF/label divergente. Consolidare in due partial:

- **`partials/follow-button.php`** — input `$handle`, `$isFollowing`. Emette il `form[data-follow]` con `follow-btn`, `data-following`, `data-label-*` (già cablato in `app.js`). Fonte di verità unica per l'AJAX progressive-enhancement.
- **`partials/connect-actions.php`** — input `$handle`, `$state` (`none|pending_out|pending_in|connected`). Emette i 4 rami form (connetti / accetta / rifiuta / disconnetti) oggi inline in `atleti/show.php`, con label da i18n.

Beneficio: un solo punto per markup, CSRF, azioni, aria — riduce il rischio di regressione sui form che oggi divergono per view.

---

## 2. Audit coerenza componenti

| Componente | Stato attuale | Azione di uniformazione |
|---|---|---|
| **Card profilo** | `.pcard` (directory) coerente; `.profile` (show) e le righe di `rete`/`feed`/`messaggi` hanno layout ad-hoc | Estrarre `partials/profile-card.php` come **unica** card lista; le righe rete/follow-list devono riusarla invece di reimplementare avatar+nome+meta |
| **Badge / chip** | `.chip`, `.chip-sport`, `.pcard-badge` (verificato), `.nav-badge`, `.admin-nav-count` | Definire scala unica: `.badge` (neutro), `.badge--verified` (giallo), `.badge--count` (danger). Il badge "verificato" `fa-circle-check` deve essere **giallo**, non verde (vedi §4) |
| **Liste** | `feed`, `inbox`, `follow-list`, `rete` con classi proprie | Introdurre `.list` / `.list-item` con slot avatar+corpo+azione; le 4 liste ci convergono |
| **Form** | `.btn` (`btn-primary/ghost/sm/block/danger`), `.icon-btn`, `.field-help`, `.form-actions`, `.alert-*` coerenti | Buona base. Standardizzare: ogni azione icona+testo usa `btn`; ogni azione solo-icona usa `.icon-btn` con `aria-label` obbligatorio (§3). Verificare che `btn-primary:hover` (oggi `#E3E6EA`, bianco) sia intenzionale e coerente ovunque |
| **Toast / alert** | `.alert-success`, `.toast-success` usano `--c-green` | Ricolorare (§4) |

---

## 3. Performance & accessibilità

**Performance:**
- **Preload font critici** in `base.php` `<head>`, prima dei CSS: il woff2 Barlow latin 400/600 e il subset FA solid.
  ```html
  <link rel="preload" href="…/barlow-latin-400.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="…/fa-subset.woff2" as="font" type="font/woff2" crossorigin>
  ```
  (Preloadare solo i pesi realmente above-the-fold: 400 e 600; non i 5 pesi × 2 range.)
- **Subset FA** (§1.1): il singolo maggior risparmio di rete.
- **Caching asset:** servire `css/js/webfonts` con `Cache-Control: public, max-age=31536000, immutable` e **cache-busting via hash** nel nome/query (`asset()` che appende `?v=<sha1>`, coerente col manifest di deploy). Oggi gli asset non hanno versioning nell'URL → rischio stale dopo deploy.
- `app.js` già `defer`; mantenere. CSS unico critico: valutare inline del critical minimo se il CSS cresce.

**Accessibilità:**
- **Skip-link assente.** Aggiungere come primo figlio di `<body>`: `<a class="skip-link" href="#main">Salta al contenuto</a>` + `<main id="main">` nei layout, con CSS visible-on-focus.
- **Controlli solo-icona:** `nav-bell` e `.icon-btn` hanno già `aria-label`; il link admin `fa-shield-halved` ha `title` ma va aggiunto `aria-label`. Regola: ogni `<button>/<a>` che contiene **solo** un `<i class="fa-solid">` deve avere `aria-label`; l'icona resta `aria-hidden="true"`. (I 47 `<i>` inline sono già `aria-hidden`.)
- **Contrasto:** su fondo scuro il testo bianco/`--c-text` è ok; verificare `--c-muted-text` (#9BA1A9 su #101218 ≈ 6:1, ok). Attenzione al **giallo `#D8F21D` su bianco** `btn-primary:hover` per il testo `--c-on-primary` (scuro su giallo = ok). Verificare `--c-danger` `#FF6B6B` come testo.
- **Focus ring:** confermare `--c-ring`/`--glow` visibile su tutti gli interattivi (`:focus-visible`), inclusi `.pcard` e gli avatar-link.
- `aria-live` già presente per toast e follow — mantenere.

---

## 4. Rimozione "no verde" residuo

Violazione del vincolo "niente verde" ancora presente in `app.css`:

| Riga | Selettore | Fix |
|---|---|---|
| 12, 25–26 | `--c-green: #80F21D`, `--c-accent: #80F21D`, `--c-accent-600: #6FD915` | Rimappare success/accento su **giallo**: `--c-accent → var(--c-primary)`. Eliminare `--c-green` o aliasare a `--c-primary`. |
| 165 | `.alert-success` (testo+bordo verde) | Usare giallo: `color: var(--c-primary)`, bg/bordo con `color-mix(... --c-primary ...)` |
| 414 | `.toast-success` (bordo/testo verde) | Idem, giallo |
| 245 | `.conn-state .fa-user-check { color: var(--c-accent) }` (verde) | `color: var(--c-primary)` |
| commento riga 3 | "verde lime #80F21D" nella palette dichiarata | Aggiornare commento: unico accento = giallo |

Nota semantica: senza verde, il "successo/connesso" si distingue per **giallo + icona** (`circle-check`, `user-check`), l'errore per **rosso `--c-danger` + icona** (`triangle-exclamation`, `circle-xmark`). Danger resta l'unico colore non giallo/neutro ammesso. `admin.css` è già pulito (nota esplicita "niente verde"); `--c-danger` per i count è coerente.

---

## Ordine di implementazione al gate

1. **Quick win senza markup:** rimuovere 3 webfont FA inutili + fix "no verde" (§1.1-B, §4). Basso rischio, deploy+curl.
2. **Subset FA dedicato** (§1.1-A) + preload font + caching versionato (§3).
3. **`.avatar` base + partial `avatar`** (§1.2-1.3), migrando le 7 view una alla volta.
4. **Partial `follow`/`connect`** (§1.4) + skip-link + aria icon-only (§3).
5. **Uniformazione card/liste/badge** (§2).

Ogni step: modifica atomica → `python3 jobs/deploy.py` → verifica `curl` sulle pagine toccate (home, atleti, atleti/{handle}, rete, feed, messaggi, profilo/edit). Nessuno step lascia la beta rotta.
