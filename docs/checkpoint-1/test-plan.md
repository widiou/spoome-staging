# Checkpoint 1 — Piano di test (QA & non-regressione)

> **Scopo.** Garantire che il **prodotto core** (auth, profili, follow, connessioni, DM,
> notifiche, claim, admin) resti solido e **senza regressioni visibili** mentre il codice
> viene rifattorizzato. Questo documento è **solo il piano**: NON eseguo deploy/test io ora —
> il fork del refactoring possiede il codice live e sta testando. Qui definisco *cosa*
> verificare, *con quali comandi* e *quali risultati attendersi*.

## 0. Premesse operative

- **Ambiente:** beta `https://spoome.it/beta` (produzione `spoome.it` INTOCCABILE).
  `BASE_PATH = /beta` → tutte le URL applicative sono prefissate (`/beta/feed`, ecc.).
- **Niente PHP locale:** i test dal vivo si fanno via **deploy + `curl`**; il DB via `q.py`.
- **Credenziali demo (da preservare sempre):**
  admin `marco.rossi@demo.spoome.local` / `SpoomeBeta25!`; profilo pubblico demo
  `giulia-bianchi`. **Ogni test deve ripristinare lo scenario demo** (claim/notifiche incluse).
- **Modello auth:**
  - Web = **sessione** (cookie `spoome_session`) + **CSRF** (`_csrf` o header `X-CSRF-Token`)
    sulle POST; fallimento CSRF → **419**.
  - API scritture = **solo-Bearer** (`CurrentUser::fromBearer`) → un cookie di sessione NON
    può mutare via API (anti-CSRF strutturale). Da verificare come **caso negativo**.
- **Convenzione di sessione (denormalizzazione al login — `startUserSession`):**
  `user_id`, `role`, `profile_id` sono scritti in sessione. `profile_id` **può essere `null`**
  (utente "claimant" senza profilo): gli helper usano `Session::has()` per distinguere
  *assente* da *null*. Verifica email → auto-login con `role='member'`.

### 0.1 Helper di nav (rischio 500 su OGNI pagina autenticata)

`is_admin()`, `dm_unread()`, `notif_unread()` girano nel layout di **ogni pagina autenticata**:
un bug qui manda in **500 l'intero sito**. Ognuno ha un **fallback** per sessioni preesistenti
al deploy — da testare esplicitamente:

| Helper | Fast-path (sessione) | Fallback (sessione legacy) |
|---|---|---|
| `is_admin()` | `Session::get('role')` | `UserRepository::findById()->isAdmin()` |
| `dm_unread()` | `Session::get('profile_id')` (via `has()`) | `ProfileRepository::findByUserId()` |
| `notif_unread()` | `NotificationRepository::unreadCount(user_id)` | — |

**Test fallback (via `q.py` + curl):** dopo login, rimuovere `role`/`profile_id` dalla sessione
non è banale via curl; in alternativa **PHPUnit** su `helpers.php` con `$_SESSION` costruito a
mano (vedi §2.6). Come minimo dal vivo: garantire **200** su tutte le pagine autenticate sia
per l'admin (con profilo) sia per un utente **claimant senza profilo** (`profile_id=null`).

---

## 1. Checklist di REGRESSIONE delle fondamenta (dal vivo, curl)

### 1.1 Setup della sessione riutilizzabile

```bash
BASE="https://spoome.it/beta"
CJ=$(mktemp)   # cookie jar

# 1) GET login → estrai il token CSRF dalla pagina (campo hidden _csrf)
CSRF=$(curl -s -c "$CJ" "$BASE/accedi" \
  | grep -oE 'name="_csrf" value="[^"]+"' | sed -E 's/.*value="([^"]+)".*/\1/')

# 2) POST login (admin demo)
curl -s -i -b "$CJ" -c "$CJ" -X POST "$BASE/accedi" \
  --data-urlencode "_csrf=$CSRF" \
  --data-urlencode "email=marco.rossi@demo.spoome.local" \
  --data-urlencode "password=SpoomeBeta25!" | head -1
# Atteso: HTTP/2 302  (redirect a "/" = login riuscito). NON 200 (=form ri-renderizzato con errore).
```

> Nota: il token CSRF va ri-estratto quando cambia sessione. Per le POST autenticate riusare
> `$CJ` e ri-leggere `_csrf` da una pagina fresca (`/profilo`).

### 1.2 Pagine autenticate → **200 atteso** (helper di nav NON devono 500)

Funzione di supporto e sweep:

