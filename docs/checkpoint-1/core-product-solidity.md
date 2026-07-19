# Core Product Solidity — backlog verso "solido & lanciabile"

> **Obiettivo**: rendere il prodotto core "il LinkedIn dello sport" (profili, directory/ricerca, follow, connessioni, feed, DM, notifiche, claim/verifica, admin) **solido e lanciabile**.
> **Fuori scope** (deciso, rimandato): marketplace / Opportunities / Billing / monetizzazione. I competitor sono solo riferimento.
> **Metodo**: valutazione end-to-end dell'esperienza reale (viste + controller + service). Ogni voce ha: cosa manca, dove (`file:riga`/schermo), perché conta per la *solidità*, priorità (P0 blocca il lancio · P1 · P2), effort (S/M/L).
> **Data**: checkpoint-1, 2026-07-04.

---

## Sintesi del giudizio

Le **fondamenta tecniche sono forti**: auth atomica + anti-enumeration + throttling, gating DM solo-tra-connessi (doppio livello), moderazione claim con anti-race/audit/notifica, SEO baseline (JSON-LD + OG/Twitter + sitemap env-aware), FULLTEXT reale, 404/500 stilizzati, flash system, empty-state presenti in molte pagine. Il design system è mobile-first nei fondamentali (viewport, token, safe-area, reduced-motion, target 44/48px).

Ciò che **impedisce di dire "core solido"** non è il backend: è il **primo miglio del prodotto**. Un utente appena registrato atterra sulla home marketing, ha un feed vuoto senza suggerimenti, un profilo grezzo senza guida, una nav che su telefono **trabocca**, notifiche che si accendono **solo** per i claim (non per follow/connessioni/DM), e un badge "verificato" che **nessuna riga di codice scrive**. In breve: le parti "vive" del network non si accendono, e i segnali di fiducia — il cuore della proposta di valore — sono spenti.

---

## P0 — Bloccano il lancio

Senza questi, il core appare rotto, morto o non credibile alla prima sessione.

### P0.1 — L'utente autenticato atterra sulla home marketing (nessun ingresso nell'app) · **S**
`config/routes.php:52-59`: la rotta `/` rende **sempre** `home.php` (hero + CTA "Registrati/Accedi"), senza ramo per l'utente loggato. Dopo `verifyEmail` (`AuthController.php:165` → `redirect('')`) l'utente verificato — momento di massima intenzione — rivede la brochure con CTA che ha già completato. **Perché conta**: si spreca il picco di intento; l'app non ha una "porta d'ingresso". **Fix**: se autenticato, `/` reindirizza a `/feed` (o a una schermata welcome). Table-stakes assoluto.

