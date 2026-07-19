# Async Consolidation — SPEC (checkpoint-2)

**Autore:** Backend/Frontend Architect · **Stato:** proposta (no production code)
**Obiettivo del fondatore:** eliminare l'AJAX ad-hoc per-feature. Un solo contratto JSON server-side per le scritture web + un solo client async centralizzato lato frontend. Consolidamento una-tantum: ogni azione mutante attuale e futura diventa async, uniforme e sicura, senza regressioni.

> Principio guida invariato: **progressive enhancement**. I form funzionano già senza JS (redirect + flash). Il client si sovrappone; il server risponde JSON **solo** quando la richiesta è async. Nessun downgrade di sicurezza: CSRF resta obbligatorio su ogni scrittura web, l'API resta solo-Bearer.

---

## 0. Stato attuale accertato (letto, non assunto)

Infrastruttura già presente e da **riusare** (non reinventare):

| Componente | File | Fatto rilevante |
|---|---|---|
| Segnale async | `src/Core/Request.php` → `wantsJson()` | `isApi() || Accept contiene application/json`. **Già usato** in 6 controller. |
| Envelope JSON | `src/Core/Response.php` | `json($data,$status,$meta)` → `{data,meta}`; `error($title,$status,...)` → `{errors:[...]}`. |
| Esito dominio | `src/Core/ServiceResult.php` | `ok/noContent/fail/fromValidator`, campi `ok,data,error,errors,code,meta`. |
| CSRF web + AJAX | `src/Core/Csrf.php` | Accetta campo `_csrf` **o** header `X-CSRF-Token`; su `wantsJson()` risponde **419 JSON**. Già pronto per l'async. |
| Client fetch | `public/assets/js/app.js` → `Spoome.api()` | Manda `Accept: application/json`, `X-CSRF-Token` (via `csrf:true`), legge `meta[name=csrf-token]` e `meta[name=base-path]`, parsa envelope, lancia `Error` con `.status/.fields/.payload`. |
| Toast | `app.js` → `Spoome.toast(msg,type)` | `type: success|error|info`. Riusare per gli errori (giallo/rosso; **mai verde**). |
| Base controller | `src/Http/Controllers/Controller.php` | Oggi solo `title()`. **È qui che va il responder unico.** |

**Duplicazione da rimuovere:** l'identico blocco `if wantsJson { ok→json / else→error } else { flash; redirect }` è copiato in `FeedController::respond()`, `FollowController::json()`, `ConnectionController::act()`, `SkillController::json()`, `NetworkController::dismissSuggestion()`. Va collassato in **un** helper nel base `Controller`.

---

## 1. Inventario delle azioni mutanti web

Scope: rotte **web** POST/PUT/PATCH/DELETE. L'area `/admin/...` è scope-flag (step-up; vedi §1.3). Il blocco `/api/...` (solo-Bearer) **non è più fuori scope**: è il secondo consumatore di prima classe della stessa logica ed è trattato integralmente nella nuova **§5 API-first / multi-client** (parity matrix, auth, versioning, contratto). La colonna "Target `data`" qui sotto è **condivisa** tra web-async e API: stesso Service, stesso envelope.

### 1.1 In-scope — azioni applicative (31)

Legenda risposta attuale: **JSON** = già risponde envelope via `wantsJson`; **JSON*** = risponde sempre JSON ma bespoke (upload); **302** = solo redirect+flash.

