# Kalorien Schritte – Pebble-Ziffernblatt

Ziffernblatt für Pebble (Ziel: Pebble Time 2 / Plattform `emery`), das die
Tagesschritte **stündlich und um 23:55** an die KalorienTracker-API schickt:

```
POST /api/activity.php   {"schritte": 8432, "datum": "2026-10-07"}
```

Die API rechnet daraus Aktivitätskalorien (Schrittlänge und Gewicht aus dem
Profil) und **ersetzt** den Schritte-Eintrag des Tages – mehrfaches Senden ist
unbedenklich. Schlägt ein Sync fehl (Handy nicht verbunden, kein Netz), versucht
die Uhr es alle 5 Minuten erneut; bei API-Fehlern (z. B. falscher Key) erst zur
nächsten vollen Stunde.

Warnsymbole (nur im Warnfall, rot): **Bluetooth zum Handy getrennt** und
**Akku unter 15 %** (nicht beim Laden; die Pebble meldet den Akku in 10-%-Schritten).
Beim Wiederverbinden wird ein ausstehender Sync sofort nachgeholt.

## Aufbau

- `src/c/main.c` – Ziffernblatt (Uhrzeit, Datum, Schritte, Sync-Status),
  Health-Abfrage, Sync-Zeitplan
- `src/pkjs/index.js` – Handy-Teil: Einstellungsseite (API-Adresse, API-Key)
  und Weiterleitung an die API. Adresse und Key bleiben auf dem Handy.
- `resources/fonts/` – Space Grotesk Bold (statische Instanz der Variable Font,
  Lizenz: `OFL-SpaceGrotesk.txt`)

## Bauen

Mit dem Pebble-SDK (`uv tool install pebble-tool`, `pebble sdk install latest`):

```
pebble build          # → build/<ordnername>.pbw
```

## Einrichten

1. `.pbw` in der Pebble-App installieren
2. Ziffernblatt in der Pebble-App öffnen → Einstellungen → API-Key eintragen
   (zu finden in KalorienTracker unter Profil → Einstellungen → API-Dokumentation)
3. „Speichern & jetzt senden“ – der Status unten auf dem Ziffernblatt zeigt
   Uhrzeit und kcal des letzten erfolgreichen Syncs