```bash
code() { curl -s -o /dev/null -w "%{http_code}" -b "$CJ" "$1"; }

for P in "/" "/feed" "/rete" "/messaggi" "/profilo" "/notifiche" \
         "/rivendicazioni" "/atleti" "/atleti/giulia-bianchi" \
         "/atleti/giulia-bianchi/follower" "/atleti/giulia-bianchi/seguiti"; do
  printf "%-40s %s\n" "$P" "$(code "$BASE$P")"
done
# Atteso: 200 su TUTTE. Un 500 su qualsiasi riga = regressione bloccante negli helper/nav.
```

Pagina thread DM (richiede connessione demo esistente — usare un handle **connesso** a Marco):

```bash
code "$BASE/messaggi/giulia-bianchi"   # Atteso 200 se connessi; 403/redirect se non connessi (vedi §1.6)
```

### 1.3 Area admin con **step-up**

```bash
# Prima del passo step-up: /admin redirige a /admin/verifica
curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" -b "$CJ" "$BASE/admin"
# Atteso: 302 …/admin/verifica

# Step-up: re-inserimento password
CSRF=$(curl -s -b "$CJ" "$BASE/admin/verifica" \
  | grep -oE 'name="_csrf" value="[^"]+"' | sed -E 's/.*value="([^"]+)".*/\1/')
curl -s -o /dev/null -w "%{http_code}\n" -b "$CJ" -c "$CJ" -X POST "$BASE/admin/verifica" \
  --data-urlencode "_csrf=$CSRF" --data-urlencode "password=SpoomeBeta25!"
# Atteso: 302 (verso destinazione memorizzata)

# Ora le pagine admin devono dare 200 (entro il TTL step-up di 30 min)
for P in "/admin" "/admin/statistiche" "/admin/utenti" "/admin/rivendicazioni" \
         "/admin/contenuti" "/admin/log"; do
  printf "%-30s %s\n" "$P" "$(code "$BASE$P")"
done
# Atteso: 200 su tutte.
```

### 1.4 Verifica dei **contatori** su ogni mutazione

Distinzione chiave (il refactor NON deve romperla):

- **Denormalizzati (colonne reali su `profiles`):** `followers_count`, `following_count`.
  Aggiornati in `FollowRepository::follow/unfollow` (`+1` / `GREATEST(0, -1)`). **Rischio drift**:
  la colonna denormalizzata può divergere dal `COUNT(*)` reale su `follows`.
- **Calcolati live (COUNT):** connessioni accettate (`ConnectionRepository::connectionCount`),
  richieste in entrata (`incomingCount`), notifiche non lette (`NotificationRepository::unreadCount`),
  messaggi non letti (`MessageRepository::unreadTotal`). Qui il rischio è la **query** (placeholder
  riusati → 500) e la **correttezza del filtro**, non il drift.

**Invariante follow (denormalizzato == reale) — verifica via `q.py` dopo follow/unfollow:**

```sql
-- Deve restituire 0 righe: nessun profilo con contatore denormalizzato ≠ COUNT reale.
SELECT p.id, p.followers_count,
       (SELECT COUNT(*) FROM follows f WHERE f.followee_id = p.id) AS real_followers,
       p.following_count,
       (SELECT COUNT(*) FROM follows f WHERE f.follower_id = p.id) AS real_following
FROM profiles p
HAVING followers_count <> real_followers OR following_count <> real_following;
```

**Sequenza mutazione + asserzione (usare un profilo di test, NON demo, o ripristinare):**

```bash
# Follow via web (attore = Marco, target = un profilo pubblico di test)
CSRF=$(curl -s -b "$CJ" "$BASE/profilo" | grep -oE 'name="_csrf" value="[^"]+"' | sed -E 's/.*value="([^"]+)".*/\1/')
curl -s -o /dev/null -w "%{http_code}\n" -b "$CJ" -X POST "$BASE/atleti/<target>/segui" \
  --data-urlencode "_csrf=$CSRF"
# Atteso 200/302. Poi ri-eseguire la query d'invariante → 0 righe.
# Ripetere segui (idempotenza): il contatore NON deve incrementare due volte (INSERT IGNORE → rowCount 0).
```

Checklist contatori per mutazione:

| Mutazione | Contatore da verificare | Atteso |
|---|---|---|
| `POST /atleti/{h}/segui` | `followers_count(target)`, `following_count(attore)` | +1 ciascuno; invariante = 0 righe |
| ripetere `segui` | idem | **invariato** (idempotente) |
| `POST /atleti/{h}/nonseguire` | idem | −1 (mai < 0); invariante = 0 righe |
| `connetti` → accept | `connectionCount` di entrambi | +1 (COUNT live corretto) |
| `disconnetti` | `connectionCount`, `incomingCount` | −1 / pending rimossa |
| invio DM | badge `dm_unread` del destinatario | +1; a `markRead` → 0 |
| azione che genera notifica (claim) | badge `notif_unread` del destinatario | +1; a lettura → 0 |

### 1.5 Ricerca profili usa **FULLTEXT** (non full scan)

`ProfileRepository::listPublic` usa `MATCH(display_name, headline, bio) AGAINST (:q IN BOOLEAN MODE)`
(indice `ft_profiles_search`) **OR** `handle LIKE 'term%'` (prefisso indicizzabile, no wildcard iniziale).

```bash
# Ricerca via directory pubblica (parametro ?q=)
code "$BASE/atleti?q=giulia"                      # Atteso 200, risultati pertinenti
curl -s -o /dev/null -w "%{http_code}\n" "$BASE/api/v1/profiles?q=gi"   # prefisso corto → 200, nessun 500
```

