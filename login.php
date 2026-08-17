<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

// Bereits eingeloggt?
if (currentUser()) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    header("Location: {$scheme}://{$host}/index.php");
    exit;
}

$error  = '';
$next   = preg_replace('/[^a-zA-Z0-9\/\-_\.]/', '', $_GET['next'] ?? '/index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate Limiting
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!checkRateLimit('login_' . $ip, 10, 300)) {
        $error = 'Zu viele Versuche. Bitte 5 Minuten warten.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username && $password) {
            $db   = db();
            $stmt = $db->prepare("SELECT id, password_hash, display_name, aktiv FROM users WHERE username = ?");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if ($user && $user['aktiv'] && password_verify($password, $user['password_hash'])) {
                // Erfolgreich – Session anlegen
                regenerateSession();
                $_SESSION['user_id']      = $user['id'];
                $_SESSION['username']     = $username;
                $_SESSION['display_name'] = $user['display_name'] ?: $username;
                $_SESSION['last_active']  = time();
                $_SESSION['ip']           = $ip;

                // Passwort-Hash ggf. neu hashen (falls PHP-Version geändert)
                if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
                    $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $stmtR = $db->prepare("UPDATE users SET password_hash=? WHERE id=?");
                    $stmtR->bind_param('si', $newHash, $user['id']);
                    $stmtR->execute();
                }

                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                       || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
                        ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

                // Erstes Login ohne abgeschlossene OOBE → Onboarding
                try {
                    $stmtOobe = $db->prepare("SELECT oobe_abgeschlossen FROM users WHERE id = ? LIMIT 1");
                    if ($stmtOobe) {
                        $stmtOobe->bind_param('i', $user['id']); $stmtOobe->execute();
                        $oobe = $stmtOobe->get_result()->fetch_assoc();
                        if (empty($oobe['oobe_abgeschlossen'])) {
                            header("Location: {$scheme}://{$host}/oobe.php"); exit;
                        }
                    }
                } catch (Exception $e) { /* Spalte noch nicht migriert, OOBE überspringen */ }

                $nextUrl = strpos($next, 'http') === 0 ? $next : "{$scheme}://{$host}{$next}";
                header("Location: {$nextUrl}");
                exit;
            } else {
                // Kurze Verzögerung gegen Timing-Angriffe
                usleep(random_int(100000, 300000));
                $error = 'Benutzername oder Passwort falsch.';
            }
        } else {
            $error = 'Bitte alle Felder ausfüllen.';
        }
    }
}
?>
<!doctype html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f1117">
    <title>Login – <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
    body {
        min-height: 100dvh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
        padding-top: env(safe-area-inset-top);
    }
    .login-card {
        width: 100%;
        max-width: 380px;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 24px;
        padding: 2rem 1.75rem;
    }
    .login-logo {
        text-align: center;
        margin-bottom: 2rem;
    }
    .login-logo i {
        font-size: 3rem;
        color: var(--accent);
        display: block;
        margin-bottom: .5rem;
    }
    .login-logo h1 {
        font-size: 1.4rem;
        font-weight: 800;
        margin: 0;
    }
    .login-logo p {
        color: var(--muted);
        font-size: .85rem;
        margin: .25rem 0 0;
    }
    .login-input {
        background: var(--surface2) !important;
        border-color: var(--border) !important;
        color: var(--text) !important;
        border-radius: 12px !important;
        padding: .75rem 1rem !important;
        font-size: 1rem !important;
    }
    .login-input:focus {
        border-color: var(--accent) !important;
        box-shadow: 0 0 0 3px rgba(74,222,128,.15) !important;
    }
    .login-btn {
        width: 100%;
        padding: .85rem;
        background: var(--accent);
        color: #000;
        border: none;
        border-radius: 14px;
        font-size: 1rem;
        font-weight: 700;
        margin-top: .5rem;
        cursor: pointer;
        transition: opacity .15s;
    }
    .login-btn:active { opacity: .85; }
    </style>
</head>
<body>
<div class="login-card">
    <div class="login-logo">
        <img src="assets/icons/splash_logo.png" style="height:150px"></img>
        <h1><?= APP_NAME ?></h1>
        <p>Bitte anmelden</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:12px;font-size:.88rem;">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
        <?= csrfField() ?>
        <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

        <div class="mb-3">
            <label class="form-label" style="color:var(--muted);font-size:.82rem;">Benutzername</label>
            <input type="text" name="username" class="login-input form-control"
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                   autocomplete="username" autofocus required>
        </div>
        <div class="mb-3">
            <label class="form-label" style="color:var(--muted);font-size:.82rem;">Passwort</label>
            <div style="position:relative;">
                <input type="password" name="password" id="pwInput" class="login-input form-control"
                       autocomplete="current-password" required style="padding-right:3rem;">
                <button type="button" onclick="togglePw()"
                        style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);
                               background:none;border:none;color:var(--muted);font-size:1.1rem;padding:0;">
                    <i class="bi bi-eye" id="pwEye"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="login-btn">
            <i class="bi bi-box-arrow-in-right me-1"></i> Anmelden
        </button>
    </form>
</div>
<script>
function togglePw() {
    const inp = document.getElementById('pwInput');
    const eye = document.getElementById('pwEye');
    inp.type = inp.type === 'password' ? 'text' : 'password';
    eye.className = inp.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}
</script>
</body>
</html>
