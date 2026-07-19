# Checkpoint 2 — "Approfondire il network" · Technical Spec

> Autore: Backend Architect + DB Performance Engineer
> Stato: spec ready-to-build (nessun codice di produzione qui, solo DDL + firme).
> Vincoli di progetto: sicurezza MASSIMO, query parametrizzate, output via `e()`, envelope JSON `{data,meta}`/`{errors}`,
> scritture web con CSRF, scritture API solo-Bearer, `EMULATE_PREPARES=false` (placeholder NON riusabili).

---

## 0. Fatti di schema scoperti (il terreno reale)

### 0.1 Numerazione migrazioni
- Ultima migrazione **applicata e presente**: `0015_create_post_engagement`.
- Le migrazioni sono file PHP numerati in `database/migrations/NNNN_nome.php` che ritornano una classe anonima con `up(\PDO)`/`down(\PDO)`.
- Il `Migrator` (`src/Core/Migrator.php`) registra ogni file nella tabella `migrations` **senza estensione** (`INSERT INTO migrations (migration) VALUES ('0015_create_post_engagement')`).
- **Numeri assegnati da questa spec:**
  - `0016_create_connection_dismissals` → F1
  - `0017_create_skills` → F2
  - `0018_create_profile_views` → F3
- C'è già un file untracked `database/migrations/0006_claim_requests.php` nella working copy: **irrilevante / da ignorare** (il claim reale è `0012_create_claims.php`, già applicato). Non riusare 0006.

### 0.2 `connections` (grafo simmetrico con stato) — migrazione 0008
```
connections(
  id INT PK,
  requester_id INT NOT NULL  → profiles(id) ON DELETE CASCADE,
  addressee_id INT NOT NULL  → profiles(id) ON DELETE CASCADE,
  status ENUM('pending','accepted') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP, responded_at TIMESTAMP NULL,
  UNIQUE uniq_pair (requester_id, addressee_id),
  INDEX idx_conn_addressee (addressee_id, status),
  INDEX idx_conn_requester (requester_id, status)
)
```
- **UNA riga per coppia**, in UN verso. L'assenza del verso inverso è garantita dall'applicazione (`ConnectionRepository::findBetween` controlla entrambe le direzioni).
- Una "connessione" = riga con `status='accepted'` **in qualunque verso**.
- `requester_id`/`addressee_id` sono **profile_id**, NON user_id.
- Contatore denormalizzato `profiles.connections_count` mantenuto in `ConnectionRepository::accept()` (+1 a entrambi) e `deleteBetween()` (GREATEST(0,-1) solo se era accepted).
- Rilevante per F1: i due indici `idx_conn_requester` e `idx_conn_addressee` (entrambi con `status` come seconda colonna) coprono le join "amici-di-amici" in entrambe le direzioni senza indici nuovi.

### 0.3 `follows` (grafo asimmetrico) — migrazione 0007
`follows(id, follower_id→profiles, followee_id→profiles, UNIQUE uniq_follow(follower_id,followee_id), idx_follow_followee, idx_follow_follower)`. Contatori `profiles.followers_count`/`following_count`.

### 0.4 `profiles` — migrazioni 0001 + 0012 (claim) + 0014 (contatori)
Colonne rilevanti: `id`, `user_id INT NULL` (nullable dal claim: profili unclaimed senza owner), `claim_status ENUM('unclaimed','claimed') DEFAULT 'claimed'`, `profile_type_id`, `handle` (UNIQUE), `display_name`, `headline`, `bio`, `sport_id INT NULL` (→sports, idx_profiles_sport), `avatar_media_id`, `cover_media_id`, `location_city`, `location_region`, `location_country`, `verified_at`, `visibility ENUM('public','members','private') DEFAULT 'public'`, `followers_count`, `following_count`, `connections_count`, `unread_messages`, `created_at`, `updated_at`. FULLTEXT `ft_profiles_search(display_name,headline,bio)`.
- **Nessun indice su `location_city`** oggi → lo aggiungiamo per il fallback F1.

### 0.5 `media` — migrazione 0004
`media(id, user_id, kind, disk_path, mime, ...)`. L'avatar si aggancia via `LEFT JOIN media am ON am.id = p.avatar_media_id`, esposto come alias **`avatar_path`** (= `am.disk_path`). Pattern canonico in `ProfileRepository::SELECT_ENRICHED`.

