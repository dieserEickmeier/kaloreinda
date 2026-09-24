<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$user = currentUser();
if (!$user) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Nicht eingeloggt']); exit; }
$userId = $user['id'];
csrfCheckApi();

$db     = db();
$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// ─── POST: Eintrag anlegen ────────────────────────────────────────────────
if ($method === 'POST') {
    $name      = substr(trim($body['name'] ?? ''), 0, 255);
    $mengeG    = max(0.1, (float)($body['menge_g']   ?? 100));
    $kcal      = max(0,   (float)($body['kcal']      ?? 0));
    $eiweiss   = max(0,   (float)($body['eiweiss']   ?? 0));
    $fett      = max(0,   (float)($body['fett']      ?? 0));
    $kh        = max(0,   (float)($body['kh']        ?? 0));
    $datum     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['datum'] ?? '')
                 ? $body['datum'] : date('Y-m-d');
    $notiz     = substr(trim($body['notiz'] ?? ''), 0, 255);
    $produktId = ($body['produkt_id'] ?? null) ? (int)$body['produkt_id'] : null;

    if (!$name) {
        echo json_encode(['ok' => false, 'error' => 'Name fehlt']);
        exit;
    }

    // produkt_id ist nullable (z.B. bei manuell erstellten Produkten ohne
    // Barcode-Verknüpfung). mysqli::bind_param mit Typ 'i' und einem PHP-
    // null-Wert wirft seit PHP 8.1 einen TypeError ("must be of type int,
    // null given") statt den Wert einfach als SQL NULL zu interpretieren.
    // Fix: bei NULL eine eigene Query ohne produkt_id-Platzhalter verwenden,
    // statt zu versuchen, NULL über bind_param zu binden.
    //
    // WICHTIGER ZUSATZ-FIX: die Typstrings unten hatten bisher ein 'd' zu
    // wenig (für die Spalte "kh"), wodurch die Anzahl der Typ-Zeichen nicht
    // zur Anzahl der übergebenen Variablen passte. mysqli::bind_param wirft
    // bei dieser Diskrepanz einen ArgumentCountError, der als HTTP 500 OHNE
    // erkennbare Fehlermeldung endete – das war die eigentliche, tatsächliche
    // Ursache der zuvor beobachteten 500er, bestätigt durch den Schema-Dump
    // (Spaltenreihenfolge: user_id, produkt_id, name, menge_g, kcal, eiweiss,
    // fett, kh, datum, notiz – neun bzw. zehn Werte, nicht acht bzw. neun).
    if ($produktId === null) {
        $stmt = $db->prepare("
            INSERT INTO eintraege (user_id, produkt_id, name, menge_g, kcal, eiweiss, fett, kh, datum, notiz)
            VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('isdddddss',
            $userId, $name, $mengeG, $kcal, $eiweiss, $fett, $kh, $datum, $notiz
        );
    } else {
        $stmt = $db->prepare("
            INSERT INTO eintraege (user_id, produkt_id, name, menge_g, kcal, eiweiss, fett, kh, datum, notiz)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('iisdddddss',
            $userId, $produktId, $name, $mengeG, $kcal, $eiweiss, $fett, $kh, $datum, $notiz
        );
    }

    if ($stmt->execute()) {
        echo json_encode(['ok' => true, 'id' => $db->insert_id]);
    } else {
        error_log('entry.php POST: ' . $db->error);
        echo json_encode(['ok' => false, 'error' => 'Datenbankfehler']);
    }
    exit;
}

// ─── PUT: Eintrag bearbeiten (Menge nachträglich korrigieren) ─────────────
// Bewusst eingeschränkt auf reine Mengenänderung statt eines allgemeinen
// "alles überschreibbar"-Updates: das deckt den eigentlichen Anwendungsfall
// ab (Menge falsch geschätzt) und vermeidet, dass über die API versehentlich
// inkonsistente kcal/Makro-Werte gesetzt werden, die nicht mehr zur Menge
// passen.
if ($method === 'PUT') {
    $id = (int)($body['id'] ?? 0);
    $neueMenge = (float)($body['menge_g'] ?? 0);
    if (!$id || $neueMenge <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Ungültige ID oder Menge']);
        exit;
    }

    // Bestehenden Eintrag laden (nur eigene Einträge – user_id-Check)
    $stmt = $db->prepare("SELECT * FROM eintraege WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    $eintrag = $stmt->get_result()->fetch_assoc();
    if (!$eintrag) {
        echo json_encode(['ok' => false, 'error' => 'Eintrag nicht gefunden']);
        exit;
    }

    if ($eintrag['produkt_id']) {
        // Verknüpftes Produkt vorhanden: Pro-100g-Werte aus der produkte-
        // Tabelle nachschlagen und exakt für die neue Menge neu berechnen.
        $pStmt = $db->prepare("SELECT kcal_100g, eiweiss_100g, fett_100g, kh_100g FROM produkte WHERE id = ?");
        $pStmt->bind_param('i', $eintrag['produkt_id']);
        $pStmt->execute();
        $produkt = $pStmt->get_result()->fetch_assoc();

        if ($produkt) {
            $kcal    = $produkt['kcal_100g']    * $neueMenge / 100;
            $eiweiss = $produkt['eiweiss_100g'] * $neueMenge / 100;
            $fett    = $produkt['fett_100g']    * $neueMenge / 100;
            $kh      = $produkt['kh_100g']      * $neueMenge / 100;
        } else {
            // Produkt wurde zwischenzeitlich gelöscht (ON DELETE SET NULL
            // greift erst beim nächsten Lesen) – Fallback auf proportionale
            // Umrechnung wie bei einem Eintrag ohne produkt_id.
            $faktor  = $neueMenge / $eintrag['menge_g'];
            $kcal    = $eintrag['kcal']    * $faktor;
            $eiweiss = $eintrag['eiweiss'] * $faktor;
            $fett    = $eintrag['fett']    * $faktor;
            $kh      = $eintrag['kh']      * $faktor;
        }
    } else {
        // Kein verknüpftes Produkt (z.B. manueller Eintrag ohne Barcode):
        // wir kennen keine Pro-100g-Referenzwerte, also rechnen wir die
        // bisherigen Werte proportional zur Mengenänderung um. Das ist exakt
        // korrekt, solange die Nährwerte linear mit der Menge skalieren
        // (bei echten Lebensmitteln praktisch immer der Fall).
        $faktor  = $neueMenge / $eintrag['menge_g'];
        $kcal    = $eintrag['kcal']    * $faktor;
        $eiweiss = $eintrag['eiweiss'] * $faktor;
        $fett    = $eintrag['fett']    * $faktor;
        $kh      = $eintrag['kh']      * $faktor;
    }

    $upd = $db->prepare("
        UPDATE eintraege
        SET menge_g = ?, kcal = ?, eiweiss = ?, fett = ?, kh = ?
        WHERE id = ? AND user_id = ?
    ");
    $upd->bind_param('ddddiii', $neueMenge, $kcal, $eiweiss, $fett, $kh, $id, $userId);

    if ($upd->execute()) {
        echo json_encode(['ok' => true, 'menge_g' => $neueMenge, 'kcal' => round($kcal, 1),
                           'eiweiss' => round($eiweiss, 1), 'fett' => round($fett, 1), 'kh' => round($kh, 1)]);
    } else {
        error_log('entry.php PUT: ' . $db->error);
        echo json_encode(['ok' => false, 'error' => 'Datenbankfehler']);
    }
    exit;
}

// ─── DELETE: Eintrag löschen ──────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) {
        echo json_encode(['ok' => false, 'error' => 'Ungültige ID']);
        exit;
    }
    $stmt = $db->prepare("DELETE FROM eintraege WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $id, $userId);
    echo json_encode(['ok' => $stmt->execute(), 'affected' => $stmt->affected_rows]);
    exit;
}

// ─── GET: Einträge abrufen ────────────────────────────────────────────────
if ($method === 'GET') {
    $datum = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['datum'] ?? '')
             ? $_GET['datum'] : date('Y-m-d');
    $stmt = $db->prepare("SELECT * FROM eintraege WHERE datum = ? AND user_id = ? ORDER BY erstellt_am");
    $stmt->bind_param('si', $datum, $userId);
    $stmt->execute();
    echo json_encode(['ok' => true, 'entries' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Methode nicht erlaubt']);
