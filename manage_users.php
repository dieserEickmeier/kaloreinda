#!/usr/bin/env php
<?php
/**
 * User-Verwaltung via CLI
 *
 * Verwendung:
 *   php manage_users.php add <username> [passwort] [Anzeigename]
 *   php manage_users.php list
 *   php manage_users.php deactivate <username>
 *   php manage_users.php password <username> [neues_passwort]
 *   php manage_users.php delete <username>
 *
 * Wird kein Passwort angegeben, wird automatisch eine Passphrase generiert.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

$cmd  = $argv[1] ?? '';
$db   = db();

// ─── Passphrase-Generator ─────────────────────────────────────────────────────
function generatePassphrase(): string {
    $adj  = ['Schnell','Stark','Mutig','Gruen','Blau','Gross','Klein','Warm','Kalt',
              'Leise','Hell','Dunkel','Frisch','Wild','Sanft','Klar','Edel','Flink',
              'Tapfer','Weise','Treu','Stolz','Rasch','Froh','Wach'];
    $noun = ['Apfel','Berg','Tiger','Fluss','Stern','Wald','Adler','Stein','Wolf',
              'Baum','Fuchs','Wind','Feuer','Licht','Nacht','Blitz','Mond','Regen',
              'Falke','Lachs','Luchs','Dachs','Igel','Elch','Bison'];
    return $adj[array_rand($adj)] . $noun[array_rand($noun)] . rand(10, 99);
}

switch ($cmd) {

    case 'add':
        $username    = $argv[2] ?? '';
        $password    = $argv[3] ?? '';
        $displayName = $argv[4] ?? $username;

        if (!$username) {
            echo "Verwendung: php manage_users.php add <username> [passwort] [Anzeigename]\n";
            exit(1);
        }

        // Kein Passwort angegeben → Passphrase generieren
        $generated = false;
        if (!$password) {
            $password  = generatePassphrase();
            $generated = true;
        } elseif (strlen($password) < 8) {
            echo "Fehler: Passwort muss mindestens 8 Zeichen haben.\n";
            exit(1);
        }

        $hash   = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $apiKey = bin2hex(random_bytes(32));
        $stmt = $db->prepare("INSERT INTO users (username, password_hash, display_name, api_key) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssss', $username, $hash, $displayName, $apiKey);

        if ($stmt->execute()) {
            echo "✓ User '{$username}' (ID: {$db->insert_id}) erfolgreich angelegt.\n";
            if ($generated) {
                echo "  Generiertes Passwort: \033[1;33m{$password}\033[0m\n";
                echo "  (Bitte dem Benutzer mitteilen — wird nicht erneut angezeigt)\n";
            }
        } else {
            echo "Fehler: " . $db->error . "\n";
            exit(1);
        }
        break;

    case 'list':
        $result = $db->query("SELECT id, username, display_name, aktiv, erstellt_am FROM users ORDER BY id");
        echo str_pad('ID', 5) . str_pad('Username', 20) . str_pad('Anzeigename', 20) . str_pad('Aktiv', 8) . "Erstellt\n";
        echo str_repeat('-', 70) . "\n";
        while ($row = $result->fetch_assoc()) {
            echo str_pad($row['id'], 5)
               . str_pad($row['username'], 20)
               . str_pad($row['display_name'] ?? '-', 20)
               . str_pad($row['aktiv'] ? 'ja' : 'nein', 8)
               . $row['erstellt_am'] . "\n";
        }
        break;

    case 'password':
        $username = $argv[2] ?? '';
        $password = $argv[3] ?? '';
        if (!$username) {
            echo "Verwendung: php manage_users.php password <username> [neues_passwort]\n";
            exit(1);
        }
        $generated = false;
        if (!$password) {
            $password  = generatePassphrase();
            $generated = true;
        }
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $db->prepare("UPDATE users SET password_hash=? WHERE username=?");
        $stmt->bind_param('ss', $hash, $username);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            echo "✓ Passwort für '{$username}' geändert.\n";
            if ($generated) {
                echo "  Neues Passwort: \033[1;33m{$password}\033[0m\n";
                echo "  (Bitte dem Benutzer mitteilen — wird nicht erneut angezeigt)\n";
            }
        } else {
            echo "Fehler: User '{$username}' nicht gefunden.\n";
        }
        break;

    case 'deactivate':
        $username = $argv[2] ?? '';
        $stmt = $db->prepare("UPDATE users SET aktiv=0 WHERE username=?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        echo $stmt->affected_rows > 0
            ? "✓ User '{$username}' deaktiviert.\n"
            : "Fehler: User nicht gefunden.\n";
        break;

    case 'activate':
        $username = $argv[2] ?? '';
        $stmt = $db->prepare("UPDATE users SET aktiv=1 WHERE username=?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        echo $stmt->affected_rows > 0
            ? "✓ User '{$username}' aktiviert.\n"
            : "Fehler: User nicht gefunden.\n";
        break;

    case 'delete':
        $username = $argv[2] ?? '';
        echo "Wirklich löschen? Alle Daten des Users werden gelöscht! (ja/nein): ";
        $confirm = trim(fgets(STDIN));
        if ($confirm !== 'ja') { echo "Abgebrochen.\n"; exit; }
        $stmt = $db->prepare("DELETE FROM users WHERE username=?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        echo $stmt->affected_rows > 0
            ? "✓ User '{$username}' und alle Daten gelöscht.\n"
            : "Fehler: User nicht gefunden.\n";
        break;

    default:
        echo "KalorienTracker – User-Verwaltung\n\n";
        echo "Befehle:\n";
        echo "  add <username> [passwort] [Anzeigename]  – User anlegen (Passwort optional, sonst Passphrase)\n";
        echo "  list                                      – Alle User anzeigen\n";
        echo "  password <username> [neues_passwort]      – Passwort ändern (optional, sonst Passphrase)\n";
        echo "  deactivate <username>                     – User sperren\n";
        echo "  activate <username>                       – User entsperren\n";
        echo "  delete <username>                         – User + Daten löschen\n";
        break;
}
