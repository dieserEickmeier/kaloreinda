<?php
/**
 * Gerichte API
 * GET    /api/gericht.php              – alle Gerichte des Users
 * GET    /api/gericht.php?id=X         – einzelnes Gericht mit Zutaten
 * POST   /api/gericht.php              – neues Gericht anlegen
 * PUT    /api/gericht.php              – Gericht aktualisieren
 * DELETE /api/gericht.php              – Gericht löschen
 * POST   /api/gericht.php?action=eintragen – Gericht als Einträge buchen
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');
$user   = currentUser();
if (!$user) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Nicht eingeloggt']); exit; }
$userId = $user['id'];
csrfCheckApi();
$db     = db();
$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// Führt $fn als Transaktion aus: entweder alle Zeilen (Gericht + Zutaten bzw.
// alle Buchungen) oder keine. Rollback explizit, weil die Verbindung
// persistent ist (p:) und eine offene Transaktion sonst im nächsten Request
// auf derselben Verbindung weiterleben würde.
function inTransaction(mysqli $db, callable $fn) {
    $db->begin_transaction();
    try {
        $result = $fn();
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        $db->rollback();
        error_log('gericht.php: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Datenbankfehler']);
        exit;
    }
}

function execOrThrow(mysqli_stmt $stmt): void {
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
}

// Zutaten eines Gerichts einfügen (für POST und PUT identisch)
function insertZutaten(mysqli $db, int $gerichtId, array $zutaten): void {
    $sz = $db->prepare("INSERT INTO gericht_zutaten (gericht_id,produkt_id,name,menge_g,kcal_100g,eiweiss_100g,fett_100g,kh_100g) VALUES (?,?,?,?,?,?,?,?)");
    foreach ($zutaten as $z) {
        if (!is_array($z)) continue;
        $pid  = ($z['produkt_id'] ?? null) ? (int)$z['produkt_id'] : null;
        $znam = substr(trim($z['name'] ?? ''), 0, 255);
        $mg   = (float)($z['menge_g'] ?? 100);
        $kc   = (float)($z['kcal_100g'] ?? 0);
        $ew   = (float)($z['eiweiss_100g'] ?? 0);
        $ft   = (float)($z['fett_100g'] ?? 0);
        $kh   = (float)($z['kh_100g'] ?? 0);
        $sz->bind_param('iisddddd', $gerichtId, $pid, $znam, $mg, $kc, $ew, $ft, $kh);
        execOrThrow($sz);
    }
}

// ─── GET: Gerichte laden ──────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $id = (int)$_GET['id'];
        $s  = $db->prepare("SELECT * FROM gerichte WHERE id=? AND user_id=?");
        $s->bind_param('ii', $id, $userId); $s->execute();
        $g  = $s->get_result()->fetch_assoc();
        if (!$g) { echo json_encode(['ok'=>false,'error'=>'Nicht gefunden']); exit; }

        $sz = $db->prepare("SELECT * FROM gericht_zutaten WHERE gericht_id=? ORDER BY id");
        $sz->bind_param('i', $id); $sz->execute();
        $g['zutaten'] = $sz->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['ok'=>true, 'gericht'=>$g]); exit;
    }
    // Sortierung: 'nutzung' = nach Eintragungs-Häufigkeit (für "Häufige
    // Gerichte" auf log.php), sonst alphabetisch. Nutzung zählt Buchungen,
    // nicht Zutaten-Zeilen: alle Zutaten einer Buchung teilen denselben
    // erstellt_am-Timestamp -> COUNT(DISTINCT erstellt_am).
    $orderBy = ($_GET['sort'] ?? '') === 'nutzung'
             ? 'nutzung DESC, g.name'
             : 'g.name';
    $s = $db->prepare("
        SELECT g.*, COUNT(z.id) AS zutat_anzahl,
               ROUND(SUM(z.menge_g), 1) AS gewicht_gesamt,
               ROUND(SUM(z.menge_g * z.kcal_100g / 100), 1) AS kcal_gesamt,
               (SELECT COUNT(DISTINCT e.erstellt_am)
                FROM eintraege e
                WHERE e.gericht_id = g.id AND e.user_id = g.user_id) AS nutzung
        FROM gerichte g
        LEFT JOIN gericht_zutaten z ON z.gericht_id = g.id
        WHERE g.user_id = ?
        GROUP BY g.id ORDER BY {$orderBy}
    ");
    $s->bind_param('i', $userId); $s->execute();
    echo json_encode(['ok'=>true, 'gerichte'=>$s->get_result()->fetch_all(MYSQLI_ASSOC)]); exit;
}

// ─── POST: Gericht anlegen / eintragen ───────────────────────────────────────
if ($method === 'POST') {

    // Gericht als Tageseinträge buchen
    if (($_GET['action'] ?? '') === 'eintragen') {
        $gerichtId = (int)($body['gericht_id'] ?? 0);
        $datum     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['datum'] ?? '') ? $body['datum'] : date('Y-m-d');

        $sg = $db->prepare("SELECT * FROM gerichte WHERE id=? AND user_id=?");
        $sg->bind_param('ii', $gerichtId, $userId); $sg->execute();
        $g  = $sg->get_result()->fetch_assoc();
        if (!$g) { echo json_encode(['ok'=>false,'error'=>'Gericht nicht gefunden']); exit; }

        $sz = $db->prepare("SELECT * FROM gericht_zutaten WHERE gericht_id=?");
        $sz->bind_param('i', $gerichtId); $sz->execute();
        $zutaten = $sz->get_result()->fetch_all(MYSQLI_ASSOC);

        // Faktor entweder aus Portionen ODER aus gegessener Menge in Gramm.
        // menge_g bezieht sich auf das Gesamtgewicht aller Zutaten (roh).
        if (!empty($body['menge_g'])) {
            $mengeG        = max(1, (float)$body['menge_g']);
            $gewichtGesamt = array_sum(array_column($zutaten, 'menge_g'));
            if ($gewichtGesamt <= 0) {
                echo json_encode(['ok'=>false,'error'=>'Gericht hat kein Gewicht']); exit;
            }
            $faktor = $mengeG / $gewichtGesamt;
        } else {
            $portionen = max(0.1, (float)($body['portionen'] ?? 1));
            $faktor    = $portionen / max(1, $g['portionen']);
        }
        $si     = $db->prepare("
            INSERT INTO eintraege (user_id, produkt_id, gericht_id, name, menge_g, kcal, eiweiss, fett, kh, datum)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $eingetragen = inTransaction($db, function() use ($si, $zutaten, $faktor, $g, $userId, $gerichtId, $datum) {
            $n = 0;
            foreach ($zutaten as $z) {
                $menge   = round($z['menge_g'] * $faktor, 1);
                $f       = $menge / 100;
                $kcal    = round($z['kcal_100g']    * $f, 1);
                $eiweiss = round($z['eiweiss_100g'] * $f, 1);
                $fett    = round($z['fett_100g']    * $f, 1);
                $kh      = round($z['kh_100g']      * $f, 1);
                $pid     = $z['produkt_id'] ?: null;
                $name    = $g['name'] . ': ' . $z['name'];
                $si->bind_param('iiisddddds', $userId, $pid, $gerichtId, $name, $menge, $kcal, $eiweiss, $fett, $kh, $datum);
                execOrThrow($si);
                $n++;
            }
            return $n;
        });
        echo json_encode(['ok'=>true, 'eingetragen'=>$eingetragen]);
        exit;
    }

    // Neues Gericht anlegen
    $name     = substr(trim($body['name'] ?? ''), 0, 255);
    $portionen = max(0.5, (float)($body['portionen'] ?? 1));
    $zutaten  = $body['zutaten'] ?? [];

    if (!$name || empty($zutaten) || !is_array($zutaten)) {
        echo json_encode(['ok'=>false,'error'=>'Name und mindestens eine Zutat erforderlich']); exit;
    }

    $gerichtId = inTransaction($db, function() use ($db, $userId, $name, $portionen, $zutaten) {
        $sg = $db->prepare("INSERT INTO gerichte (user_id, name, portionen) VALUES (?,?,?)");
        $sg->bind_param('isd', $userId, $name, $portionen);
        execOrThrow($sg);
        $id = $db->insert_id;
        insertZutaten($db, $id, $zutaten);
        return $id;
    });
    echo json_encode(['ok'=>true, 'id'=>$gerichtId]);
    exit;
}

// ─── PUT: Gericht umbenennen / Portionen ändern ───────────────────────────────
if ($method === 'PUT') {
    $id       = (int)($body['id'] ?? 0);
    $name     = substr(trim($body['name'] ?? ''), 0, 255);
    $portionen = max(0.5, (float)($body['portionen'] ?? 1));
    $zutaten  = $body['zutaten'] ?? null;

    if (!$id) { echo json_encode(['ok'=>false,'error'=>'id fehlt']); exit; }

    // Besitz prüfen, bevor irgendetwas geändert wird – gericht_zutaten hat
    // keine eigene user_id, ohne diese Prüfung könnte jeder Nutzer per
    // erratener ID die Zutaten fremder Gerichte ersetzen.
    $so = $db->prepare("SELECT id FROM gerichte WHERE id=? AND user_id=?");
    $so->bind_param('ii', $id, $userId); $so->execute();
    if (!$so->get_result()->fetch_assoc()) {
        echo json_encode(['ok'=>false,'error'=>'Nicht gefunden']); exit;
    }

    inTransaction($db, function() use ($db, $id, $userId, $name, $portionen, $zutaten) {
        if ($name) {
            $su = $db->prepare("UPDATE gerichte SET name=?, portionen=? WHERE id=? AND user_id=?");
            $su->bind_param('sdii', $name, $portionen, $id, $userId);
            execOrThrow($su);
        }

        // Zutaten komplett ersetzen falls mitgeschickt
        if (is_array($zutaten)) {
            $d = $db->prepare("DELETE FROM gericht_zutaten WHERE gericht_id=?");
            $d->bind_param('i', $id);
            execOrThrow($d);
            insertZutaten($db, $id, $zutaten);
        }
    });
    echo json_encode(['ok'=>true]);
    exit;
}

// ─── DELETE ───────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int)($body['id'] ?? 0);
    $s  = $db->prepare("DELETE FROM gerichte WHERE id=? AND user_id=?");
    $s->bind_param('ii', $id, $userId); $s->execute();
    echo json_encode(['ok'=>true, 'affected'=>$s->affected_rows]);
    exit;
}

http_response_code(405);
echo json_encode(['ok'=>false,'error'=>'Methode nicht erlaubt']);
