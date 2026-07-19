# Public / Partner API — Hardening SPEC ("blindato") — checkpoint-2

**Autore:** API Platform & Security Architect · **Stato:** proposta (no production code)
**Obiettivo del fondatore:** esporre l'API di Spoome a **consumatori esterni** — integrazioni di terze parti, **widget embeddabili su siti partner/specializzati**, e accesso **search** partner — con l'API **blindata** al livello di sicurezza MASSIMO (CLAUDE.md #1).

> **Principio invariante (allineato a `async-consolidation-spec.md` §5).** L'API esterna è **UN ULTERIORE consumatore degli STESSI Service** (`Controller → Service(ServiceResult) → Repository`). Non si biforca la logica di business: si aggiunge una **porta d'ingresso** (adattatore sottile) + un **perimetro pubblico esplicitamente allow-listato**. Il web usa sessione+CSRF; il mobile usa Bearer utente; il **partner usa una credenziale-macchina nuova** (§2). Tre porte, un solo Service layer.

> **Stato accertato (letto, non assunto).**
> - `/api/v1` è già il prefisso versionato (`Config::apiPrefix()`, namespace `Api\V1\`).
> - `ProfileRepository::listPublic / findPublicByHandle / followersOf / followingOf` filtrano **già** `visibility = 'public'` in ogni ramo. `profiles.visibility ENUM('public','members','private')`: **solo `public` può lasciare l'edificio.**
> - `ProfilePresenter::card/full` è **già** una proiezione pubblica indurita: non espone mai `user_id`, `*_media_id`, `visibility`, email. È il presenter da riusare come base della superficie partner.
> - Ricerca: FULLTEXT `ft_profiles_search` (`display_name+headline+bio`, BOOLEAN MODE) già in `listPublic`, con placeholder distinti `:q`/`:qscore` (regola EMULATE_PREPARES=false rispettata).
> - `ApiController::respond(ServiceResult)` → envelope `{data,meta}`/`{errors:[{status,title,detail,fields}]}` già in essere. `requireUser()` (sessione o Bearer), `requireBearerUser()` (solo Bearer).
> - `TokenService` (Bearer **utente**) salva solo l'hash SHA-256, raw una volta. `RateLimiter` su tabella `login_attempts` (per-IP e per-chiave). `SecurityHeaders::send()` emette CSP chiusa `frame-ancestors 'self'`, HSTS via `upgrade-insecure-requests` + `.htaccess`.
> - Migrazione più recente: **0018**. Le nuove sono **0019** e **0020**.

---

## 0. Perché una superficie separata (non "aprire l'API esistente")

Le rotte `/api/v1` attuali sono **first-party**: letture pubbliche (profili/feed) + scritture **solo-Bearer utente**. Esporle così com'è ai partner sarebbe un errore di sicurezza:

1. Non c'è modo di **attribuire** il traffico a un partner (no rate-limit/quota/billing per-cliente, no audit per-cliente).
2. Le scritture Bearer sono legate all'**identità di un utente**: un partner non deve poter scrivere per conto di nessuno.
3. Il feed e le liste first-party possono evolvere (breaking-change interni) — un contratto partner deve essere **più stabile** di quello interno.

→ Si definisce un **perimetro `/api/public/v1`** (o `/api/v1/public/*` — vedi §5.1) **read-only**, allow-listato endpoint-per-endpoint, servito dagli stessi Repository/Presenter ma con un **middleware di autenticazione partner** distinto e un **rate-limit/quota per-chiave**. Le rotte first-party (`/api/v1/me/*`, feed, scritture) **non sono mai** sul perimetro partner.

---

## 1. Superficie pubblica — cosa può essere esposto (minimale, PII-free)

**Regola d'oro (defense-in-depth):** un endpoint è sulla superficie partner **solo** se (a) è in questa allow-list, (b) è `GET` (read-only), (c) tocca esclusivamente dati con `visibility='public'`, (d) restituisce una **proiezione indurita** senza PII. Le tre condizioni sono verificate a **due livelli**: nel controller (allow-list di rotta) **e** nel Repository (il `WHERE visibility='public'` e il Presenter che non emette campi interni). Se una regola cade a un livello, l'altro tiene.

### 1.1 Endpoint allow-listati (v1 partner)

| # | Endpoint partner | Scope richiesto | Fonte (riuso) | `data` |
|---|---|---|---|---|
| P1 | `GET /api/public/v1/profiles` | `read:search` | `ProfileRepository::listPublic` + `ProfilePresenter::card` | array di **card pubbliche** + `meta` paginazione |
| P2 | `GET /api/public/v1/profiles/{handle}` | `read:profiles` | `findPublicByHandle` + `ProfilePresenter::full` | **profilo pubblico completo** (proiezione indurita) |
| P3 | `GET /api/public/v1/search` | `read:search` | `listPublic(search=…)` FULLTEXT | come P1, alias esplicito "search" (q obbligatorio) |
| P4 | `GET /api/public/v1/profiles/{handle}/roster` | `read:org` | `followingOf`/roster org (solo profili org) + `card` | roster/team pubblico: array di card |
| P5 | `GET /api/public/v1/profiles/{handle}/counts` | `read:profiles` | contatori denormalizzati pubblici | `{followers_count, connections_count?}` — **solo** contatori già pubblici in UI |
| P6 | `GET /api/public/v1/embed/profile/{handle}` | `read:profiles` (chiave widget) | `full` ridotto → payload widget | dati minimi per la card embeddabile (§4) |
| P7 | `GET /api/public/v1/oembed?url=…` | nessuno scope / chiave widget | wrapper oEmbed su P6 | envelope oEmbed (§4.4) |

> P4 "roster": esposto **solo** se il profilo `{handle}` è un'organizzazione (`is_organization=true`) e i membri sono `visibility='public'`. Un roster non deve mai leakare membri privati: il Repository filtra i membri sullo stesso `visibility='public'`.

### 1.2 Proiezione pubblica esatta — cosa esce e cosa NO

**P1/P3/P4 — card pubblica** (identica a `ProfilePresenter::card`, che è già indurita):

```
handle, display_name, headline, type{key,label,is_organization},
sport{slug,name}|null, location (stringa città/regione/paese), avatar_url,
verified (bool), url (URL pubblico atleti/{handle})
```

**P2 — profilo pubblico completo** (`ProfilePresenter::full` = card + ):

```
bio, cover_url, location_detail{city,region,country}, created_at,
experiences[{id,org_name,role,location,start_year,end_year,is_current,description}],
achievements[{id,title,year,description}],
links[{id,kind,label,url}]
```

**Escluso ESPLICITAMENTE dalla superficie partner (mai serializzato):**

- `user_id`, qualsiasi `*_media_id`, `visibility` — **già** non emessi dal Presenter (defense-in-depth confermato).
- **Email**, telefono, qualsiasi contatto privato, indirizzo civico — **nessun campo PII**.
- **Contatori privati / interni**: `profile_views`, engagement interno, `notif_unread`, `dm_unread` — mai.
- **ID interni auto-increment** come chiave pubblica: la chiave pubblica di un profilo è **solo `handle`** (slug). Gli `id` che compaiono in `experiences/achievements/links` sono id di sotto-record già pubblici e stabili, non l'`id` del profilo; accettabili perché non correlano a nient'altro di privato — ma **valutare** di ometterli su P2 partner se non servono al widget (raccomandazione: ometterli sulla proiezione `embed` P6).
- **`created_at`** su P2: uscire in **ISO-8601 UTC** (coerente con §5.5 dell'async-spec), mai formato display italiano.

**Regola non negoziabile:** endpoint `members`/`private`/**qualsiasi scrittura** (POST/PUT/PATCH/DELETE) **non sono MAI** sulla superficie partner. Nessuna eccezione, nessun flag.

### 1.3 Forma della risposta (envelope + meta)

Stesso envelope first-party (zero divergenza): `{data, meta}` / `{errors:[{status,title,detail}]}`.
`meta` per liste: `{page, per_page, total, pages}` (già emesso da `ProfileController::index`).
`meta` aggiunge sempre, sul perimetro partner, gli header di quota (§3.1) — non nel body, negli **header HTTP** `X-RateLimit-*`.

---

## 2. Autenticazione & autorizzazione partner (distinta dal Bearer utente)

### 2.1 Tipo di credenziale — raccomandazione

Due profili di consumatore, due meccanismi:

| Consumatore | Credenziale | Motivazione |
|---|---|---|
| **Widget browser** (P6/P7, embed su sito partner) | **API key pubblica, origin-restricted, read-only** (`X-Api-Key` header o query per oEmbed) | Vive nel client → **non è un segreto**. La sua sicurezza è data dal **CORS/Referer allow-list per-chiave** + scope minimo (`read:profiles`) + rate-limit. |
| **Integrazione server-to-server** (P1-P5, sync, ricerca) | **API key segreta** (`Authorization: Bearer <key>` o `X-Api-Key`) **+ HMAC opzionale** per i partner ad alto valore | Segreto lato server → hashata come una password. HMAC (§2.4) per anti-replay sui partner premium. |

**Raccomandazione: API key con scopes come modello primario** (semplice, adatto a widget read + integrazioni read), **NON** OAuth2 full su SiteGround shared:

- **OAuth2 authorization-code** (delega per-utente) **non serve**: la superficie partner è **read-only su dati pubblici**, non agisce per conto di un utente → nessun consenso utente da raccogliere.
- **OAuth2 client-credentials** è *concettualmente* una API key con un token-exchange in più. Sul nostro stack porta complessità (token endpoint, TTL, introspection) senza beneficio finché i partner sono pochi e tutto è read. **Rimandabile.** Se in futuro servisse (partner numerosi, revoca centralizzata, scadenza automatica), si aggiunge un `POST /api/public/v1/oauth/token` che **emette un Bearer breve a partire da `client_id`+`client_secret`** — lo stesso `api_clients`/`api_keys` fa da registry. Progettato per essere additivo, **non lo si costruisce ora**.

→ **Decisione fondatore (vedi §6):** API key ora, OAuth2 client-credentials dopo (o mai).

### 2.2 Scopes (least-privilege)

Scope minimi, enforced **per-chiave** dal middleware partner:

| Scope | Concede |
|---|---|
| `read:profiles` | P2, P5, P6, (P7) — lettura profilo pubblico singolo |
| `read:search` | P1, P3 — directory + ricerca FULLTEXT |
| `read:org` | P4 — roster organizzazioni |

Default all'emissione: **nessuno scope** (deny-all) → il partner riceve solo ciò che gli si assegna esplicitamente. Widget key: forzata a `read:profiles` (+ eventuale `read:search`), mai oltre. Nessuno scope di scrittura esiste su questa superficie (non c'è `write:*`).

### 2.3 Modello chiavi + DDL (migrazione 0019)

Due tabelle: `api_clients` (il partner/owner) e `api_keys` (le credenziali, N per cliente — permette rotazione senza downtime e chiavi separate widget vs server).

```sql
-- 0019_create_api_platform.php

CREATE TABLE api_clients (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(190) NOT NULL,                 -- ragione sociale / nome integrazione
  owner_user_id BIGINT UNSIGNED NULL,                  -- referente interno (FK users.id, ON DELETE SET NULL)
  contact_email VARCHAR(190) NULL,
  tier          ENUM('free','partner') NOT NULL DEFAULT 'free',
  status        ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_api_clients_user FOREIGN KEY (owner_user_id)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_keys (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id     BIGINT UNSIGNED NOT NULL,
  label         VARCHAR(190) NOT NULL,                 -- "widget sito X", "sync CRM"
  key_prefix    CHAR(8)  NOT NULL,                     -- primi 8 char, NON segreto: per lookup + display "spk_ab12…"
  key_hash      CHAR(64) NOT NULL,                     -- SHA-256 del segreto completo (mai plaintext)
  key_type      ENUM('secret','public') NOT NULL DEFAULT 'secret',  -- public = widget browser origin-restricted
  scopes        JSON NOT NULL,                         -- ["read:profiles","read:search"]
  allowed_origins JSON NULL,                           -- ["https://partner.it"] — obbligatorio se key_type='public'
  hmac_secret_hash CHAR(64) NULL,                      -- SHA-256 del secondo segreto HMAC (opzionale, §2.4)
  rate_limit_per_min  INT UNSIGNED NOT NULL DEFAULT 60,
  quota_daily         INT UNSIGNED NOT NULL DEFAULT 10000,
  status        ENUM('active','revoked') NOT NULL DEFAULT 'active',
  last_used_at  TIMESTAMP NULL,
  expires_at    TIMESTAMP NULL,                        -- rotazione: scadenza pianificata
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at    TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_keys_hash (key_hash),
  KEY idx_api_keys_prefix (key_prefix),
  KEY idx_api_keys_client (client_id),
  CONSTRAINT fk_api_keys_client FOREIGN KEY (client_id)
    REFERENCES api_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Ciclo di vita (specchia `TokenService`):**
- **Issuance:** genera `raw = "spk_" . Str::token(32)`; salva `key_prefix` (per lookup O(1) senza scandire) + `key_hash = SHA-256(raw)`. Il raw è **mostrato una sola volta** all'emissione (come i token utente).
- **Lookup a runtime:** dall'header estrai il raw → `key_prefix` per restringere → confronta `hash_equals(SHA-256(raw), key_hash)` (timing-safe, come `hash_equals` in `Csrf`/migrate token).
- **Rotazione:** emetti nuova key con `expires_at` sulla vecchia → doppia validità → il partner migra → revoca la vecchia. Nessun downtime.
- **Revoca:** `status='revoked'`, `revoked_at=NOW()`. Il middleware rifiuta all'istante (401).
- **Metadata per-chiave:** owner (`client_id`), `allowed_origins`, `scopes`, `rate_limit_per_min`, `quota_daily` — tutto sulla riga, così il middleware decide senza join pesanti.

### 2.4 HMAC request signing (opzionale, partner server premium)

Per integrazioni server-to-server ad alto valore, **firma HMAC** su un segreto secondario (`hmac_secret_hash`), con timestamp+nonce anti-replay.

**Stringa canonica** (una riga, `\n`-joined):
```
METHOD \n path+query-ordinata \n X-Api-Timestamp \n X-Api-Nonce \n sha256(body|"")
```
**Header:**
```
X-Api-Key: spk_ab12…
X-Api-Timestamp: 1720099200        (unix; rifiuta se |now - ts| > 300s)
X-Api-Nonce: <uuid/16-byte hex>    (rifiuta se già visto entro la finestra)
X-Api-Signature: hex( HMAC-SHA256(hmac_secret, canonical) )
```
Verifica timing-safe (`hash_equals`). Nonce visti tenuti in una piccola tabella/cache con TTL 300s (o riuso di `login_attempts`-style con chiave `hmac_nonce:<nonce>` + `tooManyByKey`≥1). **Nota:** HMAC è *hardening addizionale*, non sostituisce l'API key; è **opzionale per tier `partner`**, off per widget browser (non può custodire un segreto).

### 2.5 Trasporto della credenziale — conferme

- Partner key: **`Authorization: Bearer <key>`** oppure **`X-Api-Key: <key>`** (accettati entrambi; il middleware prova prima `X-Api-Key`, poi `Authorization`). **MAI** cookie, **MAI** CSRF token (non è browser-session; la difesa CSRF è irrilevante perché non c'è ambient authority a cookie).
- Widget browser: **API key `public` origin-restricted read-only** — il segreto reale non è mai nel browser; la key pubblica è inutile fuori dalle `allowed_origins` (CORS) e non ha scope di scrittura.
- La distinzione dal Bearer **utente**: il middleware partner interroga `api_keys` (prefix `spk_`), **non** `auth_tokens`. Un token utente non è mai valido sulla superficie partner e viceversa — due registri, due namespace di prefisso.

---

## 3. Hardening ("blindato") — checklist dei controlli

### 3.1 Rate limiting & quote (per-chiave + per-IP)

Riuso del pattern `RateLimiter` (tabella append-only + COUNT su finestra). Serve una tabella dedicata perché il volume/dimensione è diverso dai login; vedi `api_request_log` (0020) che fa **doppio servizio** rate-limit + audit.

| Livello | Default | Enforcement |
|---|---|---|
| **Per-chiave / minuto** | `api_keys.rate_limit_per_min` (60 free / configurabile partner) | COUNT richieste della key negli ultimi 60s |
| **Per-chiave / giorno (quota)** | `api_keys.quota_daily` (10k free) | COUNT del giorno solare UTC |
| **Per-IP / minuto** (anti-DoS anche senza key valida, es. su oEmbed) | 120/min | COUNT per IP (riusa la logica `tooManyByIp`-style) |
| **Burst** | token-bucket soft: si tollera 2× per 10s poi 429 | opzionale; MVP = finestra fissa |

Superata la soglia → **`429 Too Many Requests`** con:
```
Retry-After: <secondi al reset finestra>
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
X-RateLimit-Reset: <unix reset>
```
Gli stessi `X-RateLimit-*` sono emessi su **ogni** risposta partner (non solo 429), così il client si auto-regola.

**Nota SiteGround:** **nessun WAF/edge** → tutto il rate-limit è **app-level** (PHP+MySQL). Mitigazione DoS: (a) il conteggio è una singola query indicizzata; (b) le risposte GET pubbliche sono **cache-abili** (§3.4) → la CDN SiteGround assorbe i replay identici prima di PHP; (c) l'IP-throttle rifiuta presto le richieste senza key valida.

### 3.2 CORS per-chiave (widget browser)

Il perimetro partner è cross-origin per definizione (il widget gira su `partner.it`). CORS **stretto, per-chiave, mai `*`**:

- Il middleware legge l'header `Origin` della richiesta e la key (`X-Api-Key`).
- Valida `Origin` contro `api_keys.allowed_origins` (match esatto host+scheme, no wildcard di sottodominio salvo esplicito). Se non combacia → **nessun header CORS** (il browser blocca) + log.
- Solo su match: `Access-Control-Allow-Origin: <origin richiesto esatto>` (echo dell'origin validato, mai `*` quando c'è una key), `Vary: Origin`, `Access-Control-Allow-Methods: GET, OPTIONS`, `Access-Control-Allow-Headers: X-Api-Key`, `Access-Control-Max-Age: 600`.
- **Preflight `OPTIONS`:** gestito dal middleware **prima** dell'auth di scope, risponde 204 con gli header sopra se `Origin` è allow-listato per una key pubblica.
- **Nessuna credenziale cookie:** `Access-Control-Allow-Credentials` **non** impostato (la key è in header, non in cookie; non serve e allargarebbe la superficie).
- `allowed_origins` è **obbligatorio non-vuoto** per `key_type='public'` (vincolo applicativo all'emissione).

### 3.3 Input validation & output minimization

- **Allow-list rigida dei query param** per endpoint: P1/P3 accettano solo `q, tipo, sport, pagina|page, per_page`. Qualsiasi param **sconosciuto → 400** (`unknown_parameter`) — non ignorato silenziosamente (riduce fingerprinting/abuso). *(Il controller odierno già whitelista tipo/sport contro valori esistenti e clampa `per_page`.)*
- **Max page size:** `per_page` clampato a `PER_PAGE_MAX=50` (già in `ProfileController`); `q` troncato a `SEARCH_MAX=80`.
- **Paginazione limitata:** `pages`/offset massimo per evitare scansioni profonde (deep-paging DoS) — es. rifiuta `page > 200` sul perimetro partner.
- **FULLTEXT sicuro:** la ricerca usa già BOOLEAN MODE con placeholder parametrizzati distinti `:q`/`:qscore` (EMULATE_PREPARES=false → placeholder non riusabili: già rispettato). Sanitizzare gli operatori booleani dell'utente (`+ - * " ( )`) se non voluti.
- **Output minimization = proiezione a livello Repository/Presenter**, non solo controller: `ProfilePresenter` non serializza campi interni per costruzione → anche un bug nel controller non può leakare `visibility`/`user_id`/PII. **Defense-in-depth confermata dal codice.**

### 3.4 Security headers / transport / caching

- **HTTPS-only + HSTS:** già `upgrade-insecure-requests` in CSP + `.htaccess`. Confermare `Strict-Transport-Security: max-age=31536000; includeSubDomains` sul perimetro API (follow-up già in memoria "HSTS").
- **`X-Content-Type-Options: nosniff`** (già globale). `Content-Type: application/json; charset=utf-8`.
- **Cacheabilità dei GET pubblici (CDN-friendly):** le risposte P1-P5 sono dati pubblici → `Cache-Control: public, max-age=60, s-maxage=300` + **`ETag`** (hash del payload) + `304 Not Modified` su `If-None-Match`. La CDN SiteGround serve i replay senza toccare PHP (mitiga §3.1). Le risposte autenticate per-utente **non** sono qui (il perimetro partner è pubblico per natura) → nessun rischio di cache-poisoning di dati privati. `Vary: Origin, X-Api-Key` per non incrociare cache tra partner.
- **Widget embed (P6):** l'endpoint dati resta JSON `frame-ancestors 'self'` (non è una pagina da incorniciare). La **pagina** widget iframe (§4) ha una CSP dedicata con `frame-ancestors <domini partner allow-listati>` — vedi §4.

### 3.5 Audit & observability (+ billing-ready)

Ogni richiesta partner logga una riga (0020) — base per abuse-detection, per-key usage counters e **billing futuro**:

```sql
-- 0020_create_api_request_log.php
CREATE TABLE api_request_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  api_key_id  BIGINT UNSIGNED NULL,          -- NULL = richiesta rifiutata pre-auth (key assente/invalida)
  client_id   BIGINT UNSIGNED NULL,
  ip          VARBINARY(16) NOT NULL,        -- INET6_ATON
  method      VARCHAR(8) NOT NULL,
  path        VARCHAR(190) NOT NULL,
  status      SMALLINT UNSIGNED NOT NULL,
  latency_ms  INT UNSIGNED NULL,
  origin      VARCHAR(190) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reqlog_key_time (api_key_id, created_at),   -- rate-limit + quota + usage per-key
  KEY idx_reqlog_ip_time (ip, created_at)             -- IP throttle
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- Questa tabella **serve anche da contatore rate-limit/quota** (§3.1): un solo append + COUNT indicizzati.
- **Retention:** purge job (come `RateLimiter::purgeOlderThan`) mantiene N giorni per il rate-limit; un rollup giornaliero (`api_usage_daily`, opzionale) preserva i totali per billing senza tenere ogni riga.
- **Mai** loggare la key raw né PII nel path (gli handle sono pubblici → ok).

### 3.6 Gestione dei segreti

- `api_keys.key_hash` = **SHA-256 del segreto** (mai plaintext, mai reversibile), come `auth_tokens`. `hmac_secret_hash` idem.
- Raw mostrato **una sola volta** all'emissione (UI admin `/admin` — vedi §5.2). Se perso → rotazione, non recupero.
- `key_prefix` (8 char, non segreto) per identificare/mostrare la key ("spk_ab12…") senza rivelarla.
- Confronto sempre **timing-safe** (`hash_equals`).

---

## 4. Widget embeddabili per siti partner

Due widget concreti, brand-consistent (dark, accento **giallo**, **no verde**, **no emoji**, icone Font Awesome flat), responsive e accessibili (ruoli ARIA, contrasto AA, `prefers-reduced-motion`, `prefers-color-scheme`).

### 4.1 Widget A — "Profile Card"
Card compatta di un atleta/società: avatar, `display_name`, `headline`, `type/sport`, badge `verified`, contatore follower pubblico, CTA "Vedi su Spoome" → `url` pubblico. Dati da **P6** (`/embed/profile/{handle}`).

### 4.2 Widget B — "Team / Org Roster"
Griglia dei membri pubblici di un'organizzazione (P4 roster): mini-card per ogni membro, link al profilo pubblico. Paginazione/limite (es. primi 12 + "vedi tutti").

### 4.3 Delivery: iframe vs JS snippet — **raccomandazione: iframe sandboxed**

| | **iframe** (raccomandato) | JS snippet |
|---|---|---|
| Isolamento | **Massimo**: sandbox, nessun accesso al DOM del partner, CSS/JS di Spoome non collidono | Gira nel contesto del partner (rischio conflitti CSS/JS, e il partner vede la key) |
| Sicurezza key | La key vive **server-side di Spoome** (l'iframe è servito da noi) → **il partner non maneggia mai una key** | Richiede una **public key origin-restricted** nel markup del partner |
| Controllo embed | `frame-ancestors` per-partner blocca chi non è autorizzato a incorniciare | Chiunque copi lo snippet lo usa (mitigato solo da CORS+origin) |
| Superficie CORS | **Nessuna** (l'iframe non fa CORS: same-origin verso Spoome) | Richiede il perimetro CORS per-chiave (§3.2) |
| Aggiornamenti | Cambi lato Spoome, zero deploy sul partner | idem |
| Trade-off | Meno "nativo" nello stile del partner; altezza da gestire (postMessage resize) | Più integrabile stilisticamente, ma più superficie di attacco |

**Raccomandazione:** **iframe** come default (`<iframe src="https://spoome.it/embed/atleta/{handle}" sandbox="allow-scripts allow-popups" …>`). È il più facile da blindare: la key resta nostra, niente CORS, `frame-ancestors` controlla chi incornicia, la sandbox isola. **JS snippet** offerto solo a partner premium che vogliono rendering in-page, con **public key origin-restricted** obbligatoria — è l'unico caso che *richiede* il perimetro CORS §3.2 (per questo il perimetro CORS resta progettato, ma è usato solo dallo snippet e da oEmbed).

**CSP della pagina iframe embed** (servita da Spoome, distinta da quella globale): `frame-ancestors 'self' https://partner-A.it https://partner-B.it` — l'allow-list dei `frame-ancestors` deriva dalle `allowed_origins` della key del partner (la pagina embed accetta `?key=` pubblica → deriva i domini). Chi non è in lista non può incorniciare il widget (il browser blocca). `X-Frame-Options` **omesso** sulla pagina embed (obsoleto vs `frame-ancestors`, e non supporta multi-origin) — le altre pagine restano `SAMEORIGIN`.

### 4.4 Data path, oEmbed, caching, onboarding

- **Path:** widget → (iframe: pagina embed Spoome → P6; snippet: JS → CORS P6 con public key) → **risposta cache-ata** (`s-maxage` §3.4) → CDN SiteGround assorbe i replay.
- **oEmbed (P7):** `GET /api/public/v1/oembed?url=https://spoome.it/atleti/{handle}&format=json` → envelope oEmbed standard `{type:"rich", html:"<iframe …>", width, height, provider_name:"Spoome", …}`. Abilita l'auto-embed su piattaforme che supportano oEmbed (WordPress, CMS partner). Aggiungere `<link rel="alternate" type="application/json+oembed" …>` nelle pagine `/atleti/{handle}`.
- **Onboarding partner:** il partner richiede accesso → un admin crea un `api_clients` + una `api_keys` (`public`, `read:profiles`, `allowed_origins=[dominio del partner]`) da `/admin` → consegna la key **una volta** → il partner incolla `<iframe>`/snippet. Il binding key↔origin è la garanzia: la key è inutile fuori dai domini dichiarati.

---

## 5. Governance & stabilità

### 5.1 Versioning per consumatori esterni

- **Prefisso:** `/api/public/v1/*` (namespace `Api\Public\V1\` — nuovo, distinto da `Api\V1\` first-party). *(Alternativa `/api/v1/public/*` riusa il router esistente ma accoppia la versione partner a quella first-party — sconsigliata: la stabilità esterna deve essere indipendente.)* → **decisione minore**, ma la spec raccomanda `/api/public/v1`.
- **Immutabilità:** una volta che un partner integra, `public/v1` è **contratto congelato**: solo aggiunte retro-compatibili (nuovi campi opzionali, nuove rotte). Mai rinominare/rimuovere/cambiare tipo. Breaking-change → `public/v2` con `v1` servito in parallelo finché ci sono integrazioni attive. (Stessa regola di §5.3 async-spec, ma **più stretta**: il partner non aggiorna il suo codice quando vogliamo noi.)
- **Deprecation policy:** annuncio + header `Deprecation: <data>` + `Sunset: <data>` (RFC 8594) sulle risposte, finestra minima (es. 6 mesi) prima dello spegnimento di una versione.

### 5.2 Docs, admin, tiers

- **OpenAPI 3.1** come contratto pubblicato (`/api/public/v1/openapi.json`) + una pagina docs statica. È il riferimento per i partner e genera client SDK. Raccomandato, non bloccante per l'MVP.
- **Admin:** estendere l'area `/admin` (già 404-cloak + step-up + audit) con una sezione **"Partner / API keys"**: crea client, emetti/rota/revoca key, imposta scopes/origins/quota, vedi usage (`api_request_log`). Riusa `AdminMiddleware`+`StepUpMiddleware`+CSRF.
- **Tiers (nota per billing futuro, non costruire):** `free` (quota bassa, rate-limit basso) vs `partner` (quota alta, HMAC, SLA). `api_clients.tier` + `api_keys.quota_daily/rate_limit_per_min` già modellano i limiti → il billing si aggancia a `api_request_log`/`api_usage_daily` quando servirà.

### 5.3 Vincoli SiteGround & scala

- **Nessun WAF/edge compute** → tutti i controlli (auth, rate-limit, CORS, HMAC, validazione) sono **app-level PHP+MySQL**. Accettabile perché la superficie è piccola, read-only e cache-abile.
- **Leva CDN:** i GET pubblici cache-ati (`s-maxage`, ETag) spostano il carico dei replay sulla CDN → PHP vede solo il traffico unico. È la principale difesa DoS praticabile qui.
- **Quando servirà un gateway dedicato:** se i partner crescono (rate-limit ad alta frequenza, molte key, necessità di edge auth/quotas globali), valutare un **reverse-proxy/API-gateway** (Cloudflare/Kong/APISIX) davanti a Spoome — sposta rate-limit e CORS all'edge e alleggerisce MySQL. **Non ora**: over-engineering per la fase attuale. Segnalato come punto di scala.

---

## 6. Riepilogo & decisioni per il fondatore

**Superficie pubblica:** perimetro **`/api/public/v1`** read-only, allow-listato: P1 directory, P2 profilo, P3 search (FULLTEXT), P4 roster org, P5 counts pubblici, P6 embed, P7 oEmbed. **Regola PII:** solo `visibility='public'`; proiezione = `ProfilePresenter::card/full` (già indurita) → **mai** email/PII, `user_id`, `*_media_id`, `visibility`, contatori interni, id interni oltre l'`handle`. Scritture/`members`/`private` **mai** sul perimetro partner (verificato a due livelli: allow-list rotta + `WHERE visibility='public'` nel Repository).

**Auth partner (raccomandata):** **API key con scopes** (`read:profiles`/`read:search`/`read:org`), hashata SHA-256 (mai plaintext, raw una volta). Widget browser = **public key origin-restricted read-only**; server-to-server = **secret key** + **HMAC opzionale** (timestamp+nonce anti-replay) per i partner premium. **OAuth2 client-credentials rimandato** — inutile finché tutto è read su dati pubblici; progettato come additivo se servirà.

**Top hardening:** (1) rate-limit+quota per-chiave e per-IP con `429`/`Retry-After`/`X-RateLimit-*` (app-level, no WAF); (2) **CORS stretto per-chiave**, mai `*`, `Origin` validato contro `allowed_origins`; (3) input allow-list + reject param sconosciuti + clamp `per_page`/`q`; (4) output minimization al livello Presenter (defense-in-depth); (5) HTTPS/HSTS/nosniff + GET cache-abili (ETag, `s-maxage`) per far assorbire i replay alla CDN; (6) audit per-request (`api_request_log`) → abuse-detection + billing-ready; (7) segreti hashati, timing-safe compare.

**Widget delivery (raccomandato):** **iframe sandboxed** (la key resta server-side Spoome, niente CORS, `frame-ancestors` per-partner, isolamento massimo). JS snippet + public key solo per partner premium che vogliono rendering in-page. oEmbed (P7) per auto-embed CMS.

**Nuove tabelle / migrazioni:** **0019** `api_clients` + `api_keys` (client, scopes, origins, hash key/HMAC, quota, rotazione/revoca); **0020** `api_request_log` (audit + contatore rate-limit/quota, `api_usage_daily` opzionale per billing). Admin: sezione "Partner / API keys" dentro `/admin` esistente.

**Decisioni che chiedo al fondatore:**
1. **Timing:** costruire la piattaforma partner **ora** (in parallelo alla chiusura gap API §5.1 dell'async-spec) **oppure dopo** il consolidamento core e le app native? Raccomando: **API key + iframe widget + P1/P2/P3 subito** (basso rischio, riusa Presenter/Repository esistenti), **HMAC/oEmbed/OAuth2 dopo** su domanda reale.
2. **OAuth2 & gateway:** confermare **API key app-level ora**, rimandando OAuth2 client-credentials e un API-gateway edge dedicato a quando i partner/volumi lo giustificheranno (o mai)? Raccomando **sì** — su SiteGround shared, app-level + CDN caching copre la fase attuale senza over-engineering.
