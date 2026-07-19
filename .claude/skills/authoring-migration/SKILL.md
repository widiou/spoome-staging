---
name: authoring-migration
description: Crea, applica e registra una migrazione di schema DB in Spoome (nuova tabella, colonna, indice, FK). Le migrazioni sono file numerati idempotenti in database/migrations/. Trigger: "migrazione", "cambia lo schema", "aggiungi una tabella/colonna/indice", "nuova FK", modellazione dati per una feature.
---

# Authoring di una migrazione

## Passi
1. **Nuovo file numerato progressivo**: `database/migrations/00NN_nome.php` (l'ultimo attuale è `0031`; usa il successivo).
2. **Struttura**: `return new class { public function up(\PDO $pdo){...} public function down(\PDO $pdo){...} };` (segui i file esistenti come modello).
3. **Idempotenza OBBLIGATORIA** (rieseguibile senza doppio-apply):
   - tabelle: helper `tableExists()` via `information_schema.TABLES` + `CREATE TABLE IF NOT EXISTS` (modello `0031_create_recommendations.php`).
   - colonne: `ALTER` in `try/catch(\PDOException)` (modello `0014_denormalized_counters.php`).
   - indici/FK: controlla `information_schema.STATISTICS`/`KEY_COLUMN_USAGE` prima di crearli.
4. **Applica l'SQL** via skill **db-query** (`q.py`), avendo fatto **backup** prima di ALTER/DROP.
5. **Registra**: al deploy il `Migrator` (`src/Core/Migrator.php`) fa `glob`+`INSERT` automatico; in alternativa manuale:
   `INSERT INTO migrations (migration) VALUES ('00NN_nome')`.

## Regole di schema (non negoziabili)
- FK verso `profiles(id)` / `users(id)` = **INT firmato** (le tabelle base sono INT — un BIGINT UNSIGNED dà mismatch di tipo). PK nuove ad alto volume = `BIGINT UNSIGNED`.
- Sempre `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` (storico: 0003/0004 ruppero con errori 1267/1062 per COLLATE/charset disallineati — sii esplicito).
- `ON DELETE CASCADE` dove l'integrità lo richiede.
- Indici a prefisso composito per coprire più query con uno solo (es. `(recipient_profile_id, status)`).

## Guardrail
- DDL MySQL = **commit implicito**, niente rollback → scrivi difensivo, backup prima.
- `CHECK` non affidabile su MySQL datati → enforce i vincoli nel Service, non solo nello schema.
- Backfill dei dati nella stessa `up()`.
- **Backup prima** di qualunque ALTER/DROP (skill **db-query**).
