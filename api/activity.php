<?php
/**
 * Aktivitätskalorien API
 *
 * POST   /api/activity.php  Body: { "kcal": 350, "bezeichnung": "Laufen 5km", "datum": "2026-06-22" }
 * GET    /api/activity.php?datum=2026-06-22&exclude_workout=1
 * DELETE /api/activity.php  Body: { "id": 1 }
 *
 * GET-Parameter exclude_workout (optional, Standard: nicht gesetzt = alle Einträge):
 *   Wenn auf 1/true gesetzt, werden Einträge deren Bezeichnung exakt
 *   "Workout" ist (case-insensitive) weder zurückgegeben noch in
 *   total_kcal mitgerechnet.
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
    // Zeitraum-Modus: ?days=7 liefert alle Einträge der letzten N Tage
    // in EINEM Request (statt N einzelne Tages-Anfragen).
    $days = (int)($_GET['days'] ?? 0);
    if ($days > 0 && $days <= 90) {
        $excludeWorkout = filter_var($_GET['exclude_workout'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $workoutFilter  = $excludeWorkout ? " AND LOWER(bezeichnung) != 'workout'" : '';
        $stmt = $db->prepare("SELECT id, datum, bezeichnung, kcal, erstellt_am
                              FROM aktivitaet_log
                              WHERE datum >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                                AND user_id = ?{$workoutFilter}
                              ORDER BY datum DESC, erstellt_am DESC");
        $stmt->bind_param('ii', $days, $userId);
        $stmt->execute();
        $rows  = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $total = array_sum(array_column($rows, 'kcal'));
        echo json_encode([
            'ok' => true, 'days' => $days,
            'exclude_workout' => $excludeWorkout,
            'total_kcal' => $total, 'eintraege' => $rows,
        ]);
        exit;
    }

    $datum = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['datum'] ?? '')
             ? $_GET['datum'] : date('Y-m-d');

    // Standard: alle Einträge werden zurückgegeben und summiert.
    // Mit exclude_workout=1 werden Einträge mit Bezeichnung "Workout" ausgeschlossen.
    $excludeWorkout = filter_var($_GET['exclude_workout'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if ($excludeWorkout) {
        $stmt = $db->prepare("SELECT id, datum, bezeichnung, kcal, erstellt_am FROM aktivitaet_log WHERE datum = ? AND user_id = ? AND LOWER(bezeichnung) != 'workout' ORDER BY erstellt_am DESC");
    } else {
        $stmt = $db->prepare("SELECT id, datum, bezeichnung, kcal, erstellt_am FROM aktivitaet_log WHERE datum = ? AND user_id = ? ORDER BY erstellt_am DESC");
    }
    $stmt->bind_param('si', $datum, $userId);
    $stmt->execute();
    $rows  = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $total = array_sum(array_column($rows, 'kcal'));
    echo json_encode([
        'ok' => true,
        'datum' => $datum,
        'exclude_workout' => $excludeWorkout,
        'total_kcal' => $total,
        'eintraege' => $rows,
    ]);
    exit;
}

// ─── POST ─────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body        = json_decode(file_get_contents('php://input'), true) ?? [];
    $kcal        = (int)($body['kcal'] ?? 0);
    $bezeichnung = substr(trim($body['bezeichnung'] ?? 'Training'), 0, 255);
    $datum       = preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['datum'] ?? '')
                   ? $body['datum'] : date('Y-m-d');
    if ($kcal <= 0 || $kcal > 10000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'kcal muss zwischen 1 und 10000 liegen']);
        exit;
    }
    $stmt = $db->prepare("INSERT INTO aktivitaet_log (user_id, datum, bezeichnung, kcal, quelle) VALUES (?, ?, ?, ?, 'api')");
    $stmt->bind_param('issi', $userId, $datum, $bezeichnung, $kcal);
    $stmt->execute();
    echo json_encode(['ok' => true, 'id' => $db->insert_id, 'datum' => $datum, 'bezeichnung' => $bezeichnung, 'kcal' => $kcal]);
    exit;
}

// ─── DELETE ───────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false, 'error' => 'id fehlt']); exit; }
    $stmt = $db->prepare("DELETE FROM aktivitaet_log WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    echo json_encode(['ok' => true, 'affected' => $stmt->affected_rows]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Methode nicht erlaubt']);
