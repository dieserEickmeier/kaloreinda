# Pebble-Apps für KalorienTracker

Zwei Apps für Pebble (Ziel: Pebble Time 2 / Plattform `emery`, gebaut für alle
aktuellen Plattformen), die die Tagesschritte an die KalorienTracker-API schicken:

```
POST /api/activity.php   {"schritte": 8432, "datum": "2026-10-07"}
```

Die API rechnet daraus Aktivitätskalorien (Schrittlänge und Gewicht aus dem
Profil) und **ersetzt** den Schritte-Eintrag des Tages – mehrfaches Senden ist
unbedenklich. Die Antwort enthält außerdem die Tagesbilanz (`uebrig`,
`aktiv_gesamt`), die das Ziffernblatt anzeigt.

## `watchface/` – Ziffernblatt „Kalorien Schritte“

Layout „Bento“ – Kacheln wie in der App:

| | |
|---|---|
| **Datum + Uhrzeit** (oben, ganze Breite) | |
| **Noch übrig** (kcal laut API; orange „Über Ziel“, wenn überschritten) | **Schritte** heute + Balken bis 10.000 |
| **Puls** (Pulssensor der Pebble Time 2) | **Aktiv** (Aktivkalorien des Tages laut API) + Uhrzeit des letzten Syncs |

„Noch übrig“ und „Aktiv“ sind so aktuell wie der letzte Sync (höchstens eine
Stunde alt). Das Ziffernblatt sendet außerdem jedes Mal, wenn es neu startet –
z.B. beim Zurückkehren aus einer App wie „Kalorien Sync“; danach ist auch
gerade Gegessenes berücksichtigt. Nach Mitternacht stehen dort Striche bis zum ersten Sync des
neuen Tages. Das Schrittziel steht fest im Code (`SCHRITTZIEL`), weil Pebble
Health keins bereitstellt.

Gebaut für die eckigen Plattformen (emery, basalt, diorite, flint); auf runden
Displays passt das Kachel-Layout nicht.

Sendet **stündlich und um 23:55**. Bei Verbindungsfehlern wird alle 5 Minuten erneut versucht bzw. sofort,
sobald Bluetooth wieder verbunden ist; API-Fehler (z. B. falscher Key) werden
in der Aktiv-Kachel angezeigt und erst zur nächsten vollen Stunde erneut
versucht. Während des Sendens steht dort „...“, bei Fehlern ein „!“.

Warnsymbole (nur im Warnfall, rot, rechts oben in der Zeit-Kachel): **Bluetooth zum Handy getrennt** und
**Akku unter 15 %** (nicht beim Laden; die Pebble meldet den Akku in 10-%-Schritten).

## `sync-app/` – App „Kalorien Sync“ (manueller Sync)

Sendet sofort beim Öffnen, zeigt das Ergebnis und schließt sich nach 3 Sekunden
(bei Fehlern nach 8 Sekunden; Mitte = erneut senden).

Gedacht für **Quick Launch**: auf der Uhr *Einstellungen → Quick Launch →
Oben (bzw. Unten) halten → Kalorien Sync*. Dann reicht auf dem Ziffernblatt
ein langer Druck auf die Taste. (Ziffernblätter selbst dürfen keine Tasten
verwenden; Touch auf dem ruhenden Ziffernblatt wird von der Firmware nicht
an die App weitergegeben.)

## Menü-Icons

Je App ein 25 × 25-Icon unter `resources/images/` (`menu_icon~color.png`,
`menu_icon~bw.png` für Schwarzweiß-Modelle): Apfel als Füllstand
(Ziffernblatt) bzw. Sync-Pfeile (App). Sichtbar auf der Uhr in der
Ziffernblatt-Liste (farbig), im App-Menü und bei Quick Launch (dort färbt die
Firmware die Icons einheitlich ein). Die Core-App auf dem Handy zeigt für selbst
installierte Apps keine Bilder an (nur Platzhalter) – dort erscheinen Icons nur
bei Apps aus dem Pebble-App-Store.

## `shared/`

- `pkjs/index.js` – Handy-Teil für beide Apps (per Symlink eingebunden):
  Einstellungsseite (API-Adresse, API-Key) und Weiterleitung an die API.
  Adresse und Key bleiben auf dem Handy. Jede Pebble-App hat ihren eigenen
  Speicher – der Key wird **je App einmal** eingetragen.
- `fonts/` – Space Grotesk Bold (statische Instanz der Variable Font,
  Lizenz: `OFL-SpaceGrotesk.txt`)

## Bauen

Mit dem Pebble-SDK (`uv tool install pebble-tool`, `pebble sdk install latest`):

```
cd watchface && pebble build    # → build/watchface.pbw
cd sync-app  && pebble build    # → build/sync-app.pbw
```

## Einrichten

1. Beide `.pbw` in der Pebble-App installieren
2. In der Pebble-App bei beiden Apps → Einstellungen → API-Key eintragen
   (zu finden in KalorienTracker unter Profil → Einstellungen → API-Dokumentation)
3. Auf der Uhr Quick Launch mit „Kalorien Sync“ belegen
