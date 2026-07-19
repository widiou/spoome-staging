# Spoome — Ops Plan (Checkpoint 1)

> DevOps & Release Engineering per il **prodotto core** (no pagamenti/marketplace).
> Ambiente: PHP vanilla MVC su **SiteGround (FTPS)**, MySQL. Beta live `https://spoome.it/beta`;
> produzione `spoome.it` **separata e intoccabile**. Niente PHP locale → si verifica via deploy + `curl`.
> Regola d'oro operativa (CLAUDE.md §Non negoziabili 2): gli helper di nav
> `dm_unread/notif_unread/is_admin` girano su **ogni** pagina autenticata → un bug lì manda in 500 tutto il sito.

---

## 1. Osservabilità

### 1.1 Cosa abbiamo già (fonti di verità)
- **`storage/logs/app.log`** — JSONL, tutti i livelli, un evento per riga con `request_id/ip/method/path/user_id`.
  Rotazione a **5 MB** (`Logger::MAX_BYTES`) via rename `app.log.YYYYMMDD_HHMMSS` (fuori docroot, non servito).
- **Tabella `app_logs`** — solo `error`/`warning` (`Logger::DB_LEVELS`), interrogabile, con **fingerprint**
  = `sha1(level|channel|file:line)` (o `|message` se manca file). Indici: `idx_logs_level_time`,
  `idx_logs_fingerprint`, `idx_logs_channel`. Canali attuali: `app`, `security`.
- **Admin già esistente**: `AdminLogService::grouped()` (errori raggruppati per fingerprint, i ricorrenti in cima)
  + `occurrences()` (timeline di un fingerprint); `AdminMetricsService::health()` espone `errors_24h`/`warnings_24h`.

