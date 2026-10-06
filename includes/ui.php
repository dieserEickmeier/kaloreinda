<?php
/**
 * Gemeinsame Anzeige- und Berechnungs-Helfer für Heute, Verlauf und Profil.
 */
require_once __DIR__ . '/bmr.php';

/**
 * Auswählbare Startseiten (Einstellungen → Ansicht). Schlüssel = Wert in
 * profil.startseite, nur diese Pfade sind als Ziel von start.php erlaubt.
 */
function startseiten(): array {
    return [
        'heute'    => ['label' => 'Heute',    'pfad' => '/index.php'],
        'erfassen' => ['label' => 'Erfassen', 'pfad' => '/log.php'],
        'verlauf'  => ['label' => 'Verlauf',  'pfad' => '/history.php'],
        'gerichte' => ['label' => 'Gerichte', 'pfad' => '/gerichte.php'],
        'profil'   => ['label' => 'Profil',   'pfad' => '/profil.php'],
    ];
}

/**
 * Geschätzte Schrittlänge in cm aus der Körpergröße (gängige Faustformel:
 * Männer × 0,415, Frauen × 0,413).
 */
function schrittlaengeAutoCm(array $profil): float {
    $faktor = ($profil['geschlecht'] ?? 'm') === 'w' ? 0.413 : 0.415;
    return round((int)($profil['groesse_cm'] ?? 175) * $faktor, 1);
}

/** Schrittlänge in Metern: eigener Wert aus dem Profil, sonst automatisch. */
function schrittlaengeM(array $profil): float {
    $cm = (float)($profil['schrittlaenge_cm'] ?? 0);
    return ($cm > 0 ? $cm : schrittlaengeAutoCm($profil)) / 100;
}

/** Ganzzahl mit deutschem Tausenderpunkt: 1253 → „1.253“ */
function fmtZahl(float $n): string {
    return number_format(round($n), 0, ',', '.');
}

/** Menge ohne überflüssige Nachkommastellen: 60.0 → „60“, 12.5 → „12,5“ */
function fmtMenge(float $g): string {
    return rtrim(rtrim(number_format($g, 1, ',', ''), '0'), ',');
}

/**
 * Exponentiell gewichteter gleitender Durchschnitt – gleiche Logik wie im
 * Gewichtschart (profil.php): alpha dynamisch, mehr Punkte → stärker glätten.
 */
function ewma(array $werte, ?float $alpha = null): array {
    if (!$werte) return [];
    $alpha ??= max(0.1, min(0.4, 2 / (count($werte) + 1)));
    $out = [$werte[0]];
    for ($i = 1; $i < count($werte); $i++) {
        $out[] = $alpha * $werte[$i] + (1 - $alpha) * $out[$i - 1];
    }
    return $out;
}

/**
 * Alle Gewichtsmessungen mit Trendwert, älteste zuerst.
 *
 * Der Trend (EWMA) läuft immer über die GESAMTE Historie; Ansichten wie
 * „30 Tage“ schneiden daraus nur ihren Ausschnitt aus. Würde man ihn je
 * Zeitfenster neu berechnen, hinge der „aktuelle Trend“ vom gewählten
 * Fenster ab (Startwert = erste Wiegung im Fenster, alpha je nach Anzahl)
 * und Heute, Profil und Verlauf zeigten unterschiedliche Werte.
 *
 * @return array<int,array{datum:string,kg:float,trend:float}>
 */
function gewichtsTrend(mysqli $db, int $userId): array {
    $st = $db->prepare("SELECT datum, kg FROM gewicht WHERE user_id = ? ORDER BY datum, id");
    $st->bind_param('i', $userId);
    $st->execute();
    $rows  = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $trend = ewma(array_map(fn($r) => (float)$r['kg'], $rows));
    return array_map(fn($r, $t) => ['datum' => $r['datum'], 'kg' => (float)$r['kg'], 'trend' => round($t, 2)],
                     $rows, $trend);
}

/** SVG-Polyline-Punkte für eine Sparkline in einer Box von $w × $h. */
/**
 * $min/$max optional: fester Wertebereich der y-Achse (z.B. wie im
 * Gewichtschart des Profils), sonst wird auf min..max der Werte gestreckt.
 */
function sparklinePoints(array $werte, int $w, int $h, int $pad = 3, ?float $min = null, ?float $max = null): string {
    $n = count($werte);
    if ($n < 2) return '';
    $min ??= min($werte); $max ??= max($werte);
    $span = ($max - $min) ?: 1;
    $pts = [];
    foreach (array_values($werte) as $i => $v) {
        $x = round($i / ($n - 1) * $w, 1);
        $y = round($pad + (1 - ($v - $min) / $span) * ($h - 2 * $pad), 1);
        $pts[] = "$x,$y";
    }
    return implode(' ', $pts);
}

/** CSS-Klasse für den Quellen-Punkt eines Eintrags. */
function entrySourceClass(?int $gerichtId, ?string $quelle, ?int $produktId): string {
    if ($gerichtId)                              return 'src-dish';
    if ($produktId === null && $quelle === null) return 'src-quick';
    if ($quelle === 'manuell' || $quelle === null) return 'src-man';
    return 'src-scan';
}

