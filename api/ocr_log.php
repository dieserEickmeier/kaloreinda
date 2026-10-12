<?php
// Diagnose-Protokoll des Nährwertscans (nur wenn OCR_LOG in config.php true).
// log.php schickt pro Scan-Sitzung: start, je Analyse ein frame (mit JPEG
// des analysierten Ausschnitts), ende (automatisch / Knopf / geschlossen) und
// was im Formular gespeichert bzw. verworfen wurde. Auswertung direkt in der
// Datenbank (Tabelle ocr_log), siehe schema.sql.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if (!defined('OCR_LOG') || !OCR_LOG) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Protokoll deaktiviert']);
    exit;
}
$user = currentUser();
if (!$user) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Nicht eingeloggt']); exit; }
csrfCheckApi();

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$sitzung = (string)($body['sitzung'] ?? '');
$typ     = (string)($body['typ'] ?? '');
if (!preg_match('/^[a-z0-9-]{8,40}$/', $sitzung)
    || !in_array($typ, ['start', 'frame', 'ende', 'gespeichert', 'verworfen'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültige Daten']);
    exit;
}

$daten = json_encode($body['daten'] ?? null, JSON_UNESCAPED_UNICODE);
if ($daten === false || strlen($daten) > 500000) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Daten zu groß']);
    exit;
}

// Bild: JPEG oder PNG (Base64 ohne data:-Präfix), max. 2 MB. PNG, weil
// manche Browser toDataURL('image/jpeg') still als PNG ausliefern.
$bild = null;
if (!empty($body['bild'])) {
    $bild = base64_decode((string)$body['bild'], true);
    $istBild = $bild !== false
        && (substr($bild, 0, 3) === "\xFF\xD8\xFF" || substr($bild, 0, 8) === "\x89PNG\r\n\x1A\n");
    if (!$istBild || strlen($bild) > 2_000_000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ungültiges Bild']);
        exit;
    }
}

$db = db();
// Tabelle bei Bedarf anlegen – das Protokoll ist optional, eine Migration
// soll dafür nicht nötig sein.
$db->query("
    CREATE TABLE IF NOT EXISTS ocr_log (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT          NOT NULL,
        sitzung     VARCHAR(40)  NOT NULL,
        typ         VARCHAR(20)  NOT NULL,
        daten       MEDIUMTEXT   NULL,
        bild        MEDIUMBLOB   NULL,
        erstellt_am TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        INDEX idx_sitzung (sitzung),
        INDEX idx_erstellt (erstellt_am)
    )
");

$stmt = $db->prepare("INSERT INTO ocr_log (user_id, sitzung, typ, daten, bild) VALUES (?, ?, ?, ?, ?)");
$null = null;
$stmt->bind_param('isssb', $user['id'], $sitzung, $typ, $daten, $null);
if ($bild !== null) $stmt->send_long_data(4, $bild);
$stmt->execute();

echo json_encode(['ok' => true]);
