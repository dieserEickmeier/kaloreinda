<?php
require_once __DIR__ . '/config.php';

function db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        // 'p:' prefix = persistente Verbindung (Connection Pooling)
        // Details nur ins Server-Log, nicht an den Browser (Hostname/Benutzer
        // der Datenbank sollen nicht nach außen sichtbar werden).
        try {
            $conn = new mysqli('p:' . DB_HOST, DB_USER, DB_PASS, DB_NAME);
        } catch (mysqli_sql_exception $e) {
            error_log('DB-Verbindung fehlgeschlagen: ' . $e->getMessage());
            http_response_code(500);
            die(json_encode(['error' => 'Datenbankverbindung fehlgeschlagen']));
        }
        if ($conn->connect_error) {
            error_log('DB-Verbindung fehlgeschlagen: ' . $conn->connect_error);
            http_response_code(500);
            die(json_encode(['error' => 'Datenbankverbindung fehlgeschlagen']));
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

// ─── Schema automatisch anlegen ──────────────────────────────────────────────
// HINWEIS: initSchema() wird NICHT mehr automatisch bei jedem Request
// aufgerufen (siehe Ende der Datei). Das lief bisher bei JEDER einzelnen
// Seitenanfrage und jedem API-Call und führte dabei einen mehrteiligen
// multi_query() mit acht CREATE TABLE-Anweisungen aus – unnötiger Overhead,
// und mysqli::multi_query gilt als fehleranfällig, wenn nicht alle
// Ergebnisse vollständig konsumiert werden, bevor die nächste Query auf
// derselben Verbindung läuft. Das ist ein plausibler Kandidat für die
// zuvor beobachteten, detaillosen HTTP-500-Fehler beim Eintragen, da ein
// Fehler hier schon VOR dem eigentlichen Request-Code auftreten und die
// Verbindung in einen unsauberen Zustand bringen könnte.
// Die Funktion bleibt als manuell aufrufbares Werkzeug erhalten (z.B. für
// ein zukünftiges Setup-Skript), wird aber nicht mehr automatisch beim
// Einbinden von db.php ausgeführt. Schema-Änderungen laufen über
// schema.sql, das ohnehin schon gepflegt wird und einen eigenen
// Migrations-Abschnitt für bestehende Datenbanken hat.
function initSchema(): void {
    $db = db();
    $db->multi_query("
        CREATE TABLE IF NOT EXISTS users (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            username      VARCHAR(80) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            display_name  VARCHAR(120),
            aktiv         TINYINT(1) NOT NULL DEFAULT 1,
            erstellt_am   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS produkte (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            barcode      VARCHAR(30) UNIQUE,
            name         VARCHAR(255) NOT NULL,
            kcal_100g    DECIMAL(8,2) NOT NULL DEFAULT 0,
            eiweiss_100g DECIMAL(8,2) DEFAULT 0,
            fett_100g    DECIMAL(8,2) DEFAULT 0,
            kh_100g      DECIMAL(8,2) DEFAULT 0,
            quelle       ENUM('openfoodfacts','vision','manuell') DEFAULT 'manuell',
            erstellt_am  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eintraege (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            user_id     INT NOT NULL,
            produkt_id  INT,
            name        VARCHAR(255) NOT NULL,
            menge_g     DECIMAL(8,1) NOT NULL DEFAULT 100,
            kcal        DECIMAL(8,1) NOT NULL,
            eiweiss     DECIMAL(8,1) DEFAULT 0,
            fett        DECIMAL(8,1) DEFAULT 0,
            kh          DECIMAL(8,1) DEFAULT 0,
            datum       DATE NOT NULL,
            notiz       VARCHAR(255),
            erstellt_am TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (produkt_id) REFERENCES produkte(id) ON DELETE SET NULL,
            INDEX idx_datum (datum),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS gewicht (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            user_id     INT NOT NULL,
            datum       DATE NOT NULL,
            kg          DECIMAL(5,2) NOT NULL,
            erstellt_am TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_datum (datum)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS profil (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            user_id         INT NOT NULL UNIQUE,
            groesse_cm      INT DEFAULT 175,
            aktivitaet      ENUM('sitzend','leicht','moderat','aktiv','sehr_aktiv','tracking') DEFAULT 'moderat',
            defizit_kcal    INT DEFAULT 500,
            geschlecht      ENUM('m','w') DEFAULT 'm',
            geburtsjahr     INT DEFAULT 1990,
            erstellt_am     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            aktualisiert_am TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS aktivitaet_log (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            user_id     INT NOT NULL,
            datum       DATE NOT NULL,
            bezeichnung VARCHAR(255) NOT NULL DEFAULT 'Training',
            kcal        INT NOT NULL,
            quelle      VARCHAR(50) DEFAULT 'api',
            erstellt_am TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_datum (datum)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS tagesziele (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            user_id     INT NOT NULL,
            datum       DATE UNIQUE NOT NULL,
            kcal_ziel   INT NOT NULL DEFAULT 2000,
            erstellt_am TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS sessions (
            id          VARCHAR(128) PRIMARY KEY,
            user_id     INT NOT NULL,
            ip          VARCHAR(45),
            user_agent  VARCHAR(255),
            last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            erstellt_am TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    // multi_query-Ergebnisse konsumieren
    while ($db->more_results()) $db->next_result();
}