**Verifica indicizzazione via `q.py` (che l'indice FULLTEXT sia effettivamente usato):**

```sql
EXPLAIN SELECT COUNT(*) FROM profiles p
  JOIN profile_types pt ON pt.id = p.profile_type_id
  WHERE p.visibility='public'
    AND (MATCH(p.display_name,p.headline,p.bio) AGAINST ('+giu*' IN BOOLEAN MODE)
         OR p.handle LIKE 'giu%');
-- Atteso: type=fulltext e key=ft_profiles_search (NON 'ALL' = full table scan).
SHOW INDEX FROM profiles WHERE Key_name='ft_profiles_search';  -- l'indice deve esistere.
```

Casi limite ricerca (nessun 500): termini con soli operatori boolean (`+`, `*`, `"`, `~`) →
vengono strippati → deve degradare a `handle LIKE`, mai errore; termine con `%`/`_` →
escaping `ESCAPE '\\'` deve evitare wildcard injection.

### 1.6 Casi NEGATIVI (regressione di sicurezza/authz)

```bash
# Guest su area autenticata → redirect a login (302), MAI 200
curl -s -o /dev/null -w "%{http_code}\n" "$BASE/feed"          # Atteso 302 → /accedi

# Non-admin su /admin → 404 camuffato (NON 403, non deve rivelare l'area). Testare con utente member.
curl -s -o /dev/null -w "%{http_code}\n" -b "$CJ_MEMBER" "$BASE/admin"   # Atteso 404

# POST senza CSRF → 419
curl -s -o /dev/null -w "%{http_code}\n" -b "$CJ" -X POST "$BASE/atleti/giulia-bianchi/segui"   # Atteso 419

# Handle inesistente → 404
curl -s -o /dev/null -w "%{http_code}\n" "$BASE/atleti/non-esiste-xyz"   # Atteso 404

# API scrittura con SOLO cookie di sessione (niente Bearer) → 401 (anti-CSRF: fromBearer)
curl -s -o /dev/null -w "%{http_code}\n" -b "$CJ" -X POST "$BASE/api/v1/profiles/giulia-bianchi/follow"  # Atteso 401

# Login errato → 401 (form) ; troppi tentativi dallo stesso IP → 429 (throttle per IP)
# DM verso profilo NON connesso → 403/redirect (regola "solo tra connessi")
```

Tabella attesi negativi: `401` (credenziali/API senza Bearer), `403` (pending/inactive/DM non
connessi/follow privato), `404` (admin per non-admin, risorsa inesistente), `419` (CSRF),
`422` (claim già rivendicato/già hai profilo), `429` (throttle).

---

## 2. Casi PHPUnit prioritari (core, per rischio)

Infrastruttura esistente: `tests/Unit` (no DB), `tests/Integration` (MySQL usa-e-getta via
`SPOOME_TEST_DSN`/`_USER`/`_PASS`; **skip** se assente — mai il DB di produzione). I Service
accettano `?PDO` → iniettabili senza mock. Ogni test **deterministico** e **ripristina lo stato**.

### 2.1 Auth — throttling (Integration, `AuthServiceTest`)

- `login` blocca **per IP** dopo 5 fallimenti/15 min → `code=429`; l'IP dell'attaccante non deve
  poter **lockare la vittima** (i "colpi" generici con `identifier` contenente `:` NON contano —
  già coperto in `RateLimiterTest`, estendere a `AuthService::login`).
- `register`/`registerClaimant`: throttle `reg:<ip>` a 10/60 min → `error='throttled'`.
- `requestPasswordReset`: doppio throttle (`pwf:ip` 5/15 min **e** `pwf:em` 3/60 min).
- `resetPassword`: throttle `pwr:<ip>` 10/15 min.

### 2.2 Auth — anti-enumeration / timing (Unit + Integration)

- **Timing:** `login` su email inesistente esegue comunque `password_verify` contro `DUMMY_HASH`
  (nessun early-return che riveli l'assenza). Test: email inesistente e password errata su email
  esistente ritornano **lo stesso `code=401`** e stesso messaggio (`auth.error.credentials`).
- **Register:** `email_taken` NON deve propagarsi all'utente: il controller tratta `ok` ed
  `email_taken` in modo identico (stesso flash + redirect). Assert su `AuthController::register`
  logica (o test funzionale) che i due rami producano la **stessa risposta**.
- **Reset request:** `requestPasswordReset` è `void` e non rivela mai l'esistenza (nessuna
  differenza osservabile tra email nota/ignota/sospesa).

### 2.3 Auth — register **atomico** (Integration)

- `AuthService::register` crea **utente + profilo in transazione**: forzare un fallimento nella
  creazione profilo (es. `typeIdByKey` valido ma `profiles.create` che lancia) e verificare
  **rollback totale** → nessun utente orfano (`emailExists` resta `false`).
- Handle univoco: due `register` con lo stesso `display_name` → `uniqueHandle` genera handle
  distinti (nessuna violazione UNIQUE non gestita).
- Utente creato in stato **pending**; `login` prima della verifica → `code=403` (`auth.error.pending`).

### 2.4 Claim ownership (Integration, `ClaimServiceTest`) — area a rischio corsa

- `request`: fallisce con `422` se profilo **già rivendicato** (`claim_status != 'unclaimed'` o
  `user_id != null`), se l'utente **ha già un profilo** (`userHasProfile`), se **già pending**
  (dedup `pendingFor`); `429` se throttled.
- `approve`: **ricontrolli anti-corsa** — anche se la richiesta è `pending`, se nel frattempo il
  profilo è stato assegnato (`profile_owner_id != null`) o il richiedente ha ottenuto un profilo,
  ritorna `422` senza assegnare. Sul percorso felice: `assignOwner` imposta owner, marca la
  richiesta `approved`, e **rigetta automaticamente le altre pending** sullo stesso profilo
  (`rejectOtherPending`), scrive audit, crea notifica `claim_approved`.
- **Doppia approvazione concorrente** dello stesso profilo (due richieste diverse): solo la prima
  assegna; la seconda vede `err_taken` (422). Simulabile assegnando owner tra `findDetail` e
  `assignOwner` (o due chiamate sequenziali sullo stato mutato).
- `reject`: marca `rejected`, audit, notifica `claim_rejected` (con nota opzionale troncata a 500).

### 2.5 Reset password **atomico** (Integration, `PasswordResetServiceTest`)

- `resolveAndConsume` è la **claim atomica**: `UPDATE … WHERE used_at IS NULL` → solo **una**
  chiamata concorrente ottiene `rowCount()===1`; la seconda ottiene `null` (monouso garantito
  anche in gara). Test: due `resolveAndConsume` sullo stesso raw token → primo `userId`, secondo `null`.
- Token **scaduto** (`expires_at < NOW()`) → `null`. `issue` invalida i precedenti token pendenti.
- `AuthService::resetPassword` dopo successo chiama `revokeAllForUser` (i token API pre-reset non
  devono più risolvere). Password che viola la policy → nessun consumo del token.

### 2.6 Il **gotcha placeholder PDO** (Integration/Unit) — causa storica di 500

`EMULATE_PREPARES=false` → i named placeholder **non sono riusabili**. Le query che ripetono lo
stesso valore usano suffissi (`:me1/:me2/:me3`, `:a1/:b1/:b2/:a2`). Test di **non-regressione**:
eseguire realmente ciascuna contro il DB usa-e-getta e verificare che **non lancino** e diano il
conteggio atteso:

- `MessageRepository::unreadTotal` (`:me1/:me2/:me3`).
- `ConnectionRepository::findBetween` / `deleteBetween` / `connectionCount` (`:a1/:b1/:b2/:a2`, `:p1/:p2`).
- `ProfileRepository::listPublic` con `search` (bind misto named + `:lim/:off` posizionali-per-nome).

> Regola di code-review da automatizzare: un `preg` che segnali un named placeholder che compare
> **due volte** nella stessa stringa SQL (heuristica di lint anti-500).

### 2.7 RateLimiter (già esistente) — mantenere e estendere

`RateLimiterTest` copre soglia per-chiave e "colpi generici non lockano il login per IP".
Aggiungere: finestra temporale (`withinMinutes` esclude i vecchi), `record(successful=true)`
non conta come fallimento, `purgeOlderThan`.

### 2.8 Unit già presenti da preservare

`PasswordPolicyTest` (10–72 char, lettera+numero), `StrTest` (`handle` hyphen-friendly),
`ServiceResultTest`. Il refactor NON deve cambiarne il contratto.

---

## 3. Smoke test end-to-end dei flussi core

Sequenza completa su un **utente di test dedicato** (mai i demo), poi **teardown** che ripristina
lo scenario. Ogni passo deve dare l'esito atteso; un fallimento **blocca il rilascio**.

1. **Registrazione** — `POST /registrati` (display_name, email test, password valida, profile_type)
   → 302 + flash "registrato". Via `q.py`: utente in stato **pending**, profilo creato, handle univoco.
2. **Anti-enumeration** — ripetere `POST /registrati` con la **stessa email** → stessa risposta
   (302 + stesso flash), nessun errore che riveli il duplicato.
3. **Verifica email** — recuperare il token dal DB (`email_verifications`) → `GET /verifica?token=…`
   → 302 (auto-login), utente ora **active**, sessione con `role`/`profile_id`.
4. **Login** — `POST /accedi` → 302 verso "/". Login pre-verifica avrebbe dato 403 (regressione check).
5. **Profilo** — `GET /profilo` 200; `POST /profilo` (aggiorna headline/bio) → persistito; aggiungere
   esperienza/palmarès/link (200 ciascuno).
6. **Follow** — `POST /atleti/{target}/segui` → `followers_count(target)+1`, `following_count(attore)+1`;
   invariante denormalizzato==reale = 0 righe; ripetere = idempotente.
7. **Connessione** — `POST /atleti/{target}/connetti` (pending) → dal secondo account **accept**;
   `connectionCount` di entrambi +1; `incomingCount` torna a 0.
8. **DM** — solo tra connessi: `POST /messaggi/{handle}` → messaggio persistito; `dm_unread` del
   destinatario +1; aprire il thread (`markRead`) → torna 0. DM verso non-connesso → 403.
9. **Notifica** — un'azione che notifica (es. esito claim) → `notif_unread` del destinatario +1;
   `GET /notifiche` 200; lettura → badge a 0.
10. **Claim** — con un utente **claimant** (registrato via `/registrati/rivendica`, senza profilo):
    `POST /atleti/{unclaimed}/rivendica` (422 se non-unclaimed) → admin `approve` → owner assegnato,
    altre pending auto-rifiutate, notifica `claim_approved` al richiedente. Verificare il **guard**
    "hai già un profilo" (422).
11. **Logout** — `POST /esci` → 302; le pagine autenticate tornano a redirigere a login.

### 3.1 Preservazione dello scenario demo (obbligatoria)

- **Prima:** snapshot via `q.py` dei conteggi demo chiave (follow/connessioni/notifiche di
  `marco.rossi` e `giulia-bianchi`, claim pending esistenti).
- **Dopo:** eliminare SOLO i dati dell'utente di test (follows, connections, messages, notifications,
  profile, user) e ri-verificare che gli snapshot demo coincidano. Backup prima di qualsiasi DELETE.
- **Regola:** nessun test tocca i profili/claim/notifiche demo; se un flusso li richiede come target,
  usa operazioni **reversibili** e ripristina.

---

## 4. Criteri di uscita (gate del checkpoint)

- Tutte le pagine di §1.2/§1.3 → **200** (per admin e per claimant senza profilo). Zero 500.
- Invariante contatori follow = **0 righe** dopo ogni ciclo di mutazione.
- `EXPLAIN` ricerca profili usa `ft_profiles_search` (no full scan).
- Tutti i casi negativi (§1.6) danno il codice atteso (401/403/404/419/422/429), non 200/500.
- Suite PHPUnit §2 verde (Integration esegue con DB usa-e-getta; Unit ovunque).
- Smoke E2E §3 completo e **scenario demo ripristinato** (snapshot pre==post).
