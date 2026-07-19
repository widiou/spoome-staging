---
name: beta-smoke-check
description: Smoke test dal vivo di Spoome dopo ogni deploy sulla beta. Verifica che nessuna pagina autenticata vada in 500, che le rotte chiave rispondano 200/302 e che i casi negativi (401/403/419/422/404) e lo step-up admin funzionino. Trigger: dopo un deploy, "smoke test", "verifica che la beta sia su", "controlla che non ci siano 500".
---

# Smoke test dal vivo (post-deploy)

Custode della non-regressione: gli helper di nav (`dm_unread/notif_unread/is_admin`) girano su OGNI pagina autenticata — un bug lì manda in 500 tutto il sito.

## Setup
Cookie jar **fresco per-run** in `$CLAUDE_JOB_DIR/tmp/` (mai riusare un jar tra run: sessione stantia = falsi risultati).

## 1. Login demo
```
J=$CLAUDE_JOB_DIR/tmp/smoke_jar.txt; rm -f "$J"
# GET form login, estrai _csrf fresco dall'HTML:
curl -c "$J" -b "$J" -s https://spoome.it/beta/accedi | grep -o '_csrf" value="[^"]*"'
# POST login col token estratto:
curl -c "$J" -b "$J" -s -X POST https://spoome.it/beta/accedi \
  --data-urlencode "email=marco.rossi@demo.spoome.local" \
  --data-urlencode "password=SpoomeBeta25!" \
  --data-urlencode "_csrf=$TOKEN" -o /dev/null -w "login %{http_code}\n"
```

## 2. Pagine chiave (atteso 200)
```
for p in / /feed /rete /messaggi /profilo /atleti/giulia-bianchi; do
  curl -c "$J" -b "$J" -s -o /dev/null -w "%{http_code} $p\n" "https://spoome.it/beta$p"
done
```

## 3. Step-up admin
`GET /admin` → atteso 302 verso `/admin/verifica`; POST verifica con la password → poi `GET /admin` = 200.

## 4. Casi negativi (obbligatori)
- guest (jar vuoto) su `/rete` → 302/401
- `/admin` con utente non-admin → **404** (cloak, non 403)
- POST senza `_csrf` → 419/422
- handle inesistente → 404
- token/sessione scaduta → 401

## Guardrail
- **Mai modificare i dati demo**: solo GET/letture o azioni idempotenti e ripristinabili. Non toccare i profili/utenti demo (`giulia-bianchi`, `marco.rossi`), non sporcare `claim_requests` sul roster.
- Il `_csrf` va riletto fresco a ogni GET (rigenerato).
- Se qualcosa rompe → **blocca il rilascio**, segnala rotta+status+corpo, e passa a rollback (skill **beta-deploy**).
