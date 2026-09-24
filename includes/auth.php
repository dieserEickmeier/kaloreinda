<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ── Fallback falls config.php die Konstante nicht definiert ─────────────────
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 60 * 60 * 24 * 30);

// Output buffering – verhindert "headers already sent" Fehler
if (!ob_get_level()) ob_start();

// ── Sicherheits-Header ────────────────────────────────────────────────────────
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: blob:; font-src https://cdn.jsdelivr.net; connect-src 'self' https://world.openfoodfacts.org https://tessdata.projectnaptha.com; worker-src 'self' blob:; media-src 'self';");

// ── Session-Konfiguration ─────────────────────────────────────────────────────
//ini_set('session.cookie_httponly', 1);
// HTTPS-Check: secure nur wenn tatsächlich HTTPS aktiv (auch hinter Reverse Proxy)
// Auf 0 setzen wenn HTTP erlaubt werden soll (z.B. für Let's Encrypt Setup)
//ini_set('session.cookie_secure', 0);
//ini_set('session.cookie_samesite', 'Strict');
//ini_set('session.use_strict_mode', 1);
//ini_set('session.gc_maxlifetime', SESSION_LIFETIME);

ini_set('session.use_strict_mode', 1);
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path'     => '/',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_name('kt_session');
session_start();

// ── Session-Fixation verhindern ───────────────────────────────────────────────
function regenerateSession(): void {
    session_regenerate_id(true);
    $_SESSION['regenerated_at'] = time();
}

// ── Eingeloggten User abrufen ─────────────────────────────────────────────────
function currentUser(): ?array {
    if (empty($_SESSION['user_id'])) return null;

    // Session-Timeout prüfen
    if (!empty($_SESSION['last_active']) && (time() - $_SESSION['last_active']) > SESSION_LIFETIME) {
        session_destroy();
        return null;
    }
    $_SESSION['last_active'] = time();

    // IP-Binding (optional, verhindert Session-Hijacking)
    //if (!empty($_SESSION['ip']) && $_SESSION['ip'] !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
        //session_destroy();
        //return null;
    //}

    return [
        'id'           => (int)$_SESSION['user_id'],
        'username'     => $_SESSION['username']     ?? '',
        'display_name' => $_SESSION['display_name'] ?? '',
    ];
}

// ── Login erforderlich ────────────────────────────────────────────────────────
function requireLogin(): array {
    $user = currentUser();
    if (!$user) {
        $target = urlencode($_SERVER['REQUEST_URI'] ?? '/index.php');
        // Absoluten URL bauen – funktioniert auch hinter HTTPS auf nicht-Standard-Port
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
                ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        header("Location: {$scheme}://{$host}/login.php?next={$target}");
        exit;
    }
    return $user;
}

// ── Rate Limiter (Login-Brute-Force-Schutz) ───────────────────────────────────
function checkRateLimit(string $key, int $maxAttempts = 5, int $windowSec = 300): bool {
    $cacheKey = 'rl_' . md5($key);
    if (!isset($_SESSION[$cacheKey])) {
        $_SESSION[$cacheKey] = ['count' => 0, 'since' => time()];
    }
    $rl = &$_SESSION[$cacheKey];
    if ((time() - $rl['since']) > $windowSec) {
        $rl = ['count' => 0, 'since' => time()];
    }
    $rl['count']++;
    return $rl['count'] <= $maxAttempts;
}

// ── CSRF-Token ────────────────────────────────────────────────────────────────
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfValid(): bool {
    $expected = $_SESSION['csrf_token'] ?? '';
    $token    = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    // Leerer Session-Token (z.B. neue Session) darf nie als gültig gelten,
    // sonst würde hash_equals('', '') einen fehlenden Token akzeptieren.
    return $expected !== '' && is_string($token) && hash_equals($expected, $token);
}

function csrfCheck(bool $json = false): void {
    if (csrfValid()) return;
    http_response_code(403);
    if ($json) {
        header('Content-Type: application/json');
        die(json_encode(['ok' => false, 'error' => 'CSRF-Token ungültig – bitte Seite neu laden']));
    }
    die('CSRF-Token ungültig – bitte Seite neu laden');
}

// Für API-Endpunkte mit Session-Login: schreibende Methoden brauchen den
// X-CSRF-Token-Header (setzt der fetch-Wrapper in layout.php automatisch).
// Zugriffe per API-Key sind nicht betroffen – dort wird kein Cookie genutzt.
function csrfCheckApi(): void {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) {
        csrfCheck(true);
    }
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

// Prüft den API-Key aus Header oder Body gegen die users-Tabelle.
// Gibt die user_id zurück wenn der Key gültig ist, sonst null.
function getUserIdFromApiKey(): ?int {
    // 1. Authorization: Bearer <key>
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $key    = str_replace('Bearer ', '', $header);

    // 2. Custom Header: X-Api-Key: <key>  (Nginx-kompatibler Workaround)
    if (!$key) {
        $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    }

    // 3. Custom Header: api_key: <key>
    if (!$key) {
        $key = $_SERVER['HTTP_API_KEY'] ?? '';
    }

    // 4. JSON Body: { "api_key": "<key>" }
    if (!$key) {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $key  = $body['api_key'] ?? '';
    }

    // 5. GET-Parameter: ?api_key=<key>  (Fallback für einfache Tests)
    if (!$key) {
        $key = $_GET['api_key'] ?? '';
    }

    if (!$key || strlen($key) < 16) return null;

    $db   = db();
    $stmt = $db->prepare("SELECT id FROM users WHERE api_key = ? AND aktiv = 1 LIMIT 1");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int)$row['id'] : null;
}
