# Spoome v2 — Blueprint architetturale

> Stella polare del progetto. Documento vivo. Da **validare insieme** prima di scrivere il codice dei domini.
> Legacy di riferimento: repo `widiou/spoome-staging` (storia git precedente a questo reboot).

---

## 1. Visione
**Il professional network dello sport** (SPOrt + hOME): la "casa" dei protagonisti dello sport — atleti, società,
associazioni, federazioni, fan (e in prospettiva agenti, tecnici, giornalisti). Il valore non è consultare
un'enciclopedia, ma **avere un'identità professionale sportiva, connettersi, farsi trovare e cogliere opportunità**.

**Cuore dell'MVP**: l'**identità pro dell'atleta** — un profilo/CV sportivo verificato (carriera, risultati,
palmarès, media). Da lì crescono connessioni, contenuti e recruiting.

---

## 2. Principi architetturali (non negoziabili)
1. **API-first** — il backend espone un'unica **API JSON versionata** (`/api/v1/...`); è il contratto. Il web è il
   primo client, le **app native Android/iOS** sono altri client sulla stessa API. La logica di business vive solo nel backend.
2. **Mobile-first** — UI progettata prima per il telefono, poi scalata. Design system dedicato.
3. **Rendering ibrido (SEO + app)** — pagine pubbliche **server-rendered** (HTML/OpenGraph/JSON-LD per Google);
   aree autenticate e interazioni via **JSON**. Un solo backend, due modalità di consumo.
4. **Auth a token per il nativo** — sessione web (cookie) **+** token Bearer per le app native, sullo stesso guard.
5. **Sicurezza by default** — CSRF sui form, escaping automatico nelle view, segreti solo in `.env`,
   docroot = `public/` (il resto non raggiungibile via web), rate limiting, upload validati.
6. **Vanilla, deployabile su shared** — PHP + JS vanilla + MySQL, nessun framework; `vendor/` versionato a mano
   (niente Composer/SSH sul server); migrazioni eseguibili via runner web protetto.
7. **Lingua** — dominio e URL in italiano (`/atleti`, `/sport`); codice, tabelle e colonne in inglese.

---

## 3. Utenti, ruoli, tipi di profilo
Due assi distinti:

- **Ruolo di piattaforma** (`users.role`): `member` | `moderator` | `admin`. Governa i permessi tecnici.
- **Tipo di profilo** (config-driven, scalabile): `atleta`, `societa`, `associazione`, `federazione`, `fan`.
  Nuovo tipo = nuova riga in `profile_types` (con il suo schema di attributi), **non** nuove tabelle.

**Entitlement (freemium)**: cosa può fare un utente dipende dal suo **piano** (`free` | `premium` | …).
Le feature premium (es. ricerca avanzata, contatti illimitati, statistiche profilo, badge, visibilità) sono
gate applicativi che leggono l'abbonamento attivo. Progettato estensibile fin da subito, attivabile quando serve.

---

## 4. Glossario di dominio
- **User** — l'account (credenziali, ruolo, stato). Solo autenticazione.
- **Profile** — l'identità pubblica di un user, di un certo *tipo*. È ciò che si vede e si cerca.
- **Follow** — relazione **asimmetrica** (seguo senza reciprocità). Per la visibilità (fan → atleta).
- **Connection** — relazione **reciproca** (richiesta + accettazione). La rete professionale.
- **Post** — contenuto nel feed. **Opportunity** — annuncio/provino/offerta. **Application** — candidatura.
- **Conversation/Message** — messaggistica diretta. **Verification** — processo per il badge verificato.
- **Sport** — tassonomia di riferimento (dati seed, non contenuto utente).

---

## 5. Data model (schema v2 — proposta)
MySQL 8 / InnoDB / `utf8mb4_unicode_ci`. `snake_case`, FK vere, `created_at/updated_at` ovunque.
Colonne "calde" dentro i JSON promosse a colonne generate + indice quando serve filtrarle.
Legenda fase: 🟢 = fondamenta MVP · 🟡 = MVP esteso · ⚪ = roadmap.