| # | Rotta | Controller@metodo | Cosa fa | Attuale | Async JS oggi | Target `data` |
|---|---|---|---|---|---|---|
| 1 | POST `/feed/post/{id}/like` | FeedController@like | toggle like | **JSON** | sì `[data-like]` | `{liked:bool,count:int}` |
| 2 | POST `/feed/post/{id}/commenta` | FeedController@comment | aggiunge commento | **JSON** | sì `[data-comment]` | `{id:int,count:int}` |
| 3 | POST `/feed/commento/{id}/elimina` | FeedController@deleteComment | elimina commento | **JSON** | no | `{post_id:int}` |
| 4 | POST `/atleti/{h}/segui` | FollowController@follow | segui | **JSON** | sì `[data-follow]` | `{following,followers_count,following_count}` |
| 5 | POST `/atleti/{h}/nonseguire` | FollowController@unfollow | smetti | **JSON** | sì `[data-follow]` | idem |
| 6 | POST `/atleti/{h}/connetti` | ConnectionController@connect | richiedi/accetta | **JSON** | sì `[data-suggest-connect]` (solo da Rete) | `{status,connections_count}` |
| 7 | POST `/atleti/{h}/disconnetti` | ConnectionController@disconnect | annulla/rimuovi | **JSON** | no | idem |
| 8 | POST `/atleti/{h}/competenze/{id}/endorsa` | SkillController@endorse | endorsa | **JSON** | sì `[data-endorse]` | `{endorsed:true,count:int}` |
| 9 | POST `/atleti/{h}/competenze/{id}/rimuovi` | SkillController@removeEndorse | rimuovi endorse | **JSON** | sì `[data-endorse]` | `{endorsed:false,count:int}` |
| 10 | POST `/rete/suggerimenti/{h}/ignora` | NetworkController@dismissSuggestion | ignora suggerito | **JSON** | sì `[data-suggest-dismiss]` | `{dismissed:true,handle}` |
| 11 | POST `/profilo/competenze/riordina` | SkillController@reorder | riordina skill | **JSON** | no (bespoke drag?) | `{ok:true}` |
| 12 | POST `/profilo/avatar` | AvatarController@upload | upload avatar | **JSON*** | sì (cropper.js) | `{image_url}` |
| 13 | POST `/profilo/avatar/elimina` | AvatarController@delete | rimuove avatar | **JSON*** | sì | `{...}` |
| 14 | POST `/profilo/cover` | AvatarController@uploadCover | upload cover | **JSON*** | sì | `{image_url}` |
| 15 | POST `/profilo/cover/elimina` | AvatarController@deleteCover | rimuove cover | **JSON*** | sì | `{...}` |
| 16 | POST `/profilo/competenze` | SkillController@add | aggiunge skill | **302** | no | `{id:int}` (201) |
| 17 | POST `/profilo/competenze/{id}/elimina` | SkillController@delete | elimina skill | **302** | no | `{ok:true}` (204) |
| 18 | POST `/profilo` | MyProfileController@update | salva profilo | **302** (re-render su errore) | no | `{saved:true}` |
| 19 | POST `/profilo/esperienze` | ProfileDetailsController@addExperience | crea esperienza | **302** | no | `{id}` (201) |
| 20 | POST `/profilo/esperienze/{id}` | @updateExperience | modifica | **302** | no | `{ok:true}` |
| 21 | POST `/profilo/esperienze/{id}/elimina` | @deleteExperience | elimina | **302** | no | `{ok:true}` (204) |
| 22 | POST `/profilo/palmares` | @addAchievement | crea palmarès | **302** | no | `{id}` (201) |
| 23 | POST `/profilo/palmares/{id}` | @updateAchievement | modifica | **302** | no | `{ok:true}` |
| 24 | POST `/profilo/palmares/{id}/elimina` | @deleteAchievement | elimina | **302** | no | `{ok:true}` (204) |
| 25 | POST `/profilo/link` | @addLink | crea link | **302** | no | `{id}` (201) |
| 26 | POST `/profilo/link/{id}` | @updateLink | modifica | **302** | no | `{ok:true}` |
| 27 | POST `/profilo/link/{id}/elimina` | @deleteLink | elimina | **302** | no | `{ok:true}` (204) |
| 28 | POST `/atleti/{h}/rivendica` | ClaimController@request | invia rivendicazione | **302** | no | `{requested:true}` |
| 29 | POST `/feed/post` | FeedController@createPost | crea post | **302** | no | `{id, html?}` |
| 30 | POST `/feed/post/{id}/elimina` | FeedController@deletePost | elimina post | **302** | no | `{ok:true}` (204) |
| 31 | POST `/messaggi/{h}` | MessagesController@send | invia DM | **302** | no (solo `poll` GET è JSON) | `{id, conversation_id}` (201) |

