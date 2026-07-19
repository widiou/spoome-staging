# Checkpoint 1 — Checklist di completamento hardening

> Contesto: audit di sicurezza completato, **postura forte, nessun P0**. Restano item
> di configurazione/hardening da chiudere prima del lancio del **prodotto core** (NON marketplace).
> Vincolo di riferimento: `docs/SECURITY.md` (livello MASSIMO). Regola d'oro: mai fidarsi
> dell'input, sempre escapare l'output, mai segreti nel repo.
>
> Questo documento è **solo operativo**: per ogni item → *rischio/scenario*, *fix*,
> *priorità*, *come verificarlo dopo il deploy*. Non modifica config/codice live (un fork
> paralleloo sta applicando alcuni fix; lo stato reale del `.htaccess`/`public/.htaccess`
> è annotato item per item).

## Stato dei file al momento della stesura

| File | Header sicurezza | LimitRequestBody | CSP | HSTS |
|------|:---:|:---:|:---:|:---:|
| `.htaccess` (root, docroot attuale su `/beta/`) | ✅ | ✅ 10 MB | ✅ (con `'unsafe-inline'` su style) | ❌ (commentato) |
| `public/.htaccess` (docroot finale) | ✅ | ✅ 10 MB | ✅ (con `'unsafe-inline'` su style) | ❌ (assente) |
| `src/Core/SecurityHeaders.php` (via PHP, `index.php:28`) | ✅ | n/a (solo Apache) | ✅ (con `'unsafe-inline'` su style) | ❌ (assente) |

Legenda priorità: **P1** = da chiudere prima del lancio · **P2** = subito dopo il lancio / prossima iterazione.

---

## 1. Header di sicurezza + CSP + LimitRequestBody replicati in `public/.htaccess` e/o via PHP

**Stato: ✅ GIÀ COPERTO dal fork** (verificare non regredisca).

- **Rischio/scenario (foot-gun docroot→public):** oggi la docroot è la root del progetto
  (`/beta/`) e gli header vivono nel `.htaccess` di root. Quando la docroot viene spostata a
  `public/`, il `.htaccess` di root **non viene più letto**: senza replica, CSP e security
  header sparirebbero in silenzio (clickjacking, sniffing MIME, nessun limite al body).
- **Fix (già presente):**
  - `public/.htaccess` replica X-Content-Type-Options, X-Frame-Options, Referrer-Policy,
    X-Permitted-Cross-Domain-Policies, Cross-Origin-Opener-Policy, CSP, Cache-Control e
    `LimitRequestBody 10485760`.
  - `SecurityHeaders::send()` è chiamato dal front controller (`public/index.php:28`) e riemette
    gli stessi header via PHP → indipendente dalla docroot/mod_headers.
- **Gap residuo (nota, non bloccante):**
  - `LimitRequestBody` è **solo Apache**: non è replicato in PHP. Se un domani si servisse
    dietro un webserver che ignora `.htaccess` (es. nginx), il limite sul body sparirebbe.
    → **Backstop consigliato (P2):** controllo PHP su `Content-Length` (rifiuto 413 oltre 10 MB)
    in fondo a `SecurityHeaders`/bootstrap. Coerente con `post_max_size`/`upload_max_filesize`.
  - `Cache-Control: no-store` NON è emesso da PHP (solo via `.htaccess`). Per le pagine
    autenticate è difesa utile → valutare emissione anche via PHP (P2).
- **Verifica dopo il deploy:**
  ```
  curl -sI https://spoome.it/beta/ | grep -iE 'content-security-policy|x-frame-options|x-content-type|referrer-policy'
  ```
  Deve mostrare tutti gli header. Ripetere **dopo** l'eventuale spostamento docroot→public.
  Test `LimitRequestBody`: POST di un body >10 MB deve tornare **413** *prima* di PHP.
  ```
  head -c 11000000 /dev/zero | curl -s -o /dev/null -w '%{http_code}\n' -X POST --data-binary @- https://spoome.it/beta/accedi
  ```

---

## 2. HSTS coordinato col dominio di produzione

**Stato: ❌ APERTO — deliberatamente non impostato.**

