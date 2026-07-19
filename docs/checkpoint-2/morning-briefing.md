# Briefing del mattino — lavoro notturno (notte 2026-07-04 → 05)

Mandato: lavorare in autonomia tutta la notte, testare TUTTE le funzionalità, sistemare i bug, arrivare a un punto solido su tutto ciò che è già stato deciso. Accantonare (non decidere) i temi che richiedono il fondatore → sotto.

## ✅ Consegnato e live prima della notte
- Consolidamento async: contratto unico `Controller::respond/emitJson` (web+API), 16 azioni web migrate, 11 gap `/api/v1` chiusi, `MediaService` estratto; client `data-async` centralizzato; reload eliminati (in-place); gate PASS.
- Condivisione link: unfurl + card LinkedIn + card video (oEmbed), image-proxy firmato, SSRF-guard completo (hardening applicato + sigillato).
- Overflow orizzontale mobile: risolto (header `.nav-search min-width:0`, brand-word nascosta <720px) + backstop `overflow-x:clip`; verificato `over(0)` a 375/320.
- Post stile Instagram: header/caption/media edge-to-edge/action bar/commento inline; async intatto; verificato 375.

## 🌙 Programma della notte (in esecuzione)
1. Sweep di test funzionale end-to-end su TUTTE le feature (baseline) → matrice pass/fail + bug list.
2. Fix dei bug trovati.
3. Build realtime **Fase 1** (deciso, indipendente da decisioni aperte): stream a cursore + badge live + scaffolding push native. Additivo, senza toccare i percorsi caldi notifiche/DM. Gate + test.
4. Sweep QA mobile 375/320 su tutte le pagine (CDP) → zero overflow.
5. Gate di qualità finale sulle modifiche della notte + consolidamento.

## 🏁 STATO FINALE DELLA NOTTE — SOLIDO
- **Smoke finale (read-only)**: pubbliche (`/ /atleti /atleti/{h} /accedi /registrati`) 200; autenticate (`/feed /rete /profilo /messaggi /notifiche /atleti?q= /rivendicazioni`) 200; `/admin`→302 step-up. Nessun 500.
- **Integrità DB**: marco post 5,12 (baseline); `user_events`/`push_devices` = 0 (nessun residuo); migrazioni 0016→0021 registrate; **contatori notifiche denorm == reale (zero drift)**.
- **Demo pristina**, beta stabile. Tutto ciò che era già deciso è costruito, testato e consolidato.
- Ciò che resta è o (a) da decidere con te [sotto], o (b) il cablaggio client del realtime (modifica visibile sui percorsi caldi → con te sveglio).

## 🗣️ TEMI DA DISCUTERE DOMANI (decisioni del fondatore — NON forzate stanotte)
1. **Realtime Fase 2 (live push)**: provider gestito (Ably/Pusher ~€29-49/mese, zero ops) **vs** nodo realtime dedicato. Racc.: provider gestito.
2. **Media storage/video** (rinviato da te): provider storage (Cloudflare R2 vs B2/Spaces/S3) + provider video (Cloudflare Stream vs Bunny vs Mux). I link sono già fatti; il resto del media aspetta questa scelta.
3. **API partner/pubblica**: costruire ora il subset low-risk (API key + widget iframe + directory/profilo/ricerca) o dopo? + rinviare OAuth2/gateway edge (racc. sì).
4. **Beta → produzione reale** (`spoome.it`): quando promuovere il blocco consolidato. Ho l'autorità ma è verso utenti reali → decidiamo insieme la finestra.
5. **Post Instagram — ritocchi**: "…altro" su riga propria (ora) vs inline; carosello/video reali dipendono dal media subsystem.
6. **Stream realtime — sessione su rotta `/api`**: lo `StreamController` avvia la sessione su `/api/v1/stream/since` per il polling web (le `/api` sono di norma stateless Bearer-only). Ritenuto sicuro (GET read-only, solo dati propri, SameSite=Lax, no CORS/CSRF). Scelta: tenerlo così, oppure spostare il polling web su una rotta web dedicata (`/feed/stream`) session-backed e lasciare `/api` puramente Bearer. Da decidere insieme al **cablaggio client dei badge live**.
7. **Realtime Fase 1 — attivazione client**: il backend stream è pronto e testato; manca il cablaggio nel frontend (un poller consolidato che sostituisce quello DM a 5s + accende i badge live notifiche/DM). È una modifica visibile sui percorsi caldi → la facciamo con te sveglio.

## Esiti dei test e fix della notte
(aggiornato man mano)

- **[PASS] Sweep overflow mobile (CDP 375 + 320px)** su 7 pagine (feed, profilo, rete, messaggi, profilo pubblico, notifiche, ricerca): `over(0)` e `scrollWidth==clientWidth` su tutte, a entrambe le larghezze. Zero scroll orizzontale. Il fix header + backstop regge su tutta l'app.
- **[PASS] Sweep di test funzionale end-to-end** — tutte le 14 aree PASS, nessun P0/P1, zero 500, zero buchi sicurezza, contatori senza drift, demo ripristinata. Report `night-test-report.md`. Rilievi P3/Info:
  - P3: DM send ritorna `id:0` invece dell'id reale (consegna ok via poll) → **fix**.
  - P3: update link cross-owner ritorna 422 invece di 403/404 (nessun leak, solo status incoerente) → **fix**.
  - P3/Info: rate-limit `/link-image` e `/profilo/competenze` non osservati nel burst di test → **verificato = corretto by-design** (limiti più alti del burst: 120/10min e 30/10min); nessun bug.
  - Info: DM poll ritorna body grezzo nel JSON → il client usa `textContent` (safe, confermato dal gate); nessun rischio.
- **[FATTO] Fix dei 2 P3 reali**: DM send ora ritorna l'id reale (fix `lastInsertId` pre-UPDATE); update dettaglio cross-owner ora 404 (guardia anti falso-404). Deploy ok, pagine 200, demo ripristinata. Rate-limit/DM-escaping confermati corretti (nessuna modifica).
- **[FATTO] Realtime Fase 1 — BACKEND additivo**: `EventBus` + `user_events`(0020) + `push_devices`(0021) + `GET /api/v1/stream/since` (eventi propri + contatori + cursore) + `POST/DELETE /api/v1/devices` (Bearer, scaffolding push native). Emit soft/per-utente da choke-point unico (`NotificationRepository::create`) + `message.created`; feed = conteggio calcolato nello stream (no fan-out). DM invariato, zero regressioni, demo ripristinata. **Cablaggio client badge live rimandato al mattino.**
- **[PASS gate realtime]** nessun P0: scoping per-utente, eventi DM solo al destinatario, soft-emit isolato, Bearer-only, Session-su-`/api` SICURA (no CORS, SameSite=Lax, JSON+nosniff). Fix P2 applicati stanotte: rate-limit `/devices` + cap dispositivi, rate-floor `/stream/since`, contatore feed scoping per-utente. **Follow-up Fase 2 (segnati):** rebind token silenzioso in `push_devices` (rischio solo quando le push saranno attive), `dedup_key` su `user_events` (benigno).
