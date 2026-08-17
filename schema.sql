-- ─────────────────────────────────────────────────────────────────────────────
-- KalorienTracker – MariaDB Schema
-- Ausführen mit: mysql -u root -p < schema.sql
-- ─────────────────────────────────────────────────────────────────────────────

CREATE DATABASE IF NOT EXISTS kalorientracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'kalorientracker'@'%' IDENTIFIED BY 'geheimesPasswort';
GRANT ALL PRIVILEGES ON kalorientracker.* TO 'kalorientracker'@'%';
FLUSH PRIVILEGES;

USE kalorientracker;

-- ── User ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(80)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name  VARCHAR(120),
    aktiv         TINYINT(1)   NOT NULL DEFAULT 1,
    api_key       VARCHAR(64)  DEFAULT NULL UNIQUE,
    erstellt_am   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Produkte (geteilt, kein user_id – Barcode-Cache für alle) ────────────────
CREATE TABLE IF NOT EXISTS produkte (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    barcode       VARCHAR(30)  UNIQUE,
    name          VARCHAR(255) NOT NULL,
    kcal_100g     DECIMAL(8,2) NOT NULL DEFAULT 0,
    eiweiss_100g  DECIMAL(8,2) DEFAULT 0,
    fett_100g     DECIMAL(8,2) DEFAULT 0,
    kh_100g       DECIMAL(8,2) DEFAULT 0,
    portion_g     DECIMAL(8,1) DEFAULT NULL,  -- Portionsgröße in Gramm (optional)
    quelle        ENUM('openfoodfacts','vision','manuell') DEFAULT 'manuell',
    ersteller_id  INT          DEFAULT NULL,  -- nur bei quelle='manuell': Ersteller. NULL bei geteilten
                                                -- Cache-Produkten (openfoodfacts) – dort ist Ownership egal.
    erstellt_am   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    aktualisiert_am TIMESTAMP  DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (ersteller_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_barcode (barcode),
    INDEX idx_ersteller (ersteller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Mahlzeit-Einträge ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS eintraege (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NOT NULL,
    produkt_id  INT,
    name        VARCHAR(255) NOT NULL,
    menge_g     DECIMAL(8,1) NOT NULL DEFAULT 100,
    kcal        DECIMAL(8,1) NOT NULL,
    eiweiss     DECIMAL(8,1) DEFAULT 0,
    fett        DECIMAL(8,1) DEFAULT 0,
    kh          DECIMAL(8,1) DEFAULT 0,
    datum       DATE         NOT NULL,
    notiz       VARCHAR(255),
    erstellt_am TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (produkt_id) REFERENCES produkte(id) ON DELETE SET NULL,
    INDEX idx_datum  (datum),
    INDEX idx_user   (user_id),
    -- Zusammengesetzter Index: praktisch jede Abfrage in der App filtert
    -- gleichzeitig nach user_id UND datum (Dashboard, Verlauf, BMR-Berechnung).
    -- Die beiden Einzelindizes oben helfen dabei weniger als ein kombinierter
    -- Index, da MariaDB sonst nur einen der beiden nutzen und den Rest manuell
    -- filtern muss. Bleibt zusätzlich zu den Einzelindizes bestehen, da andere
    -- Abfragen (z.B. nur nach datum) weiterhin von ihnen profitieren können.
    INDEX idx_user_datum (user_id, datum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Gewichtsverlauf ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS gewicht (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NOT NULL,
    datum       DATE         NOT NULL,
    kg          DECIMAL(5,2) NOT NULL,
    erstellt_am TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_datum (datum),
    UNIQUE KEY uq_user_datum (user_id, datum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Benutzerprofil ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS profil (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT  NOT NULL UNIQUE,
    groesse_cm      INT  DEFAULT 175,
    aktivitaet      ENUM('sitzend','leicht','moderat','aktiv','sehr_aktiv','tracking') DEFAULT 'moderat',
    defizit_kcal    INT  DEFAULT 500,
    geschlecht      ENUM('m','w') DEFAULT 'm',
    geburtsjahr     INT  DEFAULT 1990,
    eintraege_gruppieren TINYINT(1) DEFAULT 0,
    erstellt_am     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    aktualisiert_am TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Aktivitätskalorien ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS aktivitaet_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NOT NULL,
    datum       DATE         NOT NULL,
    bezeichnung VARCHAR(255) NOT NULL DEFAULT 'Training',
    kcal        INT          NOT NULL,
    quelle      VARCHAR(50)  DEFAULT 'api',
    erstellt_am TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_datum  (datum),
    INDEX idx_user   (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Manuelle Tagesziele (optional, überschreibt TDEE-Berechnung) ─────────────
CREATE TABLE IF NOT EXISTS tagesziele (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT  NOT NULL,
    datum       DATE NOT NULL,
    kcal_ziel   INT  NOT NULL DEFAULT 2000,
    erstellt_am TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_user_datum (user_id, datum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Sessions (für zukünftige DB-basierte Session-Verwaltung) ─────────────────
CREATE TABLE IF NOT EXISTS sessions (
    id          VARCHAR(128) PRIMARY KEY,
    user_id     INT          NOT NULL,
    ip          VARCHAR(45),
    user_agent  VARCHAR(255),
    last_active TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    erstellt_am TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- MIGRATION für bestehende Live-Datenbank (manuell ausführen!)
-- CREATE TABLE IF NOT EXISTS verändert eine bereits existierende Tabelle
-- NICHT – diese beiden ALTER TABLE-Befehle müssen einmalig von Hand
-- ausgeführt werden, damit die oben beschriebenen Änderungen auch auf der
-- produktiven Datenbank ankommen.
-- ─────────────────────────────────────────────────────────────────────────────

-- 1. Cache-Alterung für den Barcode-Produkt-Cache:
ALTER TABLE produkte
    ADD COLUMN aktualisiert_am TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    AFTER erstellt_am;

-- 2. Zusammengesetzter Index für die häufigste Abfrage (user_id + datum):
ALTER TABLE eintraege
    ADD INDEX idx_user_datum (user_id, datum);

-- 3. Portionsgröße in Gramm (optional, aus OpenFoodFacts oder manuell gepflegt):
ALTER TABLE produkte
    ADD COLUMN portion_g DECIMAL(8,1) DEFAULT NULL AFTER kh_100g;

-- 4. Einträge gruppieren Option im Profil:
ALTER TABLE profil
    ADD COLUMN eintraege_gruppieren TINYINT(1) DEFAULT 0 AFTER geburtsjahr;

-- 5. Persönlicher API-Key pro Nutzer:
ALTER TABLE users ADD COLUMN api_key VARCHAR(64) DEFAULT NULL UNIQUE AFTER aktiv;
-- Bestehende Nutzer bekommen automatisch einen zufälligen Key:
UPDATE users SET api_key = LOWER(HEX(RANDOM_BYTES(32))) WHERE api_key IS NULL;

-- 6. Hilfetexte anzeigen Option:
ALTER TABLE profil
    ADD COLUMN hilfetext_anzeigen TINYINT(1) DEFAULT 1 AFTER eintraege_gruppieren;

-- 7. OOBE-Flag in users:
ALTER TABLE users ADD COLUMN oobe_abgeschlossen TINYINT(1) DEFAULT 0 AFTER api_key;

-- Bestehende Nutzer mit vorhandenem Profil als OOBE-abgeschlossen markieren:
UPDATE users u
    INNER JOIN profil p ON p.user_id = u.id
    SET u.oobe_abgeschlossen = 1;

-- 8. Makro-Anzeige Option:
ALTER TABLE profil ADD COLUMN makros_anzeigen TINYINT(1) DEFAULT 1 AFTER hilfetext_anzeigen;

-- 9. Gerichte (zusammengesetzte Mahlzeiten)
ALTER TABLE eintraege ADD COLUMN gericht_id INT DEFAULT NULL AFTER produkt_id;

CREATE TABLE IF NOT EXISTS gerichte (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NOT NULL,
    name        VARCHAR(255) NOT NULL,
    portionen   DECIMAL(4,1) NOT NULL DEFAULT 1,
    erstellt_am TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gericht_zutaten (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    gericht_id  INT          NOT NULL,
    produkt_id  INT          DEFAULT NULL,
    name        VARCHAR(255) NOT NULL,
    menge_g     DECIMAL(8,1) NOT NULL DEFAULT 100,
    kcal_100g   DECIMAL(8,2) NOT NULL DEFAULT 0,
    eiweiss_100g DECIMAL(8,2) DEFAULT 0,
    fett_100g   DECIMAL(8,2) DEFAULT 0,
    kh_100g     DECIMAL(8,2) DEFAULT 0,
    FOREIGN KEY (gericht_id) REFERENCES gerichte(id) ON DELETE CASCADE,
    FOREIGN KEY (produkt_id) REFERENCES produkte(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Performance-Indizes (sicher mit IF NOT EXISTS Logik via ALTER IGNORE)
ALTER TABLE eintraege
    ADD INDEX IF NOT EXISTS idx_gericht_id (gericht_id),
    ADD INDEX IF NOT EXISTS idx_produkt_id (produkt_id);

ALTER TABLE gerichte
    ADD INDEX IF NOT EXISTS idx_user_id (user_id);

ALTER TABLE gericht_zutaten
    ADD INDEX IF NOT EXISTS idx_gericht_id (gericht_id);

-- Volltext-Index für schnellere Produktsuche
ALTER TABLE produkte
    ADD INDEX IF NOT EXISTS idx_name (name(80));

-- 10. Eigentümer für manuelle Produkte (Fix: "Nur anlegen" ohne Eintrag
--     machte Produkt in search.php unauffindbar, da Sichtbarkeit bisher
--     über EXISTS(eintraege) statt über echte Ownership geprüft wurde):
ALTER TABLE produkte
    ADD COLUMN ersteller_id INT DEFAULT NULL AFTER quelle,
    ADD INDEX idx_ersteller (ersteller_id),
    ADD CONSTRAINT fk_produkte_ersteller FOREIGN KEY (ersteller_id) REFERENCES users(id) ON DELETE SET NULL;

-- Backfill: bestehende manuelle Produkte bekommen den Ersteller aus der
-- ersten vorhandenen Buchung zugewiesen, damit sie weiter auffindbar bleiben.
-- Produkte ohne jede Buchung (z.B. per "Nur anlegen" erzeugt, vor diesem Fix)
-- bleiben NULL und sind vorübergehend nur noch für niemanden per Suche
-- sichtbar – in der Praxis sollten das nur ganz frisch angelegte Testfälle
-- sein, da das Feature erst mit diesem Fix korrekt funktioniert.
UPDATE produkte p
INNER JOIN (
    SELECT produkt_id, MIN(user_id) AS user_id
    FROM eintraege
    WHERE produkt_id IS NOT NULL
    GROUP BY produkt_id
) e ON e.produkt_id = p.id
SET p.ersteller_id = e.user_id
WHERE p.quelle = 'manuell' AND p.ersteller_id IS NULL;
