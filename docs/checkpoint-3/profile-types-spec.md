# Spec — Potenziare le tipologie di profilo (Company-Page analog)

**Checkpoint 3 · Product Strategist + Backend Architect**
Obiettivo: rendere **società / associazioni / federazioni / fan** cittadini di prima classe accanto agli atleti, e aggiungere le prossime feature "LinkedIn-style" core-first (recommendations, affiliazioni strutturate). **NO codice di produzione qui**: solo spec, DDL e piano.

Vincolo di rotta (CLAUDE.md): **core-first** — costruire la profondità del network. **DEFERRED**: Opportunities / job / billing (marketplace) — decide il founder più avanti.

---

## 0. Sintesi esecutiva

Spoome oggi è **atleta-centrico solo nella UI e negli URL**, ma il *data model* è già sorprendentemente type-neutral: `un utente = un profilo`, e tutto il grafo sociale (post, follow, connessioni, like, commenti, skill, endorsement) è agganciato a `profile_id`, mai a `user_id`. Un'organizzazione **è già** un attore di prima classe a livello dati — posta, segue, si connette ed è endorsata *come sé stessa* attraverso gli identici code path di una persona. Quello che manca è **(a)** campi descrittivi specifici per tipo, **(b)** la relazione strutturata **atleta↔società** (oggi solo testo libero), **(c)** una pagina/editor *type-aware*, **(d)** naming di rotta corretto.

Due colonne già esistono nello schema e sono **completamente inutilizzate** — sono l'aggancio previsto: `profile_types.attributes_schema JSON` (definizione dei campi per tipo) e `profiles.attributes JSON` (valori per profilo). Le attiviamo.

---

## 1. Stato attuale (verificato, non assunto)

### 1.1 Modello dati
- **`profiles`** (`0001_create_core_tables.php`, righe 104-131): `id, user_id, profile_type_id, handle, display_name, headline, bio, sport_id, avatar_media_id, cover_media_id, location_city/region/country, verified_at, visibility ENUM('public','members','private'), attributes JSON NULL, created_at, updated_at`. `FULLTEXT ft_profiles_search(display_name, headline, bio)`. Più tardi: `claim_status` (0012), `followers_count/following_count/connections_count` (0014).
- **`UNIQUE KEY uq_profiles_user (user_id)`** → **un login ⇒ esattamente un profilo**. Non esiste multi-profilo né "page admin": l'organizzazione *è* il login. (Decisione aperta §11.)
- **`profile_types`** (righe 92-101): `id, key, label, is_organization TINYINT(1), attributes_schema JSON NULL, active, sort`. Seed: `atleta`(1, org=0), `societa`(2, org=1), `associazione`(3, org=1), `federazione`(4, org=1), `fan`(5, org=0).
- **Colonne inutilizzate (grep su `src/`)**: `profile_types.attributes_schema` → **mai letta/scritta, dead schema**. `profiles.attributes` → **mai letta/scritta**. `is_organization` → selezionata e passata alla view, ma **nessun `if`/`WHERE` cambia comportamento** in base ad essa (unico uso funzionale: `@type` schema.org in `show.php` e una classe CSS cosmetica).

### 1.2 Sotto-entità del profilo (`0005_create_profile_sections.php`)
- **`profile_experiences`**: `profile_id, org_name VARCHAR(160), role, location, start_year, end_year, is_current, description, sort`. **La relazione atleta↔club è puro testo libero** (`org_name` è una stringa; nessun FK a un `profiles` reale). "AC Milan" nell'esperienza è solo testo, scollegato da un eventuale profilo Milan.
- **`profile_achievements`**: `profile_id, title, year, description, sort` (palmarès).
- **`profile_links`**: `profile_id, kind, label, url` (kind whitelisted in PHP).
- **`profile_skills` + `skill_endorsements`** (0017): skill free-text (max 20/profilo), endorsement **gated su connessioni accettate**, contatore denormalizzato. **Non esistono recommendations** (testimonianze free-text) — grep negativo.

