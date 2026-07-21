---
name: beta-deploy
description: Procedura di deploy di Spoome sulla beta (spoome.it/beta) via FTP. Usala per ogni modifica atomica destinata alla beta — carica solo i file cambiati (manifest SHA-1), poi smoke test dal vivo. Copre anche il rollback. Trigger: "deploy", "carica in beta", "pubblica la modifica", dopo aver implementato+rivisto una modifica.
---

# Deploy sulla beta

Non c'è PHP locale: **l'unica verifica reale è deploy + curl sulla beta**, mai "sembra corretto leggendo il codice".

## Passi (in ordine, sempre)
1. **Anteprima** (auto-consentita, nessuna scrittura):
   ```
   python3 jobs/deploy.py --dry-run
   ```
   Controlla che la lista `UP` contenga SOLO i file che intendevi toccare. Se compaiono file estranei (es. appunti in `tmp/`), fermati e indaga — non deployare "a fiducia".
2. **Deploy reale** (azione mutante → richiede conferma esplicita):
   ```
   python3 jobs/deploy.py
   ```
   Carica via FTPS i file con hash diverso dal manifest `.deploy-state.json`. Upload-only (non cancella file remoti).
3. **Smoke automatico in coda (integrato in `deploy.py`, dalla issue #8)**: subito dopo l'upload, `deploy.py` esegue da solo uno smoke minimale read-only/non-autenticato contro `https://spoome.it/beta` (homepage 200, `/rete` senza sessione → 302 mai 500, `/__migrate` → 404). **Se rosso, `deploy.py` esce con codice ≠ 0 e stampa "DEPLOY NON DONE: smoke fallito"** — il deploy non si considera "fatto" senza questo gate verde. Se lo script esce con quel messaggio: **stop, non procedere oltre come se fosse concluso** — passa a Rollback qui sotto.
   - Questo smoke integrato è deliberatamente minimale (nessuna credenziale demo dentro `deploy.py`, per non incorporare segreti nel file). **Non sostituisce** il gate completo: invoca comunque la skill **beta-smoke-check** (login demo + pagine chiave + casi negativi + step-up admin) subito dopo uno smoke integrato verde. "Fatto" = smoke integrato verde **+** beta-smoke-check verde.
   - `--no-smoke` salta lo smoke integrato: **solo per casi eccezionali** (es. l'host non è raggiungibile dalla rete da cui gira l'agente ma lo è da un'altra). Se lo usi, il deploy resta NON verificato: esegui `beta-smoke-check` a mano SUBITO dopo, prima di dichiarare qualunque cosa "fatta".

## Rollback
Se lo smoke (integrato o beta-smoke-check) rompe: `git checkout -- <file>` (o revert all'ultimo commit buono) → `python3 jobs/deploy.py`. Il manifest SHA-1 rileva il diff e ricarica la versione buona, e lo smoke integrato riparte automaticamente in coda. **Mai lasciare la beta rotta.**

## Guardrail
- `deploy.py` ignora già `.env`, `.deploy.env`, `.deploy-state.json`, `.git`, `tmp/`. Appunti/script SOLO in `$CLAUDE_JOB_DIR/tmp/`, MAI in `tmp/` del repo (è stato già causa di leak: file esposti su `/beta/tmp/...`).
- Se `.deploy-state.json` è corrotto/mancante → `python3 jobs/deploy.py --all` (ricarica tutto).
- **Cache asset immutable** (`max-age=1y`): sostituire un'immagine/asset richiede un **nuovo filename** + update del riferimento nel DB/codice — sovrascrivere lo stesso nome NON aggiorna i client.
- Migrazioni DB NON viaggiano col deploy dei file: applica l'SQL a parte (skill **authoring-migration**).
- La base URL dello smoke integrato è `https://spoome.it/beta` di default; sovrascrivibile con `SMOKE_BASE_URL` in `.deploy.env` (mai un segreto, solo un URL pubblico).
