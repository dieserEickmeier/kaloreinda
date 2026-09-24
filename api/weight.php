<?php
/**
 * Gewicht-Tracking API
 *
 * POST   /api/weight.php  Body: { "kg": 82.5, "datum": "2026-06-22" }
 * GET    /api/weight.php?limit=30
 * GET    /api/weight.php?datum=2026-06-22
 * DELETE /api/weight.php  Body: { "id": 123 }
 *
 * Auth: Session-Cookie (Browser) oder
 *       Authorization: Bearer <api_key>  bzw. api_key im JSON-Body
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Api-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$db     = db();
$method = $_SERVER['REQUEST_METHOD'];

// ─── Authentifizierung ────────────────────────────────────────────────────────
$sessionUser = currentUser();
if ($sessionUser) {
    $userId = $sessionUser['id'];
    // Browser-Zugriff per Session-Cookie → CSRF-Schutz für schreibende Methoden
    csrfCheckApi();
} else {
    $userId = getUserIdFromApiKey();
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Nicht autorisiert – API Key fehlt oder ungültig']);
        exit;
    }
}

// ─── GET ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!empty($_GET['datum']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['datum'])) {
        $stmt = $db->prepare("SELECT id, datum, kg FROM gewicht WHERE datum = ? AND user_id = ?");
        $stmt->bind_param('si', $_GET['datum'], $userId);
        $stmt->execute();
        echo json_encode(['ok' => true, 'eintrag' => $stmt->get_result()->fetch_assoc()]);
        exit;
    }
    $limit = max(1, min(365, (int)($_GET['limit'] ?? 30)));
    $stmt  = $db->prepare("SELECT id, datum, kg FROM gewicht WHERE user_id = ? ORDER BY datum DESC LIMIT ?");
    $stmt->bind_param('ii', $userId, $limit);
    $stmt->execute();
    echo json_encode(['ok' => true, 'eintraege' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
    exit;
}

// ─── POST ─────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $kg    = (float)($body['kg'] ?? 0);
    $datum = preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['datum'] ?? '')
             ? $body['datum'] : date('Y-m-d');
    if ($kg <= 20 || $kg >= 300) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'kg muss zwischen 20 und 300 liegen']);
        exit;
    }
    $stmt = $db->prepare("INSERT INTO gewicht (user_id, datum, kg) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE kg = VALUES(kg)");
    $stmt->bind_param('isd', $userId, $datum, $kg);
    $stmt->execute();
    echo json_encode(['ok' => true, 'datum' => $datum, 'kg' => $kg]);
    exit;
}

// ─── DELETE ───────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false, 'error' => 'id fehlt']); exit; }
    $stmt = $db->prepare("DELETE FROM gewicht WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    echo json_encode(['ok' => true, 'affected' => $stmt->affected_rows]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Methode nicht erlaubt']);