### 1.3 Grafo sociale — tutto su `profile_id`, zero branch per tipo
- **`connections`** (0008): coppia unica, `status ENUM('pending','accepted')`, request-or-accept, contatore denormalizzato. Qualsiasi profilo si connette a qualsiasi profilo pubblico. Nessuna distinzione org/persona.
- **`follows`** (0007): asimmetrico, idempotente. Suggerimenti (`ProfileRepository::suggestedFor`) = qualsiasi profilo pubblico non già seguito, ordinato per sport-match poi follower_count. **Persone e org mescolate, nessun filtro tipo.**
- **`posts` / `activities`** (0009): **owner = `profile_id`**. `PostService::create($me->id, ...)` dove `$me->id` è il profile id. → **Le org postano nativamente come sé stesse. Il post mechanism funziona già per i profili org**; nessuna feature nuova necessaria per far postare un'organizzazione.
- **Feed** = self ∪ followed ∪ connessioni-accettate.

### 1.4 UI, routing, registrazione
- **Routing**: tutti i profili sotto **`/atleti/{handle}`** (`config/routes.php:130-150`), un unico spazio handle (handle globalmente unici via `uq_profiles_handle`). `WebProfile::show` + `views/pages/atleti/show.php` servono *ogni* tipo. **API già type-neutral**: `/api/v1/profiles/{handle}` (231-283).
- **Show view** (`views/pages/atleti/show.php`): hero + about + skills + experiences + achievements + links. **Interamente CV-atleta**, identica per ogni tipo. Unico branch org = JSON-LD `@type` (SportsOrganization vs Person, righe 6-38). Nessun campo org (roster, anno fondazione, categorie…).
- **Editor** (`/profilo`, `MyProfileController::update`, `views/pages/profilo/edit.php`): campi `display_name, handle, headline, bio, sport, location_*, visibility` + media + esperienze/palmarès/link/competenze. **Zero type-awareness**. `profile_type_id` **non modificabile** post-registrazione. Completeness meter atleta-shaped, identico per tutti.
- **Registrazione** (`AuthService::register`, `AuthController:81`): il form radio mostra **tutti** i tipi attivi (default `atleta`); validazione `in:` sui `activeTypeKeys()`. → **Chiunque può auto-registrarsi come società/federazione** oggi. (Rischio impersonazione — decisione §11.)
- **Discovery**: directory `/atleti` (`ProfileController` + `listPublic`) ha già una **facet filtro per tipo** (`?tipo=`, `pt.key = :type`) e per sport, con FULLTEXT. Nessuna rotta separata per org.

**Conclusione**: le organizzazioni sono di prima classe solo a livello dato/etichetta. Rotte, show e editor sono atleta-centrici e condivisi. Il lavoro è quasi tutto **additivo** (basso rischio di regressione), perché il grafo è già type-neutral.

---

## 2. Modello campi type-specific — raccomandazione

### 2.1 Raccomandazione: **modello ibrido**
1. **Campi descrittivi "soft", specifici per tipo → JSON** usando le due colonne già presenti:
   - `profile_types.attributes_schema` = **definizione** dei campi per tipo (chiave, label IT, tipo input, validazione, obbligatorietà per il completeness meter).
   - `profiles.attributes` = **valori** per singolo profilo.
2. **Relazioni e dati queryabili → tabelle relazionali dedicate** (affiliazioni, team) con FK/indici.

### 2.2 Perché ibrido (e non solo colonne, o solo JSON)
- I campi tipo-specifici sono **sparsi, eterogenei per tipo, raramente filtrati** (categoria/serie, anno fondazione, colori sociali, ambito federale…). Colonne dedicate ⇒ tabella larga e sparsa + un `ALTER TABLE` per ogni nuovo campo/tipo (costoso, rischioso su beta live SiteGround). JSON ⇒ evoluzione **senza migrazione DDL**, schema-per-tipo pulito. Le colonne **esistono già**: costo di attivazione minimo, zero `ALTER`.
- **Ma** ciò che è *relazione* o va *joinato/indicizzato/contato* (roster, "milita in", società affiliate) **non** deve stare in JSON: va normalizzato (§3). Regola: *JSON per descrivere, tabelle per collegare e cercare.*
- I pochi campi che diventano **facet di ricerca** (es. `serie`/categoria) possono, se e quando servirà filtrarli, essere promossi a colonna generata o colonna dedicata in un secondo momento — ma non prima che il bisogno esista.

