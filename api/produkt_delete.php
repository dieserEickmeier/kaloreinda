<?php
// Löscht ein Produkt aus der produkte-Tabelle (nur manuell angelegte,
// OpenFoodFacts-Einträge können jederzeit neu gecacht werden).
// Zugehörige eintraege-Zeilen bleiben erhalten (ON DELETE SET NULL auf
// produkt_id), sodass der Verlauf nicht verloren geht.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$user = currentUser();
if (!$user) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Nicht eingeloggt']); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Methode nicht erlaubt']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$id   = (int)($body['id'] ?? 0);

if (!$id) {
    echo json_encode(['ok'=>false,'error'=>'Keine Produkt-ID angegeben']);
    exit;
}

$db   = db();
// Nur eigene, manuell angelegte Produkte löschbar – sonst könnte jeder
// eingeloggte Nutzer per erratener ID fremde Produkte (auch OpenFoodFacts-
// Cache) löschen.
$stmt = $db->prepare("DELETE FROM produkte WHERE id = ? AND quelle = 'manuell' AND ersteller_id = ?");
$stmt->bind_param('ii', $id, $user['id']);

if ($stmt->execute()) {
    echo json_encode(['ok'=>true, 'deleted'=>$stmt->affected_rows > 0]);
} else {
    echo json_encode(['ok'=>false,'error'=>$db->error]);
}