### P0.2 — Notifiche cablate solo ai claim: follow / richiesta connessione / connessione accettata / nuovo DM non generano nulla · **M**
L'infrastruttura è pronta e corretta (`NotificationRepository` con contatore denormalizzato `users.unread_notifications`, badge campanella in nav `base.php:61-62`), ma **l'unico** `->create()` del progetto è in `ClaimService.php:143` (solo `claim_approved`/`claim_rejected`). Follow (`FollowService.php:49`), richiesta connessione (`ConnectionService.php:52`), accettazione (`ConnectionService.php:58`) e nuovo messaggio (`MessageService.php:56-61`) scrivono solo su feed/DB, **mai** una notifica. La mappa icone `notifiche/index.php:5-8` conosce solo i due tipi claim. **Perché conta**: senza notifiche su questi eventi **manca l'intero loop di re-engagement** — una richiesta di connessione o un DM sono scopribili solo visitando proattivamente `/rete` o `/messaggi`. È la differenza tra un'app viva e una morta. **Fix**: ~5 tipi nuovi, una `->create()` per evento + estendere la icon-map. (Plumbing già c'è → M, non L.)

### P0.3 — La nav principale trabocca su mobile (nessun bottom-nav, nessun hamburger) · **M**
`app.css:92` `.site-nav` è una singola riga flex **non-wrap** con 7+ voci (Atleti, Feed, Rete, Messaggi+badge, campanella+badge, Profilo, admin, Logout) e **zero media query** su `.site-header`/`.site-nav` (`base.php:54-70`). Su 360–390px la riga si schiaccia/trabocca in orizzontale. **Perché conta**: in un prodotto *mobile-first e API-first pensato per app native*, la navigazione primaria è il componente **meno** pronto al mobile — è il difetto più visibile dopo le notifiche. **Fix**: bottom-nav fisso per le 4–5 destinazioni core (o hamburger/overflow). Da abbinare a P0.7 (search nella nav).

### P0.4 — Badge "verificato" = primitiva di fiducia morta · **M**
Il badge rende in `atleti/show.php:56-58` e `profile-card.php:20`, gated su `verified_at`. La colonna esiste (`0001_create_core_tables.php:118`) **ma nessuna riga scrive mai `profiles.verified_at`**: nessuna azione admin, nessun service, nessuna rotta (esiste `/admin/utenti/{id}/verifica` ma è per l'**email**). Il badge può comparire solo via UPDATE SQL manuale. **Perché conta**: la fiducia è il cuore della proposta di valore; spedire il badge senza workflow di verifica è la lacuna di credibilità più grave (e genera il dubbio "perché nessuno è verificato?"). **Fix minimo lancio**: toggle admin verifica-profilo + audit + notifica (la verifica ancorata a fonti reali resta roadmap moat, fuori scope). **M**.

### P0.5 — Cold-start: feed morto, nessun "chi seguire", sport non chiesto all'iscrizione · **M**
`FeedService::timeline` restituisce solo contenuti di chi già segui/connetti → per un nuovo utente il feed è **garantito vuoto**. Nessun suggested-follow / popular / discover in tutto il codice. Lo sport — chiave di personalizzazione e discovery — non è chiesto al signup (`register.php` chiede il tipo profilo ma **non** lo sport; appare solo, opzionale, sepolto in `profilo/edit.php:132-144`). **Perché conta**: il cold-start è il killer classico del grafo sociale; senza suggerimenti né sport catturato, non c'è motivo per una seconda sessione. **Fix**: catturare lo sport al signup (**S**) + query "profili consigliati" (per sport/regione/popolarità) sull'empty-state del feed e nella welcome (**M**).

### P0.6 — La ricerca ignora la rilevanza (ordina per data) + rischio indice FULLTEXT · **S**
La ricerca profili usa davvero `MATCH … AGAINST(:q IN BOOLEAN MODE)` (`ProfileRepository.php:286-310`), ottimo — **ma poi `ORDER BY p.created_at DESC`** (`ProfileRepository.php:321`) anche quando c'è una query: lo score di rilevanza viene calcolato e **buttato**. Cercare "Rossi" ritorna i profili più *recenti*, non i più *pertinenti* → la ricerca **sembra rotta**. Inoltre tutto dipende dall'indice `ft_profiles_search`: se la migrazione non è applicata nel DB target, ogni query va in errore. **Fix**: `ORDER BY` per score quando c'è ricerca (**S**) + verificare l'indice deployato prima del lancio (**S**, hard-fail).

### P0.7 — Nessuna casella di ricerca globale · **S**
La search esiste **solo** dentro `/atleti` (`atleti/index.php:34-46`); la nav (`base.php:54-75`) non ha alcun input di ricerca. **Perché conta**: per un network il cui valore core è "trovare atleti/società", la scoperta è invisibile da feed, profilo e home. **Fix**: form di ricerca nella nav → `/atleti?q=` (da coordinare con P0.3).

---

## P1 — Necessari per un'esperienza credibile (non bloccano il primo deploy, ma il "solido" li richiede a breve)

### P1.1 — Nessun feedback di completezza profilo / nudge · **M**
Nessun meter "% completo", nessun prompt "aggiungi bio / esperienza", nessuna checklist (grep completeness/percent → solo grafici admin). L'editor mostra sezioni vuote con muted "empty" (`edit.php:184-185, 248-249, 291-292`). **Perché conta**: nulla spinge ad arricchire i profili sparsi → alimenta gli empty-state deboli (P1.4). **Fix**: widget "% completo + prossimo passo" in `MyProfileController` + header editor.

### P1.2 — Like e commenti: inesistenti ovunque · **L**
Nessuna tabella/service/rotta/UI per reazioni o commenti (le rotte feed sono solo `createPost`/`deletePost`; l'unico controllo su un item è il cestino sul proprio post, `feed/index.php:52-57`). **Perché conta**: senza like/commenti postare è **broadcast nel silenzio** — zero feedback loop, il segnale #1 di "feed morto". **Fix**: nuove tabelle + service + rotte + UI + notifiche. (È L ma è ciò che rende il feed vivo.)

### P1.3 — Chat statica: nessun polling · **M**
`messaggi/thread.php` è lista server-rendered + form POST → redirect → reload completo (`MessagesController.php:80-84`). I messaggi in arrivo non compaiono finché non si ricarica. **Perché conta**: una "messaggistica" che non si aggiorna sembra un prototipo. **Fix**: polling JSON del thread (il service già ritorna `messages[]` pulito) + endpoint `/notifiche/unread-count` per aggiornare i badge nav senza reload.

### P1.4 — Profilo sparso sembra "abbandonato", non intenzionale · **S-M**
`atleti/show.php` nasconde ogni sezione vuota (`!empty` alle righe 152, 159, 183, 200): un profilo col solo nome rende come nome + tipo + (forse) sport/luogo + blocco social, senza copy tipo "Questo membro non ha ancora aggiunto esperienze" né CTA owner-aware "completa il profilo". **Perché conta**: il profilo è l'artefatto core del prodotto; una pagina nome-solo mina la credibilità. **Fix**: empty-state pubblici intenzionali + prompt inline solo-owner.

### P1.5 — Claim senza prova d'identità/possesso · **S-M**
Qualsiasi utente loggato senza profilo può richiedere **qualsiasi** profilo unclaimed con messaggio **opzionale e libero** (`atleti/show.php:88-90`; `ClaimRepository::create` accetta null). L'approvazione è pura discrezione admin, **zero prova**: no match dominio email, no documento, no conferma handle social. **Perché conta**: rivendicare un profilo dà controllo di un'identità pubblica — la barra è troppo bassa per un network professionale. **Fix minimo**: messaggio obbligatorio + campo "evidenza/prova" strutturato + checklist admin. (La verifica ancorata a fonti reali = moat, roadmap.)

### P1.6 — Richieste di connessione in uscita invisibili + nessun badge nav Rete · **M/S**
`/rete` interroga solo `incomingRequestsOf` (`NetworkController.php:29`): chi ha **inviato** una richiesta non la vede né la annulla da lì (solo dal profilo target). E `base.php:58` rende "Rete" **senza** conteggio, benché le richieste pendenti siano esattamente lo stato azionabile da badge. **Fix**: sezione "richieste inviate" (**M**) + helper `network_pending()` per il badge (**S**). *Nota*: rifiuto e "rimuovi connessione" collassano sullo stesso endpoint (`ConnectionService::disconnect`) — differenziare copy (**S**).

### P1.7 — Validazione signup lato client assente · **M**
Form con `novalidate` (`register.php:20`): nessuna validazione inline, errori solo server-side, uno alla volta, in cima al form (`AuthController.php:83-90`). Nessun meter forza/match password (policy min 10 + lettere+numeri, `AuthService.php:53-55`). Un mismatch costa un reload completo con re-immissione. **Fix**: validazione di campo + meter forza/match lato client.

### P1.8 — Flash reso solo dove la pagina lo pesca esplicitamente · **S**
`Session::flash` funziona, ma è renderizzato per-pagina (inbox/thread/rete leggono `$notice`); non c'è render nel layout condiviso `base.php`. Ogni controller che flasha e redirige a una pagina che non pesca `$notice` **perde** il messaggio. **Fix**: rendere il flash una volta in `base.php`.

### P1.9 — Nessun filtro luogo nella directory · **M**
`atleti/index.php` filtra per tipo e sport ma **non** per città/regione, benché quelle colonne siano salvate, joinate e mostrate (`ProfileRepository.php:217-220`). Asse di discovery ovvio lasciato sul tavolo. Nota: il FULLTEXT copre nome/headline/bio/handle — **non** sport né luogo (cercare "nuoto" non matcha un nuotatore se non è scritto nella bio).

---

## P2 — Rinforzano il core / crescita (dopo il lancio del core)

- **P2.1 — Pagine `/sport` (tassonomia + landing SEO)** · **M-L**. Nessuna rotta/vista sport; i dati categoria esistono (`atleti/index.php:22-25`) ma non c'è superficie di browse né landing `/sport/nuoto` SEO — leva di crescita primaria per un network sportivo.
- **P2.2 — Allegati media nei post** · **L**. Composer solo-testo (`feed/index.php:15-22`); gli atleti vorranno foto/clip. (Il market-strategy nota "video/highlight" come table-stakes mancante.)
- **P2.3 — Galleria/media sul profilo** · **L**. `media` usata solo per avatar/cover; nessuna sezione gallery/highlight/logo-wall — buco di ricchezza per un profilo pro.
- **P2.4 — Entità `profile_contacts`** · **M**. Non esiste (tabella/UI); "contatto" collassato in un link kind `email` (`ProfileDetailsService.php:199-201`) — nessun telefono/agente/press strutturato.
- **P2.5 — JSON-LD `sameAs` dai social + mapping esperienze/palmarès** · **S-M**. `show.php:11-38` non emette `sameAs` (segnale di reconciliation praticamente gratis coi link già strutturati), né `worksFor`/`alumniOf`/`award`.
- **P2.6 — Immagine OG di default brandizzata** · **M**. Senza cover né avatar, `ogImage` è null → preview scialba, `twitter:card` degrada a `summary` (`base.php:36-40`).
- **P2.7 — Paginazione thread DM** · **S-M**. Il service supporta `page/perPage` (`ConversationService.php:40`) ma il controller usa sempre i default → solo gli ultimi 40 messaggi sono raggiungibili; storia lunga persa silenziosamente.
- **P2.8 — 404 vs 500 distinti** · **S**. Il `message` page usa il layout stretto `auth-card`; nessuna distinzione visiva 404/500.
- **P2.9 — Read notifiche per-item / mark-unread** · **S-M**. `NotificationController.php:22` fa `markAllRead` all'apertura: tutto-o-niente, nessun controllo per-item.
- **P2.10 — Ranking feed & dedup** · **M**. Timeline puramente cronologica senza dedup: un burst di attività "followed" può inondare il feed.
- **P2.11 — Endpoint `/search` unificato (drift architetturale)** · **M**. Il `GET /search?q=&type=&sport=` documentato in ARCHITECTURE non esiste; la search oggi = solo directory `/atleti`, nessuna ricerca cross-tipo. Da allineare doc↔codice (o aliasare, o implementare).
- **P2.12 — Endorsement/raccomandazioni** · **L**. Assenti; dimensione di credibilità tipica di un network professionale (roadmap `⚪` in ARCHITECTURE).

---

## Cosa serve, minimo, per dire che il core è SOLIDO

Il core è "solido & launch-ready" quando **tutti i P0 sono chiusi** e almeno il **nucleo dei P1 di credibilità/vivacità** è coperto:

1. **Ingresso nell'app** — l'utente autenticato non vede mai la brochure; atterra su feed/welcome. *(P0.1)*
2. **Il network si accende** — follow, richiesta/accettazione connessione e nuovo DM generano **notifiche** con badge; il badge Rete conta le richieste pendenti. *(P0.2, P1.6)*
3. **Usabile su telefono** — nav mobile che non trabocca (bottom-nav/hamburger) + ricerca globale nella nav. *(P0.3, P0.7)*
4. **Fiducia viva** — esiste un workflow di verifica profilo che scrive `verified_at` (anche solo toggle admin); i profili unclaimed hanno un marcatore onesto "non rivendicato". *(P0.4, P1.4/P1.5)*
5. **Prima sessione non vuota** — sport catturato al signup + "profili consigliati" sull'empty-state del feed. *(P0.5)*
6. **Ricerca che sembra funzionare** — ordinamento per rilevanza + indice FULLTEXT verificato in produzione. *(P0.6)*
7. **Feedback minimo** — completezza profilo con nudge + flash reso centralmente + empty-state profilo intenzionali. *(P1.1, P1.8, P1.4)*

**Fortemente raccomandato entro il lancio** (rende il feed *vivo* anziché broadcast muto): **like + commenti** *(P1.2)* e **polling DM** *(P1.3)*. Senza almeno le reazioni, il feed resta un muro a senso unico.

**Esplicitamente NON richiesto per il "core solido"**: Opportunities, Billing, monetizzazione, verifica ancorata a fonti istituzionali, app native — restano roadmap/moat post-checkpoint.

---

## Note trasversali

- **Punti di forza da preservare**: gating DM (doppio enforcement), contatore unread denormalizzato, moderazione claim (anti-race + audit + notifica + auto-reject competitor), SEO baseline (JSON-LD + OG/Twitter + sitemap + robots env-aware), 404/405/500 + flash system, FULLTEXT boolean con escaping.
- **Rischio operativo ricorrente** (da CLAUDE.md): i named placeholder PDO non riutilizzabili hanno già causato 500 (directory, `dm_unread`); ogni nuova query in helper di nav (`notif_unread`/`dm_unread`/badge Rete) gira su **ogni pagina autenticata** — un bug lì è un 500 globale. Attenzione nel cablare P0.2/P1.6.
- **Drift doc↔codice** da riconciliare: `GET /search` e "pagine sport" documentati ma non implementati (P2.11, P2.1).
