# Spec — Modello multi-profilo / page-admin (identità LinkedIn-style)

> Status: DRAFT per revisione founder. No production code — solo design.
> Vincoli: sicurezza MASSIMO, beta live intoccabile, PDO no placeholder riusati,
> API-first, dark/giallo/no-green/no-emoji. Migrazioni non distruttive + reversibili.

---

## 0. Sintesi esecutiva

Oggi l'ownership è **1:1 implicito**: una persona = un utente = UN profilo, e l'autorizzazione
alla scrittura non è un check esplicito ma è **codificata nella derivazione**
`ProfileRepository::findByUserId($user->id) → $profile->id`. Ovunque nel codice l'"acting
profile" è "l'unico profilo che quell'utente possiede", e la difesa a livello dati è lo scoping
`WHERE profile_id = :p`.

Il modello LinkedIn richiede: **una persona (profilo personale) + N pagine organizzazione che
amministra**. La leva è che il grafo sociale è già interamente su `profile_id` (post, follow,
connection, like, endorsement, message), quindi "agire come pagina" è meccanicamente banale —
basta usare il `profile_id` della pagina. Il lavoro vero è **(A) AUTHZ**: questo utente può agire
come questo profilo? e **(B) CONTEXT**: come quale profilo sto agendo adesso?

La soluzione:
1. Tabella **`profile_members(profile_id, user_id, role)`** → sorgente di verità dell'authz.
   `profiles.user_id` **resta** come "primary owner" denormalizzato (back-compat + claim flow),
   tenuto in sync.
2. **Acting context** in sessione (`acting_profile_id`, default = profilo personale) + header
   `X-Acting-Profile` per i client nativi, **sempre ri-validato server-side** contro
   `profile_members`.
3. Un unico punto di ingresso authz — **`ActingContext::resolve()` / `canActAs()`** — che
   sostituisce ogni `findByUserId($user->id)`.
4. Rollout **additivo + dual-read**: il comportamento 1:1 continua a funzionare identico durante
   tutta la migrazione (un utente con un solo profilo personale non nota nulla).

---

## 1. Stato attuale (verificato leggendo il codice, non assunto)

### 1.1 Ownership 1:1 e derivazione dell'acting profile
- `profiles.user_id INT NOT NULL` in origine (`0001_create_core_tables.php`); reso NULLable dal
  claim flow (`createUnclaimed` inserisce `user_id = NULL, claim_status = 'unclaimed'`).
- `CurrentUser::resolve(Request)` → `User` (sessione web, poi Bearer). **Non** conosce i profili.
- L'acting profile è derivato **in ogni controller** con
  `(new ProfileRepository())->findByUserId($user->id)->id`. Non esiste alcun check
  `user_id === profile.user_id`: è **implicito** perché `findByUserId` ritorna per definizione
  l'unico profilo di quell'utente (`WHERE user_id = :uid LIMIT 1`).
- Difesa a livello dati: ogni scrittura scoping-a per profilo, es.
  `PostRepository::delete(id, profileId)` → `DELETE ... WHERE id = :id AND profile_id = :p`;
  `ProfileDetailsRepository::updateExperience(id, profileId, ...)` idem. Questo è
  defense-in-depth ed è **già corretto per il multi-profilo** — non va toccato.

### 1.2 Sessione e nav helpers
- Al login (`AuthController::startUserSession`) si denormalizza in sessione:
  `user_id`, `role`, `profile_id` (`= findByUserId($userId)?->id`, può essere null per un
  claimant). `Session::has('profile_id')` distingue "assente" da "null".
- Nav helper `dm_unread()` legge `Session::get('profile_id')` → `MessageRepository::unreadTotal($pid)`.
  `notif_unread()` è per-**utente** (`NotificationRepository::unreadCount($uid)`), non per-profilo.
  `is_admin()` legge `Session::get('role')`. Girano su OGNI pagina autenticata → un bug qui = 500 globale.

### 1.3 Registrazione
- `AuthService::register(email, pw, displayName, profileTypeKey, ...)` → transazione atomica
  `users.create` + `profiles.create(userId, typeId, handle, displayName)`. Il `profileTypeKey`
  può oggi essere anche un tipo organizzazione → **self-register come società** (path da rimuovere).
