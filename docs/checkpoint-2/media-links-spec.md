# Media & Links — SPEC del sottosistema media (foto, video, audio, link preview)

> Stato: SPEC (nessun codice di produzione). Autore: Media & Storage Architect.
> Vincolo di prim'ordine: **Sicurezza livello MASSIMO** (vedi `docs/SECURITY.md`).
> Contesto host: **SiteGround shared hosting** — niente transcode video, disco/banda limitati, nessun demone.
> Design: dark, bianco/nero, **giallo** unico accento, **niente verde, niente emoji** (Font Awesome flat).

---

## 0. TL;DR (per chi ha fretta)

- **Storage raccomandato: object storage S3-compatibile + CDN → Cloudflare R2** (egress zero) come primario. I byte dei media **non transitano mai** né da MySQL né dal disco SiteGround.
- **Perché NON blob in DB:** un LONGBLOB gonfia il backup, avvelena il buffer pool InnoDB, sfonda `max_allowed_packet`, non è CDN-friendly e rende il video di fatto impossibile. È un anti-pattern noto per i media a scala.
- **Upload:** handshake **presigned direct-to-storage** — `presign` → PUT diretto a R2 → `complete` con verifica server (size/mime magic-bytes/checksum) → stato `ready`. Identico per web e app native (API-first).
- **Video:** SiteGround non transcodifica → **provider di streaming gestito (Cloudflare Stream** raccomandato; Mux/Bunny alternative) che ingesta, transcodifica in **HLS + poster**, notifica via **webhook**; noi salviamo solo il `playback_id`.
- **Link:** servizio di **unfurl** OG/Twitter/oEmbed con **immagine re-hostata** su R2, cache in `link_previews`, e una **lista di controlli SSRF centrale e obbligatoria**.
- **Nuove tabelle / migrazioni:** `0024_evolve_media`, `0025_create_post_media`, `0026_create_link_previews`, `0027_create_media_upload_intents` (dettaglio §2, §4, §7).
- **2 decisioni per il founder (§10):** (A) provider storage — **R2** vs alternative; (B) provider video — **Cloudflare Stream** vs Mux/Bunny.

---

## 1. Comparazione delle opzioni (la decisione da giustificare)

Il founder vuole evitare le cartelle di upload sul filesystem e ha ipotizzato i **blob in DB**. Di seguito il confronto onesto sulle tre architetture, sulle dimensioni che contano davvero a scala.

| Criterio | **DB blob (LONGBLOB)** | **Filesystem locale (SiteGround)** | **Object storage + CDN (R2)** |
|---|---|---|---|
| Costo backup / replica | ❌ Catastrofico: ogni `mysqldump`/snapshot trascina TB di byte immutabili; il ripristino è lentissimo; la replica ricopia media che non cambiano mai | ⚠️ Il backup SiteGround include gli upload; cresce senza controllo; niente versioning | ✅ Storage separato dal DB; il dump MySQL resta piccolo (solo metadati); R2 ha durabilità/replica gestita |
| Buffer-pool pollution | ❌ I byte dei media competono con gli index page nel buffer pool InnoDB → cache hit crollano, tutto il sito rallenta | ✅ Nessun impatto sul DB | ✅ Nessun impatto sul DB |
| Costo/latenza di serving | ❌ Ogni GET immagine = query MySQL + PHP che streamma il blob → il tuo DB e il tuo PHP diventano un file server (il peggior uso possibile di entrambi) | ⚠️ Apache serve il file, ma **da SiteGround**: banda condivisa, niente edge, TTFB alto per utenti lontani | ✅ Servito dall'**edge CDN** vicino all'utente; PHP/DB completamente fuori dal path |
| `max_allowed_packet` | ❌ Blocco reale: default 4–64 MB; un video anche breve sfora → INSERT/SELECT falliscono; forza chunking manuale fragile | n/a | n/a |
| CDN-friendliness | ❌ Nessuna: niente URL cache-abile, niente `ETag`/`immutable` nativi | ⚠️ Possibile ma va messa una CDN davanti a SiteGround (origin fragile) | ✅ Nativa: chiavi content-addressed → `Cache-Control: immutable`, edge cache globale |
| Fattibilità video | ❌ Di fatto impossibile (packet, streaming range requests) | ❌ Niente transcode, niente HLS, niente byte-range efficiente su shared hosting | ✅ Delegato a provider di streaming; R2 tiene sorgenti/audio |
| Sicurezza | ⚠️ Serving via PHP → superficie XSS/content-type sniffing se sbagli gli header | ❌ Path traversal, file eseguibili nella docroot, `.htaccess` fragile | ✅ Bucket **privato**, serving via URL firmati/CDN, nessun eseguibile, ACL al momento della firma |
| Operatività a scala | ❌ Vacuum/defrag, tabelle enormi, migrazioni lente | ⚠️ Inode limit, sync tra deploy, disco che si riempie in silenzio | ✅ Elastico, pay-as-you-go, nessun limite pratico |