- **Rischio/scenario:** senza HSTS un attaccante MITM su rete ostile può fare SSL-stripping
  (declassare a HTTP) al primo contatto. **Ma**: la beta (`spoome.it/beta`) **condivide il
  dominio** con la produzione `spoome.it`; `Strict-Transport-Security` è per-host e, con
  `includeSubDomains`/`preload`, impegna **l'intero dominio**. Impostarlo dalla beta forzerebbe
  HTTPS anche su parti di prod non pronte → rischio di lock-out. Per questo è a ragione commentato.
- **Fix (coordinato con prod):**
  1. Confermare che **tutto** `spoome.it` (prod inclusa) sia HTTPS-only e con cert valido.
  2. Rollout graduale dell'header a livello di **dominio** (non dal `.htaccess` della sola beta):
     - Fase A: `Strict-Transport-Security: max-age=300` (5 min, test).
     - Fase B: `max-age=31536000; includeSubDomains`.
     - Fase C (opzionale, irreversibile mesi): aggiungere `preload` + submit a hstspreload.org
       **solo** quando ogni sottodominio è HTTPS.
  3. Documentare la decisione in `docs/SECURITY.md` (oggi dice "HTTPS ovunque; HSTS").
- **Priorità:** **P1** per la decisione/coordinamento; l'attivazione segue il calendario prod.
- **Verifica dopo il deploy:**
  ```
  curl -sI https://spoome.it/ | grep -i strict-transport-security
  ```
  Controllare `max-age` atteso. Verificare che il redirect 301 HTTP→HTTPS sia attivo su prod e beta.
  **Non** abilitare `preload` finché non è certo che ogni subdomain regga HTTPS.

---

## 3. Sessione: timeout idle/absolute + `Session::regenerate()` sullo step-up admin

**Stato: ❌ APERTO (2 sotto-item).**

### 3a. `Session::regenerate()` mancante sullo step-up admin
- **Rischio/scenario:** al **login** l'ID sessione viene rigenerato
  (`AuthController::startUserSession()` → `Session::regenerate()`), corretto anti session-fixation.
  Ma allo **step-up admin** (`Admin\AuthController::verify()`, `AuthController.php:34`) si imposta
  solo `admin_stepup_at = time()` **senza rigenerare l'ID**. Un ID sessione fissato/predetto
  *prima* dell'elevazione resta valido *dopo* → session-fixation sul passaggio a privilegio alto,
  proprio la finestra che lo step-up dovrebbe restringere.
- **Fix:** in `Admin\AuthController::verify()`, subito dopo la verifica password riuscita e prima
  di `Session::set('admin_stepup_at', ...)`, chiamare `Session::regenerate()`.
- **Priorità:** **P1** (superficie admin ad alto privilegio; fix a 1 riga).
- **Verifica:** login admin → annota il cookie `spoome_session` → completa `/admin/verifica`
  con password corretta → il **valore del cookie deve cambiare** e l'accesso ad `/admin` deve
  restare valido. In DevTools/`curl -c/-b` confrontare il session id prima/dopo lo step-up.

### 3b. Timeout idle + absolute lato server
- **Rischio/scenario:** oggi c'è **solo** la `lifetime` del cookie (`Session::start`,
  `SESSION_LIFETIME=120` min) — controllo **client-side**, aggirabile e non affidabile. Non
  esiste enforcement server-side di: (a) **idle timeout** (invalidare dopo N minuti di inattività)
  né (b) **absolute timeout** (durata massima assoluta della sessione, es. 12–24 h). Una sessione
  rubata o lasciata su un dispositivo condiviso resta valida a lungo. Nota: lo StepUp admin ha già
  una sua TTL di 30 min (`StepUpMiddleware::TTL`), ma copre solo la finestra admin, non la sessione utente.
- **Fix:** in `Session::start()` (o in un middleware di auth) tracciare due timestamp e invalidare:
  - `last_activity`: se `now - last_activity > IDLE_TTL` → `Session::destroy()` + redirect login.
    Aggiornare `last_activity` a ogni richiesta autenticata.
  - `created_at`: se `now - created_at > ABSOLUTE_TTL` → forzare re-login anche se attiva.
  - Rigenerare l'ID periodicamente (es. ogni N minuti) come ulteriore mitigazione.
  - Rendere i valori configurabili (`SESSION_IDLE_TTL`, `SESSION_ABSOLUTE_TTL` in `.env`).