### 2.3 Schema campi per tipo (contenuto di `attributes_schema`)
Definizione JSON (esempio, forma `{"fields":[{key,label,type,required,max,options?}]}`). Output sempre via `e()`; input validato contro lo schema (whitelist di chiavi + tipo).

- **società (2)**: `categoria/serie` (select o testo: es. "Eccellenza", "Serie D"), `anno_fondazione` (int), `sede_impianto` (testo: stadio/campo), `citta_sede` (già in location), `colori_sociali` (2 hex, resi come pastiglie — **giallo resta l'accento globale, i colori società sono contenuto** non tema), `sito_ufficiale` (→ usare `profile_links`), `numero_tesserati` (int, opz). Team/categorie → **tabella** (§3.4).
- **federazione (4)**: `ambito` (enum: nazionale/regionale/provinciale), `regione` (se regionale), `discipline` (lista sport/discipline), `ente_riconoscimento` (es. CONI), `anno_fondazione`. Società affiliate → **affiliazioni** (§3.3).
- **associazione (3)**: `tipo_attivita` (testo/enum: ASD, promozione sportiva…), `anno_fondazione`, `numero_membri` (int), `attivita_principali` (testo breve). Membri → affiliazioni.
- **atleta (1)**: già ricco (headline, sport, esperienze, palmarès, skill). Aggiunta opzionale in `attributes`: `ruolo_preferito`, `piede/mano`, `altezza/peso` (opz, privacy-aware). Priorità bassa.
- **fan (5)**: **minimo**. Nessun campo professionale. Solo identità base + follow/attività.

**Nota migrazione dati**: popolare `attributes_schema` è **data-only** (UPDATE su 5 righe), reversibile, senza rischio strutturale.

---

## 3. Modello di affiliazione — la relazione chiave (analogo "employment")

### 3.1 Idea
Sostituire/aumentare il testo libero `org_name` con un **link strutturato** tra profili quando l'organizzazione **esiste su Spoome**. Alimenta contemporaneamente: il **roster/Membri** dell'org ("i nostri atleti"), il **"milita in / ha militato in"** dell'atleta, e la **discovery org→persone**. Vale anche per **società↔federazione** (affiliazione) e **società↔associazione**.

Il `profile_experiences` **resta** per la storia off-platform (club non presenti su Spoome). La show view **fonde** esperienze free-text + affiliazioni confermate in un'unica "Carriera".

### 3.2 Conferma bilaterale (analogo connection/employment)
Come una connessione, l'affiliazione ha due lati e uno **stato**:
- L'atleta dichiara "milito in X" → `status='pending'`, `requested_by='member'` → la società conferma.
- Oppure la società aggiunge l'atleta al roster → `status='pending'`, `requested_by='org'` → l'atleta conferma.
- Solo `status='confirmed'` la rende visibile su **entrambe** le pagine. Questo dà valore di *verifica sociale* (un roster confermato non è auto-dichiarato).
- **Authz**: può confermare solo il lato *destinatario* (org owner conferma le richieste verso l'org; il member conferma quelle verso di sé). Autorizzazione **al livello dati** (WHERE su ownership), defense-in-depth.
- Estensione: se l'org è **verificata** (`verified_at`), la sua conferma può contribuire al badge/segnale di verifica dell'affiliazione dell'atleta (vedi §7). Decisione §11.

### 3.3 DDL — `profile_affiliations` (migrazione **0022**)

> Ultima migrazione applicata = 0021. Questa è **0022**.

```sql
CREATE TABLE IF NOT EXISTS profile_affiliations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_profile_id INT NOT NULL,          -- atleta (o società, per società↔federazione)
    org_profile_id    INT NOT NULL,          -- società / federazione / associazione
    relation ENUM('member','affiliate','staff') NOT NULL DEFAULT 'member',
                                             -- member: atleta→società; affiliate: società→federazione/associazione; staff: dirigente/allenatore
    role VARCHAR(120) NULL,                  -- ruolo: 'Attaccante', 'Allenatore', 'Presidente'
    team VARCHAR(120) NULL,                  -- squadra/categoria: 'Prima squadra', 'Under 17'
    jersey_number SMALLINT UNSIGNED NULL,
    start_year SMALLINT UNSIGNED NULL,
    end_year   SMALLINT UNSIGNED NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('pending','confirmed','declined') NOT NULL DEFAULT 'pending',
    requested_by ENUM('member','org') NOT NULL,   -- chi ha iniziato ⇒ chi deve confermare è l'altro lato
    confirmed_at TIMESTAMP NULL DEFAULT NULL,
    description VARCHAR(500) NULL,
    sort INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_aff_member (member_profile_id, is_current, status),
    KEY idx_aff_org    (org_profile_id, is_current, status),
    UNIQUE KEY uq_aff_stint (member_profile_id, org_profile_id, team, start_year),
    CONSTRAINT fk_aff_member FOREIGN KEY (member_profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_aff_org    FOREIGN KEY (org_profile_id)    REFERENCES profiles(id) ON DELETE CASCADE
) /* charset dal pattern esistente delle migrazioni */;
```

Denormalizzazione opzionale (coerente col pattern 0014): `profiles.members_count` (roster confermato) aggiornato su confirm/remove — utile per card org e ordinamento. Aggiungibile nella stessa 0022 o rimandato.

Note PDO: nelle query che riusano il member/org id, **placeholder distinti** (`:me1/:me2`) — `EMULATE_PREPARES=false`.

### 3.4 (P2/P3) `org_teams` — squadre/categorie (migrazione **0024**, opzionale)
Quando i roster crescono, normalizzare le categorie invece del `team VARCHAR` libero:
```sql
CREATE TABLE org_teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_profile_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,        -- 'Prima squadra', 'Under 17 femminile'
    sport_id INT NULL, sort INT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_team_org (org_profile_id),
    CONSTRAINT fk_team_org FOREIGN KEY (org_profile_id) REFERENCES profiles(id) ON DELETE CASCADE
);
-- e profile_affiliations.team_id INT NULL FK → org_teams(id) (nullable, back-compat col VARCHAR).
```
**Raccomandazione**: P1/P2 usano `affiliation.team` free-text; introdurre `org_teams` solo quando serve raggruppare il roster per categoria in UI.

---

## 4. Pagina profilo organizzazione (Company-Page analog)

Sezioni della **show** per un profilo org (rese solo se `is_organization=1`), tutte responsive **≤320px senza overflow orizzontale**, async-first (`data-async`), output `e()`:

1. **Hero**: logo (avatar), cover, nome, `type_label`, badge verificato (semantica org §7), headline/slogan, sport/disciplina, sede/città, follow + connect. Pastiglie colori sociali (contenuto).
2. **About / campi tipo** (da `attributes` via schema): categoria/serie, anno fondazione, sede/impianto, ambito (federazione), ecc. — reso come lista label→valore, solo campi valorizzati.
3. **Roster / Membri**: atleti affiliati **confermati** (current + past collassato in "ex"), raggruppabili per team/categoria. Card compatte (avatar, nome, ruolo, numero). Link "vedi tutti". Per federazione: **società affiliate**; per associazione: **membri**.
4. **Palmarès dell'org** (`profile_achievements` — già esiste, generico su `profile_id`): titoli/trofei dell'entità.
5. **Post dell'org**: il meccanismo esiste già (`posts.profile_id`) — **conferma: nessuna modifica necessaria**, l'org posta come sé stessa. Mostrare la sezione post/attività nella pagina.
6. **Follower / seguiti**, **link** (sito, social), **contatti** (se pubblici).

**Fan (5)**: pagina **minimale** — identità + follow + attività recente. Nessuna sezione professionale (no skill/esperienze/palmarès/roster). L'editor fan mostra solo avatar/bio/località/link.

---

## 5. Show / edit / discovery type-aware

### 5.1 Show type-aware
`WebProfile::show` resta un unico controller; la view diventa **type-aware** via un **descrittore di sezioni per tipo** (mappa `type_key → [sezioni abilitate]`), non con `if` sparsi:
- `atleta`: about, esperienze/carriera (free-text **+** affiliazioni confermate), palmarès, skill/endorsement, recommendations, link.
- `societa/associazione`: about+campi, roster/membri, team/categorie, palmarès org, post, follower, link. (No skill/endorsement personali.)
- `federazione`: about+campi, società affiliate, discipline, palmarès, post, link.
- `fan`: identità + follow + attività. Niente altro.

Manteniamo il **Presenter** come unica sede della shape (`ProfilePresenter`): aggiungere `attributes` (filtrati per schema) e sezioni org alla forma `full()`; l'API le espone automaticamente (API-first).

### 5.2 Edit type-aware
- Editor render dei **campi di `attributes_schema`** del tipo del profilo (form generato dallo schema): input whitelisted per chiave, validati contro lo schema, salvati in `profiles.attributes`.
- Org: sezioni gestione **roster/affiliazioni** (invita atleta, conferma richieste in ingresso, chiudi stint) e **team**. Atleta: sezione "milita in" (aggiungi affiliazione → pending conferma org).
- Fan: form ridotto.
- `profile_type_id` resta immutabile via editor utente (cambio tipo = flusso admin, coerente col claim model).

### 5.3 Discovery type-aware
- Directory `/atleti` (o rinominata, §6): la facet tipo **esiste già**; aggiungere chip esplicite (Atleti · Società · Federazioni · Associazioni) e, per gli org, ordinamento per `members_count`/`followers_count`.
- **Suggerimenti misti sensati**: separare i moduli — "**Società da seguire**" (filtro `is_organization=1`, stesso sport/zona), "**Atleti della tua zona**" (`is_organization=0`, stesso sport/città), "**Persone che potresti conoscere**" (connessioni 2° grado, già esistente). Richiede aggiungere un **filtro tipo/org** a `suggestedFor` e al modulo suggerimenti (oggi assente).
- Tutto raggiungibile via `/api/v1` (facet `?type=` già supportata in `listPublic`).

---

## 6. Naming di rotta — raccomandazione

**Problema**: `/atleti/{handle}` per una federazione è semanticamente errato (LinkedIn separa `/in/` persone vs `/company/` pagine); debole per SEO.

**Raccomandazione** (basso rischio grazie agli handle globalmente unici):
1. Introdurre path canonici **type-aware**: `/societa/{handle}`, `/federazione/{handle}`, `/associazione/{handle}` per gli org; mantenere `/atleti/{handle}` per `atleta`/`fan` (o `/persone/`). L'handle risolve comunque a prescindere dal prefisso (namespace unico).
2. **Back-compat + SEO**: `WebProfile::show` verifica se il prefisso del path corrisponde al tipo; se no → **301 redirect** al canonico. Ogni vecchio `/atleti/{handle-org}` reindirizza a `/societa/{handle}`. Aggiungere `<link rel="canonical">` alla view. Nessun link rotto, autorità SEO preservata/consolidata.
3. API invariata: `/api/v1/profiles/{handle}` è già neutrale — **non toccare** (i client usano quella).

Alternativa a costo minimo (se il founder vuole rimandare): prefisso neutro unico `/p/{handle}` per tutti + label tipo nella pagina. Meno SEO-espressivo di path tipizzati. **Preferita: opzione tipizzata con 301.**

---

## 7. Completeness meter + badge verificato per tipo

### 7.1 Completeness type-aware
Sostituire il meter atleta-shaped con una **mappa di campi richiesti per `type_key`** (config, non hardcode):
- **atleta**: avatar, headline, bio, sport, località, ≥1 esperienza/affiliazione, ≥1 skill, ≥1 link.
- **società**: logo, about/bio, categoria/serie, anno fondazione, sede, ≥1 team **o** ≥1 membro affiliato, sito.
- **federazione**: logo, about, ambito, ≥1 disciplina, ≥1 società affiliata, sito.
- **associazione**: logo, about, tipo attività, ≥1 membro, contatti.
- **fan**: minimo (avatar + ≥1 follow) — o meter assente.

### 7.2 Semantica badge "verificato"
Il badge (`verified_at`) è unico visivamente (giallo, flat, no verde/emoji) ma **significa cose diverse**, con copy/tooltip diverso:
- **atleta/fan**: "**Identità verificata**" — persona reale, verificata (es. via claim/documenti).
- **società/associazione/federazione**: "**Entità ufficiale verificata**" — rivendicata da un rappresentante autorizzato e/o riscontrata su registro federale (CONI/FIGC). Peso di trust superiore.
Legare al **claim model** esistente: un org rivendicato + approvato dall'admin ottiene `verified_at`. Un'**affiliazione confermata da un org verificato** è a sua volta un segnale forte sul CV dell'atleta (mostrare micro-badge sull'affiliazione).

---

## 8. Recommendations (feature LinkedIn, core-first)

Testimonianze **free-text** (oltre agli endorsement già esistenti). Type-agnostica (una federazione può raccomandare una società).

### 8.1 DDL — `profile_recommendations` (migrazione **0025**, P3)
```sql
CREATE TABLE profile_recommendations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    author_profile_id  INT NOT NULL,     -- chi scrive
    subject_profile_id INT NOT NULL,     -- destinatario
    relationship VARCHAR(120) NULL,      -- 'Allenatore', 'Compagno di squadra', 'Federazione'
    body VARCHAR(1500) NOT NULL,
    status ENUM('pending','visible','hidden') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reco (author_profile_id, subject_profile_id),
    KEY idx_reco_subject (subject_profile_id, status),
    CONSTRAINT fk_reco_author  FOREIGN KEY (author_profile_id)  REFERENCES profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_reco_subject FOREIGN KEY (subject_profile_id) REFERENCES profiles(id) ON DELETE CASCADE
);
```
**Regole** (modello LinkedIn): solo **connessioni accettate** possono scrivere; il destinatario **approva** (`pending→visible`) prima che diventi pubblica; può nascondere (`hidden`). Output `e()`, input validato/limitato, rate-limited, notifica al destinatario. Web (CSRF) + API (Bearer).

### 8.2 Positions/experience più ricche
Con le affiliazioni (§3), la "carriera" dell'atleta unisce affiliazioni confermate (verificate dall'org) + esperienze free-text. Le affiliazioni confermate da org verificati portano il micro-badge di verifica.

