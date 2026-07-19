# Realtime Layer — SPEC (checkpoint-2)

**Autore:** Realtime / Infrastructure Architect · **Stato:** proposta (no production code)
**Obiettivo:** consegnare quasi-istantaneamente notifiche, DM, aggiornamenti feed e interazioni sui post (like/commenti) al **web** e alle **future app native iOS/Android**, senza infrangere la realtà dell'hosting (SiteGround shared) e senza downgrade di sicurezza (livello MASSIMO).

> Principio cardine: **il realtime è un'ottimizzazione sopra un inbox durevole e interrogabile, mai la fonte di verità.** Se il trasporto live è giù, il client fa catch-up via API con un cursore e non perde nulla. Gli eventi nascono negli **stessi Service** già usati da web e API — un solo dominio, tre client.

---

## 0. Stato attuale accertato (letto, non assunto)

| Componente | File | Fatto rilevante |
|---|---|---|
| Notifiche in-app | `Domain/Notifications/NotificationService.php` + `NotificationRepository.php` | `emit()` risolve attore/destinatario per `profile_id`, salta i profili senza owner; scrive in `notifications` (chiave **`user_id`**) e incrementa il contatore denormalizzato `users.unread_notifications`. Dedup anti-spam (`existsRecentSame`). |
| Tabella `notifications` | migr. 0013 | `id BIGINT`, `user_id INT`, `type`, `title`, `body`, `url`, `read_at`, `created_at`. Idx `(user_id, read_at)`, `(user_id, created_at)`. **Per-utente.** |
| DM | `Domain/Messaging/MessageService.php`, `ConversationService`, migr. 0010 | `messages(id INT, conversation_id, sender_id=profile_id, body, read_at, created_at)`; `conversations` per coppia. Autorizzazione: si scrive **solo tra profili connessi**, ricontrollata ad ogni invio. **Per-profilo.** |
| Post engagement | `Domain/Feed/PostEngagementService.php` | `toggleLike`→`postLike` notif (solo primo like, mai a sé); `comment`→`postComment` notif; contatori denormalizzati. |
| Feed / post | `Domain/Feed/PostService.php`, `FeedService`, `FeedRepository`, migr. 0009 | `create()` valida + rate-limit; il feed è un fan-out di lettura (nessuna notifica al follower alla creazione). |
| Emissione eventi oggi | `FollowService` (→`follow`), `ConnectionService` (→`connectionRequest`/`connectionAccepted`), `MessageService` (→`newMessage`), `PostEngagementService` (→`postLike`/`postComment`) | **Tutti** invocano `NotificationService` dallo stesso punto in cui muta lo stato. È qui che si innesta l'EventBus. |
| Realtime-ish attuale | `public/assets/js/app.js` L323-360 | **Polling DM** ogni 5s su thread aperto: `GET /messaggi/{handle}/poll?after=<id>` → `{messages:[{id,from_me,body}]}`; dedup client su `data-mid`; salta se `document.hidden`. Le notifiche **non** hanno polling live: il badge `notif_unread` si aggiorna solo al reload pagina. |
| Nav helpers (girano su OGNI pagina) | `Core/helpers.php` | `dm_unread()` (per profile_id), `notif_unread()` (contatore denormalizzato per user_id), `is_admin`. Un bug qui = 500 ovunque (non-negoziabile #2). |
| Contratto multi-client | `async-consolidation-spec.md`, `Core/Response.php`, `ServiceResult.php`, `Csrf.php`, `app.js`→`Spoome.api()` | Envelope `{data,meta}`/`{errors}`; `wantsJson()`; API **solo-Bearer** (no CSRF), web **session+CSRF**. Gli eventi realtime riusano identico envelope e nomi-campo. |

**Due spazi di identità da tenere presenti (fonte di bug di scoping):** le **notifiche** sono chiavate su `user_id`; **DM, like, commenti, post** su `profile_id`. Un utente ha un profilo (`ProfileRepository::findByUserId`). Lo strato realtime **normalizza tutto su `user_id`** come identità del canale (è l'account che riceve, ed è ciò che un token push/Bearer identifica), risolvendo `profile_id → user_id` al momento dell'emissione. La migrazione più recente è **0018** → il nuovo lavoro parte da **0019**.

---

## 1. Event model (core transport-agnostic)

### 1.1 Principio: emettere dove lo stato cambia, nello stesso punto delle notifiche

Ogni evento è emesso dal **Service** nel medesimo punto in cui oggi si chiama `NotificationService`. Si introduce una sottile astrazione `EventBus` iniettata nei Service (stesso pattern DI del costruttore già usato per `NotificationService`), così **cosa è successo** (dominio) è disaccoppiato da **come viene consegnato** (polling / provider / push). L'EventBus:

1. **persiste** l'evento nell'inbox durevole (`user_events`, §3) — questo è ciò che garantisce il catch-up e serve il cursore;
2. **pubblica** l'evento al fan-out live (Phase 2: HTTP POST al provider / trigger push). In Phase 1 il passo 2 è un no-op: il client fa long-poll sull'inbox.

```
Controller → Service (muta stato, ServiceResult) → EventBus::emit(DomainEvent)
                                                       ├─ EventInbox::append(user_id, event)   [durevole, cursore]
                                                       └─ RealtimePublisher::publish(event)     [Phase 2: live/push; Phase 1: no-op]
```

`EventBus` è un'interfaccia con una sola implementazione iniziale (`InboxEventBus`) che scrive solo l'inbox; Phase 2 aggiunge un decoratore che pubblica. Nessun Service conosce il trasporto.

### 1.2 Forma del payload (allineata all'envelope)

Un `DomainEvent` serializzato è un oggetto stabile, minimale, **senza PII oltre il necessario** e con i nomi-campo già usati in `data`:

```json
{
  "id": 918273,                       // BIGINT monotono = cursore (last_event_id)
  "type": "message.created",
  "created_at": "2026-07-04T14:03:11Z",
  "actor": { "handle": "marco-rossi", "display_name": "Marco Rossi" },
  "data": { "conversation_id": 42, "message_id": 5567, "preview": "Ciao, ..." }
}
```

- `actor` è **minimale** (handle + display_name pubblici), mai email/telefono/dato privato.
- `data` porta **id di riferimento**, non contenuto sensibile completo: per i DM si include al più un `preview` troncato (già visibile al destinatario che ne è partecipante); il corpo pieno si recupera dall'endpoint DM autorizzato. Questo limita il leakage se un payload finisce nel posto sbagliato.
- I nomi-campo coincidono con quelli già emessi dai Service (`liked`, `count`, `conversation_id`, `followers_count`, `status`…), così i client riusano il codice di rendering esistente.

### 1.3 Catalogo eventi

| Evento | Emesso in (Service) | Destinatario/audience (scoping) | `data` (payload) | Rende in UI |
|---|---|---|---|---|
| `notification.created` | wrapper generico attorno a `NotificationService::emit` | il **singolo** `user_id` destinatario (già risolto, owner del profilo) | `{notification_id, type, title, body, url}` | badge `notif_unread`, dropdown notifiche |
| `message.created` | `MessageService::send` | **entrambi** i partecipanti (mittente + destinatario), risolti a `user_id`; MAI terzi | `{conversation_id, message_id, preview, from_me?}` | thread aperto + badge `dm_unread` |
| `post.liked` | `PostEngagementService::toggleLike` (solo `liked=true`) | owner del post (`user_id`), mai a sé | `{post_id, liked:true, count}` | contatore like live sulla card + notifica |
| `post.unliked` | `toggleLike` (solo `liked=false`) | *(interno/opzionale)* solo per sync contatore multi-tab dell'attore stesso | `{post_id, liked:false, count}` | contatore |
| `post.commented` | `PostEngagementService::comment` | owner del post (`user_id`), mai a sé | `{post_id, comment_id, count, preview}` | contatore commenti + notifica |
| `connection.requested` | `ConnectionService` (branch richiesta) | destinatario della richiesta (`user_id`) | `{status:'pending', connections_count}` | badge Rete + notifica |
| `connection.accepted` | `ConnectionService` (branch accetta) | richiedente originale (`user_id`) | `{status:'connected', connections_count}` | badge + notifica |
| `follow.created` | `FollowService::follow` | owner del profilo seguito (`user_id`) | `{followers_count}` | notifica |
| `feed.post.created` | `PostService::create` | **fan-out**: i `user_id` di chi segue/è connesso all'autore (audience derivata) | `{post_id, author_handle}` | pill "N nuovi post" in cima al feed (no auto-inject) |

**Nota fan-out `feed.post.created`:** è l'unico evento a molti-destinatari. In Phase 1 **non** viene materializzato per-follower nell'inbox (esploderebbe le scritture); il client scopre i nuovi post con il cursore del feed (max `post_id` visto) nella stessa chiamata consolidata (§4). In Phase 2 il fan-out live si fa **per canale** (il provider pubblica sul canale-autore a cui i follower sono sottoscritti, oppure su un canale "feed" personale popolato lato provider) — vedi §5. Questo mantiene l'inbox durevole magra e sposta il costo fan-out sul provider.

**Regola di scoping (SICUREZZA, non-negoziabile):** l'audience di ogni evento è calcolata **server-side** dal Service, mai dal client. Un evento entra nell'inbox di un `user_id` **solo** se quel Service ha già autorizzato quella relazione (owner del post, partecipante della conversazione, follower). Nessun evento è mai indirizzato a un `user_id` che non è autorizzato a vederlo. `notification.created` per profili **senza owner** è saltato (come già fa `emit()`).

---

## 2. Transport options — analisi onesta per QUESTO stack

SiteGround shared hosting = PHP-FPM con un pool di worker **limitato e condiviso**, **nessun demone long-lived**, **nessuna porta custom**, deploy via FTP. Qualsiasi trasporto che **tenga un worker occupato per client** è veleno: poche decine di client in attesa esauriscono il pool e **mandano in 500 tutto il sito** (inclusi i nav helper — non-negoziabile #2).

| Trasporto | Gira su SiteGround shared? | Web live | Web background (tab chiuso) | Native (lock-screen) | Costo dominante | Verdetto |
|---|---|---|---|---|---|---|
| **Short polling** (intervallo) | ✅ Sì | Latenza = intervallo | ❌ | ❌ | Volume richieste (N utenti × freq) | **Phase 1 baseline** |
| **Long polling** (hold breve) | ⚠️ Solo con hold ≤ ~20-25s e worker pool ampio; rischioso | Bassa latenza | ❌ | ❌ | **Occupa un worker per client in attesa** | Evitare hold lunghi su shared; usare short/adaptive |
| **SSE** (`text/event-stream`) | ❌ **No** (di fatto) | Ottima | ❌ | ❌ | **1 worker FPM bloccato per client, per tutta la durata** | **Vietato su shared FPM**: esaurisce il pool. Solo su nodo dedicato. |
| **WebSocket** (Ratchet/Swoole/Node) | ❌ No | Ottima (bidirezionale) | ❌ | ❌ | Server persistente + porta | Richiede **nodo separato**/managed. Non su shared. |
| **Managed realtime** (Pusher/Ably/Supabase Realtime) | ✅ (PHP fa solo `POST` HTTP di publish) | Ottima | ⚠️ (con service worker) | ⚠️ (via loro push beta) | Abbonamento + msg/connessioni | **Phase 2 raccomandato per web live** |
| **Web Push** (VAPID + Service Worker) | ✅ (PHP invia payload cifrato agli endpoint push) | ❌ (non è uno stream) | ✅ **Sì** | ❌ | Nessun costo infra (usa push del browser) | **Phase 2 per notifiche web background** |
| **Native push** APNs (iOS) + FCM (Android) | ✅ (PHP `POST` HTTP token-based ad Apple/Google) | ❌ | — | ✅ **Sì, corretto** | Nessun costo per-messaggio (FCM gratis; APNs gratis) | **Phase 2 per native** — l'unico realtime mobile corretto |

**Perché SSE è pericoloso qui, in una riga:** SSE tiene aperta la risposta HTTP → il worker PHP-FPM che la serve **non torna mai nel pool** finché il client resta connesso; con un pool tipico di poche decine di worker condivisi con TUTTO il sito, bastano poche schede aperte per esaurirlo e restituire 502/500 a chiunque, nav helper inclusi.

### Raccomandazione

- **Web:** **Phase 1 = short/adaptive polling** su un **unico endpoint consolidato** (`/api/v1/stream/since`) che sostituisce i poll multipli e gira nativamente su SiteGround. **Phase 2 = provider managed** (Ably o Pusher) per il live-push web: PHP pubblica gli eventi via un solo `POST` HTTP, il socket fan-out è offloadato fuori dall'hosting. In parallelo **Web Push (VAPID)** per le notifiche a tab chiuso.
- **Native:** **APNs + FCM** fin dall'inizio delle app — è il realtime corretto per mobile (background/lock-screen), a costo per-messaggio nullo. Il live in-app (chat aperta) usa lo stesso provider managed del web.
- **Nessun nodo dedicato self-hosted** finché il volume non lo giustifica: gestire un processo WebSocket 24/7 (deploy, supervisione, TLS, scaling) è ops che il team oggi non ha, e SiteGround non lo ospita. Il provider managed compra tempo e sposta il rischio operativo.

---

## 3. Correttezza, catch-up, sicurezza

### 3.1 Modello di catch-up: cursore + inbox durevole

**Il realtime non è mai la fonte di verità.** Ogni client mantiene un `last_event_id`. Alla (ri)connessione — riapertura tab, ritorno online, telefono acceso dopo ore offline — chiama l'endpoint consolidato con il proprio cursore e riceve **tutto ciò che è successo dopo**, in ordine. Il live-push (Phase 2) fa solo arrivare gli eventi *prima*, ma se il socket cade il client **degrada a polling** sullo stesso cursore e non perde nulla.

**Sorgente del cursore — nuova tabella `user_events` (outbox/inbox durevole).** Le tabelle esistenti hanno cursori eterogenei (`notifications.id` per-utente, `messages.id` per-conversazione, `posts.id` per-feed): comporre più cursori è fragile per ordine/dedup. Si introduce **un unico log append-only per-utente** con id BIGINT monotono che è **il** cursore unificato. È magro (pruning dopo N giorni), disaccoppiato da `notifications` (che resta la lista utente durevole con `read_at`), e serve **sia** il polling Phase 1 **sia** il publish Phase 2 (il provider pubblica esattamente le righe che l'EventBus scrive qui).

```sql
-- migrazione 0019_create_user_events.php  (segue la 0018)
CREATE TABLE user_events (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,   -- cursore globale monotono
    user_id      INT NOT NULL,                        -- destinatario (identità canale), risolto da profile_id
    type         VARCHAR(40) NOT NULL,                -- 'message.created', 'post.liked', ...
    dedup_key    VARCHAR(120) NULL,                   -- idempotenza: es. 'like:<post>:<actor>'
    payload      JSON NOT NULL,                       -- {actor:{...}, data:{...}}  (no PII oltre il pubblico)
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ue_user_id (user_id, id),                 -- query cursore: WHERE user_id=? AND id>? ORDER BY id
    UNIQUE KEY uniq_ue_dedup (user_id, dedup_key),    -- dedup a livello DB (NULL non collide)
    CONSTRAINT fk_ue_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- **Pruning:** un job (cron SiteGround o lazy on-write) elimina righe `created_at < NOW() - INTERVAL 30 DAY`. Il client che è stato offline oltre la finestra fa un **catch-up "cold"**: ignora il cursore scaduto e ricarica lo stato corrente dagli endpoint canonici (notifiche recenti, conversazioni, feed) — mai un buco silenzioso.
- **`feed.post.created`** NON viene scritto per-follower qui (fan-out esploso). Il feed usa il proprio cursore (`max post_id`) restituito nella stessa risposta consolidata.

### 3.2 Ordinamento & dedup

- **Ordine:** l'id auto-increment di `user_events` dà un ordine totale per-utente; il client applica gli eventi in ordine di `id` e avanza `last_event_id` all'`id` massimo ricevuto.
- **Dedup lato server:** `UNIQUE(user_id, dedup_key)` scarta duplicati (es. doppio like/toggle rapido, retry di publish). `INSERT ... ON DUPLICATE KEY UPDATE id=id` (no-op).
- **Dedup lato client:** i client scartano `id <= last_event_id` già visti (idempotenza anche se live-push e polling consegnano lo stesso evento). Per i DM resta il dedup su `data-mid` già presente (§0).

### 3.3 Backpressure & degrado

- **Adaptive interval (Phase 1):** intervallo base 10s con **backoff** quando la tab è in background (`document.hidden` → pausa, come già fa il poller DM) e quando l'ultima risposta è vuota (allunga fino a ~30s); ritorna a 5-8s quando la chat è aperta o dopo attività. Un solo timer per l'intera pagina (non uno per feature).
- **Backpressure:** la risposta consolidata è **cap-ata** (es. max 50 eventi per chiamata) con un flag `meta.has_more`; il client richiama subito se `has_more` finché svuota, poi torna all'intervallo. Evita payload giganti dopo lunghe assenze.
- **Trasporto giù (Phase 2):** se il socket del provider si disconnette, il client **cade automaticamente sul polling** dello stesso endpoint/cursore. Il degrado è invisibile: stessa API, stessa correttezza, solo più latenza.

### 3.4 Sicurezza (livello MASSIMO)

- **Auth canale = identità server-derivata.** Web: sessione (il cursore è servito da un endpoint autenticato che risolve `user_id` dalla sessione). Native/API: **Bearer** (`CurrentUser::fromBearer`), nessun CSRF sulle letture GET dell'API. In Phase 2, la sottoscrizione al provider usa **private channels** `private-user-<user_id>` autorizzati da un endpoint di **auth-signing server-side** (`POST /api/v1/stream/auth`): il client non può sottoscriversi a un canale che il server non firma per lui. Un utente **non può** ascoltare il canale di un altro.
- **Autorizzazione al livello dati (defense-in-depth):** l'inbox contiene solo eventi che il Service ha già autorizzato per quel `user_id`. Il consolidato **non** ricalcola visibilità leggendo tabelle grezze: legge `user_events WHERE user_id = <me>`; per DM/feed che leggono tabelle canoniche, riusa i Service esistenti che già impongono "solo partecipanti"/"solo connessi". Nessun nuovo path privilegiato.
- **No PII nei payload:** `payload` porta solo dati già pubblici (handle, display_name) + id di riferimento + preview troncata destinata a chi è già partecipante. Il corpo pieno dei DM si recupera solo dall'endpoint autorizzato. In Phase 2, i payload verso provider/Web Push/APNs/FCM sono **minimali** (title/preview + id); il device fa fetch autenticato del dettaglio. Mai email, telefono, corpo integrale in un push.
- **Rate/abuse:** l'endpoint consolidato ha rate-limit per-utente (riusa `RateLimiter`); l'intervallo minimo client è imposto anche server-side (429 se un client martella). La registrazione device-token (§4.3) è Bearer-only e idempotente.
- **Nessuna regressione nav:** l'endpoint consolidato è **isolato**; se lancia, non tocca il rendering delle pagine. I nav helper continuano a leggere i contatori denormalizzati. Il realtime **aggiorna** i badge lato client, non li sostituisce come fonte.

---

## 4. Phase 1 — funziona SULLA beta ORA

### 4.1 Endpoint consolidato: `GET /api/v1/stream/since`

Un'**unica** chiamata sostituisce i poll separati (DM + il polling notifiche che oggi non c'è). Ritorna tutti gli eventi pendenti per l'utente autenticato dopo il suo cursore, più i contatori nav e i cursori secondari (feed).

**Request** (Bearer per native/API; sessione per web — l'endpoint accetta entrambe le auth in lettura GET, come gli altri GET):
```
GET /api/v1/stream/since?cursor=918273&feed_cursor=5540&conversation=42
```
- `cursor` — ultimo `user_events.id` visto (0 = primo avvio → non ricarica tutta la storia: ritorna solo contatori + ultimi N).
- `feed_cursor` — ultimo `post_id` visto nel feed (opzionale; per il pill "nuovi post").
- `conversation` — opzionale: se una chat è aperta, include i nuovi messaggi di quella conversazione (riusa `ConversationService::newMessages`, già autorizzato).

**Response** (envelope standard `{data, meta}`):
```json
{
  "data": {
    "events": [
      { "id": 918274, "type": "notification.created", "created_at": "...",
        "actor": {"handle":"...","display_name":"..."},
        "data": {"notification_id": 40021, "type":"post_like", "title":"...", "url":"feed"} },
      { "id": 918275, "type": "message.created", "created_at": "...",
        "actor": {"handle":"...","display_name":"..."},
        "data": {"conversation_id": 42, "message_id": 5567, "preview":"Ciao..."} }
    ],
    "counters": { "notif_unread": 3, "dm_unread": 1 },
    "feed": { "new_posts": 2, "latest_post_id": 5542 },
    "messages": [ { "id": 5567, "conversation_id": 42, "from_me": false, "body": "..." } ]
  },
  "meta": { "cursor": 918275, "has_more": false }
}
```

- **`meta.cursor`** = nuovo `last_event_id` che il client memorizza e rimanda.
- **`counters`** = valori denormalizzati (stessi che leggono i nav helper) → aggiorna i badge senza reload.
- **`messages`** = presente solo se `?conversation=` dato; rende il polling DM dedicato **obsoleto** (unifica il poller L323-360 di `app.js` in questa chiamata).
- **`feed`** = segnale per il pill "N nuovi post" (nessun auto-inject di markup → nessun rischio XSS; il click ricarica/append via il partial server-rendered esistente).

**Implementazione (compatibile shared hosting):** una singola query indicizzata `SELECT ... FROM user_events WHERE user_id = :me AND id > :cursor ORDER BY id LIMIT 50` + le letture contatori denormalizzate (O(1)) + eventuale `newMessages`. **Nessun hold**, nessun worker bloccato: la richiesta ritorna subito. Il client la richiama a intervallo adattivo (§3.3). Questo è ciò che gira **oggi** sulla beta.

### 4.2 Client (Phase 1): un solo poller

Rimpiazza il poller DM isolato con **un** timer globale in `app.js` che chiama `/api/v1/stream/since`, distribuisce gli eventi a handler dichiarativi (riusando il vocabolario `data-async-success`/`Spoome.handlers` della async-consolidation-spec: `updateCount`, `appendHtml`, il render DM condiviso `renderMessage`), aggiorna i badge nav e persiste il cursore (in memoria + `sessionStorage` per sopravvivere a un reload). `document.hidden` → pausa. Fallback silenzioso su errore (come l'attuale `.catch`).

### 4.3 Registrazione device-token (predisposizione native, Bearer-only)

Endpoint pronto già in Phase 1 (le app arrivano dopo, ma il contratto è stabile):
```
POST /api/v1/devices        (Bearer)   body: { platform: "ios"|"android"|"web", token: "<apns|fcm|webpush-subscription>" }
DELETE /api/v1/devices/{token}          rimozione al logout / token invalido
```
Tabella di supporto (stessa migrazione o 0020):
```sql
CREATE TABLE push_devices (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  platform ENUM('ios','android','web') NOT NULL,
  token VARCHAR(512) NOT NULL,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_token (token(191)),
  KEY idx_user (user_id),
  CONSTRAINT fk_pd_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 5. Phase 2 — realtime vero / scala

Attivato quando il volume di polling diventa costoso (§6) o quando serve latenza sub-secondo / notifiche a tab chiuso / native.

### 5.1 Web live-push — provider managed (Ably o Pusher)

- **Come si innesta:** il decoratore `RealtimePublisher` dell'EventBus fa **un `POST` HTTP** al provider dopo aver scritto `user_events` (fire-and-forget, con retry breve; se fallisce, il polling copre comunque → nessuna perdita). Canale `private-user-<user_id>`.
- **Sottoscrizione:** il client SDK del provider si connette e chiede l'autorizzazione a `POST /api/v1/stream/auth`, che verifica sessione/Bearer e **firma** solo il canale dell'utente corrente. Alla ricezione di un evento live, il client **avanza il cursore** e, se dubita di aver perso qualcosa (gap negli id), fa un catch-up via §4.1.
- **`feed.post.created` fan-out:** i follower sono sottoscritti al canale dell'autore (`public-author-<profile_id>` per contenuti pubblici) oppure il publish avviene sul canale-feed di ciascun follower calcolato server-side. Preferenza: pubblicare sul canale-autore per i post pubblici (fan-out lato provider), evitando N scritture.

### 5.2 Web background (tab chiuso) — Web Push (VAPID + Service Worker)

- Service Worker + `PushManager.subscribe` con chiave **VAPID**; la subscription si registra via `POST /api/v1/devices` (platform `web`).
- L'EventBus, per gli eventi "degni di notifica" (notification.created, message.created), invia il payload cifrato agli endpoint push del browser. Payload **minimale** (title + preview + url); il click apre la pagina e fa fetch autenticato del dettaglio.

### 5.3 Native — APNs (iOS) + FCM (Android)

- Registrazione token via `POST /api/v1/devices`.
- Mapping evento → push: `message.created` → alert "Nuovo messaggio da {display_name}" + `preview` + deep-link `spoome://conversazione/<id>`; `notification.created` → title/body già localizzati; like/commenti aggregabili. **Nessun corpo integrale né PII** nel payload; il device fa fetch autenticato.
- In-app (app aperta): stesso provider managed del web per il live; APNs/FCM coprono background/lock-screen.
- **Stessi eventi, stessi Service:** APNs/FCM/Web Push/provider sono tutti alimentati dal medesimo `EventBus::emit`. Un solo dominio.

### 5.4 Infra / costo / ops che Phase 2 aggiunge

| Voce | Cosa aggiunge | Costo/ops indicativo |
|---|---|---|
| Provider managed (Ably/Pusher) | Account, SDK client self-hosted (CSP: aggiungere l'host del provider — oggi CSP è chiusa), endpoint `/stream/auth` | Free tier ~200 connessioni concorrenti / ~1M msg mese; poi ~$29-49/mese fascia iniziale. Zero server da gestire. |
| Web Push (VAPID) | Coppia di chiavi VAPID, Service Worker, libreria di cifratura push server-side | **€0** infra (usa i push service dei browser). Ops: gestire subscription scadute (410 → prune). |
| APNs | Certificato/Key APNs (Apple Developer, ~$99/anno già necessario per pubblicare l'app), `POST` HTTP/2 token-based | **€0** per messaggio. Ops: rotazione key, gestione token invalidi. |
| FCM | Progetto Firebase, service account | **€0** (FCM gratis). Ops: minime. |
| Pruning `user_events` | Cron SiteGround o lazy-delete | trascurabile |
| Outbound HTTP dai publish | PHP fa `POST` al provider/APNs/FCM per evento | latenza aggiunta al request mutante → fare **fire-and-forget/async** (o coda leggera in tabella + drain via cron) per non rallentare la risposta utente. Su shared, preferire drain via cron a un demone. |

> **Nota shared hosting per Phase 2:** anche in Phase 2 SiteGround resta il *publisher* (fa `POST` uscenti), **non** l'host dei socket. Non introduce demoni. Il rischio worker-exhaustion non si ripresenta.

---

## 6. Quando il polling smette di scalare (per decidere il timing di Phase 2)

Ordine di grandezza (short poll a 10s, tab attiva): **N utenti attivi simultanei × 6 richieste/min**. A 1.000 attivi simultanei ≈ **6.000 req/min ≈ 100 req/s** di sole richieste di stream — gestibile su una query indicizzata O(log n), ma inizia a pesare sul pool FPM condiviso e sul DB. Segnali per attivare Phase 2: p95 dell'endpoint `/stream/since` in crescita, quota richieste SiteGround sotto pressione, o requisito prodotto di notifiche push a tab/app chiusa (che il polling **non può** dare). Sotto ~qualche centinaio di attivi simultanei, Phase 1 basta e avanza.

---

## 7. Multi-client parity

| Client | Auth canale | Live (Phase 2) | Background/chiuso | Catch-up |
|---|---|---|---|---|
| **Web (async)** | Sessione (+ `/stream/auth` firma il canale) | Provider managed | **Web Push** (VAPID + SW) | `GET /stream/since?cursor=` |
| **iOS** | Bearer (`/stream/auth`) | Provider managed (in-app) | **APNs** | `GET /stream/since?cursor=` |
| **Android** | Bearer | Provider managed (in-app) | **FCM** | `GET /stream/since?cursor=` |

Tutti e tre **consumano gli stessi eventi dagli stessi Service** via `EventBus`. Web usa l'endpoint stream Phase-1 **ora** e il provider live **dopo**; native = push token (`/api/v1/devices`) + Bearer. Un solo catalogo eventi, un solo inbox, un solo cursore.

---

## 8. Riepilogo esecutivo

- **9 eventi** dominio (notification/message/post.liked/commented/connection.requested/accepted/follow/feed.post.created), emessi dagli **stessi Service** che già chiamano `NotificationService`, via una sottile astrazione **`EventBus`** (persiste su `user_events` + pubblica in Phase 2). Scoping per-`user_id` server-side: nessun utente riceve eventi altrui.
- **Trasporto raccomandato:** Phase 1 **short/adaptive polling** su un **endpoint consolidato** `GET /api/v1/stream/since?cursor=` (gira su SiteGround, nessun worker bloccato); Phase 2 **provider managed** (Ably/Pusher) per il live web + **Web Push** (background web) + **APNs/FCM** (native). **SSE vietato** su shared FPM (esaurisce il pool → 500 globale).
- **Catch-up:** cursore `last_event_id` su inbox durevole **`user_events`** (migr. **0019**); il realtime è ottimizzazione, mai fonte di verità; trasporto giù → degrado automatico a polling; pruning 30gg con catch-up "cold" oltre finestra.
- **Sicurezza:** canali privati firmati (`/stream/auth`), authz al livello dati riusando i Service, payload senza PII (id + preview troncata), rate-limit per-utente. Nav helper intatti.
- **DDL nuovo:** `user_events` (0019) + `push_devices` (0020). Zero demoni anche in Phase 2 (PHP resta publisher).
- **Costo Phase 2:** provider ~free-tier→€29-49/mese; Web Push/APNs/FCM €0 per-messaggio; ops = rotazione chiavi + drain publish via cron (no demone).