### 0.6 `notifications` — migrazione 0013 + contatore 0014
```
notifications(id BIGINT PK, user_id INT→users, type VARCHAR(40), title VARCHAR(200),
              body VARCHAR(500) NULL, url VARCHAR(255) NULL, read_at TIMESTAMP NULL, created_at,
              idx_notif_user_unread(user_id,read_at), idx_notif_user_time(user_id,created_at))
```
- Contatore denormalizzato `users.unread_notifications`.
- **API di emissione** (`NotificationService`): metodi pubblici tipizzati per evento (`follow`, `connectionRequest`, `connectionAccepted`, `newMessage`, `postLike`, `postComment`) che internamente chiamano il privato:
  `emit(int $recipientPid, int $actorPid, string $type, string $titleKey, string $bodyKey, callable $urlFn, ?int $dedupHours = null)`.
  - Risolve `recipientPid`→user via `ProfileRepository::findRawById`; **salta se il profilo destinatario non ha owner** (`empty($recipient['user_id'])`).
  - `type` è una stringa ≤40 char; i tipi esistenti sono `follow`, `connection_request`, `connection_accepted`, `new_message`, `post_like`, `post_comment`.
  - Dedup: `NotificationRepository::existsRecentSame($userId, $type, $body, $hours)` — match su (user_id, type, **body**, finestra ore). Il body contiene il nome dell'attore (`I18n::t($bodyKey, ['name'=>...])`).
  - `NotificationRepository::create()` incrementa `users.unread_notifications`.
- **Nuovo tipo introdotto da questa spec: `skill_endorsed`** (F2). Aggiungere un metodo pubblico `NotificationService::skillEndorsed(int $actorPid, int $ownerPid, string $skillLabel)`.

### 0.7 `RateLimiter` (`src/Domain/Auth/RateLimiter.php`)
- `tooManyByKey(string $key, int $max, int $withinMinutes): bool` e `hit(string $key, string $ip): void` (registra un "colpo" su chiave logica in `login_attempts`, con IP sentinella `-` per non inquinare il throttle login).
- Pattern già usato da `ConnectionService`: chiave `'conn:'.$actorId`, MAX 40 / 10 min.

### 0.8 Controller/route/viste rilevanti
- `/rete` → `Web\NetworkController::index` (auth). Vista `views/pages/rete/index.php`. Mostra richieste in entrata (`incomingRequestsOf`) + connessioni (`connectionsOf`). Classi: `.net-section`, `.pcard-grid`, partial `profile-card`, `.empty-state`, `.req-list`/`.req-item`.
- Feed empty-state → vista `views/pages/feed/index.php` ha già un blocco `.suggested` / `.suggested-list` / `.suggested-card` (oggi alimentato da `$suggested` = follow cold-start).
- Pagina profilo pubblica → `Web\ProfileController::show` (rotta `GET /atleti/{handle}`). Vista `views/pages/atleti/show.php`, sezioni `.profile-section` (h2). **Qui si registra la view F3.** `show()` risolve già il profilo del visitatore (`findByUserId`) per il contesto follow/connessione: **riusare quella risoluzione** per l'upsert view (niente query extra).
- Connessioni web: azioni `POST /atleti/{handle}/connetti` e `/disconnetti` (auth+csrf), con `return=rete` per tornare alla pagina Rete. Riusabili as-is da F1 per il bottone "Collegati".
- Editor proprio profilo → `Web\MyProfileController::edit` / rotta `GET /profilo` (auth). **Qui si mostra il widget F3 "Chi ha visto il tuo profilo"** e la gestione competenze proprie (F2).
- `Web\ProfileDetailsController` + `ProfileDetailsRepository`/`ProfileDetailsService` = pattern di riferimento per le sotto-entità del profilo (esperienze/palmarès/link): **F2 competenze le clona 1:1**.

### 0.9 Gotcha PDO ribadito
`EMULATE_PREPARES=false` → un named placeholder **non è riusabile** nella stessa query. In F1 `:me` compare 6+ volte: usare **placeholder distinti** (`:me1`,`:me2`,...) **o** `bindValue` posizionali `?` (pattern già in `ProfileRepository::cardsByIds`). **Mai** mettere un placeholder in una COUNT dove non è referenziato (errore HY093).

---

## F1 — Scoperta "Persone che potresti conoscere" (2° grado)