### Raccomandazione

**Object storage S3-compatibile + CDN, con Cloudflare R2 come primario.** Motivazioni concrete:

1. **I blob in DB sono un anti-pattern per i media a scala** — non per dogma, ma per le quattro voci ❌ qui sopra (backup, buffer pool, serving via DB, `max_allowed_packet`). Un DB deve servire *metadati e relazioni*, non byte.
2. **Il filesystem su SiteGround è fragile**: è shared hosting con banda condivisa, senza edge, con `.htaccess` come unica barriera (già segnalato come rischioso nel CLAUDE.md — nessuna regressione visibile ammessa), inode limitati e disco che si riempie senza allarme. È esattamente ciò che il founder vuole evitare.
3. **R2 in particolare** perché ha **egress a costo zero** (il grande differenziale vs S3/Spaces: servire media = traffico in uscita, e su un social è la voce dominante), API **S3-compatibile** (adapter riusabile) e CDN Cloudflare integrata.

Alternative valide (vedi §10 per il trade-off da decidere):
- **Backblaze B2** — la più economica per GB stoccato; egress gratuito solo dentro la Bandwidth Alliance/Cloudflare.
- **DigitalOcean Spaces** — CDN inclusa, prezzo a bundle prevedibile; egress a pagamento oltre soglia.
- **AWS S3 + CloudFront** — lo standard, massima maturità, ma **egress caro**: penalizzante per un social ad alto traffico immagini.

> Principio guida di tutto il documento: **nessun byte di media transita da MySQL o dal disco di SiteGround.** SiteGround gira solo la logica applicativa (firma, verifica, metadati, unfurl); i byte vanno client↔R2/CDN e client↔provider video.

---

## 2. Stato attuale (letto dal codice, non assunto)

Cosa esiste oggi (checkpoint corrente):

- **`src/Domain/Media/MediaService.php`** — gestisce SOLO avatar/cover: valida+ricodifica via `ImageService`, scrive un `.webp` su `public/uploads/{avatars,covers}/<token>.webp`, crea la riga `media`, aggiorna `profiles.avatar_media_id`/`cover_media_id`, cancella il vecchio file. Rate-limit 20 upload/10min.
- **`ImageService.php`** — sicurezza già buona: MIME da `finfo` (contenuto), guardia decompression-bomb (12 MP), **ri-codifica sempre** (scarta EXIF/polyglot), output WebP, center-crop. Avatar 512×512, cover 1500×500.
- **`MediaRepository` / `Media` (model)** — CRUD minimale su `media`.
- **Controller:** `Web/AvatarController` (JSON+CSRF, campo multipart `image`) e `Api/V1/MediaController` (solo-Bearer, stessa `MediaService`). Parità web/nativa già impostata.
- **Tabella `media` (migr. 0004):** `id, user_id, kind VARCHAR(24), disk_path VARCHAR(255), mime, width, height, size_bytes, created_at`. FK `user_id→users ON DELETE CASCADE`. Referenziata da `profiles.avatar_media_id`/`cover_media_id` (FK `ON DELETE SET NULL`).
- **Serving avatar:** `url($relPath)` → file relativo servito da `/public`. I profili joinano `LEFT JOIN media am ON am.id = p.avatar_media_id` esponendo `am.disk_path AS avatar_path` (in `ProfileRepository`, `ProfileViewRepository`, `SkillRepository`, `PostRepository`).
- **Posts (migr. 0009):** `posts(id, profile_id, body VARCHAR(2000), created_at)` — **nessun riferimento a media**. Non esiste oggi alcun modo di allegare foto/video a un post. → l'integrazione richiede una **nuova tabella `post_media`** (§6).
- **`SecurityHeaders.php`** — CSP chiusa: `img-src 'self' data:`, `connect-src 'self'`, `frame-src` assente (default-src 'self'), `object-src 'none'`. **Va estesa** per ammettere CDN media, endpoint R2 e iframe di embed allow-listed (§5.4).

**Osservazioni chiave che guidano il design:**
- La tabella `media` chiave su **`user_id`**, mentre `posts`/like/commenti/DM chiavano su **`profile_id`**. Questa doppia identità è già fonte nota di bug di scoping. → Il nuovo `media` introduce **`owner_profile_id`** come proprietà dominante, mantenendo `user_id` derivabile per retro-compatibilità (§2 DDL).
- Gli avatar oggi sono **disk-based**; serve un percorso di migrazione non distruttivo verso R2 (§2.3).

### 2.1 Numerazione migrazioni (coordinamento con le altre spec)

L'ultima migrazione applicata è **0018**. Coordinamento cross-spec di checkpoint-2:

- **Realtime spec** → riserva **0019** (`user_events`) e **0020** (`push_devices`).
- **Partner / public-API spec** → riassegnata a **0021–0023** (`api_clients`+`api_keys`, `api_request_log`, `api_usage_daily`). *(Nota: il testo di quella spec cita ancora 0019/0020; in fase di merge va rinumerata a 0021–0023 per evitare collisione con realtime.)*
- **Questa spec (media)** → parte **dopo**, da **0024**:
  - **0024** `evolve_media` — evoluzione tabella `media`.
  - **0025** `create_post_media` — join post↔media (carosello).
  - **0026** `create_link_previews` — cache unfurl.
  - **0027** `create_media_upload_intents` — intent di upload presigned (reaping abbandoni).

### 2.2 DDL — evoluzione `media` (migrazione 0024)

Si evolve la tabella esistente (non se ne crea una nuova) per preservare le FK di `profiles`. Approccio additivo + backfill.

```sql
-- 0024_evolve_media.php  (segue 0018; dopo realtime 0019-0020 e partner 0021-0023)

ALTER TABLE media
  -- identità dominante allineata a posts/feed (profile_id), user_id resta per retro-compat
  ADD COLUMN owner_profile_id INT NULL AFTER user_id,
  MODIFY COLUMN kind VARCHAR(24) NOT NULL DEFAULT 'other',   -- resta libera; l'app valida l'enum logico
  ADD COLUMN media_class ENUM('image','video','audio','file') NOT NULL DEFAULT 'image' AFTER kind,
  ADD COLUMN storage_key   VARCHAR(512) NULL AFTER disk_path, -- chiave R2 content-addressed; disk_path resta per i legacy
  ADD COLUMN storage_bucket VARCHAR(64) NULL AFTER storage_key,
  ADD COLUMN duration_ms   INT UNSIGNED NULL AFTER height,
  ADD COLUMN variants       JSON NULL AFTER duration_ms,       -- {"thumb":"key…","poster":"key…","hls":"playbackId"}
  ADD COLUMN checksum_sha256 CHAR(64) NULL AFTER variants,
  ADD COLUMN status ENUM('pending','uploading','processing','ready','failed') NOT NULL DEFAULT 'ready' AFTER checksum_sha256,
  ADD COLUMN visibility ENUM('public','members','private') NOT NULL DEFAULT 'public' AFTER status,
  ADD COLUMN provider VARCHAR(32) NULL AFTER visibility,       -- 'r2' | 'cf_stream' | 'mux' | NULL(legacy disk)
  ADD COLUMN provider_asset_id VARCHAR(128) NULL AFTER provider, -- playback id del provider video
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD INDEX idx_media_owner_class (owner_profile_id, media_class),
  ADD INDEX idx_media_status (status),
  ADD UNIQUE INDEX uq_media_checksum_owner (owner_profile_id, checksum_sha256); -- dedup per owner

-- Backfill owner_profile_id dai media legacy (avatar/cover) via la mappa user→profile
UPDATE media m
  JOIN profiles p ON p.user_id = m.user_id
  SET m.owner_profile_id = p.id
  WHERE m.owner_profile_id IS NULL;

-- I media legacy hanno provider NULL e storage_key NULL → l'app li serve ancora da disk_path (fallback).
```

Note di design sul DDL:
- **`storage_key` content-addressed** (`sha256/<hash>.<ext>` sotto un prefisso per-owner) → permette `Cache-Control: public, max-age=31536000, immutable` e dedup naturale.
- **`variants JSON`** tiene le chiavi delle derivate (thumb, poster, HLS playback id) senza esplodere lo schema.
- **`status`** guida la state machine dell'upload presigned (§3) e del transcode video (§5).
- **`visibility`** decide se servire via URL CDN pubblico o **URL firmato a breve scadenza** (§4.3).
- **`provider`/`provider_asset_id`** disaccoppiano dal fornitore (adapter, §3).
- Il modello PHP `Media` e `MediaRepository` andranno estesi in modo additivo (nuovi campi opzionali) → nessuna rottura dei consumer avatar attuali.

### 2.3 Percorso di migrazione degli avatar disk → R2 (non distruttivo)

1. **Fase A (compat):** nuovo codice, avatar continuano su disco; `media.provider IS NULL` ⇒ serving da `disk_path` (comportamento attuale). Nessuna regressione visibile.
2. **Fase B (dual-write):** i nuovi avatar/cover vengono ricodificati come oggi ma **caricati su R2** (`provider='r2'`, `storage_key` valorizzata); il join in `ProfileRepository` produce l'URL da `storage_key` se presente, altrimenti da `disk_path`. Un helper `media_url(row)` centralizza questa scelta.
3. **Fase C (backfill batch):** job offline che, per i media legacy, carica il file esistente su R2, valorizza `storage_key`/`provider`, e solo dopo verifica cancella il file locale. Idempotente e ripetibile.
4. **Fase D (cleanup):** rimozione di `disk_path` dai path di serving; la colonna resta nello schema come storico finché il backfill non è certificato 100%.

