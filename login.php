<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

// Bereits eingeloggt?
if (currentUser()) {
    redirectTo('/start.php');
}

$error  = '';
// Nur interne Pfade (inkl. Query, z.B. /log.php?pick=1) – siehe safeRedirectPath()
$next   = safeRedirectPath($_GET['next'] ?? '/start.php', '/start.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate Limiting
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!checkRateLimit('login_' . $ip, 10, 300)) {
        $error = 'Zu viele Versuche. Bitte 5 Minuten warten.';
    } elseif (!csrfValid()) {
        // z.B. Login-Seite lange offen und Session inzwischen abgelaufen
        $error = 'Sitzung abgelaufen. Bitte erneut anmelden.';
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

                // Erstes Login ohne abgeschlossene OOBE → Onboarding
                try {
                    $stmtOobe = $db->prepare("SELECT oobe_abgeschlossen FROM users WHERE id = ? LIMIT 1");
                    if ($stmtOobe) {
                        $stmtOobe->bind_param('i', $user['id']); $stmtOobe->execute();
                        $oobe = $stmtOobe->get_result()->fetch_assoc();
                        if (empty($oobe['oobe_abgeschlossen'])) {
                            redirectTo('/oobe.php');
                        }
                    }
                } catch (Exception $e) { /* Spalte noch nicht migriert, OOBE überspringen */ }

                redirectTo($next);
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
    <?php renderPwaMeta(); ?>
    <title>Login – <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource-variable/space-grotesk@5/index.css">
    <link rel="stylesheet" href="/assets/css/app.css?v=42">
    <style>
    body {
        min-height: 100dvh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.5rem 1rem;
        padding-top: max(1.5rem, env(safe-area-inset-top));
    }
    .login-card { width: 100%; max-width: 380px; }
    .login-logo { margin-bottom: 2rem; }
    .login-logo img { height: 84px; display: block; margin-bottom: 1.25rem; }
    .login-logo h1 {
        font-size: 2.6rem; font-weight: 700; letter-spacing: -.04em; line-height: .95; margin: 0;
    }
    .login-logo h1 span { color: var(--accent); }
    .login-logo p { color: var(--muted); font-size: .95rem; margin: .6rem 0 0; }
    .login-input { min-height: 3.2rem; font-size: 1.05rem !important; }
    .login-btn {
        width: 100%; min-height: 3.3rem;
        background: var(--accent); color: var(--accent-ink);
        border: none; border-radius: 16px;
        font: 700 1.05rem var(--font);
        margin-top: .75rem; cursor: pointer; transition: opacity .15s;
    }
    .login-btn:active { opacity: .85; }
    .alert-danger { background: var(--danger-soft) !important; border-color: var(--danger) !important; color: var(--danger) !important; }
    </style>
</head>
<body class="no-nav">
<div class="login-card">
    <div class="login-logo">
        <img src="/assets/icons/logo.svg?v=<?= @filemtime(__DIR__ . '/assets/icons/logo.svg') ?: 1 ?>" alt="">
        <h1><?= preg_replace('/^(Kalorien)(.*)$/u', '$1<span>$2</span>', htmlspecialchars(APP_NAME)) ?></h1>
        <p>Bitte anmelden</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:14px;font-size:.9rem;">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
        <?= csrfField() ?>
        <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

        <div class="mb-3">
            <label class="form-label">Benutzername</label>
            <input type="text" name="username" class="login-input form-control"
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                   autocomplete="username" autofocus required>
        </div>
        <div class="mb-3">
            <label class="form-label">Passwort</label>
            <div style="position:relative;">
                <input type="password" name="password" id="pwInput" class="login-input form-control"
                       autocomplete="current-password" required style="padding-right:3rem;">
                <button type="button" onclick="togglePw()" aria-label="Passwort anzeigen"
                        style="position:absolute;right:.2rem;top:50%;transform:translateY(-50%);width:2.75rem;height:2.75rem;
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
