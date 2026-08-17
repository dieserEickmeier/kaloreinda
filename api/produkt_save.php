<?php
// Speichert/aktualisiert Produkte in der produkte-Tabelle.
// POST  – neues manuelles Produkt anlegen
// PATCH – portion_g für ein bestehendes Produkt nachträglich setzen
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$user = currentUser();
if (!$user) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Nicht eingeloggt']); exit; }
$userId = $user['id'];

$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$db     = db();

// ─── PATCH: portion_g für bestehendes Produkt setzen ─────────────────────────
// Wird aufgerufen, sobald der Nutzer beim Eintragen den Portion-Toggle wählt
// und eine Größe eingibt. Speichert den Wert dauerhaft in der DB, sodass
// beim nächsten Eintrag desselben Produkts "1 Portion (X g)" direkt verfügbar
// ist, ohne erneut abwiegen zu müssen.
if ($method === 'PATCH') {
    $produktId = (int)($body['produkt_id'] ?? 0);
    $portionG  = (isset($body['portion_g']) && is_numeric($body['portion_g']) && (float)$body['portion_g'] > 0)
        ? (float)$body['portion_g'] : null;

    if (!$produktId || !$portionG) {
        echo json_encode(['ok' => false, 'error' => 'produkt_id und portion_g erforderlich']);
        exit;
    }

    $stmt = $db->prepare("UPDATE produkte SET portion_g = ? WHERE id = ?");
    $stmt->bind_param('di', $portionG, $produktId);
    echo json_encode(['ok' => $stmt->execute(), 'portion_g' => $portionG]);
    exit;
}

// ─── POST: Neues manuelles Produkt anlegen ────────────────────────────────────
$name     = substr(trim($body['name'] ?? ''), 0, 255);
$kcal     = max(0, (float)($body['kcal_100g']    ?? 0));
$eiweiss  = max(0, (float)($body['eiweiss_100g'] ?? 0));
$fett     = max(0, (float)($body['fett_100g']    ?? 0));
$kh       = max(0, (float)($body['kh_100g']      ?? 0));
$portionG = (isset($body['portion_g']) && is_numeric($body['portion_g']) && (float)$body['portion_g'] > 0)
    ? (float)$body['portion_g'] : null;

if (!$name || !$kcal) {
    echo json_encode(['ok' => false, 'error' => 'Name und Kalorien erforderlich']);
    exit;
}

// Gleichnamiges manuelles Produkt mit denselben kcal bereits vorhanden?
// Scope auf ersteller_id: sonst würde ein Nutzer beim Anlegen unbemerkt das
// Produkt eines anderen Nutzers treffen und wiederverwenden.
$check = $db->prepare("
    SELECT id FROM produkte
    WHERE name = ? AND quelle = 'manuell' AND ersteller_id = ? AND ROUND(kcal_100g) = ROUND(?)
    LIMIT 1
");
$check->bind_param('sid', $name, $userId, $kcal);
$check->execute();
$existing = $check->get_result()->fetch_assoc();
if ($existing) {
    echo json_encode(['ok' => true, 'id' => $existing['id'], 'new' => false]);
    exit;
}

$stmt = $db->prepare("
    INSERT INTO produkte (barcode, name, kcal_100g, eiweiss_100g, fett_100g, kh_100g, portion_g, quelle, ersteller_id)
    VALUES (NULL, ?, ?, ?, ?, ?, ?, 'manuell', ?)
");
$stmt->bind_param('sdddddi', $name, $kcal, $eiweiss, $fett, $kh, $portionG, $userId);

if ($stmt->execute()) {
    echo json_encode(['ok' => true, 'id' => $db->insert_id, 'new' => true]);
} else {
    echo json_encode(['ok' => false, 'error' => $db->error]);
}
