---
name: secure-write-checklist
description: Checklist di sicurezza (livello MASSIMO) da applicare prima di mergiare/deployare qualsiasi mutazione di dati in Spoome — form web o endpoint API. Copre input, output, CSRF/Bearer, authz al livello dati, rate-limit e la regola d'oro sugli helper di nav. Trigger: aggiungere/modificare una POST/PUT/DELETE, un form o un endpoint di scrittura, review di sicurezza pre-deploy.
---

# Checklist scrittura sicura

Ogni voce è obbligatoria prima del deploy di una mutazione.

- [ ] **Input parametrizzato** ovunque (skill **pdo-safe-query**). Identificatori dinamici solo da whitelist server-side.
- [ ] **Output via `e()`** in tutte le view (+`nl2br` nell'ordine giusto). Nessun dato utente non-escaped.
- [ ] **Anti-CSRF strutturale**:
  - web write → rotta con middleware `$csrf` (`_csrf` o header `X-CSRF-Token`).
  - API write → `requireBearerUser` (`CurrentUser::fromBearer`), **mai** sessione/cookie.
- [ ] **Authz al livello DATI** (defense-in-depth): scoping nella query SQL (`WHERE ... AND owner_id = :me`) OLTRE al check nel Service. Non fidarsi mai solo del controllo applicativo.
- [ ] **`acting_profile_id`/valori di sessione MAI fidati**: ri-valida con `canActAs(...)` prima di agire come una pagina.
- [ ] **Rate-limit anti-abuso** sulle azioni sensibili (via `RateLimiter`).
- [ ] **Ownership su risorse per-id**: un id di un altro utente → 403/404, mai 200 (verifica con skill **authz-matrix-check**).

## Regola d'oro (rischio 500 globale)
Gli helper di nav (`dm_unread/notif_unread/is_admin/acting_*`) girano su **OGNI** pagina autenticata e **non devono MAI lanciare**: wrap in `try/catch(\Throwable)` con fallback sicuro. Un throw lì = 500 su tutto il sito.

## Principio
Nessuna "sicurezza teatrale": ogni misura reale e proporzionata, zero regressioni funzionali. Owner: `security-engineer`; enforce in `code-reviewer`; considera la skill bundled `security-review` sul diff.
