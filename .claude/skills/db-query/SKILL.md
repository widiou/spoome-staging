---
name: db-query
description: Accesso diretto al database MySQL di Spoome per leggere/scrivere dati reali (debug, verifica migrazioni, seed, query ad-hoc). Il DB reale è remoto su SiteGround (c32237.sgvps.net), non il localhost del .env. Trigger: "query sul DB", "controlla nel database", "quanti record", "verifica lo stato dati", debug di dati reali.
---

# Accesso diretto al DB

## Comando
```
cd $CLAUDE_JOB_DIR/tmp && ./dbvenv/bin/python q.py "<SQL>"
```
- `q.py` esegue **una** statement in **autocommit** (nessuna transazione implicita, nessun rollback).
- Il venv `dbvenv/` (PyMySQL + bcrypt) vive in `$CLAUDE_JOB_DIR/tmp/`. Se manca, ricrealo lì — **mai** in `tmp/` del repo.
- Il DB è quello **remoto SiteGround** (`c32237.sgvps.net`), non il `localhost` del `.env`.

## Guardrail (livello MASSIMO)
- **Backup logico OBBLIGATORIO prima di ogni ALTER/DROP/UPDATE-DELETE distruttivo.** Usa `db.py backup <file>` verso una posizione durevole FUORI dal job tmp (es. `~/Desktop/spoome_backup_<data>.sql`). Autocommit = ogni statement è definitivo.
- Le scritture sul DB sono **azioni mutanti**: conferma esplicita, mai in automatico.
- Bcrypt per hash password è disponibile nel venv (per seed/utenti demo).
- Il gotcha placeholder PDO (`EMULATE_PREPARES=false`) riguarda il codice applicativo, non q.py — ma quando riproduci una query dell'app, ricordati che i named param NON sono riutilizzabili (skill **pdo-safe-query**).

## Usi tipici
- Verificare l'effetto di una migrazione: `SELECT COUNT(*)...`, `SHOW INDEX FROM ...`, `information_schema`.
- Controllare i contatori denormalizzati vs realtà.
- Triage errori: raggruppare `app_logs` per `fingerprint` (attenzione al timezone: DB UTC vs PHP Europe/Rome).
- Ripristinare lo scenario demo dopo test.