---

## 3. Storage + upload presigned direct-to-storage

### 3.1 Astrazione `StorageAdapter`

Interfaccia (contratto, non implementazione) che rende il provider **swappable**:

```
interface StorageAdapter {
    presignPut(key, mime, maxBytes, ttlSeconds): {url, headers, expiresAt}
    presignGet(key, ttlSeconds): url            // per media privati/members
    publicUrl(key): url                          // per media public (CDN)
    head(key): {exists, size, mime, etag} | null // verifica post-upload
    delete(key): void
}
```

Implementazioni concrete: `R2StorageAdapter` (primario, firma SigV4 S3), più `B2StorageAdapter`, `SpacesStorageAdapter`, `S3StorageAdapter` (alternative). `MediaService` diventa il **front** sopra l'adapter: nessun controller conosce il provider.

> **Credenziali:** access key/secret R2 solo in env server (mai nel repo, mai nel client). Il client riceve **solo** URL firmati a tempo. Bucket **privato** di default; il "public" è servito da un binding CDN read-only, non da bucket pubblico aperto.

### 3.2 Handshake completo (le tre fasi)

**Fase 1 — `POST /api/v1/media/presign`** (Bearer per API, CSRF per web)
- Body: `{media_class, mime, byte_size, checksum_sha256?, purpose('post'|'avatar'|'cover'|'message'), width?, height?}`.
- Server valida **prima** di firmare: `mime` in allow-list per `media_class`, `byte_size` ≤ cap per classe (§7), quota utente non superata, rate-limit (riuso `RateLimiter`, chiave `presign:<user>`).
- Server calcola la **chiave content-addressed** (`sha256/<hash>` se il client fornisce il checksum, altrimenti chiave random UUID riconciliata al `complete`), crea la riga **`media_upload_intents`** (0027) con `status='pending'` e `expires_at = now+15min`, e crea/collega una riga `media` con `status='pending'`.
- Risposta: `{media_id, upload_url, method:'PUT', required_headers, key, expires_at, max_bytes}`.
- **Vincoli firmati nella URL:** `Content-Length` massimo, `Content-Type` fissato, scadenza breve (10–15 min). R2/S3 applicano `Content-Length-Range` e content-type nella policy della firma → il client non può caricare più grande o di tipo diverso.

**Fase 2 — PUT diretto client→R2** (bypassa SiteGround)
- Il client fa `PUT upload_url` con i byte. Nessun byte tocca il nostro server. Su web: `fetch`/XHR con progress (§6). Su nativo: upload multipart della piattaforma.
- Il client **deve** rispettare gli header firmati; R2 rifiuta altrimenti.

**Fase 3 — `POST /api/v1/media/{id}/complete`**
- Il server esegue `head(key)` su R2: verifica **esistenza, `size` == atteso, `mime`/`etag`**. Se disponibile, ricalcola/riconcilia il **checksum** (per media piccoli scarica-e-verifica in streaming con cap byte; per media grandi si fida di size+etag S3 e del content-type firmato).
- **Verifica magic-bytes:** per immagini/audio piccoli, il server scarica i primi KB da R2 e controlla la firma binaria reale (difesa oltre il content-type firmato).
- Se `media_class='image'`: opzionale ricodifica lato worker (§5.1). Se `='video'`: si avvia l'ingest sul provider video (§5.2) e `status='processing'`. Altrimenti `status='ready'`.
- Marca `media_upload_intents.status='completed'`; risponde con il record `media` normalizzato + URL di consumo.

### 3.3 Reaping di abbandoni e upload parziali

- **`media_upload_intents`** (0027): `id, media_id, key, expected_size, checksum, status ENUM('pending','completed','expired','failed'), expires_at, created_at`.
- Un intent `pending` oltre `expires_at` senza `complete` → **cron leggero** (SiteGround cron, no demone) che: (a) chiama `delete(key)` su R2 se l'oggetto esiste ma orfano, (b) marca intent `expired`, (c) elimina la riga `media` `pending` collegata.
- **Multipart/partial PUT:** con presigned single PUT non ci sono parti pendenti; per file grandi (video sorgente) si usa **presigned multipart upload** e il reaper chiama `AbortMultipartUpload` sugli upload incompleti oltre TTL. R2 supporta anche lifecycle rule server-side come rete di sicurezza.
- Un oggetto arriva su R2 ma il `complete` non viene mai chiamato → l'oggetto è **orfano** (nessuna riga `media` `ready` lo referenzia) e viene raccolto dal reaper/lifecycle. Nessun media orfano è mai servibile perché il serving parte sempre da una riga `media` `ready`.