### 8.3 Org "people you may know" / roster surfacing (P3)
Suggerire all'org atleti della stessa zona/sport non ancora nel roster; suggerire all'atleta compagni di roster come connessioni. Riusa il roster (`profile_affiliations`) come segnale.

---

## 9. Sicurezza & vincoli (trasversali)

- **MASSIMO**: ogni input parametrizzato; ogni output `e()`; **authz al livello dati** (WHERE su ownership per confermare/gestire affiliazioni, roster, recommendations, editing attributes). Defense-in-depth.
- **PDO `EMULATE_PREPARES=false`**: placeholder named **non riutilizzabili** → `:me1/:me2`.
- **CSRF** su tutte le scritture web; **Bearer-only** su scritture API (`CurrentUser::fromBearer`).
- **API-first**: ogni capability nuova (attributes, affiliazioni, recommendations, roster) esposta anche sotto `/api/v1` (envelope `{data,meta}` / `{errors}`), principio multi-client.
- **Nessuna regressione**: gli helper di nav girano su ogni pagina — le sezioni org sono **additive**, con feature-flag di render per tipo. Non toccare il grafo esistente.
- **Design**: dark, bianco/nero, **giallo unico accento**, no verde, no emoji (Font Awesome flat). I "colori sociali" società sono *contenuto* reso in pastiglie, **non** tema.
- **Mobile ≤320px**: roster/membri in griglia fluida, card che vanno a capo, sezioni con `overflow` gestito; **niente overflow orizzontale**. Async-first (`data-async`) per liste roster/recommendations paginate.

