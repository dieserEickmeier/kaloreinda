# KalorienTracker

Mobile-First PHP-Webapp (PWA) zum Tracken von Kalorien, mehrbenutzerfähig.
Erfassen per Barcode-Scan (OpenFoodFacts), Nährwerttabellen-Scan (OCR lokal im
Browser), Suche, Gerichte oder manuell. Optional schickt eine Pebble-Uhr die
Tagesschritte als Aktivitätskalorien.

## Voraussetzungen

- PHP 8.1+ mit `mysqli` und `allow_url_fopen` (für den OpenFoodFacts-Abruf)
- MariaDB 10.6+ / MySQL 8+
- Webserver: Apache (`.htaccess` liegt bei) oder Nginx
- HTTPS (Pflicht für den Kamerazugriff im Browser)

## Setup

### 1. Datenbank anlegen

```bash
mysql -u root -p < schema.sql
```

Wird eine bestehende Datenbank aktualisiert: Die `CREATE TABLE IF NOT EXISTS`-
Anweisungen ändern vorhandene Tabellen nicht. Am Ende von `schema.sql` steht
dafür ein Migrations-Abschnitt mit `ALTER TABLE`-Befehlen, der einmalig manuell
ausgeführt werden muss.

### 2. Konfiguration

`includes/config.php` anlegen (ist in `.gitignore`, enthält Zugangsdaten):

```php
<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'kalorientracker');
define('DB_PASS', 'geheimesPasswort');   // ← ändern!
define('DB_NAME', 'kalorientracker');

define('APP_NAME', 'KalorienTracker');
define('TAGESZIEL_KCAL', 2000);          // Standardziel, wird pro Nutzer per BMR/TDEE ersetzt

// optional: Kontakt im User-Agent für OpenFoodFacts (von OFF erwünscht)
// define('OFF_CONTACT', 'mail@example.org');
```

### 3. Benutzer anlegen

Nutzer werden ausschließlich per CLI verwaltet (kein Web-Zugriff):

```bash
php manage_users.php add <username> <passwort>
```

Beim ersten Login führt die Ersteinrichtung (`oobe.php`) durch das Profil
(Größe, Gewicht, Geschlecht, Geburtsjahr, Aktivität, Defizit).

### 4. Webserver

Document Root auf das Projektverzeichnis setzen.

**Apache:** Die mitgelieferte `.htaccess` sperrt Verzeichnislisten und
`manage_users.php` und setzt Sicherheits-Header. `display_errors` in Produktion
abschalten.

**Nginx:**

