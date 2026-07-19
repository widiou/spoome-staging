-- ============================================================================
-- Spoome v2 — RESET TOTALE DEL DB (piazza pulita).  ⚠️ DISTRUTTIVO E IRREVERSIBILE ⚠️
-- ----------------------------------------------------------------------------
-- ESEGUIRE SOLO SE:
--   • `dbz33z7hapyekg` è il DB della SOLA beta (NON condiviso con la produzione), e
--   • hai già fatto un EXPORT/BACKUP completo del database.
-- Ordine: eseguire QUESTO script, POI `0001_create_core_tables.sql`.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- Tabelle legacy (vecchio Spoome).
DROP TABLE IF EXISTS
    agenzie,
    atleti,
    athletes,
    bigevents,
    claim_requests,
    contatti,
    esperienze,
    fan,
    links_personali,
    organizations,
    professionisti,
    profili_base,
    risultati,
    rss_cache,
    search_log,
    societa;

-- Nomi condivisi legacy/v2 + eventuali tabelle v2 già create: si rimuovono per
-- rigenerarle pulite con la 0001.
DROP TABLE IF EXISTS
    profiles,
    profile_types,
    login_attempts,
    password_resets,
    email_verifications,
    auth_tokens,
    users,
    sports,
    migrations;

SET FOREIGN_KEY_CHECKS = 1;

-- Verifica: ora il database dovrebbe essere VUOTO.
--   SHOW TABLES;
-- Poi esegui 0001_create_core_tables.sql per creare lo schema v2.
