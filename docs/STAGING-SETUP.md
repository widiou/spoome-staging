# Setup ambiente di staging Spoome

Obiettivo: un ambiente di test **isolato** dalla produzione (file + DB separati) dove
validare il refactor MVC prima di promuoverlo in prod. Strategia scelta:
**repo GitHub separato** + **sottodominio SiteGround con DB dedicato**.

> Legenda: 🧑‍💻 = azione tua (pannelli/ops) · 🤖 = già fatto/farà Claude nel codice.

---

## 1. Repository GitHub di staging 🧑‍💻

1. Crea un nuovo repo privato su GitHub, es. `widiou/spoome-staging`.
2. In locale (questa cartella) aggiungi il remote e pubblica il branch di refactor:
   ```bash
   git remote add staging https://github.com/widiou/spoome-staging.git
   git push staging refactor/mvc-foundations
   ```
   - `origin` resta il repo di produzione (`widiou/sportwave`).
   - `staging` è il repo dove lavoriamo durante il refactor.
3. Promozione futura staging → prod: quando una milestone è validata, si fa merge/push
   da `staging` verso `origin/master` (te lo guido al momento).

## 2. Sottodominio + DB su SiteGround 🧑‍💻

1. **Site Tools → Domain → Subdomains**: crea `staging.spoome.it`
   (document root dedicata, es. `~/www/staging.spoome.it/public_html`).
2. **Site Tools → MySQL → Databases**: crea un DB nuovo (es. `spoome_staging`) e un utente
   dedicato con password forte. Annota host/nome/utente/password.
3. **Copia dei dati**: dal DB di produzione fai *Export* (phpMyAdmin) e *Import* nel DB di
   staging. In alternativa, per partire leggeri, importa solo le tabelle struttura + un
   sottoinsieme di `athletes`.
4. **File**: la cartella applicativa è `/network`. Su staging deve esistere
   `staging.spoome.it/network` con il codice del repo di staging.

## 3. Secondo progetto PhpStorm 🧑‍💻

1. Apri questa stessa cartella come **nuovo progetto** o usa un secondo profilo di Deployment.
2. **Tools → Deployment → Configuration**: crea un server SFTP che punta alla document root
   di `staging.spoome.it` (NON quella di produzione).
3. **Importante**: finché lavoriamo al refactor, l'upload automatico deve puntare **solo**
   allo staging. Verifica `Tools → Deployment → Automatic Upload`.

## 4. File `.env` su staging 🧑‍💻 (+ 🤖 contratto)

- 🤖 Il template è in [`.env.example`](../.env.example). `.env` NON è più tracciato in git.
- 🧑‍💻 Sul server di staging crea `/network/.env` copiando `.env.example` e compilando con le
  credenziali del **DB di staging** e:
  ```
  APP_ENV=staging
  APP_DEBUG=true
  APP_URL=https://staging.spoome.it
  ```
- 🧑‍💻 Sul server di **produzione** dovrà esistere un `/network/.env` con `APP_ENV=production`
  e le credenziali di prod (quando in Fase 0 sposteremo i segreti fuori dal codice).

## 5. Pulizia repo già applicata 🤖

- `.gitignore` riscritto con pattern reali (prima: 970 righe di singoli `.webp`).
- Tolti dal tracking (restano su disco/server): `php_errorlog`, `helpers/cache/`, `.env`,
  `.idea/`, `podio/debug.log`.
- `vendor/` e `node_modules/` restano **tracciati** di proposito (no Composer/npm sul server).
- Creata struttura `storage/cache` e `storage/logs` (contenuto ignorato, cartelle versionate).

## 6. Checklist "pronti a partire"

- [ ] Repo `spoome-staging` creato e branch `refactor/mvc-foundations` pushato.
- [ ] Sottodominio `staging.spoome.it` attivo con `/network`.
- [ ] DB di staging creato e popolato.
- [ ] PhpStorm: deploy puntato allo staging (auto-upload NON verso prod).
- [ ] `/network/.env` su staging compilato con `APP_ENV=staging`.

Quando questi punti sono spuntati, partiamo con la **Fase 0** del piano
(`.env` reale + Config, riattivazione auth API, skeleton MVC) lavorando in sicurezza su staging.
