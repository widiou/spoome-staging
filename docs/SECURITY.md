# Spoome v2 — Standard di sicurezza (livello MASSIMO)

> Vincolo di prim'ordine, non accessorio. Ogni PR/fase deve rispettare questa checklist.
> Regola d'oro: **mai fidarsi dell'input, sempre escapare l'output, mai segreti nel repo.**

## Modello di minaccia (cosa proteggiamo)
- **Account e identità** (è un social pro: furto account = danno reputazionale grave).
- **Dati personali** (email, documenti di verifica, contatti privati) → GDPR.
- **Integrità dei contenuti** (post, messaggi, profili) e **anti-abuso** (spam, impersonificazione).
- Superfici: web, **API pubblica** (anche per app native), upload file, form pubblici, area admin.

## Autenticazione & sessioni
- Password con `password_hash()` (bcrypt/argon2), **mai** reversibili; verifica con `password_verify`.
- Policy password robusta (lunghezza minima, controllo password compromesse opzionale).
- **Rate limiting / throttling** su login, registrazione, reset (tabella `login_attempts`) + lockout progressivo.
- Sessione: cookie `HttpOnly` + `Secure` + `SameSite`, **rigenerazione ID al login** (anti session-fixation), timeout.
- **Token API (Bearer)**: salvati **hashed** (SHA-256) a riposo, mai in chiaro nel DB; scadenza + **revoca**; refresh rotation.
- Verifica email e reset: token **hashed**, monouso, con scadenza breve. Nessuna user-enumeration (messaggi generici).

## Autorizzazione
- Controllo dei permessi **su ogni azione** (no IDOR): verificare sempre ownership/ruolo lato server.
- Ruoli di piattaforma (`member/moderator/admin`) + entitlement (piano). Deny-by-default.
- Area admin dietro ruolo `admin`, con audit delle azioni sensibili.

## Input & output
- **Query DB solo con prepared statements** (parametri bound). Nomi di colonna/tabella solo da **whitelist**.
- **Output escaping automatico** nelle view (`e()`), sempre, per ogni dato dinamico. Zero `echo` grezzo.
- Validazione server-side di ogni input (tipi, lunghezze, formati) — la validazione client è solo UX.
- CSRF token su tutte le mutazioni web; API stateless protette dal token Bearer + CORS allowlist (niente `*`).

## Upload & media
- Whitelist di MIME **ed** estensione; limite dimensione; **re-encoding** delle immagini (rimuove payload/EXIF).
- Nomi file generati server-side (mai dall'utente); niente esecuzione nella cartella upload.
- **Documenti di verifica**: archiviati **fuori dalla docroot** (`storage/`), serviti solo da controller autorizzato.

## Header & trasporto
- HTTPS ovunque; HSTS. `X-Content-Type-Options`, `X-Frame-Options`/`frame-ancestors`, `Referrer-Policy`.
- **CSP** restrittiva (script/style self, niente inline non-nonce) — introdotta e irrigidita progressivamente.
- **Docroot = `public/`**: `src/`, `config/`, `storage/`, `vendor/`, `jobs/`, `database/`, `views/` non raggiungibili via web
  (fallback `.htaccess` deny finché la docroot non viene spostata).

## Segreti & configurazione
- Segreti **solo in `.env`** (git-ignored). Zero credenziali/API key nel codice o nella history.
- `display_errors` **off** in produzione; errori loggati, mai mostrati all'utente.
- Runner migrazioni web **off di default** (env flag) e comunque disabilitato in produzione.

## Log, privacy, GDPR
- Log senza dati sensibili in chiaro (no password/token/PII inutile). Rotazione log.
- Minimizzazione dati; cancellazione account/dati su richiesta; base giuridica per i dati di verifica.

## Dipendenze & deploy
- `vendor/` versionato a mano → **tenere aggiornate** le librerie (Guzzle ecc.), controllare CVE note.
- Nessun file di test/debug in docroot. Niente listing directory (`Options -Indexes`).

## Disciplina di revisione
- **Security review sul diff** prima di ogni merge di feature sensibile (auth, upload, pagamenti, admin, API).
- Threat-modeling rapido per ogni nuovo dominio (§ quali dati, quali abusi, quali controlli).

## Stato attuale (F0)
✅ token/segreti fuori dal repo · ✅ cookie sessione sicuri · ✅ error handler senza leak in prod ·
✅ `.htaccess` deny cartelle sensibili + security header · ✅ token DB salvati hashed · ✅ `login_attempts` per throttling ·
✅ runner migrazioni off di default. ⏭️ CSP, rigenerazione sessione al login, rate limiter applicato (F1 auth).
