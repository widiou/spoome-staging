#!/usr/bin/env python3
"""Monta il tracker del lavoro agenti su GitHub (Issues+Milestone+Label) per widiou/spoome-staging.
Token letto a runtime da `git credential` (osxkeychain), mai hardcoded/stampato. Idempotente."""
import json, subprocess, urllib.request, urllib.error, sys

REPO = "widiou/spoome-staging"
API = f"https://api.github.com/repos/{REPO}"

def get_token():
    out = subprocess.run(["git", "credential", "fill"],
                         input="protocol=https\nhost=github.com\n\n",
                         capture_output=True, text=True).stdout
    tok = ""
    for line in out.splitlines():
        if line.startswith("password="):
            tok = line[len("password="):]
    if not tok:
        sys.exit("Nessun token nel keychain")
    return tok

TOKEN = get_token()

def api(method, path, body=None):
    url = path if path.startswith("http") else API + path
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Authorization", f"Bearer {TOKEN}")
    req.add_header("Accept", "application/vnd.github+json")
    if data:
        req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, json.loads(r.read() or "null")
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or "null")

# 0) assicura issues abilitate
st, repo = api("GET", "")
if not repo.get("has_issues"):
    api("PATCH", "", {"has_issues": True})
    print("Issues abilitate.")

# 1) LABELS ------------------------------------------------------------
agents = {
    "Regia":   ("d8f21d", "Orchestratore — dispaccio, sintesi, garante dell'ordine"),
    "Matteo":  ("5b8def", "Architetto backend — Service/Repository/ServiceResult, API"),
    "Dario":   ("8e6bd8", "DB & performance — schema, indici, scala"),
    "Sara":    ("e5484d", "Sicurezza — authz, CSRF, hardening (livello MASSIMO)"),
    "Filippo": ("f5a623", "Frontend — view, CSS, perf, a11y"),
    "Chiara":  ("e84393", "QA & test — non-regressione, CI, smoke"),
    "Giorgio": ("2d9cdb", "Release & ops — deploy, migrazioni, cron, monitoring"),
    "Elena":   ("f2994a", "Prodotto & mercato — roadmap, moat, monetizzazione"),
    "Paolo":   ("9aa0ad", "Code review — correttezza, regressioni, pattern"),
    "Bianca":  ("c774e8", "UX — flussi, IA, microcopy, accessibilità"),
}
types = {
    "tipo:feature":  ("5b8def", "Nuova funzionalità"),
    "tipo:bug":      ("e5484d", "Difetto da correggere"),
    "tipo:chore":    ("6e7688", "Manutenzione/infra/config"),
    "tipo:refactor": ("b07de8", "Refactoring senza cambio comportamento"),
    "tipo:audit":    ("d8f21d", "Analisi/verifica read-only"),
}
states = {
    "stato:in-corso":  ("f5c518", "In lavorazione"),
    "stato:in-review": ("5b8def", "In revisione (Paolo/Sara)"),
    "stato:bloccato":  ("e5484d", "Bloccato — serve una decisione/dipendenza"),
    "stato:aiuto":     ("d8f21d", "Serve aiuto da un altro agente/fondatore"),
}
def ensure_label(name, color, desc):
    st, _ = api("POST", "/labels", {"name": name, "color": color, "description": desc[:99]})
    if st == 201: print(f"  + label {name}")
    elif st == 422:  # esiste già → aggiorna colore/desc
        api("PATCH", f"/labels/{urllib.parse.quote(name)}", {"color": color, "description": desc[:99]})
    else: print(f"  ! label {name} → HTTP {st}")

import urllib.parse
print("Label agenti:")
for n,(c,d) in agents.items(): ensure_label(f"agente:{n}", c, d)
print("Label tipo/stato:")
for n,(c,d) in {**types, **states}.items(): ensure_label(n, c, d)

# 2) MILESTONES (i 6 treni di rilascio, Orizzonte 0) -------------------
trains = [
    ("R1 · Affidabilità",        "CI + test critici + gate PHPStan/cs. Rende vivi i test, sblocca ogni gate successivo."),
    ("R2 · Lifecycle sicurezza", "Reset invalida sessioni, timeout, migrate CLI e rimozione endpoint HTTP /__migrate."),
    ("R3 · Operabilità",         "Cron SiteGround armati, alert su app_logs, hook deploy→smoke automatico."),
    ("R4 · Performance in-hosting","Seek pagination, UNION connections, retention, Auth→ServiceResult."),
    ("R5 · Front & UX quick-win","Subset FA, preload, og:image, skip-link, onboarding, dedup registrazione."),
    ("R6 · Primo moat",          "Verification 'verifica-da-club' (riuso affiliazioni) + decisione IA Opportunities."),
]
st, existing_ms = api("GET", "/milestones?state=all&per_page=100")
ms_num = {m["title"]: m["number"] for m in (existing_ms or [])}
print("Milestone (treni di rilascio):")
for title, desc in trains:
    if title in ms_num:
        print(f"  = {title} (già presente)")
        continue
    st, m = api("POST", "/milestones", {"title": title, "description": desc})
    if st == 201:
        ms_num[title] = m["number"]; print(f"  + {title}")
    else:
        print(f"  ! {title} → HTTP {st} {m}")

