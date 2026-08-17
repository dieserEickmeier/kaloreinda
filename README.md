# KalorienTracker

Mobile-First PHP-Webapp zum Tracken von Kalorien, mehrbenutzerfähig.
Barcode-Scanner (OpenFoodFacts, Live- und Foto-Modus) + Fallback auf
GPT-4o Vision API für Nährwerttabellen.

## Voraussetzungen

- PHP 8.1+ mit Extensions: `mysqli`, `curl`
- MariaDB 10.6+ / MySQL 8+
- Webserver: Apache oder Nginx (mod_rewrite für saubere URLs)
- HTTPS (Pflicht für Kamera-Zugriff im Browser!)

## Setup

### 1. Datenbank anlegen

```bash
mysql -u root -p < schema.sql
```

Falls eine bereits existierende Datenbank aktualisiert wird (nicht neu
angelegt): die `CREATE TABLE IF NOT EXISTS`-Anweisungen in `schema.sql`
verändern bestehende Tabellen NICHT automatisch. Am Ende der Datei steht
dafür ein separater Migrations-Abschnitt mit `ALTER TABLE`-Befehlen, der
einmalig manuell ausgeführt werden muss.

### 2. Konfiguration anpassen

`includes/config.php` öffnen und anpassen (Datei wird nie ins Repository/in
Backups eingecheckt – enthält Datenbank-Zugangsdaten und API-Keys):

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'kalorientracker');
define('DB_PASS', 'geheimesPasswort');   // ← ändern!
define('DB_NAME', 'kalorientracker');

define('OPENAI_API_KEY', 'sk-...');      // ← OpenAI API Key
define('TAGESZIEL_KCAL', 2000);          // ← Standard-Kalorienziel, wird pro Nutzer per BMR überschrieben
```

### 3. Benutzer anlegen

Da die App mehrbenutzerfähig ist, muss mindestens ein Account angelegt
werden. Dies geschieht ausschließlich über die CLI (kein Web-Zugriff auf
die Nutzerverwaltung, aus Sicherheitsgründen):

```bash
php manage_users.php add <username> <passwort>
```

### 4. Webserver-Root

Document Root auf `/kalorientracker/` setzen.

### Apache (.htaccess im Root)

```apache
Options -Indexes
php_flag display_errors Off
```

### Nginx

```nginx
server {
    root /var/www/kalorientracker;
    index index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 5. Berechtigungen

```bash
chmod -R 755 /var/www/kalorientracker
chmod 600 includes/config.php   # Config absichern
```

## Dateistruktur

```
kalorientracker/
├── index.php          # Dashboard (heute)
├── scan.php           # Barcode-Scanner (Live + Foto) + Vision-Fallback
├── log.php            # Manuelle Eingabe
├── history.php        # 14-Tage-Verlauf, Einträge bearbeiten/löschen
├── profil.php         # Profil, BMR/TDEE-Einstellungen, Gewichtsverlauf
├── login.php / logout.php
├── manage_users.php   # CLI-only Nutzerverwaltung (kein Web-Zugriff)
├── schema.sql         # Datenbank-Schema + Migrations-Befehle
├── api/
│   ├── barcode.php    # OpenFoodFacts Lookup (mit Cache-Alterung, 90 Tage)
│   ├── vision.php     # GPT-4o Vision API (Nährwerttabelle)
│   ├── barcode_vision.php # GPT-4o Vision API (Barcode-Foto)
│   ├── entry.php      # CRUD für Einträge (inkl. Mengenkorrektur per PUT)
│   ├── activity.php   # Aktivitätskalorien (für Smartwatch-Integration)
│   └── weight.php     # Gewichts-Tracking
├── includes/
│   ├── config.php     # Konfiguration (niemals öffentlich!)
│   ├── db.php         # Datenbankverbindung
│   ├── auth.php       # Session-Verwaltung, Login-Pflicht, CSRF
│   ├── bmr.php         # Mifflin-St-Jeor BMR/TDEE-Berechnung
│   └── layout.php     # Header/Footer Templates
└── assets/
    ├── css/app.css
    └── js/app.js
```

## Features

- **Mehrbenutzerfähig** – Session-basierte Anmeldung, alle Daten pro Nutzer getrennt
- **Drei Scan-Modi**:
  - **Live-Scan** via html5-qrcode (kontinuierlicher Kamera-Stream, sofortige Erkennung)
  - **Barcode-Foto** als Fallback (nutzt die native Kamera-App, kein Dauerzugriff nötig)
  - **Nährwerttabelle fotografieren** → GPT-4o Vision extrahiert die Werte automatisch
- **Manuelle Barcode-Eingabe** als zusätzlicher Fallback
- **Automatischer Cache** – gescannte Produkte werden lokal gespeichert; OpenFoodFacts-Treffer
  werden nach 90 Tagen automatisch aufgefrischt, damit spätere Korrekturen am Originalprodukt ankommen
- **Schnellzugriff** – häufig gescannte Produkte der letzten 60 Tage lassen sich ohne erneuten Scan eintragen
- **Eintrag bearbeiten** – Menge nachträglich korrigieren, Kalorien/Makros werden automatisch neu berechnet
- **Kcal-Vorschau** berechnet sich live bei Mengenänderung
- **14-Tage-Verlauf** mit Makros und aufklappbaren Eintragsdetails
- **Donut-Ring** zeigt Tagesfortschritt (grün/orange/rot)
- **BMR/TDEE-Berechnung** nach Mifflin-St-Jeor mit Aktivitätsfaktoren (inkl. "tracking" für Smartwatch-Nutzer)
- **Gewichtsverlauf** mit Chart.js-Diagramm
- **Dark Mode** optimiert für Smartphone-Nutzung, PWA-fähig (Home-Bildschirm-Installation)

## Sicherheitshinweise

- `config.php` niemals öffentlich zugänglich machen (separates `.htaccess` mit `Require all denied`)
- HTTPS ist Pflicht (Kamerazugriff funktioniert sonst nicht)
- OpenAI API-Key mit Usage-Limit versehen
- `display_errors` in Produktion deaktivieren
- `manage_users.php` ist absichtlich nur per CLI nutzbar, nicht über den Webserver erreichbar

## Bekannte Einschränkung: iOS-Kamera-Berechtigung im Live-Modus

Auf iOS fragt der Live-Scan-Modus bei jedem PWA-Neustart erneut nach
Kamera-Berechtigung. Das liegt an einer seit Jahren offenen WebKit-
Einschränkung (getUserMedia in "Zum Home-Bildschirm hinzugefügt"-Apps
verliert die Berechtigung zuverlässig zwischen App-Starts) und lässt sich
nicht durch Code in dieser App beheben. Der Barcode-Foto-Modus ist davon
nicht betroffen, da er die native Kamera-App statt eines Dauer-Streams nutzt.
