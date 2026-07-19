---
name: scaffold-domain
description: Scaffold di un nuovo dominio/feature backend in Spoome secondo il pattern Controller → Service (ServiceResult) → Repository (PDO) → Presenter, con rotte web (CSRF) e API (Bearer) in parità. Trigger: nuova feature/dominio da zero (es. Opportunities, Billing, Events), "crea il dominio X", "aggiungi un nuovo modulo backend", aggiungere un'azione con parità web↔API.
---

# Scaffold nuovo dominio

Pattern unico del progetto. L'incoerenza ai bordi è il debito n.1: aderisci senza scorciatoie.

## Struttura (`src/Domain/<X>/`)
1. **`<X>Repository`** (PDO): ctor iniettabile `?PDO $pdo = null` (default `Db::connection()`, così è testabile). Scritture in `Db::transaction($pdo, fn)` (nesting-safe), aggiornando i **contatori denormalizzati nella STESSA transazione** (modello `FollowRepository.php`). Query sicure → skill **pdo-safe-query**.
2. **`<X>Service`**: ritorna **SEMPRE** `Core\ServiceResult` (mai array ad-hoc — è il difetto di Auth/Admin, da non replicare). Unica sede di **validazione + authz + rate-limit**. Dipendenze iniettabili con default `?Repo = null`.
3. **`<X>Presenter`** (se serve output API): metodi `public static` per-shape (modello `ProfilePresenter.php`), così web e API condividono la stessa forma.
4. **Read-model condiviso** (se la vista serve sia HTML sia GET API): un `<X>PageService` che NON tocca HTTP/sessione, riceve target + `viewerId` nullable, ritorna array puro (modello `ProfilePageService.php`). Niente `COUNT(*)` live, niente query negli helper di nav.

## Controller (sottili, 2-3 righe)
- **Web** (`Http/Controllers/Web/<X>Controller`): `requireUser`, chiude con `respond($request, $res, $redirect, $flashOk)`. Rotta con middleware `[$auth, $csrf]`.
- **API** (`Http/Controllers/Api/V1/<X>Controller`): **scritture** con `requireBearerUser` (mai sessione = anti-CSRF strutturale); letture possono usare `requireUser`. Chiude con `emitJson($res)` (unica mappatura ServiceResult→envelope `{data,meta}`/`{errors}` — non reimplementarla).

## Rotte (`config/routes.php`)
Gemelle e in parità: **dominio/URL in italiano** per il web (`/atleti/{handle}/raccomanda`), **path in inglese** per l'API (`/api/v1/profiles/{handle}/recommendation`). Usa sempre `profile_url()`/`profile_path()`, mai concatenare path a mano.

## Prima di consegnare
- Sicurezza → skill **secure-write-checklist**.
- Se schema nuovo → skill **authoring-migration**.
- Pipeline: implementa → `code-reviewer` (+ `security-engineer` se authz/dati) → **beta-deploy** → **beta-smoke-check**.