**Bilancio:** 31 azioni in-scope → **15 già JSON** (11 via `wantsJson` #1‑11 + 4 upload bespoke #12‑15) · **16 da migrare** al responder (#16‑31).

### 1.2 Fuori scope — flussi auth/sessione (6): restano native (302)

`POST /accedi`, `/registrati`, `/registrati/rivendica`, `/esci`, `/recupera-password`, `/reimposta`. Guest-flow con effetti su sessione/redirect intenzionale (login imposta sessione e naviga; reset cambia stato e reindirizza). Beneficio async nullo, rischio > valore. **Lasciare come sono** — nessun `data-async`. Nota: possono comunque riusare il loading-state generico del client (§B).

### 1.3 Fuori scope — area admin (12 POST, scope-flag)

`/admin/utenti/{id}/*` (5), `/admin/rivendicazioni/*` (3), `/admin/contenuti/{id}/elimina`, `/admin/verifica`, `/admin/rivendicazioni/nuovo`. Tutte dietro `AdminMiddleware` (404-cloak) + `StepUpMiddleware` + CSRF. Il responder unico è **applicabile** anche qui in futuro, ma **non** in questa consolidazione: l'area admin è a basso traffico e ad alta sensibilità; migrarla non ripaga il rischio ora. Restano 302. *(Flag: candidate a un secondo giro.)*

### 1.4 Nota — "notifications mark-read"

Nel brief è citata ma **non esiste** come azione mutante: `NotificationController@index` (GET `/notifiche`) chiama `markAllRead()` al render. Il contatore `notif_unread` in nav si azzera solo al prossimo caricamento pagina. **Azione futura suggerita** (non in questo scope): `POST /notifiche/lette` → responder `{unread:0}` + `data-async-success="updateCount"` sul badge nav. Documentata qui perché il client la supporterà senza codice nuovo.

---

## 2. Parte A — Contratto JSON uniforme lato server

### 2.1 Segnale async unico: `Accept: application/json`

**Scelta:** `Request::wantsJson()` (che testa `Accept: application/json`). Motivazioni:

1. **È già il segnale in produzione** in 6 controller e in `Csrf::verify` (419 JSON). Zero divergenza.
2. `Spoome.api()` invia già `Accept: application/json` su ogni chiamata.
3. Semanticamente corretto (content negotiation), indipendente da framework JS.

`X-Requested-With` è **scartato**: non è usato da nessuna parte nel codice attuale e introdurrebbe un secondo segnale da mantenere allineato. Un solo segnale, quello già vivo.

### 2.2 Responder unico nel base `Controller`

Tutta la logica di ramo (JSON vs redirect) vive in **un** metodo, ereditato da ogni controller web.

```php
// src/Http/Controllers/Controller.php
use Spoome\Core\{Request, Response, ServiceResult, Session, I18n};

/**
 * Traduce un ServiceResult in risposta HTTP.
 *  - Richiesta async (Accept: application/json) → envelope { data | errors } con status corretto.
 *  - Richiesta classica → flash (errore, o messaggio di successo esplicito) + redirect.
 *
 * @param string      $redirect  path relativo per il fallback no-JS (es. 'feed', 'profilo#link')
 * @param string|null $flashOk   messaggio di successo per il flash (solo ramo no-JS); null = nessun flash su ok
 */
protected function respond(
    Request $request,
    ServiceResult $result,
    string $redirect,
    ?string $flashOk = null
): void {
    if ($request->wantsJson()) {
        if ($result->ok) {
            // 204 → nessun corpo; altrimenti envelope {data} con lo status suggerito dal Service.
            if ($result->code === 204) { Response::noContent(); return; }
            Response::json($result->data, $result->code >= 200 && $result->code < 300 ? $result->code : 200, $result->meta);
        } else {
            Response::error(
                $result->error ?? I18n::t('api.error.invalid_data'),
                $result->code >= 400 ? $result->code : 422,
                null,
                $result->errors ? ['fields' => $result->errors] : []
            );
        }
        return;
    }
    // Ramo classico (progressive enhancement / JS off)
    if (!$result->ok) {
        Session::flash($result->error ?? I18n::t('api.error.invalid_data'), 'error');
    } elseif ($flashOk !== null) {
        Session::flash($flashOk, 'success');
    }
    Response::redirect($redirect);
}
```

**Mapping `ServiceResult` → HTTP:**

| ServiceResult | Async (JSON) | No-JS |
|---|---|---|
| `ok(data, code=200)` | `200 {data}` | `302` + flash `$flashOk` (se dato) |
| `ok(data, code=201)` | `201 {data}` | idem |
| `noContent()` (`code=204`) | `204` (no body) | idem |
| `fail(error, 422, errors)` | `422 {errors:[{title,fields}]}` | `302` + flash error |
| `fail(error, 403)` | `403 {errors}` | `302` + flash error |
| `fail(error, 429)` | `429 {errors}` | `302` + flash error |
| `fail(error, 404)` | `404 {errors}` | `302` (redirect di ripiego) |

> `fields` viene aggiunto all'oggetto errore così `Spoome.api()` lo espone già come `err.fields` (già letto dal client): niente lavoro extra per la validazione per-campo dei form.

**Casi "entità non trovata" (actor/target null)** — oggi ogni controller duplica un mini-blocco `if wantsJson error(404) else redirect`. Semplificazione: fornire un secondo micro-helper

```php
protected function notFound(Request $request, string $redirect, string $key = 'atleti.show.not_found_title'): void
{
    $this->respond($request, ServiceResult::fail(I18n::t($key), 404), $redirect);
}
```

### 2.3 Azioni da instradare nel responder + `data` atteso

Ogni metodo diventa: risolvi contesto → chiama Service → `return $this->respond($request, $res, '<redirect>', '<flashOk?>')`.

| Azione | `data` (ok) | flashOk (no-JS) |
|---|---|---|
| like #1 | `{liked,count}` | — |
| comment #2 | `{id,count}` | — |
| deleteComment #3 | `{post_id}` | — |
| follow/unfollow #4‑5 | `{following,followers_count,following_count}` | — |
| connect #6 | `{status:'pending'|'connected',connections_count}` | `connect.flash.*` |
| disconnect #7 | `{status,connections_count}` | — |
| endorse/removeEndorse #8‑9 | `{endorsed:bool,count}` | — |
| dismissSuggestion #10 | `{dismissed:true,handle}` | `suggest.flash.dismissed` |
| skill reorder #11 | `{ok:true}` | — |
| skill add #16 | `{id}` (201) | `profile.details.added` |
| skill delete #17 | `{ok:true}` (204) | `profile.details.removed` |
| profilo update #18 | `{saved:true}` | `profile.flash.saved` |
| esperienze/palmares/link CRUD #19‑27 | add→`{id}`(201) · update→`{ok:true}` · delete→`{ok:true}`(204) | `profile.details.added/updated/removed` |
| claim request #28 | `{requested:true}` (usa `res.meta.message`) | `res.meta.message` |
| createPost #29 | `{id, html?}` (vedi §4 rischi) | — |
| deletePost #30 | `{ok:true}` (204) | — |
| DM send #31 | `{id,conversation_id}` (201) | — |

> **#18 profilo update** ha oggi un ramo speciale: su errore **ri-renderizza** il form con i valori inviati. Nel ramo async questo non serve (il client mostra gli errori per-campo da `err.fields` + toast). Nel ramo no-JS **mantenere** il `renderForm(...)` esistente: `respond()` si usa solo per il ramo async — per #18 il controller fa `if ($request->wantsJson()) return $this->respond(...); else { renderForm su errore / redirect su ok }`. È l'unica azione con ramo no-JS non banale; documentato come eccezione consentita.

### 2.4 Sicurezza — nessun downgrade, gap rilevati

- **CSRF:** invariato e obbligatorio su tutte le 31 azioni (già `$csrf` in `routes.php`). Async → header `X-CSRF-Token`; form → hidden `_csrf`. `Csrf::isValid` accetta entrambi; su fallimento async → **419 JSON** (già implementato). **Nessuna azione web è priva di CSRF.**
- **API solo-Bearer:** invariato. Il responder web **non** tocca le rotte `/api`.
- **Rate-limiting:** presente nei Service sensibili (like, comment, endorse, connect, DM `dm.error.throttled` 429, avatar upload 429). Il responder propaga il 429 come envelope. **Gap:** verificare che `createPost` (#29), `skill add` (#16) e `claim request` (#28) abbiano throttle nel rispettivo Service; se assente, aggiungerlo (fuori dallo scope frontend ma segnalato).
- **Authz al livello dati:** invariata (ownership imposta nei Service). Il responder non introduce nuovi path privilegiati.

---

## 3. Parte B — Client async centralizzato (`app.js`)

### 3.1 Idea: un solo dispatcher delegato, dichiarativo

Sostituire i **7 blocchi** `querySelectorAll('form[data-*]').forEach(...)` con **un** listener `submit` delegato su `document`, guidato da `data-async` + attributi che dichiarano l'aggiornamento DOM. Nessun JS per-feature: aggiungere una nuova azione async = aggiungere attributi al form nella vista.

```js
document.addEventListener('submit', function (ev) {
  var form = ev.target.closest('form[data-async]');
  if (!form) return;              // form normale → submit nativo (progressive enhancement)
  ev.preventDefault();
  Spoome.submitAsync(form);       // orchestratore centrale
});
```

### 3.2 Vocabolario di attributi (contratto vista ↔ client)

**Sul `<form>`:**

| Attributo | Ruolo |
|---|---|
| `data-async` | attiva l'intercettazione. Usa `method`/`action` nativi del form. |
| `data-async-success="a b c"` | lista **componibile** di effetti (vedi tabella). Applicati in ordine su successo. |
| `data-async-handler="nome"` | **estensione**: invoca `Spoome.handlers['nome'](ctx)` invece/oltre agli effetti standard (hook custom registrato). |
| `data-async-confirm="testo"` | opzionale: `confirm()` prima di inviare (delete distruttivi). |
| `data-target="<sel>"` | elemento bersaglio per effetti che ne hanno bisogno (`removeCard`, `replaceHtml`). Default: il form o il suo `closest('[data-async-card]')`. |
| `data-toggle-action="/url/a|/url/b"` | per `toggleState`: le due action alternate (segui/nonseguire). |

**Effetti `data-async-success` (componibili):**

| Effetto | Attributi di supporto | Comportamento |
|---|---|---|
| `toggleState` | `data-state-key` (es. `following`/`liked`/`endorsed`), `data-toggle-action`, `data-label-on`/`data-label-off` | legge `data[key]`, toggle classi `is-on`/`btn-primary`↔`btn-ghost`, `aria-pressed`, aggiorna label, riscrive `action` per il prossimo submit |
| `updateCount` | `data-count-selector` (sel), `data-count-key` (default `count`) | scrive `data[key]` nel testo del/i nodo/i selezionati |
| `removeCard` | `data-target` (o `[data-async-card]` avo) | animazione collapse (rispetta `prefers-reduced-motion` → rimozione istantanea), poi `.remove()`; se il contenitore resta vuoto, rimuove la sezione |
| `replaceHtml` / `appendHtml` | `data-target`, sorgente `data.html` | sostituisce/appende markup restituito dal server (server-rendered, già `e()`-scaped) |
| `resetForm` | — | `form.reset()` (post/commento inviati) |
| `toast` | `data-toast-ok` | toast di conferma esplicito (raro; niente verde) |
| `reload` | — | `location.reload()` (ripiego per azioni senza effetto DOM dichiarabile) |

**Ciclo di vita comune (sempre, senza doverlo dichiarare):**
1. trova il bottone `[data-submit]`/`button[type=submit]`, aggiunge `is-loading` + `aria-busy=true`, `disabled` durante il volo (anti doppio-invio, come oggi);
2. `Spoome.api(action, {method, csrf:true, body})` — body = `FormData` del form (upload compresi) o `null` per i toggle senza campi;
3. **ok** → applica gli effetti / chiama l'handler; ripristina il bottone;
4. **errore** → `Spoome.toast(err.message, 'error')`; se `err.fields`, marca i campi (`aria-invalid`, `.field-error`); ripristina il bottone;
5. **errore hard di rete** (fetch reject) → fallback: `form.submit()` nativo (l'utente non resta bloccato).

### 3.3 Mappatura azioni → vocabolario (zero JS per-feature)

| Azione | Attributi sul form |
|---|---|
| like #1 | `data-async data-async-success="toggleState updateCount" data-state-key="liked" data-count-selector="[data-like-count]"` |
| follow #4‑5 | `data-async data-async-success="toggleState updateCount" data-state-key="following" data-toggle-action="…/segui|…/nonseguire" data-count-selector="[data-follow-followers]" data-count-key="followers_count"` |
| endorse #8‑9 | `data-async data-async-success="toggleState updateCount" data-state-key="endorsed" data-count-selector=".skill-count"` |
| connect #6 (da Rete) | `data-async data-async-success="toggleState" data-state-key="status"` (→ hook per il "richiesta inviata / collegato", vedi custom) |
| dismiss #10 | `data-async data-async-success="removeCard" data-target="[data-suggest-card]"` |
| comment #2 | `data-async data-async-handler="comment"` (append riga + count + reset — custom, vedi §3.4) |
| deleteComment #3 | `data-async data-async-success="removeCard" data-target="[data-comment-item]"` + `updateCount` |
| deletePost #30 | `data-async data-async-success="removeCard" data-target="[data-post-card]" data-async-confirm="…"` |
| skill add/delete/CRUD dettagli #16‑27 | `data-async data-async-success="reload"` inizialmente (poi `appendHtml`/`removeCard` quando le viste espongono i frammenti — migrazione incrementale) |
| createPost #29 | `data-async data-async-handler="composer"` (custom: prepend card o reload, vedi §4) |
| DM send #31 | `data-async data-async-handler="dm"` (custom: append bolla + reset + scroll, riusa la logica di `poll`) |

### 3.4 Estensione: hook custom (centralizzati ma flessibili)

3 azioni hanno logica DOM non riducibile agli effetti standard → **registrate** in `Spoome.handlers`, non sparse:

```js
Spoome.handlers = {
  comment: function (ctx) { /* append <li> commento + updateCount + reset input */ },
  dm:      function (ctx) { /* append bolla msg-me + set data-last-id + scroll bottom (condivide render con il poller) */ },
  composer:function (ctx) { /* prepend card post se ctx.data.html, else reload */ }
};
```

`ctx = { form, data, button, response }`. Il dispatcher chiama l'handler **dopo** gli effetti standard eventualmente presenti. Nuove azioni "difficili" future = nuova chiave qui, un solo posto.

> **DM e poller condividono un `renderMessage(m)`**: refactor del poller esistente (§0) per riusarlo, così invio e polling producono bolle identiche e non si duplicano nodi (dedup su `data-mid`, già presente).

### 3.5 Accessibilità

- I bottoni restano `button[type=submit]` reali dentro `<form>` (submit nativo se JS off).
- Loading: `aria-busy=true` + `disabled` durante il volo; ripristino garantito in `finally`.
- Toggle: `aria-pressed` aggiornato; label aggiornata via `aria-label`/testo.
- `removeCard`: **rispetta `prefers-reduced-motion`** (niente animazione → rimozione immediata); spostare il focus a un elemento vivo vicino (es. header della sezione) prima di rimuovere la card per non perdere il focus nel vuoto.
- Errori per-campo: `aria-invalid=true` + messaggio in un nodo `role="alert"` associato.

### 3.6 Dimensione netta e codice eliminato

- **Eliminati:** 7 blocchi bespoke (`data-follow`, `data-like`, `data-comment`, `data-endorse`, `data-suggest-connect`, `data-suggest-dismiss`) + la whitelist nel loading-state generico (righe ~112‑311 di `app.js`, ~200 righe).
- **Aggiunti:** dispatcher + tabella effetti + 3 handler ≈ 180‑220 righe.
- **Netto:** sostanzialmente invariato in byte (~pari), ma **una** superficie invece di sette, e ogni azione **futura** costa 0 righe JS (solo attributi in vista). Zero dipendenze (vanilla), stile e API (`Spoome.api`/`Spoome.toast`) invariati.

---

## 4. Parte C — Piano di migrazione (rischio crescente)

Progressive enhancement = i form **funzionano già** senza JS. Migriamo il responder e gli attributi **azione per azione**; in ogni momento un'azione non-ancora-migrata continua a fare redirect+flash. Nessun big-bang.

**Fase 0 — Fondamenta (nessun cambiamento visibile).**
1. Aggiungere `Controller::respond()` + `notFound()` (§2.2).
2. Rifattorizzare i 5 controller già-JSON (#1‑11) per usare `respond()` al posto dei loro helper privati duplicati. Comportamento identico → deploy sicuro, verifica con `curl -H 'Accept: application/json'`.
3. Introdurre il dispatcher `data-async` in `app.js` **accanto** ai vecchi handler, ma **non** ancora referenziato dalle viste. Deploy.

**Fase 1 — Portare le azioni già-async sul nuovo client (basso rischio).**
Migrare le viste di like/follow/endorse/dismiss dai `data-*` bespoke agli attributi `data-async ...`; **poi** rimuovere il blocco bespoke corrispondente in `app.js`. Una feature alla volta, verificando in beta. Sono già JSON lato server → cambia solo il lato client.

**Fase 2 — Migrare le azioni redirect-only a basso rischio (#16‑17, #19‑27, #28).**
Skill add/delete, dettagli profilo CRUD, claim request: instradare nel `respond()`, aggiungere `data-async` con `reload`/`removeCard`. Idempotenti o poco distruttive. Verifica: azione + assenza JS (form deve ancora redirigere).

**Fase 3 — Azioni ad alto rischio (ultime, con verifica dedicata).**
- **DM send (#31):** più rischiosa — interazione con il **poller** (rischio doppioni/ordine). Mitigazione: handler `dm` che condivide `renderMessage` + dedup `data-mid` già esistente; test: invio rapido multiplo + thread aperto in due tab; verificare che il messaggio inviato non venga ri-appeso dal poll.
- **comment (#2) / deleteComment (#3):** manipolano liste e contatori vivi. Mitigazione: handler `comment` + `removeCard`+`updateCount`; test: commenta, elimina, ricarica → conteggi coerenti col server.
- **createPost (#29):** decidere `html` server-rendered vs `reload`. Rischio XSS se si inietta markup: il frammento **deve** essere prodotto server-side con `e()` (stesso partial della timeline), mai costruito da campi grezzi lato client. Se non pronto, usare `reload` (sicuro) e rimandare il prepend.

**Regressioni da presidiare (non negoziabile #2 del CLAUDE.md):** gli helper nav (`dm_unread/notif_unread/is_admin`) girano su ogni pagina — il refactor del responder **non** li tocca, ma verificarne il render dopo ogni deploy (una `respond()` che lancia un `Throwable` manderebbe 500 ovunque). `respond()` non deve mai eccepire: tutti i rami terminano in `Response::*`.

**CDN SiteGround:** `assets/*` è cache-ato dalla CDN. `app.js` è incluso con `?v=<hash>` (versioning). **Ad ogni deploy di `app.js` bumpare `?v=`** e verificare che il browser scarichi la nuova versione (controllare l'URL versionato in `curl`/devtools), altrimenti la beta gira con JS vecchio a fronte di un server nuovo. Verifica end-to-end **dal vivo** ad ogni modifica atomica (§workflow).

---

## 5. API-first / multi-client (backend condiviso: web + iOS/Android)

> **Principio architetturale:** il backend è **API-first / multi-client**. La logica di business vive **una sola volta** nei Service (`Controller → Service(ServiceResult) → Repository`) ed è consumata da **più client** che condividono lo stesso envelope `{data,meta}`/`{errors}`: (1) il **web frontend** via responder async + sessione/CSRF, (2) le **future app native iOS/Android** via Bearer API versionata. Il responder web (§2) e l'`ApiController` sono **due adattatori sottili sullo stesso Service layer** — non due implementazioni.

### 5.0 Conferma: gli adattatori esistono già e sono gemelli

`ApiController::respond(ServiceResult)` (`src/Http/Controllers/ApiController.php`) implementa **esattamente** la stessa mappatura `ServiceResult → HTTP` proposta per il web in §2.2 (ok→2xx `{data,meta}`, 204 senza corpo, fail→`{errors:[{status,title,detail,fields}]}`). Quindi il lavoro server di §2 **non aggiunge un secondo contratto**: replica sul lato web l'adattatore che l'API ha già. Un solo envelope, due porte d'ingresso. `ApiController` espone anche `requireBearerUser()` (scritture, solo-Bearer) e `requireUser()` (letture, sessione **o** Bearer).

### 5.1 Parity matrix — copertura Bearer API per azione

Target: **ogni** azione mutante raggiungibile dalla Bearer API versionata con envelope identico, così una app nativa ha **copertura funzionale piena**, non "solo il web può farlo".

| Azione (web) | Endpoint Bearer oggi | Parità | Service condiviso |
|---|---|---|---|
| like #1 | `POST /api/v1/posts/{id}/like` | ✅ | `PostEngagementService::toggleLike` |
| comment #2 | `POST /api/v1/posts/{id}/comments` | ✅ | `PostEngagementService::comment` |
| deleteComment #3 | `DELETE /api/v1/comments/{id}` | ✅ | `PostEngagementService::deleteComment` |
| follow/unfollow #4‑5 | `POST`/`DELETE /api/v1/profiles/{h}/follow` | ✅ | `FollowService::follow/unfollow` |
| connect/disconnect #6‑7 | `POST`/`DELETE /api/v1/profiles/{h}/connection` | ✅ | `ConnectionService::connect/disconnect` |
| profilo update #18 | `PATCH /api/v1/me` | ✅ | `ProfileService::update` |
| esperienze CRUD #19‑21 | `POST`/`PATCH`/`DELETE /api/v1/me/experiences[/{id}]` | ✅ | `ProfileDetailsService` |
| palmarès CRUD #22‑24 | `…/me/achievements[/{id}]` | ✅ | `ProfileDetailsService` |
| link CRUD #25‑27 | `…/me/links[/{id}]` | ✅ | `ProfileDetailsService` |
| createPost #29 | `POST /api/v1/posts` | ✅ | `PostService::create` |
| deletePost #30 | `DELETE /api/v1/posts/{id}` | ✅ | `PostService::delete` |
| DM send #31 | `POST /api/v1/me/conversations/{h}` | ✅ | `MessageService::send` |
| **endorse/removeEndorse #8‑9** | — | ❌ **gap** | `SkillService::endorse/removeEndorsement` |
| **skill add #16** | — | ❌ **gap** | `SkillService::addSkill` |
| **skill delete #17** | — | ❌ **gap** | `SkillService::removeSkill` |
| **skill reorder #11** | — | ❌ **gap** | `SkillService::reorder` |
| **dismissSuggestion #10** | — | ❌ **gap** | `ConnectionSuggestionService::dismiss` |
| **claim request #28** | — | ❌ **gap** | `ClaimService::request` |
| **avatar upload/delete #12‑13** | — | ❌ **gap** | (media service; upload multipart) |
| **cover upload/delete #14‑15** | — | ❌ **gap** | (media service; upload multipart) |

**Bilancio parità:** su 31 azioni, **20 hanno già parità Bearer**, **11 sono gap**. I gap si concentrano in 4 aree: **skills** (own CRUD + endorsement), **suggerimenti** (dismiss), **rivendicazioni** (request), **media** (avatar/cover). *La logica esiste già nei Service* — il gap è solo il "guscio" API mancante.

**Rotte da aggiungere per la parità piena** (thin controllers sopra i Service esistenti, `requireBearerUser`):

| Nuova rotta Bearer | → Service |
|---|---|
| `POST /api/v1/me/skills` · `DELETE /api/v1/me/skills/{id}` · `PATCH /api/v1/me/skills/order` | `SkillService::addSkill/removeSkill/reorder` |
| `POST`/`DELETE /api/v1/profiles/{h}/skills/{id}/endorsement` | `SkillService::endorse/removeEndorsement` |
| `DELETE /api/v1/me/suggestions/{h}` | `ConnectionSuggestionService::dismiss` |
| `POST /api/v1/profiles/{h}/claim` | `ClaimService::request` |
| `POST`/`DELETE /api/v1/me/avatar` · `POST`/`DELETE /api/v1/me/cover` | media service (multipart) |

> Aggiungerle è a basso rischio: sono adattatori di 2‑3 righe su Service già testati dal web. La media API va progettata per upload multipart Bearer (il web usa cropper.js→base64; l'app nativa manderà `multipart/form-data`).

### 5.2 Modello di autenticazione per client

| Client | Autenticazione | Anti-CSRF | Risoluzione utente |
|---|---|---|---|
| **Web** | cookie di sessione | **CSRF obbligatorio** (`_csrf`/`X-CSRF-Token`) | `CurrentUser::resolve` (sessione, poi Bearer) |
| **iOS / Android (nativo)** | **Bearer token** (header `Authorization`) | **nessun CSRF** (non cookie-based; niente ambient authority) | `CurrentUser::fromBearer` (solo token) |

Entrambi **già esistono** e sono la regola vigente: le **scritture API usano `requireBearerUser()`** (solo Bearer, mai il cookie di sessione) — questo elimina alla radice la CSRF cross-site sull'API; le **scritture web** restano dietro CSRF. Nessun cambiamento, solo formalizzazione.

**Ciclo di vita del token (mobile)** — `TokenService` supporta già l'intero ciclo:

| Fase | Supporto oggi | Note |
|---|---|---|
| **Issue** (login) | ✅ `issue()` → `{access, refresh, expires_in}` | coppia access(1h)+refresh(30gg), TTL configurabili; salva solo l'hash SHA‑256, raw restituito una volta; `device_label` per multi-device |
| **Refresh** | ✅ `refresh()` con **rotation** | valida il refresh, lo **revoca**, emette nuova coppia (rotazione = furto refresh mitigato) |
| **Revoke** (logout) | ✅ `revoke()` | `AuthController::logout` revoca access **e** refresh |
| **Revoke-all** (cambio password) | ✅ `revokeAllForUser()` | `AuthService::resetPassword` lo chiama già → invalida tutte le sessioni token dopo reset |
| `last_used_at` tracking | ✅ | aggiornato a ogni `resolve` (base per audit/device list) |

**Gap del lifecycle (piccoli, per una buona UX mobile):**
- **Nessun endpoint "lista dispositivi / revoca selettiva"**: l'utente non può vedere/deslogare un singolo device. Consigliato `GET /api/v1/me/sessions` + `DELETE /api/v1/me/sessions/{id}` (dati già in `auth_tokens.device_label/last_used_at`).
- **Rotation del refresh senza reuse-detection**: se un vecchio refresh (già ruotato) viene riusato, oggi fallisce silenziosamente; una app nativa robusta beneficia di **reuse-detection** → revoca l'intera catena (segnale di furto). Opzionale, hardening.
- **`ACCESS_TOKEN_TTL`/`REFRESH_TOKEN_TTL`** già configurabili: documentarli nel contratto mobile.

### 5.3 Versioning dell'API

**Stato:** il prefisso è **già** `/api/v1` (default di `Config::apiPrefix()`, env `API_PREFIX`). Il namespace controller è `Api\V1\`. La base versionata **esiste già** — non serve migrazione.

**Raccomandazione:** *mantenere il prefisso versionato `/api/v1/` come contratto stabile* e trattarlo come **immutabile una volta che un'app nativa è in produzione**:
1. **Mai** introdurre breaking-change dentro `v1` (rinomina campi, cambio tipo, rimozione). Solo aggiunte retro-compatibili (nuovi campi opzionali, nuove rotte).
2. Un breaking-change → **`/api/v2`** con nuovo namespace `Api\V2\` (i Service restano condivisi; cambia solo l'adattatore/presenter). `v1` resta servito finché ci sono app installate.
3. Le app native devono **inviare la versione client** (header `X-Client-Version`/User-Agent) per telemetria e per poter forzare un upgrade minimo lato server.
4. Il web-async **non** vincola il versioning: usa le stesse rotte `/api/v1` o le sue rotte web — ma poiché condivide i Service, la stabilità di `v1` è garantita "gratis".

### 5.4 Principio Shared-Service — verificato

**Verifica effettuata:** i controller web e API **funnellano agli stessi metodi Service**, nessuna logica di business duplicata nei controller.

| Service | Usato da API | Usato da Web |
|---|---|---|
| `PostService`, `PostEngagementService` | `Api\V1\FeedController` | `Web\FeedController` |
| `FollowService` | `Api\V1\ProfileController` | `Web\FollowController` |
| `ConnectionService` | `Api\V1\ProfileController` | `Web\ConnectionController` |
| `ProfileService`, `ProfileDetailsService` | `Api\V1\MeController` | `Web\MyProfileController`, `Web\ProfileDetailsController` |
| `MessageService`, `ConversationService` | `Api\V1\MessagesController` | `Web\MessagesController` |
| `SkillService`, `ConnectionSuggestionService`, `ClaimService` | *(nessun controller API — vedi gap §5.1)* | `Web\SkillController`, `Web\NetworkController`, `Web\ClaimController` |

**Esito:** **tutta** la logica delle azioni mutanti è già in un Service. **Nessuna azione web ha logica da estrarre** — i controller sono già adattatori sottili (validazione input + chiamata Service + `respond`). Le 11 azioni in gap (§5.1) non richiedono estrazione, solo un controller API che chiami il Service esistente. *(Unica sfumatura: gli upload avatar/cover hanno logica di gestione file nell'`AvatarController` web; per la parità nativa quella andrebbe spostata in un `MediaService`/Service dedicato così l'API multipart la riusa — è l'unico frammento non ancora in un Service.)*

### 5.5 Stabilità del contratto per il mobile

Un client nativo compila contro un contratto fisso: va reso esplicito e invariante in `v1`.

- **Naming campi:** `snake_case` in JSON (già così: `followers_count`, `conversation_id`, `has_more`), inglese, coerente con le colonne DB. Vietato rinominare in `v1`.
- **Envelope:** successo `{data, meta?}`; errore `{errors:[{status,title,detail,fields?}]}`. `fields` = mappa `campo→messaggio` per la validazione per-campo (usata sia dal web che dal mobile).
- **Error codes:** usare gli **HTTP status** come codice primario (200/201/204/401/403/404/419/422/429) — già coerenti tra `ApiController` e il responder web. 419 = CSRF (solo web). *Raccomandazione:* aggiungere un `code` **stringa applicativa** stabile nell'oggetto errore (es. `not_connected`, `throttled`, `handle_taken`) così il mobile non fa string-matching sul `title` localizzato. *(Oggi il `title` è già localizzato: il mobile non deve dipendervi per la logica.)*
- **Paginazione:** shape unica in `meta`: `{page, per_page, has_more}` (già emesso da `Api\V1\FeedController`). Adottarla **identica** in tutte le liste (profiles, followers, conversations). Il web-async che carica liste usa lo stesso `meta`.
- **Date/orari:** esporre **ISO‑8601 UTC** (`2026-07-04T12:30:00Z`) in ogni payload API, mai stringhe pre-localizzate lato server (la localizzazione è responsabilità del client). *Verificare/normalizzare i presenter:* le date devono uscire in ISO, non nel formato display italiano.
- **Localizzazione:** i messaggi `title`/`error` sono localizzati server-side via `I18n`; il mobile può passare `Accept-Language` (futuro) ma **non** deve fondare logica sul testo — solo su status + `code`.

---

## 6. Rischi principali (sintesi)

| Rischio | Impatto | Mitigazione |
|---|---|---|
| DM async duplica messaggi col poller | Confusione UX | `renderMessage` condiviso + dedup `data-mid`; test due-tab |
| createPost inietta markup non-scaped | **XSS** | frammento server-rendered con `e()`, o `reload` |
| `respond()` eccepisce → 500 su ogni pagina (nav helpers) | Outage beta | tutti i rami chiudono in `Response::*`; nessuna eccezione; test post-deploy della nav |
| CDN serve `app.js` vecchio | Server nuovo + client vecchio = form rotti | bump `?v=`, verifica URL versionato |
| Perdita fallback no-JS durante la migrazione | Regressione accessibilità | il server tiene **sempre** il ramo redirect; il client fa fallback a `form.submit()` su errore hard |
| Validazione per-campo persa nel form profilo (#18) | UX inferiore | `err.fields` già esposto da `Spoome.api`; ramo no-JS mantiene `renderForm` |

---

## 7. Riepilogo esecutivo

- **31** azioni mutanti web in-scope: **15 già JSON** (11 via `wantsJson` + 4 upload), **16 da migrare**. +6 auth e +12 admin fuori scope.
- **Segnale async unico:** `Accept: application/json` (`Request::wantsJson()`) — già usato in 6 controller, in `Csrf::verify`, e mandato da `Spoome.api()`.
- **Server:** un solo `Controller::respond(Request, ServiceResult, string $redirect, ?string $flashOk)` + `notFound()`; collassa 5 helper duplicati; mapping `ServiceResult.code → HTTP` **identico** all'`ApiController` già esistente.
- **Client:** un dispatcher `submit` delegato su `document` guidato da `data-async` + `data-async-success` componibili + hook `data-async-handler`; nuove azioni = solo attributi in vista. Hook custom: `comment`, `dm`, `composer`.
- **API-first / multi-client:** backend a Service unico servito da 2 adattatori (responder web + `ApiController`). Parità Bearer: **20/31 azioni già coperte, 11 gap** (skills, dismiss, claim, media) — logica già nei Service, manca solo il guscio API. **Nessuna logica web da estrarre** (solo gli upload avatar/cover andrebbero in un `MediaService`). Auth: web=sessione+CSRF, nativo=Bearer senza CSRF (entrambi vivi); `TokenService` copre issue/refresh-rotation/revoke/revoke-all. **Versioning:** `/api/v1` **già in essere** → mantenerlo immutabile, breaking-change solo in `/api/v2` con Service condivisi.
- **Migrazione:** Fase 0 fondamenta → Fase 1 azioni già-async → Fase 2 redirect-only semplici → Fase 3 DM/comment/createPost con verifica dedicata. Sempre progressive enhancement, mai big-bang. Chiusura gap API = fase parallela a basso rischio (adattatori 2‑3 righe).
