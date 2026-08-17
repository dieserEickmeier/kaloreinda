<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$user = currentUser();
if (!$user) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Nicht eingeloggt']); exit; }
$userId = $user['id'];

$db = db();
$q  = trim($_GET['q'] ?? '');

if ($q === '') {
    // Ohne Suchbegriff: Top 5 der meistgenutzten Produkte des Nutzers
    // (aus der Verlaufstabelle, nur Einträge mit verknüpftem produkt_id,
    //  da nur diese vollständige Pro-100g-Nährwerte haben).
    // Nur Einträge zählen die NICHT Teil eines Gerichts sind (gericht_id IS NULL).
    // Produkte die ausschließlich über Gerichte eingetragen wurden erscheinen
    // dadurch nicht in den häufigen Lebensmitteln.
    $stmt = $db->prepare("
        SELECT p.id, p.name, p.kcal_100g, p.eiweiss_100g, p.fett_100g, p.kh_100g, p.portion_g, p.quelle,
               COUNT(*) AS anzahl
        FROM eintraege e
        JOIN produkte p ON p.id = e.produkt_id
        WHERE e.user_id = ?
          AND e.gericht_id IS NULL
        GROUP BY p.id
        ORDER BY anzahl DESC, MAX(e.erstellt_am) DESC
        LIMIT 8
    ");
    $stmt->bind_param('i', $userId);
} else {
    // Mit Suchbegriff: Volltextsuche in der produkte-Tabelle.
    // Reihenfolge: Eigeneinträge des Nutzers zuerst (nach Häufigkeit),
    // dann alle anderen bekannten Produkte aus dem globalen Cache.
    $like = '%' . $q . '%';
    // Hinweis Performance: LIKE '%…%' kann keinen B-Tree-Index nutzen.
    // Bei kleinen Tabellen (<10k Produkte) unkritisch; bei Wachstum auf
    // MATCH...AGAINST (FULLTEXT ft_name, siehe schema.sql) umstellen.
    $stmt = $db->prepare("
        SELECT p.id, p.name, p.kcal_100g, p.eiweiss_100g, p.fett_100g, p.kh_100g, p.portion_g, p.quelle,
               COUNT(e.id) AS anzahl
        FROM produkte p
        LEFT JOIN eintraege e ON e.produkt_id = p.id AND e.user_id = ?
        WHERE p.name LIKE ?
          AND (
              p.quelle != 'manuell'          -- globale Produkte (OpenFoodFacts etc.) immer zeigen
              OR p.ersteller_id = ?          -- manuelle Produkte nur für den Ersteller sichtbar
          )
        GROUP BY p.id
        ORDER BY anzahl DESC, p.name ASC
        LIMIT 20
    ");
    $stmt->bind_param('isi', $userId, $like, $userId);
}

$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo json_encode(['ok' => true, 'results' => $rows]);