### Identità & accesso
- 🟢 **users** — `id, email(unique), password_hash, role ENUM(member,moderator,admin), status ENUM(pending,active,suspended), email_verified_at, created_at, updated_at, last_login_at`
- 🟢 **auth_tokens** — token Bearer per API/native: `id, user_id→users, token_hash, kind ENUM(access,refresh), device_label, expires_at, revoked_at, last_used_at, created_at`
- 🟢 **email_verifications** — `id, user_id, token_hash, expires_at, used_at`
- 🟢 **password_resets** — `id, user_id, token_hash, expires_at, used_at`

### Profili
- 🟢 **profile_types** — `id, key(unique), label, is_organization(bool), attributes_schema JSON, active, sort`
- 🟢 **profiles** — `id, user_id→users, profile_type_id→profile_types, handle(unique, per URL), display_name,
  headline, bio, sport_id→sports NULL, avatar_media_id→media NULL, cover_media_id→media NULL,
  location_city, location_region, location_country, verified_at NULL, visibility ENUM(public,members,private),
  attributes JSON, created_at, updated_at`
- 🟡 **experiences** — `id, profile_id, kind ENUM(career,education,other), title, organization, role, sport_id NULL, location, start_date, end_date, is_current, description, sort`
- 🟡 **achievements** — palmarès/risultati: `id, profile_id, title, competition, placement, event_date, description, sort`
- 🟡 **profile_links** — `id, profile_id, label, url, kind ENUM(social,website), sort, visible`
- 🟡 **profile_contacts** — `id, profile_id, kind ENUM(email,phone,agent,other), value, label, visible`
- ⚪ **skills / endorsements** (competenze avallate da altri) — roadmap.

### Media
- 🟢 **media** — `id, owner_user_id, kind ENUM(image,video,document), path, url NULL, mime, width, height, size_bytes, is_public(bool), created_at`
  - Avatar/cover/gallery/post = pubblici (sotto `public/uploads`). Documenti di verifica = **privati** (`storage/uploads`, serviti da controller autorizzato).

### Connessioni
- 🟢 **follows** — `follower_user_id, followed_user_id, created_at` (unique pair; asimmetrico)
- 🟡 **connections** — `id, requester_user_id, addressee_user_id, status ENUM(pending,accepted,declined,blocked), created_at, responded_at` (unique coppia normalizzata)

### Feed / contenuti
- 🟡 **posts** — `id, author_user_id, body, visibility ENUM(public,connections,followers), created_at, updated_at, deleted_at`
- 🟡 **post_media** — `post_id, media_id, sort`
- 🟡 **post_likes** — `post_id, user_id, created_at`
- 🟡 **comments** — `id, post_id, author_user_id, parent_comment_id NULL, body, created_at, deleted_at`
- Feed MVP = fan-out on read (query dei post di chi seguo/connetto). Nota scaling ⚪: fan-out-on-write.

### Messaggistica
- 🟡 **conversations** — `id, created_at, last_message_at` (MVP 1-a-1; group ⚪)
- 🟡 **conversation_participants** — `conversation_id, user_id, last_read_at, joined_at`
- 🟡 **messages** — `id, conversation_id, sender_user_id, body, created_at, deleted_at`
- ⚠️ Realtime su shared hosting: niente WebSocket nativo → MVP con **polling/long-poll**; SSE o servizio esterno ⚪.

### Recruiting
- 🟡 **opportunities** — `id, poster_user_id, title, description, sport_id NULL, role, location, kind ENUM(tryout,job,collaboration), status ENUM(open,closed), deadline, created_at`
- 🟡 **applications** — `id, opportunity_id, applicant_user_id, cover_message, status ENUM(submitted,reviewed,accepted,rejected), created_at`

### Verifica (badge)
- 🟡 **verification_requests** — `id, user_id, profile_id, kind ENUM(identity,role), document_media_id→media, status ENUM(pending,approved,rejected), notes, reviewed_by NULL, reviewed_at NULL, created_at`
  - Approvazione → `profiles.verified_at = now()`.