- `AuthService::registerClaimant(email, pw)` → crea SOLO l'utente, senza profilo (per il claim flow).

### 1.4 Claim flow (già in produzione)
- `ClaimService::request` (utente senza profilo → profilo unclaimed), `approve`/`reject` (admin).
- `approve` chiama `ProfileRepository::assignOwner(profileId, userId)` →
  `UPDATE profiles SET user_id = :uid, claim_status = 'claimed'`. Vincolo attuale:
  `userHasProfile($userId)` deve essere false (un claimant ha zero profili). Con il multi-profilo
  questo vincolo va **rivisto** (vedi §4.4 e §8).

### 1.5 Call-sites che derivano l'acting profile (l'elenco che conta)
Pattern `findByUserId($user->id)` / `findEnrichedByUserId($user->id)` → usato come acting profile:

**Web** (`src/Http/Controllers/Web/`):
`MyProfileController::edit`+`::update`, `ProfileDetailsController::context`,
`SkillController` (×2), `LinkController`, `FollowController`, `ConnectionController`,
`NetworkController` (×2), `MessagesController::me`, `FeedController::actingProfile`,
`ProfileController::show` (viewer, read-only), `AuthController::startUserSession`.

**API** (`src/Http/Controllers/Api/V1/`):
`MeController` (×4), `FeedController` (×2), `MessagesController`, `SkillController` (×2),
`LinkController`, `ProfileController` (×2, follow/connect), `SuggestionController` (read),
`StreamController` (SSE unread, read), `AuthController` (login response profile).

**Altri**: `helpers.php::dm_unread` (via sessione), `MediaService` (×2, avatar/cover),
`AdminUserService::toggleProfileVerified` (findByUserId del target — admin, non acting).

→ Vedi §6 per la classificazione completa (write-authz vs read-context) e l'ordine di refactor.

---

## 2. Modello dati — `profile_members` (migrazione 0023)

> **Nota numerazione**: il brief indicava "0021 → 0022", ma `0022_seed_profile_attributes_schema.php`
> **esiste già** (ultima applicata). La prossima libera è **0023**. Inoltre il sibling
> `docs/checkpoint-3/profile-types-spec.md` propone `profile_affiliations` come "0022" (stale) e
> ipotizza 0024/0025: **coordinare la numerazione** — questa spec reclama **0023** per
> `profile_members`; affiliations slitta a 0024+.

### 2.1 Ruoli e capabilities

| Ruolo    | Modifica profilo/attrs/details/skills | Post / comment / like / follow / connect / message AS page | Gestione roster | Transfer ownership | Delete pagina |
|----------|:---:|:---:|:---:|:---:|:---:|
| `owner`  | ✓ | ✓ | ✓ (tutti i ruoli, incl. admin) | ✓ | ✓ |
| `admin`  | ✓ | ✓ | ✓ (solo editor: invita/rimuove editor) | ✗ | ✗ |
| `editor` | ✓ | ✓ | ✗ | ✗ | ✗ |

- **Profilo personale**: esattamente **un** membro con ruolo `owner` = la persona. Non ammette
  `admin`/`editor` (un profilo personale non si co-amministra — vincolo applicativo, vedi §2.4).
- **Pagina organizzazione**: **almeno un** `owner`; zero o più `admin`/`editor`.
- Regola di sicurezza roster: un `admin` **non** può gestire altri `admin` né `owner` (no
  self-escalation, no lateral escalation). Solo `owner` tocca `admin`/`owner`.
- Livello richiesto per capability (helper `canActAs`):
  - edit/post/act → `editor` (soglia minima)
  - gestione editor → `admin`
  - gestione admin, transfer, delete, manage-owner → `owner`

### 2.2 DDL

