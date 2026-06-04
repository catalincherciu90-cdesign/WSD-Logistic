-- DepanAuto - Schema completa a bazei de date
-- Ruleaza acest SQL in phpMyAdmin (tab "SQL" -> Go).
--
-- Aceasta schema include TOATE tabelele folosite de aplicatie. Versiunea veche
-- definea doar `users`, `requests`, `settings`; restul tabelelor erau create
-- dinamic in runtime (anti-pattern). Acum totul este definit explicit aici.
--
-- Daca baza de date are alt nume (ex. `wsdlogistics`), ajusteaza liniile de mai jos.

CREATE DATABASE IF NOT EXISTS depanauto CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE depanauto;

-- ---------------------------------------------------------------------------
-- Utilizatori (clienti si depanatori)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    token         VARCHAR(64) UNIQUE NOT NULL,
    role          ENUM('client', 'depanator') NOT NULL,
    name          VARCHAR(120) DEFAULT NULL,
    email         VARCHAR(190) UNIQUE DEFAULT NULL,
    phone         VARCHAR(40)  DEFAULT NULL,
    password_hash VARCHAR(255) DEFAULT NULL,
    -- Folosit de panoul admin pentru aprobare/suspendare conturi.
    -- NOTA: depanatorii noi ar trebui creati cu status 'pending' (vezi auth.php).
    status        ENUM('active', 'pending', 'suspended') NOT NULL DEFAULT 'active',
    lat           DOUBLE DEFAULT NULL,
    lng           DOUBLE DEFAULT NULL,
    online        TINYINT(1) DEFAULT 0,
    fcm_token     VARCHAR(255) DEFAULT NULL,
    -- Dovada consimtamantului GDPR (data acceptarii Termenilor + Politicii).
    consent_at    DATETIME DEFAULT NULL,
    last_seen     DATETIME DEFAULT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------------
-- Profil client (datele masinii)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS client_profiles (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_token VARCHAR(64) UNIQUE NOT NULL,
    car_plate  VARCHAR(20) DEFAULT NULL,
    car_brand  VARCHAR(60) DEFAULT NULL,
    car_model  VARCHAR(60) DEFAULT NULL,
    car_year   VARCHAR(8)  DEFAULT NULL
);

-- ---------------------------------------------------------------------------
-- Profil depanator (vehicul de interventie, licenta, rating)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS depanator_profiles (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_token       VARCHAR(64) UNIQUE NOT NULL,
    license_number   VARCHAR(60) DEFAULT NULL,
    vehicle_plate    VARCHAR(20) DEFAULT NULL,
    vehicle_type     VARCHAR(40) DEFAULT NULL,
    vehicle_brand    VARCHAR(60) DEFAULT NULL,
    vehicle_capacity VARCHAR(40) DEFAULT NULL,
    bio              TEXT DEFAULT NULL,
    rating           DECIMAL(3,2) NOT NULL DEFAULT 5.00,
    total_jobs       INT NOT NULL DEFAULT 0
);

-- ---------------------------------------------------------------------------
-- Cereri de depanare
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS requests (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    client_token    VARCHAR(64) NOT NULL,
    depanator_token VARCHAR(64) DEFAULT NULL,
    status          ENUM('waiting', 'accepted', 'completed', 'cancelled') DEFAULT 'waiting',
    client_lat      DOUBLE DEFAULT NULL,
    client_lng      DOUBLE DEFAULT NULL,
    distance_km     DOUBLE DEFAULT NULL,
    price_ron       DOUBLE DEFAULT NULL,
    cancelled_by    ENUM('client', 'depanator') DEFAULT NULL,
    cancel_reason   VARCHAR(255) DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    accepted_at     DATETIME DEFAULT NULL,
    completed_at    DATETIME DEFAULT NULL,
    cancelled_at    DATETIME DEFAULT NULL
);

-- ---------------------------------------------------------------------------
-- Mesaje pe o cerere (de la depanator catre client)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS request_messages (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    request_id  INT NOT NULL,
    sender_role ENUM('client', 'depanator') NOT NULL,
    message     VARCHAR(500) NOT NULL,
    seen        TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------------
-- Evaluari (rating client -> depanator), o evaluare per cursa
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ratings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    request_id      INT NOT NULL UNIQUE,
    client_token    VARCHAR(64) NOT NULL,
    depanator_token VARCHAR(64) NOT NULL,
    stars           TINYINT NOT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------------
-- Administratori panou
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,
    email         VARCHAR(190) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_super      TINYINT(1) NOT NULL DEFAULT 0,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login    DATETIME DEFAULT NULL
);

-- ---------------------------------------------------------------------------
-- Sesiuni admin (token de sesiune cu expirare)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_sessions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    admin_id   INT NOT NULL,
    token      VARCHAR(64) UNIQUE NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------------
-- Setari platforma (pret pe km etc.)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(64) UNIQUE NOT NULL,
    setting_value VARCHAR(255) NOT NULL
);

-- Valori default pentru preturi
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('price_per_km', '4.50'),
    ('price_fixed',  '10.00'),
    ('price_min',    '25.00');

-- ---------------------------------------------------------------------------
-- Indexuri pentru performanta
-- ---------------------------------------------------------------------------
CREATE INDEX idx_users_token        ON users(token);
CREATE INDEX idx_users_role_online  ON users(role, online);
CREATE INDEX idx_users_status       ON users(status);
CREATE INDEX idx_requests_status    ON requests(status);
CREATE INDEX idx_requests_client    ON requests(client_token);
CREATE INDEX idx_requests_depanator ON requests(depanator_token);
CREATE INDEX idx_messages_request   ON request_messages(request_id);
CREATE INDEX idx_ratings_depanator  ON ratings(depanator_token);
CREATE INDEX idx_admin_sessions_tok ON admin_sessions(token);

-- ---------------------------------------------------------------------------
-- Primul administrator
-- ---------------------------------------------------------------------------
-- NU pune parola in clar aici. Genereaza hash-ul cu PHP:
--   php -r "echo password_hash('PAROLA_TA', PASSWORD_DEFAULT);"
-- apoi ruleaza (inlocuind hash-ul):
-- INSERT INTO admins (name, email, password_hash, is_super)
-- VALUES ('Admin', 'admin@wsdlogistics.ro', '$2y$....HASH....', 1);
