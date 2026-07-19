---
name: authz-matrix-check
description: Verifica sistematica dell'autorizzazione in Spoome — matrice di ruoli (anon / utente-A / utente-B non-owner / admin) contro rotte con ownership e area admin, per scovare IDOR e confermare il 404-cloak admin. Trigger: feature con ownership (profili, claim, DM, post, membri pagina), area admin, "verifica authz/permessi", audit IDOR, review di sicurezza pre-deploy.
---

# Matrice di autorizzazione (IDOR + 404-cloak)

## Metodo
Per ogni rotta mutante o dettaglio sensibile, prova con **4 identità** (curl con jar diversi):
1. **anonimo** (jar vuoto)
2. **utente-A** (owner)
3. **utente-B** (non-owner)
4. **admin** (dopo step-up)

## Cosa deve valere
- **Area admin** (`/admin/*`) con utente non-admin → **404** (cloak via `AdminMiddleware`), **non 403**. Un 403 rivelerebbe l'esistenza dell'area.
- **Risorse per-id con ownership** (es. `/profilo/esperienze/{id}/elimina`, DM, membri pagina): passare l'id di **un altro utente** → 403/404, **mai 200**. Questo è il test IDOR.
- **Scritture API** con cookie di sessione (senza Bearer) → **401** (non devono passare): le scritture sono solo-Bearer.
- **Claim**: verificare dedup e guard "hai già un profilo"; ricontrolli anti-corsa in `approve`.
- **Envelope API**: nessun leak di campi privati (email, token) nella forma pubblica.

## Guardrail
- Stato via skill **db-query** prima/dopo (es. `claim_requests`, `profiles.claim_status`).
- **Ripristina sempre lo scenario demo** dopo test distruttivi (backup se serve): non sporcare il roster demo né i profili/utenti demo.
- Owner: `qa-test-engineer` + `security-engineer`. Complementare alla skill **secure-write-checklist** (authoring) — questa è la verifica dal vivo.
