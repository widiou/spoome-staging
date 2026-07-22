#!/usr/bin/env python3
"""Deploy FTPS di Spoome v2 verso SiteGround (beta).

Legge le credenziali da .deploy.env (gitignored) e carica il progetto sulla docroot della beta.
Change-detection affidabile via manifest di hash SHA-1 (.deploy-state.json), NON via dimensione.

Dopo l'upload gira uno SMOKE minimale, read-only e non-autenticato contro la beta (issue #8):
il deploy non stampa "Fatto" e NON esce con codice 0 se lo smoke fallisce — vedi smoke_test().
Questo è il gate automatico "in coda" al deploy; la skill beta-smoke-check (login demo + step-up
admin + casi negativi) resta il controllo completo da lanciare comunque dopo un deploy reale.

Uso:
    python3 jobs/deploy.py            # carica i file col contenuto cambiato + smoke automatico
    python3 jobs/deploy.py --all      # forza il ri-caricamento di tutto + smoke automatico
    python3 jobs/deploy.py --dry-run  # mostra cosa caricherebbe, senza caricare (nessuno smoke)
    python3 jobs/deploy.py --no-smoke # salta lo smoke automatico (SOLO casi eccezionali: il deploy
                                      # NON è verificato, va controllato a mano subito dopo)
"""
import os, sys, ssl, hashlib, json
import urllib.request, urllib.error
from ftplib import FTP_TLS, FTP, error_perm

PROJECT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
STATE = os.path.join(PROJECT, ".deploy-state.json")

IGNORE_DIRS = {".git", ".idea", "node_modules", "__pycache__", "tmp", ".team", ".claude"}
IGNORE_FILES = {".env", ".deploy.env", ".deploy.log", ".deploy-state.json", ".DS_Store"}
IGNORE_SUFFIX = (".pyc",)

# Base URL pubblica della beta, verificata dallo smoke post-upload. Sovrascrivibile con
# SMOKE_BASE_URL in .deploy.env (mai hardcodare credenziali qui: solo un URL pubblico).
DEFAULT_SMOKE_BASE_URL = "https://spoome.it/beta"

# Rotte controllate dallo smoke minimale: SOLO read-only / non-autenticato (nessuna credenziale
# demo qui dentro — login+step-up restano nella skill beta-smoke-check completa).
#   - "/"         homepage pubblica -> 200
#   - "/rete"     rotta autenticata senza sessione -> deve redirigere (302), MAI 500
#   - "/__migrate" vecchio endpoint HTTP delle migrazioni, rimosso -> deve restare 404
SMOKE_CHECKS = [
    ("/", {200}, "homepage"),
    ("/rete", {302}, "pagina autenticata senza sessione -> redirect /accedi (mai 500)"),
    ("/__migrate", {404}, "endpoint HTTP migrazioni rimosso (non deve ricomparire)"),
]


class _NoRedirectHandler(urllib.request.HTTPRedirectHandler):
    """Impedisce a urllib di seguire i redirect. redirect_request()->None fa sì che urllib sollevi
    HTTPError sul 3xx invece di seguirlo: _http_status lo cattura e ne estrae .code (il 3xx grezzo)."""
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def _http_status(url, timeout=15):
    """GET read-only di `url` senza seguire redirect. Ritorna lo status code (anche 3xx/4xx/5xx),
    o None se irraggiungibile. Non solleva MAI: oltre a HTTPError (3xx/4xx/5xx) e URLError (DNS/
    connessione), cattura qualsiasi altro errore (es. http.client.BadStatusLine su risposta
    malformata) come None, così lo smoke resta un FAIL controllato e non un crash post-upload."""
    opener = urllib.request.build_opener(_NoRedirectHandler)
    req = urllib.request.Request(url, headers={"User-Agent": "Spoome-Deploy-Smoke/1.0"})
    try:
        with opener.open(req, timeout=timeout) as resp:
            return resp.getcode()
    except urllib.error.HTTPError as e:
        return e.code
    except Exception:
        return None


def smoke_test(base_url):
    """Smoke post-deploy minimale: verifica le rotte in SMOKE_CHECKS contro `base_url`.
    Ritorna (ok: bool, dettagli: list[str]) — MAI solleva eccezioni (un host irraggiungibile
    è un FAIL dello smoke, non un crash dello script)."""
    ok = True
    details = []
    for path, expected, label in SMOKE_CHECKS:
        url = base_url.rstrip("/") + path
        code = _http_status(url)
        passed = code in expected
        ok = ok and passed
        shown = str(code) if code is not None else "IRRAGGIUNGIBILE"
        details.append(f"  [{'OK' if passed else 'FAIL'}] {label}: {path} -> {shown} (atteso {sorted(expected)})")
    return ok, details