### 1.2 Fingerprint degli errori ricorrenti — query di sorveglianza
Da eseguire via `q.py` (o pannello admin `/admin/logs`). Top offender delle ultime 24h:
```sql
SELECT fingerprint, level, channel, COUNT(*) AS hits,
       MAX(created_at) AS last_seen, ANY_VALUE(message) AS sample,
       ANY_VALUE(CONCAT(file, ':', line)) AS loc
FROM app_logs
WHERE created_at > NOW() - INTERVAL 24 HOUR
GROUP BY fingerprint, level, channel
ORDER BY hits DESC
LIMIT 20;
```
**Fingerprint da tenere sotto osservazione fin da subito** (rischi noti dell'architettura):
- **500 su placeholder PDO riusati** — `EMULATE_PREPARES=false` → named placeholder non riutilizzabili
  (CLAUDE.md): raggruppare per `file:line` in `channel='app'`, `exception_class LIKE '%PDOException%'`.
- **500 sui nav-helper** (vedi §1.3) — qualunque errore con `path` che copre pagine autenticate diffuse.
- **Sicurezza** — burst su `channel='security'` (login falliti/accessi negati) = abuso/attacco in corso.

### 1.3 Sorvegliare che le pagine autenticate NON vadano in 500 (badge nav ovunque)
Poiché `dm_unread/notif_unread/is_admin` sono renderizzati su ogni layout autenticato, un errore lì è
**sistemico**, non locale. Segnali:
```sql
-- 5xx sistemici nelle ultime 2h, per rotta
SELECT path, COUNT(*) AS c, MAX(created_at) AS last
FROM app_logs
WHERE level='error' AND created_at > NOW() - INTERVAL 2 HOUR
GROUP BY path ORDER BY c DESC;
```
Un singolo fingerprint che compare su **≥3 path distinti** in pochi minuti ⇒ quasi certamente un nav-helper.
**Sonda esterna (smoke, no login richiesto per il segnale grezzo):** dopo ogni deploy fare `curl -sS -o /dev/null -w '%{http_code}'`
su un set fisso di URL autenticati/pubblici chiave (home, feed, un profilo pubblico, `/api/health` se presente):
qualunque `500` = stop e rollback. Vedi §2.5.

### 1.4 Soglie / alert (baseline pre-launch)
| Segnale | Sorgente | WARN | CRITICAL |
|---|---|---|---|
| Errori totali/ora | `app_logs level='error'` | > 10/h | > 50/h **o** +500% vs media 7g |
| Nuovo fingerprint mai visto | `app_logs` (min(created_at) < 1h) | qualsiasi in prod | ricorre ≥5 volte in 15 min |
| Stesso fingerprint su ≥3 path | `app_logs` | ≥3 path/10min | ≥5 path/10min (nav-helper) |
| Burst `channel='security'` | `app_logs` | > 20/5min stesso IP | > 100/5min o multi-IP |
| Smoke 5xx post-deploy | `curl` | 1 endpoint | qualsiasi endpoint autenticato |
| Crescita `login_attempts` | `COUNT(*)` | > 500k righe | > 2M righe (vedi §4) |
| Dimensione `storage/logs` | FS | > 50 MB | rotazione non gira / disco pieno |

**Meccanismo alert (senza infra esterna):** un job schedulato SiteGround (cron) — `jobs/health-check.php`
— esegue le query sopra ogni 5–10 min e invia email su superamento soglia (stesso mailer dell'app).
Fino a quando il cron non è in piedi: check manuale post-deploy + review `/admin/logs` giornaliera.

---

## 2. Runbook di rilascio verso produzione

### 2.1 Pre-flight (checklist, bloccante)
- [ ] `git status` pulito, branch allineato; diff passato in review (security review sul diff se tocca auth/upload/admin/API).
- [ ] `.env` di destinazione corretto: `APP_ENV=production`, `APP_DEBUG=false`/`display_errors` off,
      `MIGRATION_HTTP_ENABLED=false`, `MIGRATION_TOKEN` vuoto o non usato.
- [ ] Migrazioni pendenti note: `php jobs/migrate.php status` (vedi §3) — deciso se e quando applicarle.
- [ ] **Backup DB fresco** eseguito e verificato (§4).
- [ ] `python3 jobs/deploy.py --dry-run` → rivedere la lista dei file che verrebbero caricati (nessun `.env`, nessun file di test).

### 2.2 Passaggio docroot → `public/` in sicurezza (SECURITY.md §Header/trasporto)
Oggi la docroot è la **root del progetto** sotto `/beta/`; il funzionamento è retto dal `.htaccess` che instrada tutto
su `public/index.php` e nega `src|config|database|storage|jobs|vendor|views|tests`. Il target è **docroot = `public/`**,
così le cartelle applicative sono *fisicamente* fuori dalla web root (difesa reale, non solo `.htaccess`).

Procedura a rischio minimo (reversibile):
1. Preparare, **senza** cambiare la docroot, un `public/.htaccess` con: gli **stessi security header** e la
   **stessa regola di caching far-future** oggi nella root (§2.3), più `Options -Indexes`.
2. Verificare che tutti i path relativi reggano: il front controller usa `dirname(__DIR__)` per la root
   → resta valido con docroot su `public/`. `assets/` e `uploads/` sono già serviti da `public/`.
3. **Cambiare la docroot** nel pannello SiteGround (Domain → Document Root) a `.../beta/public`.
   Questo non tocca i file: è la mossa reversibile chiave.
4. Smoke test (§2.5). In caso di problemi: **ripristinare la docroot alla root** (rollback istantaneo, §2.6).
5. Solo a docroot spostata e stabile: irrobustire negando comunque l'accesso alle cartelle sensibili
   (ora già fuori root) e rimuovere le regole di fallback ridondanti dal `.htaccess` di root.

> Nota HSTS: **non** attivare da `.htaccess` beta — il dominio è condiviso con la produzione (già annotato nel `.htaccess`).
> HSTS va deciso a livello di dominio insieme alla prod, non in questo rilascio.

### 2.3 Attivazione caching far-future degli asset (`?v=filemtime` già presente)
Il cache-busting è già in uso (query string `?v=filemtime` sugli asset). Il `.htaccess` già applica agli asset
(`css|js|woff2?|ttf|eot|png|jpe?g|gif|svg|webp|ico|map`) `Cache-Control: public, max-age=31536000, immutable`
mentre l'HTML dinamico resta `no-store`. **Azioni di rilascio:**
- Garantire che la stessa coppia di regole viva nel `.htaccess` della docroot effettiva (root **o** `public/` dopo §2.2).
- Verificare a caldo:
  ```sh
  curl -sI https://spoome.it/beta/assets/app.css?v=123 | grep -i cache-control   # atteso: max-age=31536000, immutable
  curl -sI https://spoome.it/beta/                     | grep -i cache-control   # atteso: no-store
  ```
- `immutable` è sicuro **solo** perché ogni cambio di asset cambia `filemtime` → cambia l'URL. Non introdurre mai
  asset referenziati senza `?v=` (verrebbero congelati in cache per un anno).

### 2.4 Ordine delle operazioni (deploy standard, senza schema change)
1. Backup DB (§4). 2. `deploy.py --dry-run` → conferma. 3. `python3 jobs/deploy.py` (upload solo file con hash
cambiato, manifest `.deploy-state.json`). 4. Smoke test (§2.5). 5. Watch `app_logs` per 10–15 min (§1).

**Con schema change:** applicare le **migrazioni prima** se sono *additive/backward-compatible* (nuove tabelle/colonne
nullable) così il codice vecchio ancora gira; applicarle **dopo** il deploy solo se il vecchio codice non le tollera.
Preferire sempre migrazioni additive + due fasi (expand → contract) per evitare finestre di 500. Comando in §3.

> Attenzione (MySQL): i DDL fanno **commit implicito** → le migrazioni devono essere **idempotenti/difensive**
> (`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`), perché non c'è rollback transazionale dello schema.

### 2.5 Smoke test post-deploy (bloccante)
Set fisso di endpoint, atteso `200`/`302`, **mai `500`**:
```sh
for u in / /feed /accedi /chi-siamo /<handle-profilo-pubblico>; do
  code=$(curl -sS -o /dev/null -w '%{http_code}' "https://spoome.it/beta$u")
  echo "$code  $u"
done
```
Un login reale sul giro autenticato (admin demo) per confermare che i **badge nav** girano (nessun 500 sistemico).

### 2.6 Rollback
- **Codice:** il deploy è per-file e non distruttivo; per tornare indietro fare `git checkout <sha-precedente>` in locale
  e ri-lanciare `python3 jobs/deploy.py` (ricarica i file tornati diversi dal manifest). Tenere sempre nota dell'ultima
  SHA buona in produzione. Per forzare l'allineamento completo: `deploy.py --all`.
- **Docroot:** ripristinare la Document Root al valore precedente nel pannello SiteGround (istantaneo).
- **DB/migrazioni:** **niente rollback automatico dello schema** (§3). Recovery = restore dal backup pre-rilascio
  (§4) oppure una **nuova migrazione additiva** che corregge in avanti (preferito se ci sono dati nuovi da preservare).
- **Cache asset:** se un asset è stato pubblicato con URL sbagliato/senza `?v=`, ripubblicare con nuovo `filemtime`
  (nuovo URL) — non si può invalidare un `immutable` già servito.

---

## 3. Runner migrazioni CLI — spec (`php jobs/migrate.php`)

**Obiettivo:** sostituire il runner HTTP `POST /__migrate` (oggi dietro `!isProduction() && MIGRATION_HTTP_ENABLED
&& MIGRATION_TOKEN`, `config/routes.php`) con un comando CLI, eliminando del tutto la superficie web. Riusa
`Spoome\Core\Migrator` **così com'è** (nessuna logica duplicata).

### 3.1 File `jobs/migrate.php` (nuovo)
- **Solo CLI**: `if (PHP_SAPI !== 'cli') { exit(1); }` in testa — non deve mai essere raggiungibile via web.
  In più, il `.htaccess` già nega `jobs/` (difesa in profondità).
- Bootstrap identico all'app: `require src/autoload.php; require config/env.php; require src/Core/helpers.php;`
  poi `Db::connection()` e `new Migrator(Db::connection(), dirname(__DIR__).'/database/migrations')`.
- Exit code: `0` = successo, `1` = errore/almeno una migrazione fallita (per uso in cron/CI).

### 3.2 Comandi
| Comando | Comportamento |
|---|---|
| `php jobs/migrate.php status` | Elenca **applicate** (da tabella `migrations`) e **pendenti** (`Migrator::pending()`), in ordine. Read-only. Exit 0. |
| `php jobs/migrate.php up` | Applica in ordine tutte le pendenti (`Migrator::migrate()`). Stampa `OK: <name>` / `FAIL: <name> -> <msg>`. **Si ferma alla prima fallita** (già così nel Migrator). Exit 1 se qualcosa fallisce. |
| `php jobs/migrate.php up --pretend` | (opzionale) Stampa cosa applicherebbe senza eseguire (`pending()` + dry-run). |
| `php jobs/migrate.php --help` | Uso. Comando sconosciuto ⇒ help + exit 1. |

> **`down`/rollback: fuori scope per ora.** Il `Migrator` non lo espone e i DDL MySQL fanno commit implicito;
> il recovery resta *restore da backup* o *migrazione additiva correttiva* (§2.6). Se in futuro servirà, aggiungere
> `down` che chiama `->down(PDO)` dell'ultima migrazione applicata e ne rimuove la riga da `migrations` — ma **non**
> abilitarlo in produzione senza backup fresco.

### 3.3 Naming a timestamp (nuova convenzione)
- Da ora i file migrazione: **`YYYYMMDDHHMMSS_snake_case.php`** (es. `20260704153000_add_last_seen_to_profiles.php`),
  UTC, sempre uno stesso identico contratto: `return new class { public function up(PDO $pdo): void {...}
  public function down(PDO $pdo): void {...} };`.
- **Compatibilità con le esistenti** `0001_*`…`0014_*`: `Migrator::pending()` ordina con `sort($files)`
  (lessicografico). `0001` < `0002` < … < `20260704…` restano ordinati correttamente (i prefissi numerici a 4 cifre
  precedono sempre i timestamp a 14 cifre). **Non rinominare** le migrazioni già applicate: il nome è la chiave in
  `migrations`, rinominarle le farebbe ri-eseguire. La transizione è additiva: le nuove nascono già a timestamp.
- Il timestamp evita le collisioni di numero quando più persone/agenti creano migrazioni in parallelo (problema tipico
  della numerazione sequenziale).

### 3.4 Ritiro del runner HTTP
Dopo che `jobs/migrate.php` è in uso: rimuovere il blocco `POST /__migrate` da `config/routes.php` e le chiavi
`MIGRATION_HTTP_ENABLED`/`MIGRATION_TOKEN` da `.env`/`.env.example`. Aggiornare CLAUDE.md §Workflow (oggi dice di
registrare le migrazioni a mano con `INSERT INTO migrations …`): d'ora in poi **`php jobs/migrate.php up`** è l'unica
via, e registra da sé la riga in `migrations`.

---

## 4. Backup DB e pruning `login_attempts`

### 4.1 Backup DB
- **Prima di ogni migrazione e di ogni rilascio con schema change** (già §2.1): dump completo.
  Con accesso diretto (`q.py`/PyMySQL) o `mysqldump` dal pannello:
  ```sh
  mysqldump --single-transaction --quick --default-character-set=utf8mb4 \
    -h <DB_HOST> -u <DB_USER> -p <DB_NAME> | gzip > backup_$(date +%Y%m%d_%H%M%S).sql.gz
  ```
  `--single-transaction` = snapshot consistente su InnoDB senza lock (tutte le tabelle sono InnoDB).
- **Schedulato:** cron giornaliero SiteGround (oltre agli snapshot nativi dell'host). **Retention** consigliata:
  giornalieri 7g + settimanali 4w. Conservare i dump **fuori dalla docroot** (mai in `public/`); `.htaccess` nega
  già `*.sql`/`*.bak` come rete di sicurezza.
- **Verifica del backup** (un backup non testato non è un backup): periodicamente ripristinare l'ultimo dump su un DB
  scratch e contare le righe delle tabelle chiave (`users`, `profiles`, `posts`).
- **Restore** (recovery §2.6): `gunzip < backup_*.sql.gz | mysql -h <host> -u <user> -p <db>`. Mai eseguire un restore
  in produzione senza aver prima dumpato lo stato corrente.

### 4.2 Pruning `login_attempts` (cresce illimitata)
La tabella `login_attempts` (throttling login/registrazione/reset) accumula una riga per ogni tentativo e **non ha
scadenza** → crescita illimitata. Serve solo per la finestra di rate-limiting recente (ordine di minuti/ore).

- **Job schedulato** (`jobs/prune.php`, o riga nel cron di manutenzione), **giornaliero**:
  ```sql
  DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 30 DAY;
  ```
  30 giorni sono ampiamente sufficienti per throttling + eventuale analisi abusi a breve termine; abbassare a 7g se
  la tabella cresce molto. Cancellare a **batch** su tabelle grandi per non lockare:
  `DELETE ... LIMIT 10000;` in loop finché `ROW_COUNT()=0`.
- L'indice `(attempted_at)` non esiste esplicitamente (ci sono `idx_login_identifier(identifier,attempted_at)` e
  `idx_login_ip(ip,attempted_at)`): il `DELETE … WHERE attempted_at <` può fare scan. Se la tabella è già grande,
  eseguire il primo pruning in finestra di basso traffico, poi valutare un indice dedicato su `attempted_at`.
- **Stessa cura per `app_logs`**: cresce anch'essa. Pruning giornaliero coerente con la retention log
  (es. `DELETE FROM app_logs WHERE created_at < NOW() - INTERVAL 90 DAY;`) — 90g bilancia storico dei fingerprint
  ricorrenti e dimensione tabella. I file `app.log.*` ruotati vanno anch'essi prunati sul filesystem (>30g / >N file).

### 4.3 Un unico job di manutenzione
Consolidare pruning `login_attempts` + `app_logs` + rotazione file log + (opzionale) health-check/alert (§1.4) in
`jobs/maintenance.php` schedulato via cron SiteGround, con output loggato. Tutti i job (`migrate`, `maintenance`,
`prune`) sono **CLI-only** (`PHP_SAPI==='cli'`) e vivono sotto `jobs/` (già negato dal `.htaccess`).
