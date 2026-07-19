-- Spoome v2 — Migrazione 0001: schema fondante (auth, token API, sport, tipi profilo, profili)
-- Eseguibile a mano su phpMyAdmin / console DB di PhpStorm. Equivalente a database/migrations/0001_create_core_tables.php.
-- Sicuro/idempotente (CREATE TABLE IF NOT EXISTS + INSERT IGNORE). Registra sé stesso in `migrations`.
-- Charset/collation uniformi: utf8mb4 / utf8mb4_unicode_ci.

-- Tracking migrazioni (così il runner PHP non la riapplica).
CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Utenti (solo autenticazione).
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('member','moderator','admin') NOT NULL DEFAULT 'member',
    status ENUM('pending','active','suspended') NOT NULL DEFAULT 'pending',
    email_verified_at TIMESTAMP NULL DEFAULT NULL,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Token Bearer per API/native (salvati come SHA-256 hex, mai in chiaro).
CREATE TABLE IF NOT EXISTS auth_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    kind ENUM('access','refresh') NOT NULL DEFAULT 'access',
    device_label VARCHAR(190) NULL,
    expires_at TIMESTAMP NOT NULL,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    last_used_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_auth_token_hash (token_hash),
    KEY idx_auth_user (user_id),
    CONSTRAINT fk_auth_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verifica email (token hashed, monouso, con scadenza).
CREATE TABLE IF NOT EXISTS email_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_emailver_hash (token_hash),
    KEY idx_emailver_user (user_id),
    CONSTRAINT fk_emailver_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reset password (token hashed, monouso, con scadenza).
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pwreset_hash (token_hash),
    KEY idx_pwreset_user (user_id),
    CONSTRAINT fk_pwreset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tentativi di login (throttling anti-brute-force).
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(190) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    successful TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_identifier (identifier, attempted_at),
    KEY idx_login_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tassonomia sport.
CREATE TABLE IF NOT EXISTS sports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(140) NOT NULL,
    category VARCHAR(120) NULL,
    icon VARCHAR(120) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_sports_slug (slug),
    UNIQUE KEY uq_sports_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tipi di profilo (scalabili, config-driven).
CREATE TABLE IF NOT EXISTS profile_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(50) NOT NULL,
    label VARCHAR(120) NOT NULL,
    is_organization TINYINT(1) NOT NULL DEFAULT 0,
    attributes_schema JSON NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_ptype_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Profili (identità pubblica dell'utente).
CREATE TABLE IF NOT EXISTS profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    profile_type_id INT NOT NULL,
    handle VARCHAR(60) NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    headline VARCHAR(200) NULL,
    bio TEXT NULL,
    sport_id INT NULL,
    avatar_media_id INT NULL,
    cover_media_id INT NULL,
    location_city VARCHAR(120) NULL,
    location_region VARCHAR(120) NULL,
    location_country VARCHAR(120) NULL,
    verified_at TIMESTAMP NULL DEFAULT NULL,
    visibility ENUM('public','members','private') NOT NULL DEFAULT 'public',
    attributes JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_profiles_handle (handle),
    UNIQUE KEY uq_profiles_user (user_id),
    KEY idx_profiles_type (profile_type_id),
    KEY idx_profiles_sport (sport_id),
    CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_profiles_type FOREIGN KEY (profile_type_id) REFERENCES profile_types (id) ON DELETE RESTRICT,
    CONSTRAINT fk_profiles_sport FOREIGN KEY (sport_id) REFERENCES sports (id) ON DELETE SET NULL,
    FULLTEXT KEY ft_profiles_search (display_name, headline, bio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed dei tipi di profilo.
INSERT IGNORE INTO profile_types (`key`, label, is_organization, sort) VALUES
    ('atleta',       'Atleta',       0, 10),
    ('societa',      'Società',      1, 20),
    ('associazione', 'Associazione', 1, 30),
    ('federazione',  'Federazione',  1, 40),
    ('fan',          'Fan',          0, 50);

-- Registra la migrazione come applicata.
INSERT IGNORE INTO migrations (migration) VALUES ('0001_create_core_tables');
