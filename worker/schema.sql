-- DepanAuto — schema Cloudflare D1 (SQLite)
-- Aplica: wrangler d1 execute depanauto --remote --file=./schema.sql
-- (Echivalentul SQLite al lui depanauto/database.sql din varianta PHP/MySQL.)

-- Utilizatori (clienti si depanatori)
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    token         TEXT UNIQUE NOT NULL,
    role          TEXT NOT NULL CHECK (role IN ('client','depanator')),
    name          TEXT,
    email         TEXT UNIQUE,
    phone         TEXT,
    password_hash TEXT,
    status        TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','pending','suspended')),
    lat           REAL,
    lng           REAL,
    online        INTEGER NOT NULL DEFAULT 0,
    fcm_token     TEXT,
    consent_at    TEXT,
    last_seen     TEXT,
    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS client_profiles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_token TEXT UNIQUE NOT NULL,
    car_plate  TEXT,
    car_brand  TEXT,
    car_model  TEXT,
    car_year   TEXT
);

CREATE TABLE IF NOT EXISTS depanator_profiles (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    user_token       TEXT UNIQUE NOT NULL,
    license_number   TEXT,
    vehicle_plate    TEXT,
    vehicle_type     TEXT,
    vehicle_brand    TEXT,
    vehicle_capacity TEXT,
    bio              TEXT,
    rating           REAL NOT NULL DEFAULT 5.0,
    total_jobs       INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS requests (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    client_token    TEXT NOT NULL,
    depanator_token TEXT,
    status          TEXT NOT NULL DEFAULT 'waiting' CHECK (status IN ('waiting','accepted','completed','cancelled')),
    client_lat      REAL,
    client_lng      REAL,
    distance_km     REAL,
    price_ron       REAL,
    cancelled_by    TEXT,
    cancel_reason   TEXT,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    accepted_at     TEXT,
    completed_at    TEXT,
    cancelled_at    TEXT
);

CREATE TABLE IF NOT EXISTS request_messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id  INTEGER NOT NULL,
    sender_role TEXT NOT NULL CHECK (sender_role IN ('client','depanator')),
    message     TEXT NOT NULL,
    seen        INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS ratings (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id      INTEGER NOT NULL UNIQUE,
    client_token    TEXT NOT NULL,
    depanator_token TEXT NOT NULL,
    stars           INTEGER NOT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS admins (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT NOT NULL,
    email         TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    is_super      INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT NOT NULL DEFAULT (datetime('now')),
    last_login    TEXT
);

CREATE TABLE IF NOT EXISTS admin_sessions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id   INTEGER NOT NULL,
    token      TEXT UNIQUE NOT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS settings (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    setting_key   TEXT UNIQUE NOT NULL,
    setting_value TEXT NOT NULL
);

-- Rate limiting (per IP + actiune)
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket   TEXT PRIMARY KEY,
    count    INTEGER NOT NULL DEFAULT 0,
    reset_at INTEGER NOT NULL
);

INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES
    ('price_per_km', '4.50'),
    ('price_fixed',  '10.00'),
    ('price_min',    '25.00');

CREATE INDEX IF NOT EXISTS idx_users_role_online  ON users(role, online);
CREATE INDEX IF NOT EXISTS idx_users_status       ON users(status);
CREATE INDEX IF NOT EXISTS idx_requests_status    ON requests(status);
CREATE INDEX IF NOT EXISTS idx_requests_client    ON requests(client_token);
CREATE INDEX IF NOT EXISTS idx_requests_depanator ON requests(depanator_token);
CREATE INDEX IF NOT EXISTS idx_messages_request   ON request_messages(request_id);
CREATE INDEX IF NOT EXISTS idx_ratings_depanator  ON ratings(depanator_token);
CREATE INDEX IF NOT EXISTS idx_admin_sessions_tok ON admin_sessions(token);

-- Primul admin: genereaza un hash PBKDF2 cu scriptul din worker si insereaza manual:
--   INSERT INTO admins (name,email,password_hash,is_super) VALUES ('Admin','admin@wsdlogistics.ro','<hash>',1);
