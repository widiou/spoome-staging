#!/usr/bin/env python3
"""dbq.py — runner SQL diretto sul DB Spoome (equivalente stabile di q.py).

Serve a Regia/agenti per applicare migrazioni ed eseguire query ad-hoc SUL DB REALE
(SiteGround), leggendo le credenziali da `.env` A RUNTIME. Le credenziali NON vengono
mai stampate né esposte nel contesto: lo script le usa solo per aprire la connessione,
esattamente come fanno `Db.php` (app) e `jobs/deploy.py` (FTP via .deploy.env).

Vive in `.team/` → NON deployato (IGNORE_DIRS in jobs/deploy.py), come team.py.

Uso:
  python3 .team/dbq.py "SELECT VERSION()"
  python3 .team/dbq.py --file database/migrations/xxx.sql
  python3 .team/dbq.py --check            # preflight sola-lettura (versione + presenza tabelle chiave)

Autocommit ON. Con --file o SQL multi-statement, esegue tutti gli statement in ordine
e si ferma al primo errore (stampando quale). NON stampa mai host/user/password.
"""
import os
import sys

try:
    import pymysql
    from pymysql.constants import CLIENT
except ImportError:
    sys.exit("pymysql non disponibile in questo ambiente.")

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))  # repo root (.team/ -> ..)


def load_env():
    env = {}
    path = os.path.join(ROOT, ".env")
    with open(path) as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip().strip('"').strip("'")
    return env


def connect():
    env = load_env()
    return pymysql.connect(
        host=env.get("DB_HOST", "localhost"),
        user=env.get("DB_USER", ""),
        password=env.get("DB_PASS", ""),
        database=env.get("DB_NAME", ""),
        port=int(env.get("DB_PORT") or 3306),
        unix_socket=(env.get("DB_SOCKET") or None),
        charset=env.get("DB_CHARSET", "utf8mb4"),
        autocommit=True,
        connect_timeout=20,
        client_flag=CLIENT.MULTI_STATEMENTS,
    )


def run_sql(sql):
    conn = connect()
    try:
        with conn.cursor() as cur:
            affected = cur.execute(sql)
            while True:
                rows = cur.fetchall() if cur.description else None
                if rows is not None:
                    for r in rows:
                        print("\t".join("" if c is None else str(c) for c in r))
                else:
                    print(f"-- OK ({cur.rowcount} righe)")
                if not cur.nextset():
                    break
    finally:
        conn.close()


def check():
    env = load_env()
    conn = connect()
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT VERSION()")
            print("MYSQL_VERSION:", cur.fetchone()[0])
            print("DB_NAME:", env.get("DB_NAME", ""))  # nome db (non segreto), conferma target
            cur.execute("SELECT DATABASE()")
            print("CONNECTED_DB:", cur.fetchone()[0])
    finally:
        conn.close()
    print("CHECK_OK")


def main():
    args = sys.argv[1:]
    if not args:
        sys.exit(__doc__)
    if args[0] == "--check":
        check()
    elif args[0] == "--file":
        with open(args[1]) as f:
            run_sql(f.read())
    else:
        run_sql(args[0])


if __name__ == "__main__":
    main()
