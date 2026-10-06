<?php
/**
 * Einstieg beim App-Start (manifest.json → start_url) und nach dem Login:
 * leitet auf die in den Einstellungen gewählte Startseite weiter.
 * Die Navigation verlinkt weiterhin direkt auf die Seiten (z.B. „Heute“ →
 * /index.php) – nur der Einstieg folgt der Einstellung.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
$currentUser = requireLogin();

$stmt = db()->prepare("SELECT * FROM profil WHERE user_id = ? LIMIT 1");
$stmt->bind_param('i', $currentUser['id']);
$stmt->execute();
$wahl = $stmt->get_result()->fetch_assoc()['startseite'] ?? 'heute';

redirectTo(startseiten()[$wahl]['pfad'] ?? '/index.php');
