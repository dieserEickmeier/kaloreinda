<?php
/**
 * Grundumsatz (BMR) + TDEE Berechnung
 * Mifflin-St-Jeor-Formel (1990) – gilt als eine der genauesten Schätzformeln
 *
 * Männer:  BMR = 10 * kg + 6.25 * cm - 5 * Alter + 5
 * Frauen:  BMR = 10 * kg + 6.25 * cm - 5 * Alter - 161
 */
function berechneTdee(?array $profil, ?float $kg): ?int {
    if (!$profil || !$kg) return null;

    $alter = date('Y') - ($profil['geburtsjahr'] ?? 1990);
    $cm    = (int)($profil['groesse_cm'] ?? 175);

    $bmr = (10 * $kg) + (6.25 * $cm) - (5 * $alter)
         + (($profil['geschlecht'] ?? 'm') === 'w' ? -161 : 5);

    $faktoren = [
        'sitzend'    => 1.2,
        'leicht'     => 1.375,
        'moderat'    => 1.55,
        'aktiv'      => 1.725,
        'sehr_aktiv' => 1.9,
        // Für Smartwatch-Tracker: nur Grundumsatz, Aktivität wird separat
        // über das Aktivitäts-Log addiert (keine Doppelzählung)
        'tracking'   => 1.0,
    ];
    $f = $faktoren[$profil['aktivitaet'] ?? 'moderat'] ?? 1.55;

    return (int)round($bmr * $f);
}

/**
 * Berechnet die DGE-Makroziele basierend auf Kalorienziel, Gewicht, Alter und Geschlecht.
 *
 * DGE-Empfehlungen (2024):
 * - Eiweiß: 0,8 g/kg KG (>65 Jahre: 1,0 g/kg), mind. 10% der Energie
 * - Fett:   max. 30% der Energiezufuhr
 * - KH:     > 50% der Energiezufuhr (Rest nach Eiweiß und Fett)
 *
 * @return array ['eiweiss' => g, 'fett' => g, 'kh' => g]
 */
function berechneMakroziele(array $profil, ?float $kg, int $zielKcal): array {
    $alter = $profil ? (date('Y') - (int)($profil['geburtsjahr'] ?? 1990)) : 35;

    // Eiweiß: 0,8 g/kg (>65 Jahre: 1,0 g/kg) — falls kein Gewicht bekannt,
    // Schätzung aus Kalorienziel (10% der Energie / 4 kcal/g)
    if ($kg && $kg > 20) {
        $eiweissFaktor = $alter >= 65 ? 1.0 : 0.8;
        $eiweissG      = round($kg * $eiweissFaktor);
    } else {
        $eiweissG = round($zielKcal * 0.15 / 4);
    }

    // Fett: 30% der Energiezufuhr
    $fettG = round($zielKcal * 0.30 / 9);

    // KH: Rest der Energie (mind. 50% angestrebt)
    $eiweissKcal = $eiweissG * 4;
    $fettKcal    = $fettG * 9;
    $khKcal      = max(0, $zielKcal - $eiweissKcal - $fettKcal);
    $khG         = round($khKcal / 4);

    return ['eiweiss' => $eiweissG, 'fett' => $fettG, 'kh' => $khG];
}
