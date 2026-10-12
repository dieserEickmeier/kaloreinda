<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

// Auth: Session ODER API-Key – dieser Endpoint schreibt in die produkte-
// Tabelle (OpenFoodFacts-Cache), darf also nicht öffentlich sein, sonst
// kann jeder die Datenbank mit beliebigen Barcode-Anfragen fluten.
$user = currentUser();
$userId = $user['id'] ?? getUserIdFromApiKey();
if (!$userId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Nicht autorisiert']);
    exit;
}

$code = trim($_GET['code'] ?? '');
if (!$code || !preg_match('/^\d{5,20}$/', $code)) {
    echo json_encode(['ok' => false, 'error' => 'Ungültiger Barcode']);
    exit;
}

$db = db();

// ─── 1. Lokale DB prüfen ──────────────────────────────────────────────────
// Cache-Alterung: ein Treffer wird direkt verwendet, wenn er manuell
// angelegt wurde (quelle='manuell' – es gibt keine externe Quelle, von der
// wir auffrischen könnten) ODER jünger als 90 Tage ist. Ältere
// OpenFoodFacts-Einträge werden neu abgerufen, damit spätere Korrekturen am
// Originalprodukt (z.B. Rezepturänderung) irgendwann ankommen. Fehlt nur die
// Portionsgröße, wird höchstens wöchentlich nachgefragt – sonst ginge jeder
// Scan eines Produkts ohne Portionsangabe erneut an OpenFoodFacts.
// Manuelle Produkte mit Barcode (aus „nicht gefunden → anlegen“) sind wie
// der OpenFoodFacts-Cache für alle sichtbar: der Barcode ist eindeutig, ein
// zweiter Nutzer könnte sonst kein eigenes Produkt dazu anlegen.
$stmt = $db->prepare("
    SELECT *,
           (quelle = 'manuell' OR aktualisiert_am > DATE_SUB(NOW(), INTERVAL 90 DAY)) AS frisch,
           (aktualisiert_am > DATE_SUB(NOW(), INTERVAL 7 DAY)) AS kuerzlich
    FROM produkte WHERE barcode = ?
");
$stmt->bind_param('s', $code);
$stmt->execute();
$cached = $stmt->get_result()->fetch_assoc();
if ($cached) {
    $frisch    = (bool)$cached['frisch'];
    $kuerzlich = (bool)$cached['kuerzlich'];
    unset($cached['frisch'], $cached['kuerzlich']);
    if ($cached['quelle'] === 'manuell'
        || ($frisch && (!is_null($cached['portion_g']) || $kuerzlich))) {
        echo json_encode(['ok' => true, 'product' => $cached, 'from' => 'cache']);
        exit;
    }
}

// Veralteter Cache-Eintrag als Rückfall, falls OpenFoodFacts nicht antwortet
// oder das Produkt dort inzwischen fehlt – besser alte Werte als gar keine.
function cacheFallbackOder(?array $cached, string $error, bool $offline): void {
    if ($cached) {
        echo json_encode(['ok' => true, 'product' => $cached, 'from' => 'cache-veraltet']);
    } else {
        echo json_encode(['ok' => false, 'error' => $error, 'offline' => $offline]);
    }
    exit;
}

// ─── 2. OpenFoodFacts API ──────────────────────────────────────────────────
// serving_quantity und serving_size explizit anfordern – bei fields-Parameter
// liefert die v2-API NUR die genannten Felder, fehlende Felder kommen nie an.
$url  = "https://world.openfoodfacts.org/api/v2/product/{$code}.json"
      . "?fields=product_name,product_name_de,nutriments,brands,serving_quantity,serving_size";
// OpenFoodFacts bittet um einen aussagekräftigen User-Agent mit Kontakt.
// Kontakt optional über OFF_CONTACT in config.php.
$ua   = 'Kaloreinda/1.0' . (defined('OFF_CONTACT') ? ' (' . OFF_CONTACT . ')' : '');
$ctx  = stream_context_create(['http' => [
    'timeout'       => 6,
    'header'        => "User-Agent: {$ua}\r\n",
    'ignore_errors' => true,   // 404 liefert ebenfalls JSON mit status=0
]]);

$raw  = @file_get_contents($url, false, $ctx);
if ($raw === false) {
    cacheFallbackOder($cached, 'OpenFoodFacts nicht erreichbar – bitte später nochmal versuchen', true);
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    cacheFallbackOder($cached, 'OpenFoodFacts antwortet nicht korrekt – bitte später nochmal versuchen', true);
}
if (($data['status'] ?? 0) !== 1) {
    cacheFallbackOder($cached, 'Produkt nicht in OpenFoodFacts', false);
}

$p    = $data['product'];
$n    = $p['nutriments'] ?? [];
$pName = trim($p['product_name_de'] ?? '') ?: trim($p['product_name'] ?? '');
$name = trim($pName . ' ' . ($p['brands'] ?? ''));
$name = $name ?: 'Unbekanntes Produkt';

// energy_100g ist bei OpenFoodFacts immer kJ – nur als Rückfall verwenden
// und umrechnen, sonst landen kJ-Werte (Faktor ~4,2 zu hoch) als kcal im Cache.
if (isset($n['energy-kcal_100g']) && is_numeric($n['energy-kcal_100g'])) {
    $kcal = (float)$n['energy-kcal_100g'];
} elseif (isset($n['energy-kj_100g']) && is_numeric($n['energy-kj_100g'])) {
    $kcal = round((float)$n['energy-kj_100g'] / 4.184, 1);
} elseif (isset($n['energy_100g']) && is_numeric($n['energy_100g'])) {
    $kcal = round((float)$n['energy_100g'] / 4.184, 1);
} else {
    $kcal = 0.0;
}
$eiweiss = (float)($n['proteins_100g']        ?? 0);
$fett    = (float)($n['fat_100g']             ?? 0);
$kh      = (float)($n['carbohydrates_100g']   ?? 0);

// Portionsgröße: OpenFoodFacts liefert serving_quantity (numerischer Wert in g/ml)
// oder serving_size (z.B. "30 g" als String) – wir bevorzugen serving_quantity,
// da es direkt numerisch ist und keine weitere Verarbeitung braucht.
$portionG = null;
if (!empty($p['serving_quantity']) && is_numeric($p['serving_quantity'])) {
    $portionG = (float)$p['serving_quantity'];
} elseif (!empty($p['serving_size'])) {
    // Fallback: "30 g" oder "30g" → Zahl extrahieren
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*g/i', $p['serving_size'], $m)) {
        $portionG = (float)str_replace(',', '.', $m[1]);
    }
}

