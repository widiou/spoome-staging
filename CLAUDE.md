# Spoome — Manuale aziendale (contesto per tutti gli agenti)

**Spoome** è "il LinkedIn dello sport": un professional network per atleti, società, associazioni, federazioni e fan. Ambizione: scala tipo LinkedIn. Vedi `docs/ARCHITECTURE.md` e `docs/SECURITY.md`.

## Stack & convenzioni
- **PHP vanilla MVC**, MySQL, deploy su **SiteGround** (beta live: `https://spoome.it/beta` — la produzione `spoome.it` è separata e intoccabile).
- Autoload **PSR-4** (`Spoome\` → `src/`). Pattern: **Controller → Service (ritorna `Core\ServiceResult`) → Repository (PDO)**.
- **PDO con `EMULATE_PREPARES=false`**: i named placeholder **NON sono riutilizzabili** nella stessa query (usa `:me1/:me2/...`). È già stato causa di 500 ricorrenti — attenzione.
- **Dominio e URL in italiano; codice, tabelle e colonne in inglese.**
- API: envelope JSON `{data, meta}` / `{errors:[...]}`. Scritture API **solo-Bearer** (anti-CSRF via `CurrentUser::fromBearer`); scritture web con **CSRF** (`_csrf` o header `X-CSRF-Token`).

## Non negoziabili
1. **Sicurezza livello MASSIMO.** Ogni input parametrizzato, ogni output via `e()`, authz al livello dati (defense-in-depth).
2. **Nessuna regressione visibile.** Gli helper di nav (`dm_unread/notif_unread/is_admin`) girano su OGNI pagina autenticata: un bug lì manda in 500 tutto il sito.
3. **Deploy + test dal vivo ad ogni modifica atomica.** Mai lasciare la beta rotta.
4. **Design:** dark, bianco/nero con **giallo** come unico accento, **niente verde**, **niente emoji** (icone Font Awesome flat).

## Workflow operativo
- **Deploy:** `python3 jobs/deploy.py` (FTP, manifest SHA-1). Non c'è PHP locale → si verifica via deploy + `curl`.
- **DB diretto:** `cd $CLAUDE_JOB_DIR/tmp && ./dbvenv/bin/python q.py "<SQL>"` (autocommit; bcrypt nel venv).
- **Migrazioni:** file numerato in `database/migrations/`, applica l'SQL via q.py, registra con `INSERT INTO migrations (migration) VALUES ('00NN_nome')`.
- **Credenziali demo/test:** admin `marco.rossi@demo.spoome.local` / `SpoomeBeta25!` (richiede step-up su `/admin/verifica`); altri utenti demo con la stessa password.
- **Memoria di progetto:** in `.claude/projects/.../memory/` (indice `MEMORY.md`).

## Orchestrazione (orchestratore + pool di specialisti)
- **La sessione principale è l'ORCHESTRATORE.** Non implementa a mano: scompone il lavoro, **delega** allo specialista giusto, integra i risultati e garantisce l'ordine. L'utente parla solo con l'orchestratore.
- **Il pool** vive in `.claude/agents/` (9 specialisti, selezionabili come `subagent_type`). Ogni agente ha ruolo singolo, `model` dedicato e `tools` con privilegio minimo (i read-only — `code-reviewer`, `product-strategist` — non hanno Edit/Write).
- **Regole di dispatch:**
  - Lavori indipendenti → agenti **in parallelo** (una sola risposta con più chiamate).
  - Feature reali → **pipeline**: `backend-architect`/dominio *implementa* → `code-reviewer` (+ `security-engineer` se tocca authz/dati) *rivede il diff* → deploy → `qa-test-engineer` *smoke dal vivo*. Il QA ha già pescato P0 sfuggiti alla review: **non saltare il gate**.
  - Ricerca/mercato → `product-strategist`; UX/flussi → `ux-designer`; query/indici → `db-performance-engineer`; deploy/config/CSP → `devops-release-engineer`; view/CSS/JS → `frontend-engineer`.
- **Guardrail** (`.claude/settings.json`): auto-consentiti solo comandi sicuri/read-only (git read, `ls`, deploy `--dry-run`, curl smoke sulla beta); segreti (`.env`, `.deploy.env`) fuori dalla portata dei tool; ogni azione mutante (deploy pieno, SQL via `q.py`, migrazioni) resta a conferma esplicita.

## Tracciamento del lavoro — GitHub (`.team/`)
**Ogni operazione passa dal tracker.** Motore = GitHub Issues + Milestone + Label del repo `widiou/spoome-staging` (stile VS Team, nessun sistema reinventato). Dettagli e roster in `.team/README.md`; CLI in `.team/team.py` (token dal keychain, **non deployato**).
- **Agenti = nomi italiani** (label `agente:*`): Regia (orchestratore), Matteo (backend), Dario (DB), Sara (sicurezza), Filippo (frontend), Chiara (QA), Giorgio (ops), Elena (prodotto), Paolo (review), Bianca (UX).
- **Milestone = treni di rilascio** (R1…R6). **`stato:*`** = in-corso/in-review/bloccato/aiuto.
- **Flusso:** Regia apre l'issue (agente+tipo+treno) → l'agente `stato:in-corso`, lavora, **carica** il risultato come commento firmato, o chiede `stato:aiuto`/`bloccato` → `stato:in-review` (Paolo, +Sara se authz/dati) → deploy → smoke verde → `close` col commit collegato. Serializzato, un treno alla volta.
- Uso: `./.team/team.py list --mine <Nome>` · `comment <n> "..." --as <Nome>` · `state <n> <stato>` · `close <n> --comment "..."`.

## Competenze (Skill) — `.claude/skills/`
Procedure ripetibili codificate: invocale (via lo strumento Skill) quando il compito le attiva, invece di rifare a memoria.
- **Skill di progetto (custom):**
  - `beta-deploy` — deploy FTP sulla beta (dry-run→deploy→smoke→rollback). *devops, orchestratore*
  - `beta-smoke-check` — smoke dal vivo post-deploy (login demo, pagine 200, casi negativi, step-up). *qa, devops*
  - `db-query` — accesso diretto al DB via `q.py` (backup prima dei distruttivi). *devops, db*
  - `authoring-migration` — crea+applica+registra migrazione idempotente. *db, backend, devops*
  - `pdo-safe-query` — placeholder PDO non riutilizzabili + lint (previene i 500 HY093). *tutti gli implementativi, code-reviewer*
  - `scaffold-domain` — nuovo dominio Controller→Service(ServiceResult)→Repository→Presenter + parità web/API. *backend*
  - `secure-write-checklist` — checklist sicurezza MASSIMO per ogni mutazione. *security, code-reviewer, backend*
  - `mobile-overflow-check` — verifica overflow a 320/375/390/430 via CDP. *frontend, qa, ux*
  - `authz-matrix-check` — matrice ruoli IDOR + 404-cloak admin. *qa, security*
- **Skill bundled da adottare:** `security-review` (*security*), `code-review`/`simplify` (*code-reviewer*), `verify` (*qa*), `claude-in-chrome` (*frontend, qa*), `dataviz` (statistiche admin, *frontend*), `artifact-design` (deliverable visivi, *orchestratore, ux, product*), `deep-research` (*product*), `ui-ux-pro-max` (*ux, frontend*).
- **Backlog skill** (da costruire quando servono): `htaccess-sync`, `app-logs-triage`, `cron-maintenance-check`, `env-config-check`, `phpunit-bootstrap`.

## La squadra (`.claude/agents/`)
backend-architect · db-performance-engineer · security-engineer · frontend-engineer · qa-test-engineer · devops-release-engineer · product-strategist · code-reviewer · ux-designer