### Freemium / billing
- 🟡 **plans** — `id, key(unique), label, price_cents, interval ENUM(month,year), features JSON, active`
- 🟡 **subscriptions** — `id, user_id, plan_id, status ENUM(active,canceled,expired,trialing), started_at, current_period_end, provider, provider_ref`
- Accesso feature: derivato da `plan.features` (JSON) dell'abbonamento attivo. Provider pagamenti ⚪ (SDK vendored).

### Sistema
- 🟢 **sports** — `id, name, slug(unique), category, icon, active` (seed: tassonomia curata)
- 🟡 **notifications** — `id, user_id, type, actor_user_id NULL, subject_type, subject_id, read_at NULL, created_at`
- 🟢 **migrations** — tracking del runner.
- ⚪ **audit_log**, **search_log**.

### Ricerca / discovery
- 🟡 MVP: query MySQL + **FULLTEXT** su `profiles(display_name, headline, bio)` + filtri (sport, tipo, luogo).
  Upgrade a motore esterno ⚪.

---

## 6. API (contratto, `/api/v1`)
Envelope uniforme: `{ "data": ..., "meta": ..., "errors": [...] }`. Codici HTTP semantici.

**Auth** (doppio guard: sessione web **o** Bearer token)
- `POST /auth/register` · `POST /auth/login` (→ access+refresh token) · `POST /auth/refresh` · `POST /auth/logout`
- `GET /auth/verify?token=` · `POST /auth/password/forgot` · `POST /auth/password/reset`
- `GET /me`

**Profili** `GET /profiles/{handle}` · `PATCH /profiles/me` · sotto-risorse `experiences|achievements|links|contacts` (CRUD) · `POST /media`

**Grafo sociale** `POST /profiles/{id}/follow` · `DELETE …/follow` · `GET …/followers|following` ·
`POST /connections` (request) · `PATCH /connections/{id}` (accept/decline) · `GET /connections`

**Feed** `GET /feed` · `POST /posts` · `POST /posts/{id}/like` · `POST /posts/{id}/comments`

**Messaggi** `GET /conversations` · `GET /conversations/{id}/messages` · `POST /conversations/{id}/messages`

**Opportunità** `GET /opportunities` · `POST /opportunities` · `POST /opportunities/{id}/applications`

**Verifica** `POST /verifications` · `GET /verifications/me`

**Altro** `GET /sports` · `GET /search?q=&type=&sport=` · `GET /notifications`

---

## 7. Rendering & SEO
- **Server-rendered** (PHP views, escaping automatico): profili pubblici (`/atleti/{handle}`), pagine sport,
  directory, landing. Con `<title>`, meta OpenGraph, **JSON-LD** (schema.org/Person, SportsTeam…), sitemap.
- **Client (vanilla JS)**: interazioni (follow, like, invio messaggi, form) via API; progressive enhancement.
- **Aree app** (feed, messaggi, impostazioni): app-shell + API.
- Stesso backend: i controller Web rendono HTML usando gli **stessi Service** dei controller Api.

---