/** Lesbares Quell-Label zu entrySourceClass(). */
function entrySourceLabel(string $cls): string {
    return ['src-dish' => 'Gericht', 'src-quick' => 'Schnell', 'src-man' => 'Manuell', 'src-scan' => 'Barcode'][$cls] ?? '';
}

/**
 * Tageswerte (Summen, Aktivität, Gewicht, Ziel) für einen Datumsbereich.
 *
 * Ziel-Logik wie auf „Heute“ (index.php): ein manuelles Tagesziel aus
 * `tagesziele` hat Vorrang, sonst TDEE(Gewicht des Tages) − Defizit
 * (min. 1200), sonst TAGESZIEL_KCAL. Aktivitätskalorien werden addiert.
 * Gewicht des Tages = letzte Messung an/vor dem Tag; vor der ersten Messung
 * die erste Messung.
 *
 * @return array<string,array> nach Datum (Y-m-d) aufsteigend
 */
function ladeTageswerte(mysqli $db, int $userId, array $profil, string $von, string $bis): array {
    $q = function (string $sql, string $types, ...$args) use ($db) {
        $st = $db->prepare($sql);
        $st->bind_param($types, ...$args);
        $st->execute();
        return $st->get_result()->fetch_all(MYSQLI_ASSOC);
    };

    $summen = array_column($q("
        SELECT datum, SUM(kcal) AS kcal, SUM(eiweiss) AS eiweiss, SUM(fett) AS fett,
               SUM(kh) AS kh, COUNT(*) AS anzahl
        FROM eintraege WHERE user_id = ? AND datum BETWEEN ? AND ?
        GROUP BY datum", 'iss', $userId, $von, $bis), null, 'datum');

    $aktiv = array_column($q("
        SELECT datum, SUM(kcal) AS kcal FROM aktivitaet_log
        WHERE user_id = ? AND datum BETWEEN ? AND ? GROUP BY datum",
        'iss', $userId, $von, $bis), 'kcal', 'datum');

    $manuell = array_column($q("
        SELECT datum, kcal_ziel FROM tagesziele
        WHERE user_id = ? AND datum BETWEEN ? AND ?",
        'iss', $userId, $von, $bis), 'kcal_ziel', 'datum');

    $gewichte = $q("SELECT datum, kg FROM gewicht WHERE user_id = ? AND datum <= ?
                    ORDER BY datum, id", 'is', $userId, $bis);

    $defizit = (int)($profil['defizit_kcal'] ?? 500);
    $kg      = $gewichte ? (float)$gewichte[0]['kg'] : null;
    $gi      = 0;
    $out     = [];
    for ($d = new DateTime($von), $end = new DateTime($bis); $d <= $end; $d->modify('+1 day')) {
        $datum = $d->format('Y-m-d');
        while ($gi < count($gewichte) && $gewichte[$gi]['datum'] <= $datum) {
            $kg = (float)$gewichte[$gi]['kg'];
            $gi++;
        }
        $tdee = berechneTdee($profil ?: null, $kg);
        if (isset($manuell[$datum])) {
            $basis = (int)$manuell[$datum];
        } elseif ($tdee) {
            $basis = max(1200, $tdee - $defizit);
        } else {
            $basis = TAGESZIEL_KCAL;
        }
        $s       = $summen[$datum] ?? null;
        $akt     = (float)($aktiv[$datum] ?? 0);
        $kcal    = (float)($s['kcal'] ?? 0);
        $ziel    = $basis + $akt;
        $out[$datum] = [
            'datum'     => $datum,
            'kcal'      => $kcal,
            'eiweiss'   => (float)($s['eiweiss'] ?? 0),
            'fett'      => (float)($s['fett'] ?? 0),
            'kh'        => (float)($s['kh'] ?? 0),
            'anzahl'    => (int)($s['anzahl'] ?? 0),
            'aktivKcal' => $akt,
            'kg'        => $kg,
            'basisZiel' => $basis,
            'ziel'      => $ziel,
            'uebrig'    => $ziel - $kcal,
            'leer'      => !$s,
        ];
    }
    return $out;
}

/** Mini-Ring (SVG) für Wochenstreifen. $anteil 0..1, $over = Ziel überschritten. */
function miniRing(float $anteil, bool $over, int $size = 30): string {
    $r = ($size - 6) / 2; $c = round(2 * M_PI * $r, 2);
    $off = round($c * (1 - max(0, min(1, $anteil))), 2);
    $mid = $size / 2;
    $cls = $over ? 'ws-bar over' : 'ws-bar';
    $bar = $anteil > 0 ? "<circle class=\"$cls\" cx=\"$mid\" cy=\"$mid\" r=\"$r\" stroke-dasharray=\"$c\" stroke-dashoffset=\"$off\"/>" : '';
    return "<svg width=\"$size\" height=\"$size\" viewBox=\"0 0 $size $size\" aria-hidden=\"true\"><circle class=\"ws-track\" cx=\"$mid\" cy=\"$mid\" r=\"$r\"/>$bar</svg>";
}

/** Kurzer Wochentag (Mo, Di, …) für ein Y-m-d-Datum. */
function wochentagKurz(string $datum): string {
    return ['So','Mo','Di','Mi','Do','Fr','Sa'][(int)date('w', strtotime($datum))];
}