---

## 10. Piano a fasi (per valore / rischio)

### **P1 — Type-first UI & campi (alto valore, basso rischio, additivo)**
- **0022 (data-only)**: popolare `profile_types.attributes_schema` per i 5 tipi. *(Se si preferisce, la 0022 è la tabella affiliazioni e questa diventa 0023 — vedi nota numerazione sotto.)*
- Attivare `profiles.attributes`: editor **type-aware** (form da schema) + show **type-aware** (descrittore sezioni) + Presenter/API espongono `attributes`.
- **Completeness meter** per tipo + copy badge verificato per tipo.
- **Route canonicalization** `/societa//federazione//associazione/` + 301 + `rel=canonical`.
- Fan: pagina/editor minimali.
- Rischio: basso (UI + JSON, nessun nuovo grafo). Valore: alto (org finalmente di prima classe).

### **P2 — Affiliazioni (la keystone)**
- **Migrazione `profile_affiliations`** (0022 o 0023) + Service/Repository + conferma bilaterale + notifiche + authz al livello dati.
- Sezione **Roster/Membri** su org, **"milita in / ha militato in"** su atleta, gestione roster nell'editor org, "milita in" nell'editor atleta.
- **Discovery tipizzata**: filtro tipo/org in `suggestedFor` + moduli "Società da seguire" / "Atleti della tua zona".
- API `/api/v1/profiles/{handle}/affiliations` (+ azioni confirm/decline).
- Rischio: medio (nuovi flussi di scrittura + authz + notifiche). Valore: molto alto (è *la* relazione del network sportivo).