- **Priorità:** **P1** idle timeout · **P2** absolute timeout + rotazione periodica.
- **Verifica:** loggarsi, restare inattivi oltre `IDLE_TTL` (abbassarlo a 1–2 min in staging),
  poi richiedere una pagina autenticata → deve reindirizzare al login. Per l'absolute: sessione
  tenuta "attiva" oltre `ABSOLUTE_TTL` deve comunque forzare re-login. Controllare che gli helper
  di nav (`dm_unread/notif_unread/is_admin`) non vadano in 500 sulla sessione scaduta (regressione P0 di sito).

---

## 4. Rimozione del runner migrazioni HTTP a favore della CLI

**Stato: ❌ APERTO — endpoint ancora presente (protetto).**

- **Rischio/scenario:** `config/routes.php:229-242` monta `POST /__migrate` che esegue
  `Migrator::migrate()` via web. Ha **tripla protezione** (disattivo in produzione,
  richiede `MIGRATION_HTTP_ENABLED=true`, richiede `MIGRATION_TOKEN` confrontato con
  `hash_equals`) → rischio attuale basso. Ma un endpoint web che applica DDL arbitrario è una
  superficie ad alto impatto: un errore di config (flag/token lasciati attivi, `isProduction()`
  che ritorna false per mis-deploy) esporrebbe l'esecuzione di migrazioni da remoto. Le migrazioni
  sono già oggi applicate via CLI (`q.py`, vedi CLAUDE.md) → l'endpoint è ridondante.
- **Fix:**
  1. Aggiungere uno script CLI dedicato, es. `bin/migrate.php`, che istanzia `Migrator` e stampa
     il log (nessuna dipendenza HTTP; già disponibile la classe `Core\Migrator`).
  2. **Rimuovere** il blocco `POST /__migrate` da `config/routes.php`.
  3. Rimuovere le env `MIGRATION_HTTP_ENABLED` / `MIGRATION_TOKEN` da `.env`/documentazione.
  4. Aggiornare `docs/SECURITY.md` (voce "Runner migrazioni web off di default" → "rimosso, solo CLI").