### F1.1 Migrazione `0016_create_connection_dismissals`
```sql
CREATE TABLE IF NOT EXISTS connection_dismissals (
    profile_id           INT NOT NULL,   -- chi ignora (profilo dell'utente)
    dismissed_profile_id INT NOT NULL,   -- suggerimento ignorato
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (profile_id, dismissed_profile_id),
    KEY idx_dismiss_profile (profile_id, created_at),
    CONSTRAINT fk_dismiss_profile FOREIGN KEY (profile_id)           REFERENCES profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_dismiss_target  FOREIGN KEY (dismissed_profile_id) REFERENCES profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
- PK composta = anti-doppione naturale + copertura per la sub-query di esclusione `WHERE profile_id = :me`.
- `down()`: `DROP TABLE IF EXISTS connection_dismissals`.

### F1.2 Indici richiesti
- **Nessun indice nuovo su `connections`**: `idx_conn_requester(requester_id,status)` e `idx_conn_addressee(addressee_id,status)` bastano per le due semi-join (una per direzione).
- **Nuovo indice per il fallback per città**, da aggiungere **nella migrazione 0016** (stessa transazione logica):
  ```sql
  ALTER TABLE profiles ADD KEY idx_profiles_city (location_city);
  ```
  (guardia idempotente: `SHOW INDEX FROM profiles WHERE Key_name='idx_profiles_city'` prima di ALTER, come già fatto altrove).
- `idx_profiles_sport` (esistente) copre il fallback per sport.

### F1.3 SQL di discovery (forma indicizzabile)
Regola d'oro anti-full-scan: **non** espandere l'intera tabella `connections` in un derivato UNION e poi joinare (MySQL materializza e perde gli indici). Invece: partire dall'insieme **piccolo** dei miei amici (`myfriends`) e per ciascuno pescare i suoi amici con due JOIN a uguaglianza (una per direzione) — così `idx_conn_requester`/`idx_conn_addressee` si attivano.

```sql
SELECT
    <SELECT_ENRICHED su p ...>,
    foaf.mutual_count