### **P3 — Profondità**
- **Recommendations** (`profile_recommendations`, 0025) web+API.
- `org_teams` (0024) se i roster richiedono raggruppamento per categoria.
- Org "people you may know" / roster surfacing; società↔federazione affiliation UI; micro-badge affiliazione verificata.
- Rischio: medio. Valore: consolidamento.

### **DEFERRED (decide il founder)**
- Opportunities / job board / candidature.
- Billing / abbonamenti / lato domanda monetizzato.
- Multi-profilo / "page admin" (una persona che amministra la pagina società da account personale) — vedi §11.

> **Numerazione migrazioni**: ultima applicata = **0021**. Assegnazione proposta: **0022** `create_profile_affiliations` (keystone, se si vuole prioritizzare il grafo) **+** una migrazione data-only per `attributes_schema` (0023). Se P1 va prima, invertire: **0022** seed `attributes_schema` (data-only), **0023** `create_profile_affiliations`. Poi **0024** `org_teams`, **0025** `profile_recommendations`. Registrare ogni file in `migrations` dopo l'apply (pattern q.py del CLAUDE.md).

---

## 11. Decisioni che il founder deve prendere

1. **Naming di rotta**: adottare path tipizzati **`/societa/` `/federazione/` `/associazione/`** con 301 da `/atleti/` (raccomandato: semanticamente corretto, buon SEO) **oppure** tenere tutto sotto `/atleti/` (costo zero ma etichetta errata)? *(Impatta P1 e SEO.)*

2. **Trust / onboarding org**: oggi **chiunque può auto-registrarsi come società/federazione** (rischio impersonazione di entità reali). Gli org devono passare dal **claim model + verifica admin** (raccomandato: solo profili org *rivendicati/verificati* possono esistere o postare pubblicamente), oppure resta l'auto-registrazione libera? *(Impatta sicurezza e valore del badge verificato.)*

3. **Autorità dell'affiliazione + modello identità**: **(a)** l'affiliazione atleta↔società richiede la **conferma dell'org** per diventare "verificata" e la conferma di un org verificato **auto-verifica** l'esperienza dell'atleta (raccomandato)? **(b)** Il vincolo `un login = un profilo` significa che chi gestisce una società **non può avere anche** un profilo atleta personale sullo stesso account, e non esiste "page admin" alla LinkedIn: teniamo il modello 1:1 semplice (raccomandato per ora, DEFER multi-admin) oppure serve fin da subito il modello *persona amministra pagina org*? *(Decisione architetturale più pesante: cambia ownership, authz e claim.)*