```sql
CREATE TABLE IF NOT EXISTS profile_members (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    profile_id  INT          NOT NULL,
    user_id     INT          NOT NULL,
    role        ENUM('owner','admin','editor') NOT NULL DEFAULT 'editor',
    invited_by  INT          NULL,               -- user_id di chi ha invitato (audit)
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_member (profile_id, user_id),   -- un utente = un solo ruolo per profilo
    KEY idx_member_user (user_id),                -- "le pagine che gestisco" (hot path switcher)
    KEY idx_member_profile_role (profile_id, role),
    CONSTRAINT fk_member_profile FOREIGN KEY (profile_id) REFERENCES profiles (id) ON DELETE CASCADE,
    CONSTRAINT fk_member_user    FOREIGN KEY (user_id)    REFERENCES users (id)    ON DELETE CASCADE,
    CONSTRAINT fk_member_inviter FOREIGN KEY (invited_by) REFERENCES users (id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- `ON DELETE CASCADE` su profile/user: cancellando un profilo o un utente si puliscono i member.
  (Il last-owner safeguard §5 vive a livello applicativo, non FK.)
- Nessun ENUM riutilizzato in query problematiche; tutti i filtri per `role` sono parametrizzati.

### 2.3 Reversibilità
`down()` = `DROP TABLE IF EXISTS profile_members;`. Non distruttivo per `profiles` (che resta la
sorgente per il fallback dual-read). Registrare con
`INSERT INTO migrations (migration) VALUES ('0023_create_profile_members')`.

### 2.4 `profiles.user_id`: si tiene (denormalizzato) — decisione
**Raccomandazione: TENERE `profiles.user_id`** come "primary owner" denormalizzato, **in sync**
con `profile_members` (l'`owner` primario). Motivi:
- ~25 query esistenti (`findByUserId`, `findEnrichedByUserId`, claim `assignOwner`,
  `userHasProfile`, `MediaService`) leggono `user_id`. Deprecarlo = big-bang rischioso sulla beta.
- `profile_members` diventa **sorgente di verità per l'AUTHZ**; `profiles.user_id` resta un
  denormalizzato di comodità/back-compat + il perno del claim flow.
- **Invariante da mantenere**: per ogni profilo con `user_id` non-null esiste una riga
  `profile_members(profile_id, user_id, role='owner')`. Le operazioni che cambiano l'owner
  (claim approve, transfer, remove-owner) aggiornano **entrambi** in un'unica transazione.
- Personale: `user_id` = la persona, per sempre (no transfer di profilo personale).

---

## 3. Backfill & back-compat (dentro la 0023, idempotente)

```sql
-- Ogni profilo già posseduto → un membro 'owner'. Idempotente (INSERT IGNORE su UNIQUE).
INSERT IGNORE INTO profile_members (profile_id, user_id, role, invited_by, created_at)
SELECT p.id, p.user_id, 'owner', NULL, COALESCE(p.created_at, NOW())
FROM profiles p
WHERE p.user_id IS NOT NULL;
```

- I profili unclaimed (`user_id IS NULL`) **non** generano membri — corretto (nessun controllore).
  Al claim `approve`, oltre a `assignOwner`, si inserisce la riga `owner` (vedi §7 refactor claim).
- Gli org self-registrati esistenti hanno già `user_id` → diventano `owner`. Nessun caso orfano.
- **Dual-read durante il rollout**: `ActingContext` e `canActAs` leggono `profile_members`; se
  (per sessioni/dati pre-deploy) manca la riga ma `profiles.user_id === user->id`, si tratta come
  `owner` (fallback identico al comportamento 1:1). Il backfill rende questo ramo quasi mai usato,
  ma garantisce zero regressioni durante la finestra di deploy.

---

## 4. Acting context ("agisci come")

### 4.1 Concetto
Un utente loggato ha: **1 profilo personale** (via `profiles.user_id` = suo, tipo persona) + un
insieme di **profili che gestisce** (`profile_members.user_id` = suo). L'"acting profile" è quello
per cui vengono attribuite le azioni (post/comment/follow/message/edit/roster).

### 4.2 Trasporto del contesto
- **Web (sessione)**: `Session::set('acting_profile_id', <id>)`. Default = profilo personale
  (`profiles.user_id`). Uno **switcher** in nav ("Pubblica come…" / "Agisci come…") fa POST
  CSRF-protected a `POST /agisci-come` con `{profile_id}` → il server valida membership e
  aggiorna la sessione. Persistente tra le pagine finché non si cambia o si fa logout.
- **API/native (Bearer)**: header **`X-Acting-Profile: <profile_id>`** (oppure param
  `acting_profile_id` nel body per le POST). **Stateless**: se assente → default profilo
  personale del bearer user. Mai persistito lato server per i client nativi.
- **Regola d'oro**: il client dichiara l'intento, il **server ri-valida SEMPRE** la membership
  server-side ad ogni richiesta. Il valore in sessione/header non è mai fidato di per sé — è solo
  un suggerimento che passa da `canActAs(user, profileId, requiredRole)`.

### 4.3 API di risoluzione (nuovo, `src/Domain/Auth/ActingContext.php`)
```
ActingContext::resolve(Request, User): int            // profile_id acting, validato; default personale
ActingContext::canActAs(User, int $profileId, string $requiredRole): bool
ActingContext::roleFor(User, int $profileId): ?string // owner|admin|editor|null
ActingContext::managed(User): array                   // pagine che gestisce (per switcher)
```
- `resolve` legge (nell'ordine): header `X-Acting-Profile` (Bearer) → `Session acting_profile_id`
  (web) → default personale. Poi **chiama `canActAs(...,'editor')`**; se fallisce → **fallback al
  personale** (non 403 silenzioso per un GET; per le POST di scrittura → 403, vedi §6).
- `canActAs`: query parametrizzata unica su `profile_members`
  `SELECT role FROM profile_members WHERE profile_id = :pid AND user_id = :uid LIMIT 1`
  + confronto gerarchia ruoli (`owner ≥ admin ≥ editor`). **Dual-read fallback** §3.
- Cache per-richiesta negli attributi della `Request` (come fa già `CurrentUser`), per non
  ripetere la query nelle hot path (nav helpers + azione).

### 4.4 Nav helpers aggiornati
- `dm_unread()` / badge messaggi → devono riflettere l'**acting profile**, non più il fisso
  `Session profile_id`. Nuova sorgente: `ActingContext::resolve()->id`. (I DM sono su `profile_id`,
  quindi cambiando contesto cambia l'inbox mostrata — coerente con LinkedIn.)
- `notif_unread()` → **resta per-utente** in Fase 1 (le notifiche sono su `user_id`). Decisione
  aperta §9: se le notifiche di una pagina debbano diventare per-profilo (P2).
- Attenzione MASSIMA: questi helper girano su ogni pagina → il refactor deve essere **null-safe**
  e col fallback al personale, mai lanciare (un throw = 500 globale).

---

## 5. Creare una pagina (org) — flusso

Sostituisce il path "self-register come società". La persona si registra **sempre come persona**,
poi crea pagine.

### 5.1 Flusso
1. Utente loggato (profilo personale esistente) → "Crea una pagina".
2. Form: tipo org (`societa|associazione|federazione` — solo tipi con `is_organization=1`),
   nome, handle (auto da nome, `uniqueHandle`), sport opzionale.
3. Transazione atomica:
   - `INSERT profiles (user_id=<creatore>, claim_status='claimed', profile_type_id, handle,
     display_name, sport_id)` — **`user_id` = creatore** (primary owner denormalizzato).
   - `INSERT profile_members (profile_id, user_id=<creatore>, role='owner', invited_by=NULL)`.
4. Redirect al profilo pagina; opzionalmente switch immediato dell'acting context sulla nuova pagina.

### 5.2 Endpoint
- **Web**: `GET /pagine/nuova` (form) · `POST /pagine` (CSRF). Controller `PageController::create`
  → `PageService::create(User $creator, array $input): ServiceResult`.
- **API**: `POST /api/v1/pages` (Bearer-only write). Envelope `{data:{id,handle}}` / `{errors}`.
- Anti-abuso: rate-limit per utente sulla creazione pagine (riuso `RateLimiter`, es. N/giorno).
  Free per decisione founder → vedi §8.
- **Decisione**: cap sul numero di pagine per utente? (raccomando un soft-cap generoso + audit).

### 5.3 Registrazione — cosa cambia
- `AuthService::register` deve **rifiutare** i `profileTypeKey` organizzazione (solo tipi persona).
  Il form di registrazione perde la scelta "società"; UI: "Registrati come persona, poi crea la
  tua società/associazione".
- Org self-registrati storici: già `user_id` non-null → backfillati `owner`. Nessuna azione.

---

## 6. Refactor authz — call-site list & ordine sicuro

**Principio**: sostituire ogni derivazione `findByUserId($user->id)->id` con
`ActingContext::resolve()` (read) o con `canActAs(...,requiredRole)` + acting id (write). La difesa
a livello dati (`WHERE profile_id = :p`) **rimane** — è già multi-profilo-safe.

### 6.1 Classi di call-site

**A. WRITE — devono passare per `canActAs(...,'editor'|'admin'|'owner')` e usare l'acting id**
(403 se membership assente):
1. `MyProfileController::update` (+ `edit` render) — **profile core edit** → `editor`.
2. `Api/MeController` update profilo (×) — → `editor`.
3. `ProfileDetailsController` (add/update/delete experience/achievement/link) — → `editor`.
   (già scoping `WHERE profile_id`; aggiungere il gate acting.)
4. `Api/MeController` details CRUD — → `editor`.
5. `SkillController` web (×2) + `Api/SkillController` (×2) — skills add/endorse-context — → `editor`.
6. `LinkController` web + api (unfurl attribuito al profilo) — → `editor`.
7. `FeedController` web (`actingProfile`→`PostService::create`) + `Api/FeedController` (×2) — post
   create/delete — → `editor`. (`PostService::delete` già scoping per profileId.)
8. `PostEngagementService` chiamanti (like/comment/deleteComment) — `actorProfileId` = acting — → `editor`.
9. `FollowController` web + `Api/ProfileController` follow/unfollow (×2) — actor = acting — → `editor`.
10. `ConnectionController` web + eventuale api — actor = acting — → `editor`.
11. `MessagesController` web (`me`) + `Api/MessagesController` — DM actor = acting — → `editor`
    (vedi §7 per "DM come pagina").
12. `MediaService` (avatar/cover upload, ×2) — oggi `findEnrichedByUserId($userId)`; deve accettare
    il **profileId acting** + `canActAs('editor')`.
13. **Roster & pagina** (nuovi): `PageMemberController` invite/accept/remove/transfer/leave —
    `admin`/`owner` secondo §2.1.

**B. READ / CONTEXT — usano l'acting id ma senza gate di scrittura** (fallback personale, mai 403):
14. `dm_unread` (helper) — acting id.
15. `Api/StreamController` SSE unread — acting id.
16. `NetworkController` (×2), `Api/SuggestionController` — "il mio grafo" dal punto di vista acting.
17. `ProfileController::show` viewer identity — resta il profilo personale? **Decisione §9**
    (raccomando: viewer = acting, coerente con "sto navigando come la pagina").
18. `Api/AuthController` + `AuthController::startUserSession` — al login setta
    `acting_profile_id = <personale>` (default) oltre a `profile_id` (che resta per back-compat).

**C. INVARIATI** (già corretti, non toccare):
- Tutti i repository con `WHERE ... AND profile_id = :p` (post delete, details update/delete,
  message scoping) — la difesa a livello dati regge già il multi-profilo.
- `notif_unread` (per-utente) — invariato Fase 1.
- Claim flow — refactor mirato in §7 (sync `profile_members`), non parte del gate acting.

### 6.2 Conteggio
≈ **20 call-site di WRITE** (classe A, dedup per file) + **5 di READ/context** (classe B) + i **3
punti di bootstrap** (session login web, api login, nav helper) = **~25–28 punti** da modificare,
concentrati sulla derivazione dell'acting profile. Nessun `user_id === profile.user_id` letterale
da cercare (non esiste): il refactor è "cambia la fonte del `profileId`".

### 6.3 Ordine di rollout sicuro (additivo, dual-read, zero-regressione)
La chiave: introdurre `profile_members` + `ActingContext` **prima** di cambiare qualsiasi
call-site, e far sì che per un utente mono-profilo `resolve()` ritorni esattamente lo stesso id di
prima. Ogni step è deployabile e testabile dal vivo da solo.

- **R0 — Migrazione 0023 + backfill** (nessun codice che la usa ancora). Verifica: ogni profilo
  owned ha una riga `owner`; conteggi coerenti. Reversibile (drop). Zero effetto runtime.
- **R1 — `ActingContext` + `canActAs`** con **dual-read fallback** (member row → altrimenti
  `profiles.user_id`). Unit-testato. Ancora non cablato nei controller. Deploy no-op osservabile.
- **R2 — Bootstrap sessione**: al login setta `acting_profile_id = personale`. Nav
  `dm_unread` passa a `ActingContext::resolve()` (che per mono-profilo = identico a prima).
  **Test dal vivo prioritario** (helper su ogni pagina).
- **R3 — Creazione pagina** (`PageService` + endpoint web/api) + rimozione self-register org.
  Ora esistono utenti con >1 profilo → serve lo switcher.
- **R4 — Switcher UI** (`POST /agisci-come`) + header `X-Acting-Profile` nell'API. Da qui il
  contesto può divergere dal personale.
- **R5 — Cablaggio WRITE (classe A) a ondate**, un dominio per deploy, dal meno hot al più hot:
  skills/links → profile-details → profile core/media → posting/engagement → follow/connect →
  messaging → roster. Ogni ondata: `canActAs` + acting id, poi test dal vivo (create come pagina,
  edit come pagina, 403 se non membro).
- **R6 — Roster management** (`PageMemberController`, inviti/accept/remove/transfer/leave) con
  last-owner safeguard §5-bis.
- **R7 — Refactor claim** per sincronizzare `profile_members` (§7) e rivedere il vincolo
  "un utente = un profilo".

Rollback ad ogni step: i controller non ancora cablati continuano a usare `findByUserId`; per
mono-profilo i due percorsi coincidono, quindi anche uno stato misto è coerente.

---

## 7. Gestione membri, claim, DM-come-pagina

### 7.1 Roster: inviti / accept / remove / transfer / leave
- **Invito**: `owner`/`admin` invita per email o handle utente. Modello: riga
  `profile_members` con stato "pending"? → **Decisione §9**. Raccomando Fase 1 **semplice**:
  l'invito crea una **notifica + record di invito**; l'accettazione inserisce la riga
  `profile_members`. Per non aggiungere una colonna stato subito, si può usare una tabella
  `profile_member_invites(profile_id, invited_user_id, role, token, invited_by, expires_at)` e
  materializzare il member solo on-accept (mantiene `profile_members` = "membri effettivi").
- **Remove**: `owner` rimuove chiunque (tranne violare last-owner); `admin` rimuove solo `editor`.
- **Transfer ownership** (`owner` only): transazione — promuove il target a `owner`, e (per
  personale non applicabile) aggiorna `profiles.user_id = target` se si trasferisce il primary
  owner; il vecchio owner resta `admin` o esce (scelta UI). Audit obbligatorio.
- **Leave** (`profile_member` esce da una pagina): consentito **tranne** se è l'ultimo `owner`.

### 7.2 Last-owner safeguard (mirror di `AdminUserService`)
`AdminUserService` già implementa "non rimuovere l'ultimo admin attivo". Replicare identico:
prima di `remove`/`leave`/`demote` di un `owner`, contare gli owner attivi
`SELECT COUNT(*) FROM profile_members WHERE profile_id = :pid AND role = 'owner'`; se `= 1` e il
target è quell'owner → `ServiceResult::fail('...last_owner...', 422)`. Una pagina non resta mai
senza owner. Audit di ogni cambio ruolo/rimozione (riuso `AuditRepository`).

### 7.3 Sicurezza roster
- Gate `canActAs(user, pageId, requiredRole)` server-side su ogni azione (owner per gestire
  admin/owner/transfer/delete; admin per gestire editor). No self-escalation
  (`admin` non promuove sé/altri a `admin`). Tutte le query parametrizzate. CSRF (web) / Bearer (api).

### 7.4 Claim flow — refactor mirato (R7)
- `ClaimService::approve` → oltre a `assignOwner`, in **stessa transazione**
  `INSERT profile_members (profile_id, user_id, role='owner')`. Mantiene l'invariante §2.4.
- Vincolo storico `userHasProfile($userId)` (il claimant non deve avere profili): con il
  multi-profilo va **rilassato/ridefinito** — un utente ora ha sempre un profilo personale. Il
  claim deve verificare che l'utente non sia **già membro** di quel profilo, non che sia senza
  profili. **Decisione §9** (impatta l'onboarding claimant: oggi un claimant nasce senza profilo
  personale; nel nuovo modello ogni persona ha il suo personale).

### 7.5 DM verso una pagina — risposta Fase 1
- I DM sono `profile_id → profile_id`. Un messaggio "a una pagina" arriva alla conversazione del
  `profile_id` della pagina. **Chi lo legge?** Fase 1 raccomandata: **qualsiasi membro con
  ruolo ≥ editor** che agisce come la pagina vede l'inbox della pagina (perché `dm_unread` e la
  thread list usano l'acting id). Nessun fan-out per-admin in Fase 1. Nota: il badge non-letto è
  condiviso tra i membri (un admin che legge azzera per tutti) — accettabile per la beta; il
  per-membro read-state è P2. `MessageService::send` emette già un evento verso
  `recipient.user_id` (il primary owner) — sufficiente in Fase 1.

---

## 8. Sicurezza / abuso (org free per decisione founder)

Registrazione org gratuita e senza verifica obbligatoria ⇒ **rischio impersonation** (chiunque crea
"AC Milan"). Mitigazioni leggere **senza** verifica obbligatoria ora:
- `verified_at` resta il badge di verifica (già esistente, gestito da admin) — le pagine nuove
  nascono **non verificate**; UI mostra chiaramente "non verificata".
- **Report/claim/takedown**: riusare l'infrastruttura di moderazione e il claim. Un ente reale può
  richiedere il trasferimento/rimozione (percorso admin), e l'admin può verificare/sospendere.
- **Rate-limit** creazione pagine per utente (anti mass-squatting) + audit di ogni creazione.
- **Handle namespace**: gli handle sono globalmente unici (già garantito da `uniqueHandle`) →
  chi crea per primo prende l'handle; il claim/transfer admin risolve le dispute a posteriori.
- Roadmap (non ora): verifica documentale opt-in per il badge, blocklist di nomi protetti.

---

## 9. Decisioni che restano al founder

1. **`profiles.user_id`**: confermare "tenere + sync" (raccomandato) vs deprecare. Raccomando tenere.
2. **Viewer identity in navigazione** (`ProfileController::show`, follow/connect state): quando
   agisco come pagina, "chi sono" per il grafo/relazioni è la **pagina** (raccomandato) o resto
   sempre "io personale"? Impatta cosa mostra "segui/connetti".
3. **Modello inviti**: tabella `profile_member_invites` con accept esplicito (raccomandato) vs
   aggiungere a `profile_members` a un membro subito senza accept.
4. **Claim vs profilo personale**: ora ogni persona ha un profilo personale → il claim non
   "diventa la mia identità" ma "aggiunge una pagina che gestisco". Confermare la nuova semantica
   (e rilassare `userHasProfile`).
5. **Notifiche di pagina** per-profilo (P2) vs per-utente (Fase 1, raccomandato).
6. **Cap pagine per utente** (soft-limit) e limiti rate della creazione.
7. **Numerazione migrazioni**: confermare `profile_members = 0023` e slittare `profile_affiliations`
   (profile-types-spec) a 0024+, per evitare collisione con `0022` già applicata.

---

## 10. Riepilogo tabelle/endpoint nuovi

| Artefatto | Tipo | Note |
|-----------|------|------|
| `profile_members` | tabella (0023) | sorgente authz; backfill owner |
| `profile_member_invites` | tabella (P1/decisione) | inviti con accept |
| `ActingContext` / `canActAs` | classe Domain/Auth | risoluzione + validazione server-side |
| `PageService` / `PageController` | service+controller | crea pagina (web + api) |
| `PageMemberController` | controller | roster: invite/accept/remove/transfer/leave |
| `POST /pagine`, `GET /pagine/nuova` | rotte web | crea pagina (CSRF) |
| `POST /agisci-come` | rotta web | switch acting context (CSRF) |
| `POST /api/v1/pages` | rotta api | crea pagina (Bearer) |
| `X-Acting-Profile` | header api | acting context stateless, ri-validato |
| `POST /api/v1/pages/{id}/members` … | rotte api | roster management (Bearer) |
