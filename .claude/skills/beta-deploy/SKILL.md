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
3. **Smoke immediato** → invoca la skill **beta-smoke-check** (login demo + pagine chiave 200 + casi negativi + step-up admin). Non dichiarare "fatto" prima di questo gate.

## Rollback
Se lo smoke rompe: `git checkout -- <file>` (o revert all'ultimo commit buono) → `python3 jobs/deploy.py`. Il manifest SHA-1 rileva il diff e ricarica la versione buona. **Mai lasciare la beta rotta.**

## Guardrail
- `deploy.py` ignora già `.env`, `.deploy.env`, `.deploy-state.json`, `.git`, `tmp/`. Appunti/script SOLO in `$CLAUDE_JOB_DIR/tmp/`, MAI in `tmp/` del repo (è stato già causa di leak: file esposti su `/beta/tmp/...`).
- Se `.deploy-state.json` è corrotto/mancante → `python3 jobs/deploy.py --all` (ricarica tutto).
- **Cache asset immutable** (`max-age=1y`): sostituire un'immagine/asset richiede un **nuovo filename** + update del riferimento nel DB/codice — sovrascrivere lo stesso nome NON aggiorna i client.
- Migrazioni DB NON viaggiano col deploy dei file: applica l'SQL a parte (skill **authoring-migration**).