// ─── In lokaler DB cachen ─────────────────────────────────────────────────
// portion_g: COALESCE, damit eine von Nutzern nachgetragene Portionsgröße
// nicht durch ein fehlendes serving_quantity auf NULL zurückfällt.
// aktualisiert_am explizit setzen – ON UPDATE CURRENT_TIMESTAMP greift nur,
// wenn sich ein Wert ändert, sonst würde ein unverändertes Produkt nach
// 90 Tagen bei jedem Scan neu abgerufen.
$ins = $db->prepare("
    INSERT INTO produkte (barcode, name, kcal_100g, eiweiss_100g, fett_100g, kh_100g, portion_g, quelle)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'openfoodfacts')
    ON DUPLICATE KEY UPDATE
        name=VALUES(name), kcal_100g=VALUES(kcal_100g),
        eiweiss_100g=VALUES(eiweiss_100g), fett_100g=VALUES(fett_100g),
        kh_100g=VALUES(kh_100g), portion_g=COALESCE(VALUES(portion_g), portion_g),
        aktualisiert_am=NOW()
");
$ins->bind_param('ssddddd', $code, $name, $kcal, $eiweiss, $fett, $kh, $portionG);
$ins->execute();

// Gespeicherte Zeile zurückgeben (enthält id und ggf. die erhaltene portion_g)
$s = $db->prepare("SELECT * FROM produkte WHERE barcode = ?");
$s->bind_param('s', $code);
$s->execute();
$row = $s->get_result()->fetch_assoc();

echo json_encode(['ok' => true, 'product' => $row, 'from' => 'openfoodfacts']);