### 3.4 DDL — `media_upload_intents` (migrazione 0027)

```sql
-- 0027_create_media_upload_intents.php
CREATE TABLE media_upload_intents (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  media_id      INT NOT NULL,
  storage_key   VARCHAR(512) NOT NULL,
  expected_size INT UNSIGNED NOT NULL,
  checksum_sha256 CHAR(64) NULL,
  status        ENUM('pending','completed','expired','failed') NOT NULL DEFAULT 'pending',
  expires_at    TIMESTAMP NOT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_intent_media FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE,
  INDEX idx_intent_status_exp (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 4. Serving (CDN pubblico + URL firmati per privati)

### 4.1 Media pubblici
- Serviti via **URL CDN** su chiave content-addressed. Header: `Cache-Control: public, max-age=31536000, immutable`, `ETag`, `Content-Type` corretto, **`Content-Disposition: inline`** solo per tipi sicuri; per tutto il resto `attachment`. `X-Content-Type-Options: nosniff` (già impostato).
- Chiavi immutabili ⇒ nessuna invalidazione cache: un nuovo media = nuova chiave.

### 4.2 Media privati / members-only
- **URL firmati a breve scadenza** (5–15 min) emessi da `presignGet` **solo dopo authz al livello dati**: il server controlla `media.visibility` e la relazione (owner, connessione, membership) prima di firmare. La firma è la barriera: se non sei autorizzato, non ottieni l'URL.
- Nessun URL privato è cache-abile pubblicamente (`private, no-store` a livello di risposta applicativa che emette il redirect firmato).

### 4.3 Regola d'oro
Il **CDN non fa authz**; l'authz avviene **all'emissione dell'URL firmato** (defense-in-depth, coerente con CLAUDE.md §non-negoziabili). Un media `private` non ha mai un URL pubblico stabile.

---

## 5. Derivate / processing (senza transcode su SiteGround)

### 5.1 Immagini
Due opzioni (decidibili in fase implementativa, non bloccanti):
- **(a) On-access resizing proxy** — **Cloudflare Images** o un Worker/URL di trasformazione: `…/cdn-cgi/image/width=NNN,quality=82,format=auto/<key>`. Nessuna derivata pre-generata da stoccare; formati moderni (`format=auto` → AVIF/WebP) negoziati per-browser.
- **(b) Worker di ricodifica al `complete`** — per gli upload via API di foto "reali", un piccolo worker (o la stessa GD già usata da `ImageService`, se il file è piccolo e passa da un endpoint controllato) ricodifica, **strippa EXIF/metadata**, cappa le dimensioni, produce thumb + versione display, e le carica come `variants`.
- In entrambi i casi si **preserva la logica di sicurezza già esistente** di `ImageService` (MIME da contenuto, anti decompression-bomb, ri-encode sempre). Preferenza: **(a) Cloudflare Images** per non caricare SiteGround, con `ImageService` come fallback/validatore.

### 5.2 Video (il punto critico: SiteGround non transcodifica)
Delegare a un **provider di streaming gestito**. Flusso:
1. `presign` con `media_class='video'` → il server chiede al provider un **direct-upload URL** (Cloudflare Stream: `POST /stream?direct_user=true` → `uploadURL`; Mux: `POST /video/uploads`). La riga `media` va `status='uploading'`, `provider='cf_stream'`.
2. Il client carica **direttamente al provider** (di nuovo, mai su SiteGround).
3. Il provider transcodifica in **HLS (adaptive bitrate) + poster/thumbnail**; a fine lavorazione invia un **webhook** al nostro `POST /api/v1/webhooks/video`.
4. Il webhook (autenticato con **firma HMAC del provider**, verificata) marca `media.status='ready'`, salva `provider_asset_id` (playback id) e le chiavi poster/HLS in `variants`.
5. Il player web usa un `<video>` con HLS (hls.js self-hosted, coerente con CSP) puntando all'URL di playback del provider; poster dall'URL provider/CDN.

Nota: i **byte sorgente** del video possono restare sul provider (Stream li tiene) — non serve duplicarli su R2. R2 tiene semmai un backup opzionale del sorgente se si vuole indipendenza dal provider.

### 5.3 Audio
- Object storage R2 diretto (stesso handshake presigned). Nessun transcode obbligatorio; opzionale normalizzazione a un formato web-safe (AAC/MP3/Opus) lato worker.
- **Waveform/peaks** (per il player a onde) → **deferito**: generazione peaks in un worker asincrono che scrive un JSON di peaks in `variants`; il player degrada a barra semplice se assente.

### 5.4 CSP da estendere (`SecurityHeaders.php`)
Per far funzionare CDN media + player video + embed, la CSP va allargata **in modo chirurgico** (host espliciti, niente wildcard aperte):
- `img-src 'self' data: https://<cdn-media-host>` (aggiungere l'host CDN R2/Images).
- `media-src 'self' https://<cdn-media-host> https://<video-provider-host>` (nuova direttiva, per `<video>`/`<audio>`).
- `connect-src 'self' https://<r2-endpoint> https://<video-provider-upload-host>` (per il PUT diretto e l'upload video).
- `frame-src https://<youtube-nocookie> https://<vimeo> …` (**solo** i provider oEmbed allow-listed, §5 link).
- `script-src` resta `'self'` (hls.js e gli embed player self-hosted o via iframe sandboxed, non script inline di terzi).

---

## 6. Integrazione: post, feed, API, async, componente post

### 6.1 `post_media` — allegare media ai post (migrazione 0025)
Oggi `posts` non referenzia media. Nuova join per il **carosello stile Instagram**:

```sql
-- 0025_create_post_media.php
CREATE TABLE post_media (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  post_id    INT NOT NULL,
  media_id   INT NOT NULL,
  position   TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- ordine nel carosello
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pm_post  FOREIGN KEY (post_id)  REFERENCES posts(id)  ON DELETE CASCADE,
  CONSTRAINT fk_pm_media FOREIGN KEY (media_id) REFERENCES media(id)  ON DELETE CASCADE,
  UNIQUE KEY uq_pm_post_pos (post_id, position),
  INDEX idx_pm_media (media_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
- Un post può inoltre avere **una** link-card (0 o 1) via `link_previews` (§5 link) — riferita da una colonna `posts.link_preview_url_hash` (aggiunta additiva nella 0026) o da una seconda join `post_link`. Preferenza: colonna nullable su `posts` per semplicità (una sola link-card per post).
- Il feed carica i media di un post con una query batch `post_media JOIN media` (come già fatto per i commenti in `PostRepository`), scegliendo l'URL via l'helper `media_url()` (public CDN o firmato).

### 6.2 API-first
Il flusso presigned è **identico** per web e nativo: entrambi chiamano `presign`/`complete`. La creazione post accetta `media_ids[]` (già `ready`) + `link_url?`. Nessun upload multipart verso i nostri endpoint per i post (l'attuale multipart resta solo per il legacy avatar finché non migrato al presigned).

### 6.3 Partner / public API
- I media **pubblici** sono esposti via URL CDN, **PII-free** (nessun path che riveli user id interni; chiavi content-addressed opache).
- I media `private`/`members` **non** sono mai serviti alla public API (o solo dietro scope esplicito e URL firmato a TTL cortissimo, mai cache-abile).

### 6.4 Client async / upload progress
- Il client async (vedi `async-consolidation-spec.md`) orchestra: `presign` → PUT con **progress event** (XHR `upload.onprogress`) → `complete` → attach al post. Stato UI: pending → uploading (%) → processing (video) → ready. Retry idempotente: se `complete` fallisce, si ritenta senza ri-caricare (l'oggetto è già su R2, riconciliato per checksum).

### 6.5 Componente post stile Instagram
- **Carosello media** (`post_media` ordinato per `position`) + **link-card** (§5). Rendering brand: dark, bordo sottile, accento **giallo** sui controlli, **niente verde, niente emoji** (frecce/indicatori Font Awesome flat). Video con poster e play overlay; audio con player a onde (peaks deferiti → fallback barra).

---

## 7. Link — servizio di unfurl sicuro + embed (priorità del founder)

### 7.1 Flusso
Quando un utente incolla una URL in un post:
1. Il server (job sincrono breve o async) fa l'**unfurl**: fetch della pagina, estrazione di **Open Graph** (`og:*`), **Twitter Card** (`twitter:*`) e, per i provider allow-listed, **oEmbed**.
2. I campi vengono **sanitizzati** e salvati in `link_previews`, chiave su `url_hash` (SHA-256 dell'URL normalizzato).
3. L'**immagine di anteprima** viene **re-hostata** su R2 (mai hotlink): scaricata sotto gli stessi controlli SSRF, ricodificata via `ImageService`, salvata come `media` e referenziata da `image_media_id`. Servita via CDN.
4. La card viene resa dal `link_previews` cache-ato; TTL via `expires_at` (es. 7 giorni) con refresh lazy.

### 7.2 DDL — `link_previews` (migrazione 0026)

```sql
-- 0026_create_link_previews.php
CREATE TABLE link_previews (
  url_hash       CHAR(64) PRIMARY KEY,           -- sha256(normalized_url)
  url            VARCHAR(2048) NOT NULL,
  title          VARCHAR(300) NULL,
  description    VARCHAR(600) NULL,
  image_media_id INT NULL,                        -- immagine re-hostata (media.id)
  site_name      VARCHAR(120) NULL,
  type           VARCHAR(40) NULL,                -- og:type
  provider       VARCHAR(60) NULL,               -- oEmbed provider (se embed)
  html_embed     TEXT NULL,                       -- SOLO da provider allow-listed, sanitizzato
  status         ENUM('ok','blocked','failed') NOT NULL DEFAULT 'ok',
  fetched_at     TIMESTAMP NULL DEFAULT NULL,
  expires_at     TIMESTAMP NULL DEFAULT NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lp_image FOREIGN KEY (image_media_id) REFERENCES media(id) ON DELETE SET NULL,
  INDEX idx_lp_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- collegamento post → link-card (una sola per post)
ALTER TABLE posts ADD COLUMN link_preview_url_hash CHAR(64) NULL AFTER body,
  ADD CONSTRAINT fk_post_link FOREIGN KEY (link_preview_url_hash)
      REFERENCES link_previews(url_hash) ON DELETE SET NULL;
```

### 7.3 Protezione SSRF — OBBLIGATORIA E CENTRALE

Tutta la logica di fetch (pagina + immagine + oEmbed) passa **esclusivamente** per un `SafeHttpFetcher` che applica, in ordine, **tutti** questi controlli. Nessun fetch di URL esterno può bypassarlo.

- **Schema allow-list:** solo `http`/`https`. Rifiuta `file://`, `gopher://`, `ftp://`, `data:`, `dict://`, ecc.
- **Risoluzione DNS esplicita + blocco IP:** risolvi l'hostname **prima** di connettere e **blocca** ogni IP in range privato/riservato:
  - IPv4: `10/8`, `172.16/12`, `192.168/16`, `127/8` (loopback), `169.254/16` (link-local, **inclusi 169.254.169.254 metadata**), `0.0.0.0/8`, `100.64/10` (CGNAT), multicast/reserved.
  - IPv6: `::1` (loopback), `fc00::/7` (ULA), `fe80::/10` (link-local), `::ffff:0:0/96` (IPv4-mapped → riverifica l'IPv4 sottostante), `2001:db8::/32`.
- **Guardia DNS-rebinding / TOCTOU:** **pin dell'IP** validato e connessione a **quell'IP** (Host header preservato), oppure ri-risoluzione+ri-validazione dopo ogni redirect. L'IP effettivamente contattato deve essere quello validato.
- **Ri-validazione ad ogni redirect:** ogni hop `3xx` ripassa **tutti** i controlli (schema + IP). Un redirect verso `169.254.169.254` o `127.0.0.1` viene bloccato.
- **Cap redirect:** massimo 3–5 hop, poi abort.
- **Timeout totale + cap byte:** connect+read timeout stretti (es. 5s) e **max response bytes** (es. 2–5 MB pagina, cap separato più alto per l'immagine); abort in streaming appena superato.
- **Content-Type gate:** per la pagina si accetta solo `text/html`/`application/xhtml+xml` (+ `application/json` per endpoint oEmbed); per l'immagine solo `image/*` verificato poi da **magic bytes**. Si nega/scarta tutto il resto.
- **Nessuna credenziale propagata:** nessun cookie, nessun header di auth, nessuna sessione; `User-Agent` dedicato e onesto; niente `Authorization`.
- **Sanitizzazione di OGNI campo** prima di storage e render: `title/description/site_name` passano da `e()` (escape output) e strip di controlli/entità pericolose; nessun HTML grezzo di terzi entra nel DOM.
- **Porte:** blocca porte non standard/pericolose (opzionale: allow-list `80/443` + eventuali; nega `22/25/6379/…`).
- **Rate-limit e cache negativa:** unfurl limitato per-utente (riuso `RateLimiter`) e risultati `blocked`/`failed` cache-ati per non ritentare in loop (amplificazione).

### 7.4 Embed ricchi (YouTube, Vimeo, …)
- **Solo** via **allow-list di provider oEmbed** (YouTube, Vimeo, Spotify, X/Twitter, SoundCloud, …). Per un dominio non in lista → **niente embed**, solo la link-card generica.
- Si interroga l'endpoint oEmbed **ufficiale** del provider (via `SafeHttpFetcher`); l'`html_embed` restituito è **normalizzato/ricostruito da noi** (non renderizzato as-is): tipicamente si estrae solo il `video id`/URL e si costruisce **noi** un `<iframe sandbox>` verso l'host ufficiale (es. `youtube-nocookie.com`). **Mai renderizzare HTML remoto arbitrario.**
- L'iframe è `sandbox="allow-scripts allow-same-origin allow-presentation"` (minimo indispensabile), `referrerpolicy="no-referrer"`, host in CSP `frame-src` allow-listed.

### 7.5 Rendering della card
- **Link generico:** card dark con immagine re-hostata (CDN) a sinistra/top, titolo, `site_name`, dominio; bordo sottile, hover accento **giallo**. Nessuna emoji; icona link Font Awesome flat.
- **Embed allow-listed:** iframe sandboxed con poster e play; degrada a card se il provider non risponde.

---

## 8. Sicurezza, quote, ACL (livello MASSIMO)

- **Validazione per magic-bytes**, non solo MIME client: al `complete` e su ogni immagine re-hostata, il server verifica la firma binaria reale; il content-type è anche fissato nella firma di upload (doppia barriera).
- **Cap dimensione per classe:** immagini ~15 MB, audio ~30 MB, video via provider (limite del provider, es. 200 MB–qualche GB) — enforced sia nella policy firmata (`Content-Length-Range`) sia al `complete`.
- **Quota per-utente:** somma `byte_size` dei `media` `ready` per `owner_profile_id` contro un limite di piano; controllata al `presign` (prima di firmare). Colonna/vista aggregata o `COUNT/SUM` indicizzato.
- **Rate-limit:** riuso `RateLimiter` per `presign`, `complete`, `unfurl` (chiavi dedicate) — coerente con l'attuale 20 upload/10min.
- **Virus/exploit:** ri-encode delle immagini scarta payload; per audio/video il provider/worker non esegue mai il file; nessun file è mai eseguibile (bucket privato, `Content-Disposition` corretto, `nosniff`); opzionale hook antivirus (ClamAV via provider) sui sorgenti prima di `ready`.
- **ACL su firma:** l'authz `public/members/private` è applicata **all'emissione dell'URL firmato** (il CDN non decide). Un `private` non ha URL pubblico.
- **Webhook video:** verifica **firma HMAC** del provider, replay-protection (timestamp+nonce), idempotenza sul `provider_asset_id`.
- **Deploy CSP:** le modifiche a `SecurityHeaders.php` (§5.4) e allo `.htaccess` vanno testate dal vivo (nessuna regressione: gli helper di nav girano su ogni pagina).

---

## 9. Sketch di costo (scala modesta, ordine di grandezza)

Ipotesi: ~5.000 utenti attivi, ~50k immagini (media 400 KB → ~20 GB), ~2.000 video/mese brevi, traffico immagini ~500 GB/mese.

| Voce | Servizio | Costo indicativo/mese |
|---|---|---|
| Storage oggetti (~20–50 GB) | Cloudflare R2 (~$0.015/GB) | ~$0.3–0.75 |
| **Egress immagini (~500 GB)** | R2 **egress $0** (vs S3 ~$40+) | **$0** |
| Trasformazioni immagini | Cloudflare Images (o Worker) | ~$5 (a volume) |
| Video ingest+storage+delivery | Cloudflare Stream (~$5/1000 min storage + $1/1000 min delivered) | ~$10–30 |
| Unfurl/link | trascurabile (fetch + cache) | ~$0 |
| **Totale indicativo** | | **~$20–40/mese** |

Il differenziale chiave è l'**egress zero** di R2: su un social ad alte immagini, l'egress è la voce che con S3/CloudFront esploderebbe.

---

## 10. Le 2 decisioni per il founder

**Decisione A — Provider di object storage.**
- **Raccomandato: Cloudflare R2** — egress $0 (decisivo per un social a immagini), S3-compatibile, CDN integrata.
- Alternative: **B2** (storage per-GB più economico, ma egress gratis solo via Cloudflare), **DO Spaces** (bundle prevedibile, CDN inclusa), **S3+CloudFront** (massima maturità, ma egress caro).
- *Da decidere:* si conferma **R2** come primario? (L'adapter lo rende comunque swappabile.)

**Decisione B — Provider di streaming video.**
- **Raccomandato: Cloudflare Stream** — coerente con R2/CDN già scelti, direct-upload + HLS + poster + webhook, pricing a minuti semplice.
- Alternative: **Mux** (API/analytics superiori, più caro), **Bunny Stream** (il più economico, CDN inclusa, meno "enterprise").
- *Da decidere:* **Cloudflare Stream** (ecosistema unico) o **Bunny Stream** (costo minimo) o **Mux** (feature/analytics)?

---

## Appendice — riepilogo nuove tabelle / migrazioni

| Migr. | Oggetto | Scopo |
|---|---|---|
| **0024** | `evolve_media` (ALTER) | `owner_profile_id`, `media_class`, `storage_key`, `duration_ms`, `variants JSON`, `checksum`, `status`, `visibility`, `provider`, `provider_asset_id`; backfill owner; dedup index |
| **0025** | `post_media` | carosello post↔media ordinato |
| **0026** | `link_previews` + `posts.link_preview_url_hash` | cache unfurl + link-card per post |
| **0027** | `media_upload_intents` | intent presigned + reaping abbandoni |

*(0019–0020 riservate a realtime; 0021–0023 riservate a partner/public-API.)*
