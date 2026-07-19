#!/usr/bin/env python3
"""Deploy FTPS di Spoome v2 verso SiteGround (beta).

Legge le credenziali da .deploy.env (gitignored) e carica il progetto sulla docroot della beta.
Change-detection affidabile via manifest di hash SHA-1 (.deploy-state.json), NON via dimensione.

Uso:
    python3 jobs/deploy.py            # carica i file col contenuto cambiato dall'ultimo deploy
    python3 jobs/deploy.py --all      # forza il ri-caricamento di tutto
    python3 jobs/deploy.py --dry-run  # mostra cosa caricherebbe, senza caricare
"""
import os, sys, ssl, hashlib, json
from ftplib import FTP_TLS, FTP, error_perm

PROJECT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
STATE = os.path.join(PROJECT, ".deploy-state.json")

IGNORE_DIRS = {".git", ".idea", "node_modules", "__pycache__", "tmp", ".team", ".claude"}
IGNORE_FILES = {".env", ".deploy.env", ".deploy.log", ".deploy-state.json", ".DS_Store"}
IGNORE_SUFFIX = (".pyc",)

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
    print(f"\nFatto. Caricati: {uploaded}. Manifest aggiornato ({len(current)} file).")

if __name__ == "__main__":
    main()
