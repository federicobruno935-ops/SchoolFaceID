-- ============================================================
-- SchoolFaceID — Schema E-R per phpMyAdmin Designer
-- ============================================================

DROP DATABASE IF EXISTS schoolfaceid_er;
CREATE DATABASE schoolfaceid_er CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE schoolfaceid_er;

-- ============================================================
-- CLASSI
-- ============================================================
CREATE TABLE classi (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nome            VARCHAR(50) NOT NULL,
    anno_scolastico VARCHAR(20),
    UNIQUE KEY uq_classe (nome, anno_scolastico)
) ENGINE=InnoDB;

-- ============================================================
-- UTENTI (studenti + professori + admin)
-- ============================================================
CREATE TABLE utenti (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    nome          VARCHAR(100) NOT NULL,
    cognome       VARCHAR(100) NOT NULL,
    email         VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    ruolo         ENUM('studente','professore','admin') NOT NULL,
    classe_id     INT NULL,
    foto_path     VARCHAR(255),
    encoding      LONGTEXT,
    attivo        TINYINT(1) DEFAULT 1,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_utenti_classe FOREIGN KEY (classe_id)
        REFERENCES classi(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- PRESENZE
-- ============================================================
CREATE TABLE presenze (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    studente_id  INT NOT NULL,
    data         DATE NOT NULL,
    ora_entrata  TIME,
    ora_uscita   TIME,
    stato        ENUM('presente','assente','ritardo','uscita_anticipata') NOT NULL,
    rilevato_da  ENUM('facciale','manuale') DEFAULT 'facciale',
    note         TEXT,
    CONSTRAINT fk_presenze_studente FOREIGN KEY (studente_id)
        REFERENCES utenti(id) ON DELETE CASCADE,
    INDEX idx_presenze_data (data),
    INDEX idx_presenze_studente_data (studente_id, data)
) ENGINE=InnoDB;

-- ============================================================
-- LOG RICONOSCIMENTI
-- ============================================================
CREATE TABLE log_riconoscimenti (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    utente_id  INT NULL,
    timestamp  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    confidenza FLOAT,
    esito      ENUM('riconosciuto','sconosciuto') NOT NULL,
    CONSTRAINT fk_log_utente FOREIGN KEY (utente_id)
        REFERENCES utenti(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- PASSWORD RESET TOKENS
-- ============================================================
CREATE TABLE password_reset_tokens (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    utente_id  INT NOT NULL,
    token      VARCHAR(64) NOT NULL UNIQUE,
    scadenza   DATETIME NOT NULL,
    usato      TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_token_utente FOREIGN KEY (utente_id)
        REFERENCES utenti(id) ON DELETE CASCADE,
    INDEX idx_token_scadenza (scadenza, usato)
) ENGINE=InnoDB;

-- ============================================================
-- DATI DI ESEMPIO (per visualizzare le relazioni nel Designer)
-- ============================================================
INSERT INTO classi (nome, anno_scolastico) VALUES
  ('4D', '2025/2026'),
  ('5A', '2025/2026'),
  ('5B', '2025/2026');

INSERT INTO utenti (nome, cognome, email, password_hash, ruolo, classe_id, foto_path) VALUES
  ('Admin',      'Sistema',  'admin@scuola.it',                        '$2y$10$exampleHash', 'admin',     NULL, NULL),
  ('Mario',      'Bianchi',  'mario.bianchi@scuola.it',                '$2y$10$exampleHash', 'professore', NULL, NULL),
  ('Federico',   'Bruno',    'federico.bruno@studenti.iispascal.it',   '$2y$10$exampleHash', 'studente',   1,    'uploads/studenti/1_federico_bruno/1.png'),
  ('Alessandro', 'Rossi',    'alessandro.rossi@studenti.iispascal.it', '$2y$10$exampleHash', 'studente',   1,    'uploads/studenti/2_alessandro_rossi/1.png');

INSERT INTO presenze (studente_id, data, ora_entrata, ora_uscita, stato, rilevato_da) VALUES
  (3, CURDATE(), '07:55:00', NULL, 'presente', 'facciale'),
  (4, CURDATE(), '08:22:00', NULL, 'ritardo',  'facciale');

INSERT INTO log_riconoscimenti (utente_id, confidenza, esito) VALUES
  (3, 0.92, 'riconosciuto'),
  (4, 0.88, 'riconosciuto'),
  (NULL, 0.45, 'sconosciuto');