```nginx
server {
    root /var/www/kalorientracker;
    index index.php;

    location ~ ^/(manage_users\.php|includes/|schema\.sql) { deny all; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 5. Berechtigungen

```bash
chmod -R 755 /var/www/kalorientracker
chmod 600 includes/config.php
```

## Dateistruktur

```
kalorientracker/
├── start.php          # Einstieg (PWA start_url) → in den Einstellungen gewählte Startseite
├── index.php          # Heute: Tagesbilanz, Einträge, Aktivität
├── log.php            # Erfassen: Barcode, Nährwert-Scan, Suche, schnell/manuell, Gerichte
├── gerichte.php       # Gerichte (Rezepte aus Zutaten) anlegen und bearbeiten
├── history.php        # Verlauf: Charts und Kennzahlen (bis 365 Tage), letzte 7 Tage
├── profil.php         # Gewicht und Aktivität
├── einstellungen.php  # Profil-/BMR-Daten, Startseite, Anzeige-Optionen
├── oobe.php           # Ersteinrichtung nach dem ersten Login
├── api_docs.php       # Persönlicher API-Key + API-Dokumentation
├── scan.php           # Alt-URL, leitet auf log.php weiter
├── login.php / logout.php
├── manage_users.php   # CLI-only Nutzerverwaltung
├── manifest.json      # PWA-Manifest inkl. Quick Actions
├── schema.sql         # Datenbank-Schema + Migrations-Befehle
├── api/
│   ├── barcode.php        # OpenFoodFacts-Lookup mit lokalem Cache
│   ├── search.php         # Produktsuche / häufige Lebensmittel
│   ├── produkt_save.php   # Produkt anlegen (optional mit Barcode), Portionsgröße setzen
│   ├── produkt_delete.php # eigenes manuelles Produkt löschen
│   ├── entry.php          # Einträge anlegen/bearbeiten/löschen
│   ├── gericht.php        # Gerichte + als Eintrag buchen
│   ├── activity.php       # Aktivitätskalorien / Schritte (Session oder API-Key)
│   └── weight.php         # Gewicht
├── includes/
│   ├── config.php     # Konfiguration (nicht im Repository)
│   ├── db.php         # Datenbankverbindung
│   ├── auth.php       # Session, Login-Pflicht, CSRF, API-Key
│   ├── bmr.php        # Mifflin-St-Jeor BMR/TDEE
│   ├── ui.php         # gemeinsame Anzeige-/Berechnungs-Helfer
│   └── layout.php     # Header, Navigation, globaler Scanner
├── assets/
│   ├── css/app.css
│   ├── js/app.js          # App-weite Helfer
│   ├── js/scanner.js      # Barcode-Live-Scanner (global)
│   ├── js/zxing-wasm.js + zxing_reader.wasm   # Barcode-Erkennung
│   ├── js/ocr/            # OCR-Bundle, Web Worker, ONNX-Runtime-WASM
│   └── models/            # OCR-Modelle (PP-OCRv6 tiny)
└── pebble/            # Pebble-Ziffernblatt und Sync-App (siehe pebble/README.md)
```

## Features

- **Mehrbenutzerfähig** – Session-Login, alle Daten pro Nutzer getrennt
- **Barcode-Scan** – Live-Scanner (ZXing/WASM, EAN/UPC) über den Scan-Button der
  Navigation auf jeder Seite, alternativ Barcode eintippen
- **Nährwerttabelle scannen** (Beta) – live ohne Auslösen: PaddleOCR (PP-OCRv6 tiny)
  läuft lokal im Browser (Web Worker), liest kcal, Makros und Portionsgröße und
  übernimmt automatisch, sobald die Werte bestätigt und plausibel sind; tolerant
  gegen schräg gehaltene Etiketten
- **Unbekannter Barcode** – direkt per Nährwert-Scan oder manuell anlegen, der
  Barcode wird mitgespeichert und beim nächsten Scan gefunden
- **Produkt-Cache** – OpenFoodFacts-Treffer werden lokal gespeichert und nach
  90 Tagen aufgefrischt; ist OpenFoodFacts nicht erreichbar, greift der Cache
- **Sichtbarkeit** – gescannte Produkte sind für alle Nutzer sichtbar, manuell
  angelegte nur für den Ersteller (mit Barcode angelegte für alle, die ihn scannen),
  Gerichte immer privat
- **Suche und häufige Lebensmittel** – die 8 meistgenutzten Produkte als Kacheln
- **Portionen** – Menge in Gramm oder Portionen, Portionsgröße wird gespeichert
- **Schneller Eintrag** – nur kcal (und optional Makros), ohne Produkt anzulegen
- **„Nur anlegen“** – Produkt speichern, ohne es heute zu buchen (Meal-Prep)
- **Gerichte** – aus Zutaten zusammengesetzt, nach Portionen oder Gramm buchbar
- **Einträge bearbeiten** – Menge nachträglich ändern, Werte werden neu berechnet
- **Tagesziel** – BMR/TDEE nach Mifflin-St-Jeor mit Aktivitätsfaktor und Defizit,
  Aktivitätskalorien erhöhen das Tagesbudget
- **Verlauf** – Kalorien pro Tag gegen das Ziel und Gewichtstrend, bis 365 Tage
- **API-Key** – für Kurzbefehle/Smartwatch, Doku unter `api_docs.php`
- **Pebble** – Ziffernblatt mit Tagesbilanz, Schritte → Aktivitätskalorien
- **PWA** – Home-Bildschirm-Installation, Quick Actions (Scannen, Suchen,
  Schneller Eintrag, Gewicht), Dark Mode

## Scan-Bibliotheken aktualisieren

Alle Scan-Dateien liegen lokal unter `assets/` (kein CDN zur Laufzeit).

**Barcode – [zxing-wasm](https://github.com/Sec-ant/zxing-wasm)** (aktuell 3.1.5, MIT)
`dist/iife/reader/index.js` → `assets/js/zxing-wasm.js`, `dist/reader/zxing_reader.wasm` → `assets/js/zxing_reader.wasm`,
dann `ZXING_ASSET_VERSION` in `assets/js/scanner.js` anpassen.

**Nährwerte – [ppu-paddle-ocr](https://github.com/PT-Perkasa-Pilar-Utama/ppu-paddle-ocr)** (6.6.1, MIT) mit
onnxruntime-web (1.30.0, MIT, nur WASM-Backend) und PP-OCRv6 tiny (Apache 2.0). Bundle bauen:

```bash
npm install ppu-paddle-ocr@6.6.1 onnxruntime-web@1.30.0 esbuild
cat > entry.js <<'JS'
import * as ort from "onnxruntime-web";
import { PaddleOcrService } from "ppu-paddle-ocr/web";
globalThis.PpuOcr = { PaddleOcrService, ort };   // globalThis: auch im Web Worker
JS
echo 'export * from "./node_modules/onnxruntime-web/dist/ort.wasm.min.mjs";' > ort-shim.js
npx esbuild entry.js --bundle --minify --format=iife --target=es2020,safari16 \
    --alias:onnxruntime-web=./ort-shim.js --outfile=assets/js/ocr/ppu-ocr.js
cp node_modules/onnxruntime-web/dist/ort-wasm-simd-threaded.{mjs,wasm} assets/js/ocr/
# assets/js/ocr/ocr-worker.js ist eigener Code und bleibt unverändert
```

Modelle (`PP-OCRv6_tiny_det.ort`, `PP-OCRv6_tiny_rec.ort`, `ppocrv6_tiny_dict.txt`) von
[huggingface.co/snowfluke/ppu-paddle-ocr-models](https://huggingface.co/snowfluke/ppu-paddle-ocr-models) nach `assets/models/`.
Danach `OCR_ASSET_VERSION` in `log.php` erhöhen.

## Sicherheitshinweise

- `includes/config.php` darf nicht ausgeliefert werden (Nginx: `deny`, siehe oben)
- HTTPS ist Pflicht (sonst kein Kamerazugriff)
- `display_errors` in Produktion deaktivieren
- `manage_users.php` ist nur per CLI nutzbar und per `.htaccess` gesperrt
- API-Keys sind pro Nutzer (einsehbar unter `api_docs.php`) und wie Passwörter zu behandeln

## Bekannte Einschränkung: iOS-Kamera-Berechtigung

In der als Home-Bildschirm-App installierten PWA fragt iOS bei jedem
App-Neustart erneut nach der Kamera-Berechtigung (Barcode- und Nährwert-Scan).
Das ist eine seit Jahren offene WebKit-Einschränkung von `getUserMedia` in
Standalone-Web-Apps und lässt sich nicht durch Code in dieser App beheben.
Innerhalb einer laufenden Sitzung bleibt die Berechtigung erhalten.
