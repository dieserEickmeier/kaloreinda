<?php
function renderHeader(string $title = APP_NAME, string $activeNav = ''): void {
    global $currentUser;
    $pageTitle = $title === APP_NAME ? APP_NAME : "$title – " . APP_NAME;
?>
<!doctype html>
<html lang="de" data-bs-theme="dark" style="height:100%;">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">

    <!-- PWA / Homescreen -->
    <link rel="manifest" href="/manifest.json">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#0f1117">

    <!-- iOS-spezifisch -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Kalorien">
    <link rel="apple-touch-icon" href="/assets/icons/icon-180.png?v=<?= filemtime(__DIR__ . '/../assets/icons/icon-180.png') ?>">
    <link rel="apple-touch-startup-image" href="/assets/icons/splash.png?v=<?= filemtime(__DIR__ . '/../assets/icons/splash.png') ?>">

    <title><?= htmlspecialchars($pageTitle) ?></title>
    <!-- DNS-Prefetch für CDN -->
    <link rel="dns-prefetch" href="//cdn.jsdelivr.net">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <!-- Styles -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/app.css?v=41">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
    <script>
    // Hängt den CSRF-Token automatisch an alle schreibenden Requests an die
    // eigene API an (POST/PUT/PATCH/DELETE) – die Endpunkte prüfen ihn.
    (function() {
        var token = document.querySelector('meta[name="csrf-token"]').content;
        var origFetch = window.fetch.bind(window);
        window.fetch = function(input, init) {
            init = init || {};
            var method = (init.method || 'GET').toUpperCase();
            var url    = typeof input === 'string' ? input : (input && input.url) || '';
            if (method !== 'GET' && method !== 'HEAD'
                && new URL(url, location.href).origin === location.origin) {
                var headers = new Headers(init.headers || {});
                headers.set('X-CSRF-Token', token);
                init = Object.assign({}, init, { headers: headers });
            }
            return origFetch(input, init);
        };
    })();
    </script>
</head>
<body>
<!-- Deckt die Statusleisten-/Dynamic-Island-Zone permanent mit dem
     Hintergrund ab. Notwendig weil apple-mobile-web-app-status-bar-style:
     black-translucent die Statusleiste transparent über den Seiteninhalt
     legt – ohne dieses Element schimmert hochscrollender Content durch
     diese Zone, da der sticky Page-Header selbst erst UNTERHALB davon
     beginnt (top: var(--safe-top)), nicht bei y=0. -->
<div class="status-bar-cover"></div>
<div id="app">
<?php } ?>

<?php
function renderFooter(string $activeNav = ''): void {
    global $currentUser;
    $nav = [
        ['href' => '/index.php',   'icon' => 'bi-house-fill',       'label' => 'Heute',    'key' => 'home'],
        ['href' => '/log.php',     'icon' => 'bi-plus-circle-fill', 'label' => 'Erfassen', 'key' => 'log'],
        ['href' => '/history.php', 'icon' => 'bi-calendar3',        'label' => 'Verlauf',  'key' => 'history'],
        ['href' => '/profil.php',  'icon' => 'bi-person-circle',    'label' => 'Profil',   'key' => 'profil'],
    ];
?><!-- ── Eintrag bearbeiten Modal (global) ────────────────────── -->
<div id="editEntryModal" onclick="if(event.target===this)closeEditModal()">
    <div class="edit-modal__inner">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
            <h2 style="font-size:1.05rem;font-weight:700;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:80%;" id="editEntryName"></h2>
            <button onclick="closeEditModal()"
                    style="background:var(--surface2);border:none;border-radius:50%;
                           width:2rem;height:2rem;color:var(--muted);font-size:1rem;
                           display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-x"></i>
            </button>
        </div>
        <label style="font-size:.82rem;color:var(--muted);display:block;margin-bottom:.4rem;">Menge (g)</label>
        <input type="number" id="editMengeInput" inputmode="decimal" min="0.1" step="0.1"
               oninput="updateEditPreview()"
               style="width:100%;background:var(--surface);border:1px solid var(--border);
                      border-radius:12px;padding:.65rem .85rem;color:var(--text);
                      font-size:1rem;margin-bottom:.75rem;">
        <div id="editKcalPreview"
             style="text-align:center;font-size:1.4rem;font-weight:800;color:var(--accent);margin-bottom:1.25rem;">
            – kcal
        </div>
        <button onclick="saveEditEntry()"
                style="display:block;width:100%;background:var(--accent);color:#000;
                       border:none;border-radius:14px;padding:1rem;font-size:1rem;
                       font-weight:700;margin-bottom:.5rem;">
            <i class="bi bi-check-circle-fill me-1"></i> Speichern
        </button>
        <button onclick="closeEditModal()"
                style="display:block;width:100%;background:var(--surface2);color:var(--text);
                       border:none;border-radius:14px;padding:1rem;font-size:1rem;font-weight:700;">
            <i class="bi bi-x-circle me-1"></i> Abbrechen
        </button>
    </div>
</div>

</div><!-- #app -->

<!-- Bottom Navigation -->
<div class="bottom-nav-bg"></div>
<nav class="bottom-nav">
    <?php foreach ($nav as $item): ?>
        <a href="<?= $item['href'] ?>" class="bottom-nav__item <?= $activeNav === $item['key'] ? 'active' : '' ?>">
            <i class="bi <?= $item['icon'] ?>"></i>
            <span><?= $item['label'] ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
<script src="/assets/js/app.js?v=7" defer></script>
<script>
(function() {
    // Misst die echte safe-area-inset-bottom via CSS-Trick
    // und setzt sie als CSS-Variable, falls env() nicht korrekt ausgewertet wird
    var el = document.createElement('div');
    el.style.cssText = 'position:fixed;bottom:0;left:0;width:1px;' +
        'height:env(safe-area-inset-bottom,0px);pointer-events:none;';
    document.body.appendChild(el);
    var safeBottom = el.offsetHeight;
    document.body.removeChild(el);
    if (safeBottom > 0) {
        document.documentElement.style.setProperty('--safe-bottom', safeBottom + 'px');
    }
})();
</script>
</body>
</html>
<?php } ?>