## 8. Architettura del codice
```
public/
  index.php            # UNICO front controller: instrada web (/...) e API (/api/v1/...)
  .htaccess            # tutto -> index.php ; header sicurezza
  assets/              # css/js/img del design system mobile-first
  uploads/             # media PUBBLICI (avatar, cover, post) — serviti direttamente
src/
  Core/                # Env, Config, Container, Router, Request, Response, Json, View,
                       # Session, Csrf, Auth (SessionGuard + TokenGuard), Validator,
                       # Db (PDO), Migrator, Logger, ErrorHandler, RateLimiter
  Domain/<Dominio>/    # Entity + Repository + Service per: Users, Auth, Profiles, ProfileTypes,
                       # Connections, Feed, Messaging, Opportunities, Verification, Billing,
                       # Media, Sports, Notifications, Search
  Http/
    Controllers/Web/   # rendono HTML
    Controllers/Api/V1/# rendono JSON
    Middleware/        # Auth, Admin, Csrf, RateLimit, ...
views/                 # layouts/ pages/ partials/ (server-rendered, auto-escaped)
database/migrations/   # schema versionato ; database/seeds/ (sport)
config/  jobs/  storage/(logs,cache,uploads privati)  vendor/(manuale)
```
- **Autoloader PSR-4 a mano** (`Spoome\` → `src/`), niente Composer sul server.
- **Container** minimale per il wiring dei Service (testabilità).

---

## 9. Sicurezza
- Docroot = `public/` → `src/`, `config/`, `storage/`, `vendor/`, `jobs/` non raggiungibili via web.
  (Se il pannello SiteGround non consente di spostare la docroot di `/beta/`: fallback con `.htaccess` deny.)
- CSRF su ogni form/muta di stato web; token Bearer per API con hashing a riposo, scadenza, revoca.
- Escaping automatico in View; password `password_hash`; rate limiting su login/registrazione/reset.
- Upload: whitelist MIME/estensione, dimensione, re-encoding immagini; documenti di verifica **fuori** dalla docroot.
- Header: CSP, X-Content-Type-Options, niente `Access-Control-Allow-Origin: *` (CORS allowlist per le app native).
- Nessun segreto nel repo (solo `.env`); `display_errors` off in prod, log su `storage/logs`.

---

## 10. Ambiente & deploy
- **SiteGround shared**, PHP 8.x. Beta: `https://spoome.it/beta/`. Prod `spoome.it` separata/intoccata.
- **Deploy**: PhpStorm auto-upload (SFTP) ad ogni modifica. `vendor/` versionato a mano.
- **DB**: `dbz33z7hapyekg` (stesso host). "DB da zero" = si (ri)crea lo schema v2 (nuove tabelle) — le query/migrazioni
  le esegue Massimo. Runner web protetto da `MIGRATION_TOKEN` (disabilitato in produzione).
- **Base path**: la app vive sotto `/beta/` → URL e cookie tengono conto del prefisso (`BASE_PATH`).

---

## 11. Roadmap a fasi (l'architettura accoglie tutto da subito; si riempie a strati)
- **F0 — Fondamenta** 🟢 front controller (web+API), Router, Request/Response/Json, Config/Env, Db/PDO, View+escaping,
  Session, Csrf, Auth guard (session+token), Validator, Logger, ErrorHandler, Migrator, design system mobile-first, health route.
- **F1 — Identità & accesso** 🟢 users, auth (register/login/verify/reset), token API, `/me`, profilo base + tipi.
- **F2 — Profilo atleta** 🟢 profilo pubblico server-rendered (SEO) + editor; experiences/achievements/links/contacts; media/avatar; sport.
- **F3 — Grafo sociale** 🟡 follow + connections + notifiche base.
- **F4 — Feed** 🟡 post, like, commenti, feed.
- **F5 — Messaggi** 🟡 conversazioni 1-a-1 (polling).
- **F6 — Opportunità** 🟡 bacheca + candidature.
- **F7 — Verifica** 🟡 richiesta con documento + revisione admin + badge.
- **F8 — Freemium** 🟡 piani, abbonamenti, gate entitlement.
- **F9 — Hardening & scala** ⚪ ricerca avanzata, realtime, pagamenti, performance/cache, sitemap, app native.

---

## 12. Decisioni aperte (da sciogliere strada facendo)
1. **Docroot `/beta/`**: possiamo puntarla a `public/`? (altrimenti front controller a root + `.htaccess` deny).
2. **Handle profilo**: schema URL definitivo (`/atleti/{handle}` per gli atleti; `/u/{handle}` generico?).
3. **Realtime messaggi**: polling MVP ok? servizio esterno per il futuro?
4. **Pagamenti freemium**: quale provider quando ci arriveremo (Stripe/PayPal), vincoli shared hosting.
5. **Tassonomia sport**: da quale lista partiamo per il seed?
6. **Skill UI/UX**: da installare (fonte da fornire) per il design system.
