<?php
// Auswertung des Nährwertscan-Protokolls (Tabelle ocr_log, siehe api/ocr_log.php).
// Nur per CLI:
//   php tools/ocr_log.php                      → Sitzungen der letzten 7 Tage
//   php tools/ocr_log.php <sitzung> <verz>     → Bilder + Daten einer Sitzung exportieren
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
$db = db();

$werteText = function ($w): string {
    if (!is_array($w)) return '–';
    $f = fn($k) => (isset($w[$k]) && $w[$k] !== '') ? $w[$k] : '–';
    return sprintf('kcal %s · E %s · F %s · KH %s · P %s',
        $f('kcal'), $f('eiweiss'), $f('fett'), $f('kh'), $f('portion'));
};

if ($argc < 2) {
    $res = $db->query("
        SELECT sitzung, MIN(erstellt_am) AS beginn, SUM(typ = 'frame') AS frames,
               MAX(CASE WHEN typ = 'ende' THEN daten END)        AS ende,
               MAX(CASE WHEN typ = 'gespeichert' THEN daten END) AS gespeichert,
               MAX(typ = 'verworfen')                             AS verworfen,
               MAX(CASE WHEN typ = 'start' THEN daten END)        AS start
        FROM ocr_log
        WHERE erstellt_am > NOW() - INTERVAL 7 DAY
        GROUP BY sitzung ORDER BY beginn
    ");
    foreach ($res as $r) {
        $ende  = json_decode($r['ende'] ?? 'null', true);
        $start = json_decode($r['start'] ?? 'null', true);
        $gesp  = json_decode($r['gespeichert'] ?? 'null', true);
        echo "{$r['sitzung']}  {$r['beginn']}  {$r['frames']} Analysen  "
           . "Ende: " . ($ende['grund'] ?? 'offen') . "  Engine: " . ($start['engine'] ?? '?') . "\n";
        echo "    erkannt:     " . $werteText($ende['werte'] ?? null) . "\n";
        if ($gesp) {
            echo "    gespeichert: " . $werteText($gesp) . "  („" . ($gesp['name'] ?? '') . "“)\n";
        } elseif ($r['verworfen']) {
            echo "    Formular verworfen\n";
        }
    }
    exit;
}

$sitzung = $argv[1];
$verz    = rtrim($argv[2] ?? ('ocr_log_' . $sitzung), '/');
if (!is_dir($verz) && !mkdir($verz, 0755, true)) { fwrite(STDERR, "Kann $verz nicht anlegen\n"); exit(1); }

$stmt = $db->prepare("SELECT typ, daten, bild, erstellt_am FROM ocr_log WHERE sitzung = ? ORDER BY id");
$stmt->bind_param('s', $sitzung);
$stmt->execute();
$nr = 0;
$jsonl = fopen("$verz/protokoll.jsonl", 'w');
foreach ($stmt->get_result() as $r) {
    $eintrag = ['typ' => $r['typ'], 'zeit' => $r['erstellt_am'], 'daten' => json_decode($r['daten'] ?? 'null', true)];
    if ($r['typ'] === 'frame') {
        $nr++;
        $eintrag['nr'] = $nr;
        if ($r['bild'] !== null) {
            $endung = substr($r['bild'], 0, 4) === "\x89PNG" ? 'png' : 'jpg';
            $datei  = sprintf('frame-%03d.%s', $nr, $endung);
            file_put_contents("$verz/$datei", $r['bild']);
            $eintrag['bild'] = $datei;
        }
    }
    fwrite($jsonl, json_encode($eintrag, JSON_UNESCAPED_UNICODE) . "\n");
}
fclose($jsonl);
echo "$nr Analysen nach $verz exportiert\n";
