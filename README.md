# Spoome v2 — il network dello sport (SPOrt + hOME)

Professional network dello sport: atleti, società, associazioni, federazioni e fan.
Ricostruzione da zero (2026) — vanilla PHP + JavaScript + MySQL, **API-first** e **mobile-first**,
pronta per essere consumata anche da app native Android/iOS.

- 📐 **Progettazione**: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — stella polare del progetto.
- 🌐 **Ambiente**: gira su SiteGround (shared). Beta: https://spoome.it/beta/ · Prod separata: https://spoome.it
- ⚙️ **Deploy**: PhpStorm auto-upload (SFTP). Niente Composer/SSH sul server → `vendor/` versionato a mano.
- 🗄️ **DB**: le migrazioni si scrivono qui e si eseguono sul server (via runner protetto o a mano).

## Struttura
```
public/      web root (unico entry: index.php) — front controller web + API
src/Core/    kernel: router, request/response, config, auth, view, db, migrator
src/Domain/  domini (Users, Auth, Profiles, Connections, Feed, Messaging, ...)
src/Http/    controller Web + Api/V1 + middleware
views/       template server-rendered (SEO), mobile-first
database/    migrations + seeds (tassonomia sport)
config/ jobs/ storage/ vendor/
```

## Come partire (dev)
1. Copia `.env.example` → `.env` e compila le credenziali del DB `/beta/`.
2. Le migrazioni girano sul server tramite il runner protetto (vedi ARCHITECTURE §Deploy).
3. Ogni modifica → PhpStorm la carica su `/beta/`.