FROM (
    SELECT cand_id, COUNT(DISTINCT via) AS mutual_count
    FROM (
        -- i miei amici (accepted), entrambe le direzioni → insieme piccolo
        SELECT addressee_id AS fid FROM connections WHERE requester_id = :me1 AND status='accepted'
        UNION
        SELECT requester_id AS fid FROM connections WHERE addressee_id = :me2 AND status='accepted'
    ) myfriends
    JOIN (
        -- amici-di-amici, direzione A (mio amico è il requester)
        SELECT c.addressee_id AS cand_id, c.requester_id AS via
        FROM connections c WHERE c.status='accepted'
        UNION ALL
        -- direzione B (mio amico è l'addressee)
        SELECT c.requester_id AS cand_id, c.addressee_id AS via
        FROM connections c WHERE c.status='accepted'
    ) foaf_edges ON foaf_edges.via = myfriends.fid
    GROUP BY cand_id
) foaf
JOIN profiles p        ON p.id = foaf.cand_id
JOIN profile_types pt  ON pt.id = p.profile_type_id
LEFT JOIN sports s     ON s.id = p.sport_id
LEFT JOIN media am     ON am.id = p.avatar_media_id
LEFT JOIN media ac     ON ac.id = p.cover_media_id
WHERE p.visibility = 'public'
  AND foaf.cand_id <> :me3
  -- non già in relazione (pending o accepted, in qualunque verso)
  AND foaf.cand_id NOT IN (
        SELECT addressee_id FROM connections WHERE requester_id = :me4
        UNION
        SELECT requester_id FROM connections WHERE addressee_id = :me5
  )
  -- non ignorato
  AND foaf.cand_id NOT IN (
        SELECT dismissed_profile_id FROM connection_dismissals WHERE profile_id = :me6
  )
ORDER BY foaf.mutual_count DESC, (p.sport_id = :sport) DESC, p.connections_count DESC, p.id DESC
LIMIT :lim
```
Note d'implementazione:
- **Placeholder distinti** `:me1..:me6` (+ `:sport`, `:lim`), oppure `bindValue` posizionali `?` per l'id ripetuto. Vietato riusare `:me`.
- `foaf_edges` **non** è filtrato per un profilo → è comunque un UNION ALL della tabella intera. A regime questo è il **collo di bottiglia** (vedi F1.6). Alla scala attuale (beta) è accettabile; la mitigazione è pianificata, non immediata.
- Il fallback (sotto) gira **solo se** il discovery ritorna meno di `:lim` righe (cold-start / grafo rado), poi si concatena escludendo gli id già ottenuti.

### F1.4 SQL di fallback (cold-start, stesso sport / stessa città)
Eseguito come **seconda query** dal service quando il 2° grado è insufficiente. Riusa `SELECT_ENRICHED`:
```sql
... SELECT_ENRICHED ...
WHERE p.visibility='public'
  AND p.id <> :me1
  AND (p.sport_id = :sport OR p.location_city = :city)
  AND p.id NOT IN (SELECT addressee_id FROM connections WHERE requester_id = :me2
                   UNION SELECT requester_id FROM connections WHERE addressee_id = :me3)
  AND p.id NOT IN (SELECT dismissed_profile_id FROM connection_dismissals WHERE profile_id = :me4)
  AND p.id NOT IN (<id già suggeriti dal 2° grado, come lista ? posizionali>)
ORDER BY (p.sport_id = :sport2) DESC, (p.location_city = :city2) DESC, p.connections_count DESC, p.id DESC
LIMIT :lim
```
- `:sport`/`:sport2` e `:city`/`:city2` distinti (riuso vietato). Se `sportId`/`city` sono NULL, il service omette il ramo corrispondente costruendo la WHERE dinamicamente (mai bind di placeholder non referenziati).

### F1.5 Firme repo/service/controller/route
**`ConnectionSuggestionRepository`** (nuovo, `src/Domain/Connections/`):
```php
/** @return array<int,array> righe SELECT_ENRICHED + chiave 'mutual_count' */
public function secondDegree(int $profileId, ?int $sportId, ?string $city, int $limit = 12): array
/** @param int[] $excludeIds @return array<int,array> */
public function fallbackBySportOrCity(int $profileId, ?int $sportId, ?string $city, array $excludeIds, int $limit): array
public function dismiss(int $profileId, int $dismissedProfileId): void   // INSERT IGNORE
```
(In alternativa questi due `secondDegree`/`fallback...` possono vivere come metodi su `ProfileRepository` accanto a `suggestedFor`, per riusare `SELECT_ENRICHED` privato; scelta lasciata all'implementatore — preferenza: nuova classe che riceve il PDO e incolla la costante enriched, per non gonfiare ProfileRepository.)

**`ConnectionSuggestionService`** (`src/Domain/Connections/`):
```php
public function suggestionsFor(int $profileId, ?int $sportId, ?string $city, int $limit = 12): array
    // 2° grado; se conteggio < limit, completa col fallback escludendo gli id già presenti.
public function dismiss(int $actorProfileId, int $targetProfileId, string $ip): ServiceResult
    // rate-limit 'sugg:'.$actorProfileId (MAX 60 / 10 min); ServiceResult::ok/fail.
```
- **NON** emette notifiche. Il bottone "Collegati" del modulo riusa la rotta esistente `POST /atleti/{handle}/connetti` (che già fa la notifica `connection_request`).

**Controller** — estendere `Web\NetworkController`:
```php
public function index(Request $request): void   // aggiunge $suggestions al render di rete/index
public function dismissSuggestion(Request $request): void  // POST, CSRF; legge {handle}; flash + redirect 'rete'
```

**Rotte** (in `config/routes.php`, blocco Connessioni):
```php
$router->post('/rete/suggerimenti/{handle}/ignora', [WebNetwork::class, 'dismissSuggestion'], [$auth, $csrf]);
// GET /rete già esistente: la index ora popola anche $suggestions.
```
**Rendering:**
- `/rete`: nuova `.net-section` "Persone che potresti conoscere" in cima, con `.pcard-grid` di card che mostrano `mutual_count` ("N connessioni in comune"), bottone **Collegati** (form → `.../connetti` con `return=rete`) e bottone **Ignora** (form → `.../ignora`).
- Feed empty-state: opzionale, il modulo può riusare `$suggestions` al posto/oltre l'attuale `$suggested` (follow). Consigliato: lasciare il feed sui follow, mettere il 2° grado su `/rete` (dove il segnale "amici in comune" è più pertinente).

**Varianti API (Bearer)**: opzionali, **rimandate**. Se volute in futuro: `GET {api}/me/suggestions` (lettura) + `POST {api}/me/suggestions/{handle}/dismiss`. Non necessarie per Checkpoint 2.

### F1.6 Rischio efficienza + mitigazione (il punto critico)
- **Rischio:** il ramo `foaf_edges` è un `UNION ALL` dell'intera `connections` che MySQL **materializza**; la join a `myfriends` non usa indice sul derivato. Con centinaia di migliaia di edge questo è O(edges) per ogni caricamento di `/rete` (pagina calda).
- **Mitigazione immediata (v1):** `LIMIT` basso (12), esecuzione **solo** su `/rete` (non su ogni pagina), e — dove il DB lo supporta (MySQL 8) — riscrittura con **CTE** `WITH myfriends AS (...)` referenziata due volte, così l'ottimizzatore fa la lookup indicizzata `connections.requester_id/addressee_id = myfriends.fid` invece di materializzare tutti gli edge. Preferire la CTE se la versione MySQL di SiteGround è ≥ 8.0 (da confermare; il progetto usa già JSON/FULLTEXT InnoDB → probabile 8.0).
- **Mitigazione a regime (v2, NON ora):** tabella materializzata `connection_suggestions(profile_id, suggested_profile_id, mutual_count, computed_at)` ricomputata da un **job batch** (notturno / on-write throttled) — lettura O(1) sulla pagina calda. Da valutare quando il grafo cresce; segnare come follow-up.
- **Cache breve:** avvolgere il risultato in `Core\Cache::remember('sugg:'.$profileId, 300, ...)` (5 min) per assorbire i refresh ravvicinati.

---

## F2 — Competenze + Endorsement

### F2.1 Decisione: catalogo globale vs free-text per profilo
**Scelta: free-text per profilo** (`profile_skills.label`), **niente** tabella `skills` globale in v1.
Motivazioni: (a) zero governance di tassonomia/merge/localizzazione; (b) coerenza col pattern esistente delle sotto-entità profilo (`profile_experiences`, `profile_achievements`, `profile_links` sono tutte free-text con `sort`); (c) `UNIQUE(profile_id,label)` impedisce i doppioni per profilo; (d) normalizzazione in un catalogo condiviso è un refactor additivo futuro (colonna `skill_ref_id` nullable) senza rompere i dati.

### F2.2 Migrazione `0017_create_skills`
```sql
CREATE TABLE IF NOT EXISTS profile_skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    profile_id INT NOT NULL,
    label VARCHAR(60) NOT NULL,
    position INT NOT NULL DEFAULT 0,
    endorsements_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_profile_skill (profile_id, label),
    KEY idx_skill_profile (profile_id, position),
    CONSTRAINT fk_skill_profile FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS skill_endorsements (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    skill_id INT NOT NULL,
    endorser_profile_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_skill_endorser (skill_id, endorser_profile_id),
    KEY idx_endorse_endorser (endorser_profile_id),
    CONSTRAINT fk_endorse_skill  FOREIGN KEY (skill_id)            REFERENCES profile_skills(id) ON DELETE CASCADE,
    CONSTRAINT fk_endorse_profile FOREIGN KEY (endorser_profile_id) REFERENCES profiles(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
- `UNIQUE uq_skill_endorser` = un solo endorsement per (skill, endorser); l'anti-doppione è a livello DB.
- `endorsements_count` denormalizzato su `profile_skills` (coerente con la linea contatori del progetto).
- `down()`: drop `skill_endorsements` poi `profile_skills`.

### F2.3 Manutenzione contatore
- **Endorse:** `INSERT IGNORE INTO skill_endorsements ...`; se `rowCount()===1` → `UPDATE profile_skills SET endorsements_count = endorsements_count + 1 WHERE id = :skill`.
- **Rimuovi:** `DELETE FROM skill_endorsements WHERE skill_id=:s AND endorser_profile_id=:e`; se `rowCount()===1` → `UPDATE profile_skills SET endorsements_count = GREATEST(0, endorsements_count - 1) WHERE id = :skill`.
- Idempotenza garantita: senza riga inserita/cancellata il contatore non si muove (niente drift su doppio click).

### F2.4 Authz / rate-limit / notifica
- **Solo connessioni ACCEPTED** del proprietario della skill possono endorsare: `ConnectionRepository::areConnected($endorserProfileId, $ownerProfileId)` deve essere `true`.
- **No self-endorse:** `$endorserProfileId !== $ownerProfileId`.
- **Profilo senza owner:** una skill può esistere solo su profilo `claimed` con `user_id` (le competenze si aggiungono dall'editor `/profilo`, quindi il proprietario esiste per costruzione); l'endorse verso profilo owner-less è comunque bloccato a monte dal check connessione.
- **Rate-limit:** `RateLimiter::tooManyByKey('endorse:'.$endorserProfileId, 60, 10)` + `hit()` sul successo (chiave logica, IP sentinella).
- **Notifica `skill_endorsed`** al proprietario:
  - Nuovo metodo `NotificationService::skillEndorsed(int $actorPid, int $ownerPid, string $skillLabel)` → `emit($ownerPid, $actorPid, 'skill_endorsed', 'notif.skill_endorsed.title', 'notif.skill_endorsed.body', fn($a)=>'atleti/'.$a['handle'], 24)`.
  - **Dedup 24h**: `existsRecentSame` sul body (che contiene il nome dell'attore). Nota: il body include il **nome dell'attore** ma non la label skill → endorsi multipli su skill diverse dallo stesso attore entro 24h collassano in una notifica (accettabile anti-spam). Se si vuole notificare per-skill, includere la label nel body (`['name'=>..,'skill'=>..]`) — decisione UX, default: collassa.
  - `emit` salta da solo se il profilo owner non ha `user_id`.

### F2.5 Gestione competenze del proprietario (add / reorder / remove)
- **Max 20 skill per profilo**: il service conta (`SELECT COUNT(*) FROM profile_skills WHERE profile_id=:p`) prima dell'insert; oltre → `ServiceResult::fail(..., 422)`.
- **Validazione label:** `trim`, lunghezza 1..60, collassare spazi multipli; rifiuto se vuota. Output SEMPRE via `e()` in vista. Nessun HTML ammesso.
- **Reorder:** aggiornamento di `position` per lista di id **posseduti** (WHERE `profile_id=:p` sempre presente: ownership a livello SQL, difesa in profondità — come `ProfileDetailsRepository`).

### F2.6 Firme repo/service/controller/route
**`SkillRepository`** (`src/Domain/Profiles/` — accanto a ProfileDetailsRepository):
```php
public function forProfile(int $profileId): array                 // ORDER BY position ASC, id ASC
public function countForProfile(int $profileId): int
public function add(int $profileId, string $label, int $position): int
public function reorder(int $profileId, array $orderedIds): void   // WHERE ... AND profile_id=:p
public function delete(int $id, int $profileId): void              // WHERE id=:id AND profile_id=:p
public function findOwnerProfileId(int $skillId): ?int             // JOIN a profiles per authz endorse
public function endorse(int $skillId, int $endorserProfileId): bool     // INSERT IGNORE + counter++ ; true se creato
public function removeEndorsement(int $skillId, int $endorserProfileId): bool  // DELETE + counter-- ; true se rimosso
/** @return int[] skill_id che l'endorser ha già approvato per un dato profilo (per stato bottoni) */
public function endorsedSkillIdsBy(int $endorserProfileId, int $ownerProfileId): array
```
**`SkillService`** (`src/Domain/Profiles/`):
```php
public function addSkill(int $ownerProfileId, string $label): ServiceResult      // max 20, validazione, UNIQUE
public function removeSkill(int $ownerProfileId, int $skillId): ServiceResult
public function reorder(int $ownerProfileId, array $orderedIds): ServiceResult
public function endorse(int $endorserProfileId, int $skillId, string $ip): ServiceResult
    // authz: owner = findOwnerProfileId(skillId); no-self; areConnected; rate-limit; endorse(); notifica skill_endorsed
public function removeEndorsement(int $endorserProfileId, int $skillId, string $ip): ServiceResult
```
**Controller `Web\SkillController`** (nuovo) — azioni proprietario su `/profilo/...`, endorse su `/atleti/{handle}/...`:
```php
public function add(Request $request): void       // POST /profilo/competenze
public function delete(Request $request): void    // POST /profilo/competenze/{id}/elimina
public function reorder(Request $request): void   // POST /profilo/competenze/riordina  (JSON progressive)
public function endorse(Request $request): void   // POST /atleti/{handle}/competenze/{id}/endorsa
public function removeEndorse(Request $request): void // POST /atleti/{handle}/competenze/{id}/rimuovi
```
**Rotte** (`config/routes.php`):
```php
// Gestione competenze proprie (editor)
$router->post('/profilo/competenze',              [WebSkill::class, 'add'],     [$auth, $csrf]);
$router->post('/profilo/competenze/{id}/elimina', [WebSkill::class, 'delete'],  [$auth, $csrf]);
$router->post('/profilo/competenze/riordina',     [WebSkill::class, 'reorder'], [$auth, $csrf]);
// Endorsement (dal profilo pubblico altrui)
$router->post('/atleti/{handle}/competenze/{id}/endorsa',  [WebSkill::class, 'endorse'],       [$auth, $csrf]);
$router->post('/atleti/{handle}/competenze/{id}/rimuovi',  [WebSkill::class, 'removeEndorse'], [$auth, $csrf]);
```
**Rendering:**
- `views/pages/atleti/show.php`: nuova `.profile-section` "Competenze" con lista chip `.skill` + badge `endorsements_count`. Se il visitatore è connesso accepted e non è il proprietario → bottone endorse per ogni skill (form CSRF, progressive-enhance AJAX come like/follow esistenti, `data-endorse`).
- `views/pages/profilo/*` (editor): CRUD competenze (add/reorder drag/remove) speculare a esperienze/palmarès.
- **API Bearer:** opzionale, rimandata (`POST {api}/me/skills`, `DELETE {api}/me/skills/{id}`, `POST {api}/skills/{id}/endorse`). Non richiesta per Checkpoint 2.
- **Nuove chiavi i18n:** `notif.skill_endorsed.title`, `notif.skill_endorsed.body`, più label UI competenze/endorse (in `lang/it/*`).

---

## F3 — "Chi ha visto il tuo profilo"

### F3.1 Migrazione `0018_create_profile_views`
```sql
CREATE TABLE IF NOT EXISTS profile_views (
    viewer_profile_id INT NOT NULL,
    viewed_profile_id INT NOT NULL,
    first_viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_viewed_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    view_count INT NOT NULL DEFAULT 1,
    PRIMARY KEY (viewed_profile_id, viewer_profile_id),
    KEY idx_pv_viewed_recent (viewed_profile_id, last_viewed_at),
    CONSTRAINT fk_pv_viewer FOREIGN KEY (viewer_profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_pv_viewed FOREIGN KEY (viewed_profile_id) REFERENCES profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
- **Una riga per coppia (viewer, viewed)** → crescita **limitata** (O(coppie distinte)), non O(visite). Niente tabella eventi append-only.
- PK `(viewed_profile_id, viewer_profile_id)`: chiave dell'upsert **e** clustering per "chi ha visto X" (query calda del proprietario). `idx_pv_viewed_recent` ordina per recency dentro un viewed.
- `first_viewed_at` = default all'insert, mai aggiornato; `last_viewed_at` aggiornato nell'upsert.
- `down()`: `DROP TABLE IF EXISTS profile_views`.

### F3.2 Registrazione (dove + come)
In **`Web\ProfileController::show()`**, dentro il ramo che risolve il profilo del visitatore (già presente per follow/connessione). Registrare **solo quando**:
1. `viewerUserId !== null` (autenticato),
2. il visitatore **ha un profilo** (`$viewer !== null`),
3. **non è il proprietario** (`$viewer->id !== $pid`).
Upsert singolo (costo O(1), una sola query per view):
```sql
INSERT INTO profile_views (viewer_profile_id, viewed_profile_id)
VALUES (:viewer, :viewed)
ON DUPLICATE KEY UPDATE last_viewed_at = NOW(), view_count = view_count + 1
```
- **Admin/stealth:** default = **registra normalmente** (nessun trattamento speciale). Opzione futura "navigazione anonima" (toggle per-utente che salta l'upsert) — **NON implementata ora**, solo annotata (vedi F3.5).
- Nessuna emissione notifica (feature in chiaro, passiva).
- Il visibility gate resta quello di `findPublicByHandle` (solo profili public sono visualizzabili → registrabili).

### F3.3 Query di lettura (widget proprietario)
**Viewer recenti** (join enriched + media):
```sql
SELECT <SELECT_ENRICHED su p>, pv.last_viewed_at, pv.view_count
FROM profile_views pv
JOIN profiles p        ON p.id = pv.viewer_profile_id
JOIN profile_types pt  ON pt.id = p.profile_type_id
LEFT JOIN sports s     ON s.id = p.sport_id
LEFT JOIN media am     ON am.id = p.avatar_media_id
LEFT JOIN media ac     ON ac.id = p.cover_media_id
WHERE pv.viewed_profile_id = :me
ORDER BY pv.last_viewed_at DESC
LIMIT :lim
```
(usa `idx_pv_viewed_recent`). `:me` unico → nessun problema di riuso.

**Conteggio viewer distinti ultimi 7 giorni** (distinto è implicito: una riga per viewer):
```sql
SELECT COUNT(*) FROM profile_views
WHERE viewed_profile_id = :me AND last_viewed_at > (NOW() - INTERVAL 7 DAY)
```

**Trend giornaliero 7gg (sparkline):**
```sql
SELECT DATE(last_viewed_at) AS d, COUNT(*) AS c
FROM profile_views
WHERE viewed_profile_id = :me AND last_viewed_at >= (CURDATE() - INTERVAL 6 DAY)
GROUP BY d
ORDER BY d
```
- **Caveat (documentato):** poiché il modello è roll-up (una riga per viewer), il trend conta i viewer il cui **ultimo** accesso cade in quel giorno, non le visite grezze per giorno. È un'approssimazione voluta (costo/crescita). Il service normalizza a 7 bucket riempiendo di 0 i giorni mancanti prima di passare alla sparkline SVG. Se in futuro serve il conteggio visite grezze per giorno, servirà una tabella eventi separata — **fuori scope**.

### F3.4 Firme repo/service/controller/route
**`ProfileViewRepository`** (`src/Domain/Profiles/`):
```php
public function record(int $viewerProfileId, int $viewedProfileId): void   // upsert ON DUPLICATE KEY
/** @return array<int,array> righe enriched + last_viewed_at, view_count */
public function recentViewers(int $profileId, int $limit = 12): array
public function distinctViewers7d(int $profileId): int
/** @return array<int,int> mappa 'Y-m-d' => count sugli ultimi 7 giorni (grezza, da normalizzare) */
public function dailyTrend7d(int $profileId): array
```
**Integrazione controller:**
- `Web\ProfileController::show()`: dopo aver risolto `$viewer`, se autenticato + profilo + non-own → `(new ProfileViewRepository())->record($viewer->id, $pid);` (fire-and-forget, in try/catch soft: un errore qui **non deve** far cadere la pagina profilo).
- **Widget** nel `Web\MyProfileController::edit` (rotta `GET /profilo`): passa `recentViewers`, `distinctViewers7d`, `dailyTrend7d` (normalizzato a 7 bucket) alla vista. Sezione "Chi ha visto il tuo profilo" con avatar recenti, contatore 7gg e sparkline SVG (coerente con lo stile stats admin, niente verde, accento giallo).
- **Nessuna rotta di scrittura nuova.** Opzionale: `GET /profilo/visite` per la lista completa paginata (rimandabile).

### F3.5 Privacy
- Il proprietario che apre **il proprio** profilo **non** viene registrato (regola 3: `$viewer->id !== $pid`).
- **In chiaro, nessun paywall/Pro** (deciso): tutti i dati (viewer, conteggio, trend) sono visibili al proprietario senza gating.
- **Follow-up futuro (NON ora):** flag `profiles.anonymous_browsing TINYINT(1) DEFAULT 0` (o preferenza utente) che, se attivo, fa saltare l'upsert in `record()` così l'utente naviga "in incognito" (e, simmetricamente, non appare tra i viewer). Solo annotato.

---

## Riepilogo migrazioni (ordine di applicazione)
| # | File | Contenuto | Note perf |
|---|------|-----------|-----------|
| 0016 | `0016_create_connection_dismissals` | tabella `connection_dismissals` + `ALTER profiles ADD KEY idx_profiles_city` (idempotente) | indice città per fallback F1 |
| 0017 | `0017_create_skills` | `profile_skills` + `skill_endorsements` | UNIQUE anti-doppione, counter denormalizzato |
| 0018 | `0018_create_profile_views` | `profile_views` (roll-up upsert) | PK (viewed,viewer) copre query calda |

Applicare via `q.py`, poi `INSERT INTO migrations (migration) VALUES ('0016_create_connection_dismissals'), ('0017_create_skills'), ('0018_create_profile_views')` (nomi **senza** `.php`).

## Bandiere di rischio / gotcha (checklist per chi implementa)
1. **[CRITICO] F1 riuso placeholder:** `:me` compare 6 volte → usare `:me1..:me6` distinti o `?` posizionali. Errore garantito altrimenti (500 ricorrente noto).
2. **[CRITICO] F1 full-scan `foaf_edges`:** UNION ALL dell'intera `connections` materializzato. v1 accettabile con LIMIT 12 + cache 5min su `/rete`; preferire CTE se MySQL ≥ 8.0; v2 = tabella materializzata via job batch (follow-up, non ora).
3. **F1 COUNT senza placeholder inutili:** le sub-query di esclusione non devono introdurre bind non referenziati (HY093).
4. **F3 record soft-fail:** l'upsert view in `ProfileController::show()` va in try/catch — la pagina profilo gira su un percorso SEO caldo, un errore non deve mai dare 500.
5. **F2 counter drift:** aggiornare `endorsements_count` **solo** se `rowCount()===1` (INSERT IGNORE/DELETE), con `GREATEST(0, ...)` in decremento.
6. **F2 authz endorse:** verificare `areConnected` (accepted) + no-self **prima** di scrivere; ownership a livello SQL su tutte le mutazioni skill (`WHERE profile_id=:p`).
7. **Notifiche owner-less:** `NotificationService::emit` già salta i profili senza `user_id` — non duplicare il check, ma non aggirarlo.
8. **Nuovo tipo notifica `skill_endorsed`:** aggiungere le chiavi i18n `notif.skill_endorsed.title/body`; senza di esse `I18n::t` renderebbe la chiave grezza.
9. **Verifica versione MySQL SiteGround** prima di scegliere CTE vs derived-table in F1 (probabile 8.0 vista la presenza di JSON/FULLTEXT InnoDB).
10. **Design:** card suggerimenti, chip competenze, sparkline visite → dark, accento giallo, icone Font Awesome flat, **niente verde/niente emoji**.

## Punti che richiedono una TUA decisione
- **F1 dove mostrare il 2° grado:** solo `/rete` (consigliato) o anche empty-state feed?
- **F2 granularità notifica endorse:** collassare per attore/24h (default) o notificare per singola skill (body con label)?
- **F1/F3 API Bearer:** costruire le varianti API ora o rimandarle (spec le marca come rimandabili)?
- **F1 v2 materializzata:** dare priorità ora o attendere segnali di crescita del grafo?