def load_deploy_env():
    path = os.path.join(PROJECT, ".deploy.env")
    if not os.path.isfile(path):
        sys.exit("ERRORE: manca .deploy.env (credenziali FTP). Vedi header di jobs/deploy.py.")
    env = {}
    for line in open(path):
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1); env[k.strip()] = v.strip()
    return env

def sha1(path):
    h = hashlib.sha1()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()

def should_skip(rel):
    parts = rel.split(os.sep)
    return (any(p in IGNORE_DIRS for p in parts)
            or os.path.basename(rel) in IGNORE_FILES
            or rel.endswith(IGNORE_SUFFIX))

def local_files():
    for root, dirs, files in os.walk(PROJECT):
        dirs[:] = [d for d in dirs if d not in IGNORE_DIRS]
        for name in files:
            rel = os.path.relpath(os.path.join(root, name), PROJECT)
            if not should_skip(rel):
                yield rel.replace(os.sep, "/"), os.path.join(root, name)

def connect(env):
    use_tls = env.get("FTP_TLS", "true").lower() in ("1", "true", "yes")
    if use_tls:
        ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
        ftp = FTP_TLS(context=ctx)
    else:
        ftp = FTP()
    ftp.connect(env.get("FTP_HOST", "ftp.spoome.it"), int(env.get("FTP_PORT", "21")), timeout=40)
    ftp.login(env["FTP_USER"], env["FTP_PASS"])
    if use_tls:
        ftp.prot_p()
    ftp.set_pasv(True)
    base = env.get("FTP_REMOTE_DIR", "/").rstrip("/")
    if base:
        ensure_dir(ftp, base); ftp.cwd(base)
    return ftp

_made = set()
def ensure_dir(ftp, path):
    cur = ""
    for p in [x for x in path.split("/") if x]:
        cur = cur + "/" + p if cur else p
        if cur in _made:
            continue
        try: ftp.mkd(cur)
        except error_perm: pass
        _made.add(cur)

def main():
    args = set(sys.argv[1:])
    force = "--all" in args
    dry = "--dry-run" in args
    no_smoke = "--no-smoke" in args
    env = load_deploy_env()

    manifest = {}
    if os.path.isfile(STATE) and not force:
        try: manifest = json.load(open(STATE))
        except Exception: manifest = {}

    # Calcola i file cambiati (hash diverso dal manifest).
    changed = []
    current = {}
    for rel, full in sorted(local_files()):
        digest = sha1(full)
        current[rel] = digest
        if force or manifest.get(rel) != digest:
            changed.append((rel, full))

    print(f"{len(current)} file totali · da caricare: {len(changed)}"
          + (" [DRY-RUN]" if dry else ""))
    if dry:
        for rel, _ in changed: print("  UP", rel)
        return
    if not changed:
        print("Niente da caricare (tutto aggiornato).")
        return

    ftp = connect(env)
    uploaded = 0
    for rel, full in changed:
        d = os.path.dirname(rel)
        if d: ensure_dir(ftp, d)
        with open(full, "rb") as fh:
            ftp.storbinary(f"STOR {rel}", fh)
        uploaded += 1
        print("↑", rel)
    ftp.quit()

    json.dump(current, open(STATE, "w"), indent=0)
    print(f"\nUpload completato: {uploaded} file caricati (manifest aggiornato, {len(current)} totali).")

    # Il deploy NON è "fatto" senza uno smoke verde in coda (issue #8). Eccezione esplicita
    # e documentata: --no-smoke, per casi eccezionali (es. host irraggiungibile da qui ma
    # verificabile solo da un'altra rete) — in quel caso il deploy resta non verificato e
    # va controllato a mano SUBITO dopo (skill beta-smoke-check).
    if no_smoke:
        print("\n[SMOKE] SALTATO (--no-smoke). Deploy NON verificato automaticamente: "
              "esegui subito la skill beta-smoke-check a mano prima di considerarlo concluso.")
        return

    base_url = env.get("SMOKE_BASE_URL", DEFAULT_SMOKE_BASE_URL)
    print(f"\n[SMOKE] verifica read-only contro {base_url} ...")
    ok, details = smoke_test(base_url)
    print("\n".join(details))

    if not ok:
        print("\nDEPLOY NON DONE: smoke fallito. Non considerare il deploy concluso — "
              "valuta il rollback (skill beta-deploy: revert dei file + python3 jobs/deploy.py) "
              "e ri-esegui lo smoke prima di chiudere.")
        sys.exit(1)

    print(f"\n[SMOKE] verde. Fatto. Caricati: {uploaded}. Manifest aggiornato ({len(current)} file).")

if __name__ == "__main__":
    main()
