# Squadra Spoome — tracker del lavoro agenti

Tracciamento in stile Visual Studio Team, ma sul motore che già abbiamo: **GitHub Issues + Milestone + Label** del repo `widiou/spoome-staging`. Nessun sistema reinventato, tracciabilità nativa lavoro↔commit↔codice.

> **Regola operativa:** ogni unità di lavoro passa da qui. La Regia apre un work item, lo assegna a un agente, l'agente carica risultati / chiede aiuto come commento, muove lo stato, e chiude collegando il commit.

## Il roster (ruolo → nome → label)
| Nome | Ruolo | Label |
|---|---|---|
| **Regia** | Orchestratore (dispaccio, sintesi, ordine) | `agente:Regia` |
| **Matteo** | Architetto backend | `agente:Matteo` |
| **Dario** | DB & performance | `agente:Dario` |
| **Sara** | Sicurezza (livello MASSIMO) | `agente:Sara` |
| **Filippo** | Frontend | `agente:Filippo` |
| **Chiara** | QA & test | `agente:Chiara` |
| **Giorgio** | Release & ops | `agente:Giorgio` |
| **Elena** | Prodotto & mercato | `agente:Elena` |
| **Paolo** | Code review | `agente:Paolo` |
| **Bianca** | UX | `agente:Bianca` |

I nomi mappano 1:1 i ruoli in `.claude/agents/`.

## La board
- **Milestone = treni di rilascio** (R1…R6 dell'audit). → `…/milestones`
- **Label `agente:*`** = chi ci lavora · **`tipo:*`** = feature/bug/chore/refactor/audit · **`stato:*`** = in-corso/in-review/bloccato/aiuto (fanno da colonne finché non attivi Projects).
- Vista board: **Issues** filtrate per milestone/label. → `…/issues`
- **Kanban drag-and-drop (opzionale):** crea un Project dal repo (New project → Board) — popola da solo dalle issue. Richiede lo scope `project` sul token per gestirlo via API; dalla UI web bastano 2 clic.

## Il CLI (`.team/team.py`)
Token letto a runtime dal keychain (mai in chiaro). **Non viene deployato** (escluso in `jobs/deploy.py`).

```bash
./.team/team.py list --mine Chiara         # i miei work item aperti
./.team/team.py list --train R2            # tutto il treno R2
./.team/team.py open --agent Sara --type bug --train R2 "Titolo" "Corpo markdown"
./.team/team.py comment 12 "Subset fatto, -240KB confermati" --as Filippo
./.team/team.py state 12 in-review         # in-corso|in-review|bloccato|aiuto|clear
./.team/team.py assign 12 Filippo
./.team/team.py close 12 --comment "Verificato live, smoke verde"
```

## Il flusso (definizione di "done")
1. **Regia** apre l'issue (agente + tipo + treno).
2. L'agente mette `stato:in-corso`, lavora, **carica** i risultati come commento firmato.
3. Se serve, `stato:aiuto` o `stato:bloccato` con un commento che spiega cosa serve.
4. Pronto → `stato:in-review`; **Paolo** (o **Sara** se tocca authz/dati) rivede.
5. Deploy → **smoke verde** → nessuna nuova entry `app_logs` riconducibile → `close` con il commit collegato.

Rilasci piccoli e frequenti, ma **serializzati** (un treno alla volta). Ordine prima di tutto.

## Setup / rigenerazione
Lo script `.team/setup.py` (idempotente) ricrea label, milestone e il backlog iniziale. Non serve rilanciarlo se la board esiste già.