# 3) SEED ISSUES (backlog Orizzonte 0) --------------------------------
# (agente, tipo, milestone_title, titolo, corpo)
issues = [
 ("Chiara","tipo:chore","R1 · Affidabilità","CI GitHub Actions con servizio MySQL",
  "**Obiettivo:** pipeline su ogni push/PR: `composer install` + MySQL 8 come service container + `SPOOME_TEST_DSN` + `composer test` + `composer stan` + `composer cs`.\n\n**Perché:** i 4 test esistenti oggi non girano mai (nessuna CI = rischio n.1). Sblocca tutti i gate successivi.\n\n**Done:** workflow verde su una PR di prova; badge di stato."),
 ("Chiara","tipo:feature","R1 · Affidabilità","Test Auth + Claim su schema usa-e-getta",
  "**Obiettivo:** coprire `AuthService` (login/register/resetPassword: throttle IP vs email, dummy-hash timing, consumo monouso reset, gate beta/prod) e `ClaimService` (approve, dedup pending, guard 'hai già un profilo').\n\n**Pattern:** come `tests/Integration/RateLimiterTest.php` (MySQL usa-e-getta, skip-gated su DSN).\n\n**Done:** test verdi in CI."),
 ("Chiara","tipo:bug","R1 · Affidabilità","Verificare + chiudere TOCTOU in ClaimService::approve",
  "**Rilievo audit (da confermare a codice):** i ricontrolli anti-corsa leggono `findDetail`/`userHasProfile` **prima** della transazione, senza `SELECT … FOR UPDATE` → finestra TOCTOU sotto due approve concorrenti.\n\n**Done:** test che isola la corsa; fix con lock esplicito nella tx; verificato."),
 ("Sara","tipo:bug","R2 · Lifecycle sicurezza","Il reset password deve invalidare le sessioni web",
  "**Rilievo audit [P1]:** `AuthService::resetPassword` chiama solo `tokens->revokeAllForUser()` (token API), mai `Session::destroy`/rotazione → dopo un reset la sessione web dell'attaccante resta valida.\n\n**Fix proposto:** `session_epoch`/`password_changed_at` su users, confronto in `CurrentUser::resolve`.\n\n**Done:** cookie vecchio → redirect login dopo reset."),
 ("Sara","tipo:feature","R2 · Lifecycle sicurezza","Timeout sessione idle + assoluto",
  "**Rilievo audit [P2]:** nessun timeout server-side, solo il cookie `SESSION_LIFETIME` (client-side). Aggiungere `last_seen`/`login_at` in sessione, scadenza idle 30-60m e assoluta 12-24h.\n\n**Done:** sessione inattiva scaduta lato server; verificato."),
 ("Giorgio","tipo:chore","R2 · Lifecycle sicurezza","Runner migrazioni CLI + rimozione endpoint HTTP /__migrate",
  "**Obiettivo:** `php jobs/migrate.php up|status` che invoca il `Migrator` esistente; rimuovere l'endpoint web `/__migrate` (superficie DDL inutile, `config/routes.php:372-382`).\n\n**Done:** migrazioni applicabili da CLI; endpoint HTTP rimosso; smoke verde."),
 ("Giorgio","tipo:chore","R3 · Operabilità","Armare i cron SiteGround (maintenance + news)",
  "**Obiettivo:** schedulare `jobs/maintenance.php` (`17 3 * * *`) e `jobs/news_fetch.php` (~ogni 15min) dal pannello SiteGround.\n\n**Urgenza:** `login_attempts` già a ~8.5k righe senza purge.\n\n**Done:** prima esecuzione loggata verificata via `app_logs`/tabelle purgate."),
 ("Giorgio","tipo:feature","R3 · Operabilità","Alert soglia su app_logs + hook deploy→smoke",
  "**Obiettivo:** (a) estendere `maintenance.php` con alert quando un `fingerprint` supera N errori/24h; (b) far sì che `beta-deploy` non sia 'fatto' senza `beta-smoke-check` verde in coda.\n\n**Done:** alert emesso su spike simulato; deploy senza smoke = non-done."),
 ("Dario","tipo:refactor","R4 · Performance in-hosting","Seek pagination (feed / listPublic / messaggi)",
  "**Rilievo audit [P1]:** OFFSET ovunque → costo O(offset) sulle pagine profonde. Passare a keyset su `(score,id)`/`(created_at,id)`.\n\n**File:** `FeedRepository.php`, `ProfileRepository.php:484`, `MessageRepository.php:45`.\n\n**Done:** EXPLAIN senza deep-scan; parità di risultati verificata."),
 ("Dario","tipo:refactor","R4 · Performance in-hosting","UNION rewrite connections + indici + retention",
  "**Obiettivo:** riscrivere `requester_id=:x OR addressee_id=:x` (`ProfileRepository.php:559`) come UNION indicizzato + indici `(addressee_id,status)`/`(requester_id,status)`; job di retention per `activities`/`app_logs`/`link_previews`; fix `COUNT(*)` live admin (`PostRepository.php:172`).\n\n**Done:** EXPLAIN pulito; storage potato."),
 ("Matteo","tipo:refactor","R4 · Performance in-hosting","Auth → ServiceResult (chiude Fase B3)",
  "**Obiettivo:** `AuthService` (register/login/resetPassword) ritorna `Core\\ServiceResult` invece di array ad-hoc (`AuthService.php:92,184,265`), eliminando l'ultima duplicazione web/api sul percorso critico.\n\n**Done:** comportamento invariato; test di regressione verdi."),
 ("Filippo","tipo:chore","R5 · Front & UX quick-win","Subset Font Awesome (−240KB) + preload woff2",
  "**Rilievo audit:** `fa-brands-400.woff2` (117KB) mai usato, `fa-regular`/`fa-v4compat` morti; solo `fa-solid` (~67 glifi) serve. Subset (o SVG sprite) + `<link rel=preload>` per Barlow e FA solid.\n\n**Done:** −240KB verificati; LCP migliorato; icone invariate."),
 ("Filippo","tipo:feature","R5 · Front & UX quick-win","og:image sui profili + skip-link + sr-only badge",
  "**Obiettivo:** passare `ogImage` (avatar/cover assoluto) a `View::render` sui profili; aggiungere skip-link e `sr-only` sul badge `.bn-dot` della bottom-nav.\n\n**Done:** anteprima social con immagine; a11y tastiera/SR verificata."),
 ("Filippo","tipo:refactor","R5 · Front & UX quick-win","Partial avatar unico + riuso connection-actions",
  "**Rilievo audit:** avatar duplicato 7+ volte con classi diverse; `connection-actions.php` usato in 1 sola view. Estrarre partial `avatar` (path/iniziali/size) e riusare le azioni follow/connect ovunque.\n\n**Done:** un solo componente; zero regressioni visive (mobile-overflow-check)."),
 ("Bianca","tipo:feature","R5 · Front & UX quick-win","Onboarding 3-step + dedup 'profilo simile' in registrazione",
  "**Rilievo audit:** onboarding a zero; nessun check anti-duplicato in registrazione. 3 step (chi sei/sport → cerca-il-tuo-profilo → chi seguire) + ricerca live 'profilo simile trovato' con CTA a rivendica.\n\n**Done:** flusso guidato live; duplicati intercettati prima della creazione."),
 ("Bianca","tipo:feature","R5 · Front & UX quick-win","Microcopy next-step su rifiuto claim",
  "**Rilievo audit:** `claim.mine` mostra la nota di rifiuto admin senza guida. Aggiungere next-step (correggi e reinvia / contatta).\n\n**Done:** rifiuto con azione chiara; copy in `lang/it.php`."),
 ("Elena","tipo:feature","R6 · Primo moat","Verification 'verifica-da-club' (riuso affiliazioni)",
  "**Obiettivo (1° mattone del moat):** riusare il meccanismo di affiliazione bilaterale confermata per un badge 'verificato dalla società' → trasforma il claim da auto-dichiarato a certificato. Dominio `Verification` a sé, parità web↔API day-1 (skill `scaffold-domain`).\n\n**Done:** un atleta demo verificato da una società; badge visibile; API espone lo stato."),
 ("Bianca","tipo:chore","R6 · Primo moat","Decisione IA: la casa di Opportunities",
  "**Da decidere PRIMA di scaffoldare Opportunities:** voce di navigazione dedicata vs sotto Profilo. Proposta UX + wireframe leggero, decisione fondatore.\n\n**Done:** collocazione IA decisa e documentata; sblocca il dominio Opportunities (Orizzonte 1)."),
]

st, open_issues = api("GET", "/issues?state=all&per_page=100")
existing_titles = {i["title"] for i in (open_issues or []) if "pull_request" not in i}
print("Issue (backlog Orizzonte 0):")
created = 0
for agente, tipo, ms_title, title, body in issues:
    if title in existing_titles:
        print(f"  = (esiste) {title}"); continue
    labels = [f"agente:{agente}", tipo]
    payload = {"title": title, "body": body + "\n\n---\n_Aperto dalla **Regia** · tracker agenti Spoome_",
               "labels": labels}
    if ms_title in ms_num:
        payload["milestone"] = ms_num[ms_title]
    st, iss = api("POST", "/issues", payload)
    if st == 201:
        created += 1; print(f"  + #{iss['number']} [{agente}] {title}")
    else:
        print(f"  ! {title} → HTTP {st} {iss}")

print(f"\nFatto. Issue create: {created}. Board: https://github.com/{REPO}/issues")
print(f"Milestone: https://github.com/{REPO}/milestones")
