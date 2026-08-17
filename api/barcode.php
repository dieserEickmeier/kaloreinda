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
// Cache-Alterung: ein Treffer wird nur verwendet, wenn er entweder manuell
// angelegt wurde (quelle='manuell' – es gibt keine externe Quelle, von der
// wir auffrischen könnten) ODER jünger als 90 Tage ist. Ältere
// OpenFoodFacts-Einträge werden stillschweigend neu abgerufen, damit
// spätere Korrekturen am Originalprodukt (z.B. Rezepturänderung) irgendwann
// ankommen, statt für immer auf dem ersten Scan-Ergebnis sitzen zu bleiben.
$stmt = $db->prepare("
    SELECT * FROM produkte
    WHERE barcode = ?
      AND (quelle = 'manuell' OR aktualisiert_am > DATE_SUB(NOW(), INTERVAL 90 DAY))
");
$stmt->bind_param('s', $code);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if ($row) {
    // Cache-Treffer: direkt zurückgeben — es sei denn, portion_g fehlt noch,
    // dann holen wir die Daten frisch von OpenFoodFacts um sie nachzutragen.
    if (!is_null($row['portion_g']) || $row['quelle'] === 'manuell') {
        echo json_encode(['ok' => true, 'product' => $row, 'from' => 'cache']);
        exit;
    }
    // portion_g fehlt → weiter zu OpenFoodFacts (UPDATE folgt unten)
}

// ─── 2. OpenFoodFacts API ──────────────────────────────────────────────────
// serving_quantity und serving_size explizit anfordern – bei fields-Parameter
// liefert die v2-API NUR die genannten Felder, fehlende Felder kommen nie an.
$url  = "https://world.openfoodfacts.org/api/v2/product/{$code}.json?fields=product_name,nutriments,brands,serving_quantity,serving_size";
$ctx  = stream_context_create(['http' => [
    'timeout' => 6,
    'header'  => "User-Agent: KalorienTracker/1.0 (contact@example.com)\r\n",
]]);

$raw  = @file_get_contents($url, false, $ctx);
$data = $raw ? json_decode($raw, true) : null;

if (!$data || ($data['status'] ?? 0) !== 1) {
    echo json_encode(['ok' => false, 'error' => 'Produkt nicht in OpenFoodFacts']);
    exit;
}

$p    = $data['product'];
$n    = $p['nutriments'] ?? [];
$name = trim(($p['product_name'] ?? '') . ' ' . ($p['brands'] ?? ''));
$name = $name ?: 'Unbekanntes Produkt';

$kcal    = (float)($n['energy-kcal_100g']    ?? $n['energy_100g'] ?? 0);
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
$ins = $db->prepare("
    INSERT INTO produkte (barcode, name, kcal_100g, eiweiss_100g, fett_100g, kh_100g, portion_g, quelle)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'openfoodfacts')
    ON DUPLICATE KEY UPDATE
        name=VALUES(name), kcal_100g=VALUES(kcal_100g),
        eiweiss_100g=VALUES(eiweiss_100g), fett_100g=VALUES(fett_100g),
        kh_100g=VALUES(kh_100g), portion_g=VALUES(portion_g)
");
$ins->bind_param('ssddddd', $code, $name, $kcal, $eiweiss, $fett, $kh, $portionG);
$ins->execute();
// Bei ON DUPLICATE KEY UPDATE ist insert_id=0 wenn ein bestehender Eintrag
// aktualisiert wurde → echte ID per SELECT nachladen.
$productId = $db->insert_id ?: (function() use ($db, $code) {
    $s = $db->prepare("SELECT id FROM produkte WHERE barcode = ?");
    $s->bind_param('s', $code);
    $s->execute();
    return (int)($s->get_result()->fetch_assoc()['id'] ?? 0);
})();

echo json_encode(['ok' => true, 'product' => [
    'id'          => $productId,
    'barcode'     => $code,
    'name'        => $name,
    'kcal_100g'   => $kcal,
    'eiweiss_100g'=> $eiweiss,
    'fett_100g'   => $fett,
    'kh_100g'     => $kh,
    'portion_g'   => $portionG,
    'quelle'      => 'openfoodfacts',
]]);
