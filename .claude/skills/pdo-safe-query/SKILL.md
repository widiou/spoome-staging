---
name: pdo-safe-query
description: Checklist e lint per scrivere query PDO sicure in Spoome, evitando il gotcha dei placeholder non riutilizzabili (EMULATE_PREPARES=false) che ha già causato 500 ricorrenti. Trigger: scrivere/modificare una query SQL in un Repository, usare placeholder named ripetuti o una clausola IN (...), review pre-deploy di codice che tocca SQL.
---

# Query PDO sicure

`EMULATE_PREPARES=false`: **i named placeholder NON sono riutilizzabili** nella stessa query. Riusare `:id` due volte = errore `HY093` → 500.

## Regole di authoring
- **Un named param distinto per ogni occorrenza**: se lo stesso valore serve in due punti, usa `:me1` e `:me2` (bindando lo stesso valore due volte). Vale per `:q`/`:qscore`, ecc.
- **Clausola `IN (...)`**: genera placeholder **posizionali** `?` con `array_fill(0, count($ids), '?')` + `implode(',')`, poi `array_merge` dei bind (modello `FollowRepository.php`).
- **Non mescolare** posizionali `?` e named `:x` nella stessa query.

## Lint statico (pre-deploy, quando si tocca SQL)
Cerca query con lo stesso `:token` ripetuto:
```
grep -rnE ':[a-zA-Z_][a-zA-Z0-9_]*' src/Domain --include=*.php
```
Poi ispeziona a mano ogni stringa SQL multi-linea con un token ripetuto (il grep dà falsi positivi tra query diverse: leggi il contesto, non contare soltanto). Conferma che ogni ripetizione sia stata rinominata `:meN`.

## Guardrail
- Ogni input **parametrizzato** (mai concatenazione). Identificatori dinamici (nomi colonna/ordinamento) SOLO da whitelist server-side.
- Questo è enforce obbligatorio in code review pre-deploy.