- **Priorità:** **P1** (riduzione superficie; l'alternativa CLI esiste già di fatto).
- **Verifica dopo il deploy:**
  ```
  curl -s -o /dev/null -w '%{http_code}\n' -X POST https://spoome.it/beta/__migrate
  ```
  Deve tornare **404** (rotta inesistente), non 403. Confermare che le migrazioni si applichino
  via `php bin/migrate.php` (o `q.py`) e che `SELECT migration FROM migrations` rifletta l'ultimo file.

---

## 5. Togliere `style-src 'unsafe-inline'` dalla CSP

**Stato: ❌ APERTO — `'unsafe-inline'` presente in tutti e 3 i punti (root, public, PHP).**

- **Rischio/scenario:** la CSP è restrittiva su script (`script-src 'self'`, nessun inline JS) ma
  `style-src 'self' 'unsafe-inline'` consente CSS inline. `'unsafe-inline'` su style indebolisce
  la CSP: abilita alcune tecniche di data-exfiltration/CSS-injection e attenua la difesa in profondità
  contro XSS che manipolano attributi `style`. Obiettivo `docs/SECURITY.md`: "niente inline non-nonce".
- **Situazione reale (8 occorrenze `style=` in `views/`):**
  - **Statiche** (spostabili in classe CSS): `views/pages/profilo/edit.php:265,280`
    → `style="grid-column: span 2"` → creare classe `.field--span2`.
  - **Dinamiche** (valore calcolato server-side): barre/legende admin in
    `views/pages/admin/dashboard.php:70`, `views/pages/admin/stats.php:59-61,88,117`
    → `style="width: <?= $pct ?>%"`, `style="background: <?= $colors[...] ?>"`.
    Queste **non** si spostano in una classe statica; servono:
    - CSS custom properties impostate inline con **nonce**, oppure
    - un set di classi discretizzate (es. step del 5%), oppure
    - un `<style nonce="...">` per-pagina generato server-side.
- **Fix:**
  1. Spostare gli inline **statici** in classi nel CSS self-hosted.
  2. Per i **dinamici**, introdurre un **nonce** per-richiesta (generato nel bootstrap, esposto alle
     view) e passare da `style-src 'self' 'unsafe-inline'` a `style-src 'self' 'nonce-XXXX'`
     in **tutti e tre** i punti (root `.htaccess`, `public/.htaccess`, `SecurityHeaders.php`).
     Nota: con un nonce va gestita la coerenza tra header PHP e header Apache (l'Apache statico non
     può conoscere il nonce → in regime nonce la CSP va emessa **solo da PHP**, lasciando ad Apache
     gli altri header).
  3. In alternativa a basso sforzo: eliminare del tutto gli inline (classi + custom properties senza
     nonce) e togliere `'unsafe-inline'` senza introdurre nonce.
- **Priorità:** **P2** (irrigidimento progressivo; richiede refactor view + eventuale meccanismo nonce).
- **Verifica dopo il deploy:** con `'unsafe-inline'` rimosso, aprire ogni pagina con inline style
  (profilo edit, dashboard, stats) e controllare la **console del browser**: nessuna violazione CSP
  `Refused to apply inline style`. Le barre/legende admin devono rendersi correttamente.
  ```
  curl -sI https://spoome.it/beta/ | grep -i content-security-policy   # non deve più contenere 'unsafe-inline'
  ```
  Consigliato: fase di rodaggio con `Content-Security-Policy-Report-Only` prima dell'enforcement.

---

## 6. Token di reset/verify passati in GET (query string)

**Stato: ❌ APERTO — token in query string.**

- **Rischio/scenario:** i token monouso arrivano via **GET**:
  - `AuthController::verifyEmail()` legge `$request->query['token']` (`AuthController.php:148`).
  - `AuthController::showReset()` legge `$request->query['token']` (`AuthController.php:192`).
  Un token in query string finisce in: cronologia browser, log di access del webserver/proxy,
  header `Referer` verso terze parti (analytics, risorse esterne), bookmark. Se intercettato prima
  della scadenza/consumo, consente takeover account (reset) o verifica indebita. **Mitiganti già
  presenti:** token *hashed* a riposo, monouso, scadenza breve (`docs/SECURITY.md`); il POST di
  reset (`reset()`) prende il token dal **body**, non dalla query — solo la fase GET lo espone.
- **Fix (difesa in profondità; il GET del link email è in parte inevitabile):**
  1. **Referrer:** assicurare `Referrer-Policy: strict-origin-when-cross-origin` (già impostato) o
     più stretto (`no-referrer`) sulle pagine di verify/reset, così il token non esce nel `Referer`.
  2. **Consumo immediato / one-time:** su `showReset` (GET) **non** validare/consumare il token;
     validarlo e invalidarlo solo al **POST**. Per la verifica email, trasformare il pattern in:
     GET mostra una pagina di conferma con form → **POST** (CSRF) esegue la verifica, così il token
     "attivo" transita nel body e viene consumato una sola volta.
  3. **No-log del token:** verificare che il token non finisca nei log applicativi/access log
     (mascheramento) e che le pagine reset/verify abbiano `Cache-Control: no-store` (già default).
  4. **Scadenza breve** confermata (idealmente ≤ 30–60 min per il reset).
- **Priorità:** **P2** (mitiganti forti già attivi; è irrigidimento). Alzare a **P1** solo se emerge
  che il token viene loggato in chiaro o inoltrato a host terzi.
- **Verifica dopo il deploy:**
  - Aprire un link di reset/verify e controllare che navigando da lì verso una risorsa esterna il
    `Referer` **non** contenga il token (DevTools → Network → Request Headers).
  - Grep sugli access log/app log: il valore del token non deve comparire in chiaro.
  - Confermare che riusare lo stesso link una seconda volta dia "token non valido/consumato".
  - `curl -sI` della pagina reset → `Cache-Control: no-store` presente.

---

## Riepilogo priorità

| # | Item | Stato | Priorità |
|---|------|-------|----------|
| 1 | Header/CSP/LimitRequestBody in `public/.htaccess` + PHP | ✅ Coperto (backstop body-size PHP = P2) | — |
| 2 | HSTS coordinato con prod | ❌ Aperto | **P1** (decisione) |
| 3a | `Session::regenerate()` su step-up admin | ❌ Aperto | **P1** |
| 3b | Timeout idle / absolute lato server | ❌ Aperto | **P1** idle / **P2** absolute |
| 4 | Rimuovere runner migrazioni HTTP → CLI | ❌ Aperto | **P1** |
| 5 | Togliere `style-src 'unsafe-inline'` | ❌ Aperto | **P2** |
| 6 | Token reset/verify fuori dalla query GET | ❌ Aperto | **P2** |
