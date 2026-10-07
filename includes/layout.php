<?php
/**
 * PWA-/Homescreen-Meta für ALLE Seiten (auch login.php und oobe.php).
 * Fehlen diese Tags auf der Seite, von der aus „Zum Home-Bildschirm“
 * gewählt wird (meist der Login), legt iOS nur ein Safari-Lesezeichen an –
 * die App startet dann mit Safari-Navigationsleisten statt standalone.
 */
function renderPwaMeta(): void {
    $v = fn(string $f) => @filemtime(__DIR__ . '/../assets/icons/' . $f) ?: 1;
?>
    <!-- PWA / Homescreen -->
    <link rel="manifest" href="/manifest.json">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#09090b">

    <!-- iOS-spezifisch -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Kalorien">
    <link rel="icon" type="image/svg+xml" href="/assets/icons/favicon.svg?v=<?= $v('favicon.svg') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png?v=<?= $v('favicon-32.png') ?>">
    <link rel="apple-touch-icon" href="/assets/icons/icon-180.png?v=<?= $v('icon-180.png') ?>">
    <link rel="apple-touch-startup-image" href="/assets/icons/splash.png?v=<?= $v('splash.png') ?>">
<?php }

function renderHeader(string $title = APP_NAME, string $activeNav = ''): void {
    global $currentUser;
    $pageTitle = $title === APP_NAME ? APP_NAME : "$title – " . APP_NAME;
?>
<!doctype html>
<html lang="de" data-bs-theme="dark" style="height:100%;">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">

    <?php renderPwaMeta(); ?>

    <title><?= htmlspecialchars($pageTitle) ?></title>
    <!-- DNS-Prefetch für CDN -->
    <link rel="dns-prefetch" href="//cdn.jsdelivr.net">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <!-- Styles -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource-variable/space-grotesk@5/index.css">
    <link rel="stylesheet" href="/assets/css/app.css?v=43">
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
/**
 * Einheitlicher Seitenkopf: großer Titel links, runde Icon-Buttons rechts.
 * $actionsHtml wird unverändert ausgegeben (vom Aufrufer zu escapen).
 * $backHref (optional) zeigt links einen Zurück-Button für Unterseiten.
 */
function renderPageHeader(string $title, string $actionsHtml = '', string $sub = '', string $backHref = ''): void { ?>
<div class="page-header">
    <?php if ($backHref !== ''): ?><a href="<?= htmlspecialchars($backHref) ?>" class="icon-btn sm" aria-label="Zurück"><i class="bi bi-chevron-left"></i></a><?php endif; ?>
    <h1><?php if ($sub !== ''): ?><small><?= htmlspecialchars($sub) ?></small><?php endif; ?><?= htmlspecialchars($title) ?></h1>
    <?php if ($actionsHtml !== ''): ?><div class="hdr-actions"><?= $actionsHtml ?></div><?php endif; ?>
</div>
<?php }

function renderFooter(string $activeNav = ''): void {
    global $currentUser;
    $nav = [
        ['href' => '/index.php',    'icon' => 'bi-lightning-charge', 'iconOn' => 'bi-lightning-charge-fill', 'label' => 'Heute',    'key' => 'home'],
        ['href' => '/history.php',  'icon' => 'bi-graph-up',         'iconOn' => 'bi-graph-up',              'label' => 'Verlauf',  'key' => 'history'],
        ['scan' => true],
        ['href' => '/log.php',      'icon' => 'bi-plus-circle',      'iconOn' => 'bi-plus-circle-fill',      'label' => 'Erfassen', 'key' => 'log'],
        ['href' => '/profil.php',   'icon' => 'bi-person',           'iconOn' => 'bi-person-fill',           'label' => 'Profil',   'key' => 'profil'],
    ];
?><!-- ── Eintrag bearbeiten Modal (global) ────────────────────── -->
<div id="editEntryModal" onclick="if(event.target===this)closeEditModal()">
    <div class="edit-modal__inner sheet">
        <div class="sheet-head">
            <div style="flex:1;min-width:0;">
                <span class="sheet-overline">Eintrag bearbeiten</span>
                <h2 class="text-truncate" id="editEntryName"></h2>
            </div>
            <button type="button" class="pf-close" onclick="closeEditModal()" aria-label="Schließen"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="pf-kcal" style="padding-top:.2rem;">
            <span class="pf-kcal__num" id="editKcalPreview">–</span>
            <span class="pf-kcal__unit">kcal</span>
        </div>
        <div class="pf-menge">
            <div class="pf-menge__field">
                <input type="number" id="editMengeInput" inputmode="decimal" min="0.1" max="5000" step="0.1"
                       oninput="updateEditPreview()" aria-label="Menge in Gramm">
                <span>g</span>
            </div>
        </div>
        <div class="ruler" data-ruler-for="editMengeInput"></div>
        <div class="pf-actions">
            <button type="button" class="scan-btn" onclick="saveEditEntry()">Speichern</button>
            <button type="button" class="scan-btn secondary" onclick="closeEditModal()">Abbrechen</button>
        </div>
    </div>
</div>

</div><!-- #app -->

<!-- ── Barcode-Scanner (global, Logik in assets/js/scanner.js) ──── -->
<!-- ── Zentrales Scan-Status-Overlay ──────────────────────────── -->
<div id="scanOverlay">
    <div class="so-card">
        <div class="so-ring"><i id="soIcon" class="bi bi-upc-scan"></i></div>
        <div class="so-text" id="soText">Suche…</div>
    </div>
</div>

<!-- ── ZXing WASM Live-Scanner ────────────────────────────────────────── -->
<div id="zxingWrap" style="display:none;position:fixed;inset:0;z-index:9999;
     background:#000;flex-direction:column;">

    <!-- Video – volles Bild, kein Cover-Crop, keine Abdunklung -->
    <div style="position:relative;flex:1;overflow:hidden;background:#000;
                display:flex;align-items:center;justify-content:center;">
        <video id="zxingVideo" autoplay playsinline muted
               style="width:100%;height:100%;object-fit:contain;display:block;"></video>

        <!-- Scan-Rahmen: nur Ecken, kein Overlay, kein Dimming -->
        <div style="position:absolute;inset:0;pointer-events:none;
                    display:flex;align-items:center;justify-content:center;">
            <div id="zxingFrame" style="
                position:relative;width:78%;aspect-ratio:3/1.2;
                transition:border-color .2s;">
                <!-- Ecken oben links -->
                <div style="position:absolute;top:0;left:0;width:22px;height:22px;
                            border-top:3px solid var(--accent);border-left:3px solid var(--accent);
                            border-radius:4px 0 0 0;"></div>
                <!-- Ecken oben rechts -->
                <div style="position:absolute;top:0;right:0;width:22px;height:22px;
                            border-top:3px solid var(--accent);border-right:3px solid var(--accent);
                            border-radius:0 4px 0 0;"></div>
                <!-- Ecken unten links -->
                <div style="position:absolute;bottom:0;left:0;width:22px;height:22px;
                            border-bottom:3px solid var(--accent);border-left:3px solid var(--accent);
                            border-radius:0 0 0 4px;"></div>
                <!-- Ecken unten rechts -->
                <div style="position:absolute;bottom:0;right:0;width:22px;height:22px;
                            border-bottom:3px solid var(--accent);border-right:3px solid var(--accent);
                            border-radius:0 0 4px 0;"></div>
                <!-- Scan-Linie -->
                <div id="zxingScanLine" style="
                    position:absolute;left:4px;right:4px;height:2px;
                    background:linear-gradient(90deg,transparent,var(--accent),transparent);
                    top:0;animation:zxingScan 1.8s ease-in-out infinite;"></div>
            </div>
        </div>

        <!-- Erfolgs-Flash (startet bei opacity:0) -->
        <div id="zxingFlash" style="
            position:absolute;inset:0;background:var(--accent);
            opacity:0;pointer-events:none;transition:opacity .15s;"></div>
    </div>

    <!-- Status + Controls -->
    <div style="background:var(--bg);padding:1.25rem 1.5rem calc(1.25rem + env(safe-area-inset-bottom, 0px));
                display:flex;flex-direction:column;align-items:center;gap:1rem;">
        <!-- Statustext -->
        <div style="display:flex;align-items:center;gap:.6rem;">
            <div id="zxingDot" style="width:8px;height:8px;border-radius:50%;
                 background:var(--accent);flex-shrink:0;
                 animation:zxingPulse 1.4s ease-in-out infinite;"></div>
            <span id="zxingStatus" style="font-size:.95rem;color:var(--text);
                  font-weight:500;letter-spacing:.01em;">Barcode positionieren…</span>
        </div>
        <!-- Hinweistext -->
        <p style="margin:0;font-size:.78rem;color:var(--muted);text-align:center;line-height:1.4;">
            Barcode innerhalb des Rahmens halten.<br>Die Erkennung startet automatisch.
        </p>
        <!-- Stop-Button -->
        <button onclick="zxingStop()"
                style="width:100%;max-width:320px;
                       background:rgba(255,255,255,.08);
                       border:1px solid rgba(255,255,255,.12);
                       border-radius:14px;color:var(--text);
                       font-size:1rem;font-weight:600;
                       padding:.85rem 1rem;cursor:pointer;
                       -webkit-tap-highlight-color:transparent;
                       transition:background .15s;">
            ✕ &nbsp;Scanner schließen
        </button>
    </div>
</div>

<style>
/* ── Zentrales Scan-Status-Overlay ─────────────────────────────── */
#scanOverlay {
    position: fixed; inset: 0; z-index: 10001;
    display: none; align-items: center; justify-content: center;
    pointer-events: none;
}
#scanOverlay .so-card {
    display: flex; flex-direction: column; align-items: center; gap: 1rem;
    background: rgba(15, 17, 23, .72);
    backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px);
    border: 1px solid rgba(255, 255, 255, .09);
    border-radius: 24px;
    padding: 1.75rem 2.25rem;
    box-shadow: 0 8px 40px rgba(0, 0, 0, .5);
    animation: soPop .25s cubic-bezier(.34, 1.56, .64, 1);
}
#scanOverlay .so-ring {
    position: relative; width: 58px; height: 58px;
    display: flex; align-items: center; justify-content: center;
}
#scanOverlay .so-ring::before {
    content: ''; position: absolute; inset: 0;
    border-radius: 50%;
    border: 3px solid transparent;
    border-top-color: var(--so-color, var(--accent));
    border-right-color: var(--so-color, var(--accent));
    animation: soSpin .8s linear infinite;
}
#scanOverlay .so-ring::after {
    content: ''; position: absolute; inset: -7px;
    border-radius: 50%;
    border: 1px solid var(--so-color, var(--accent));
    opacity: .35;
    animation: soPulse 1.6s ease-out infinite;
}
#scanOverlay .so-ring i {
    font-size: 1.5rem; color: var(--so-color, var(--accent));
}
#scanOverlay .so-text {
    font-size: .92rem; font-weight: 600; color: var(--text);
    letter-spacing: .01em; text-align: center; max-width: 240px;
}
@keyframes soSpin  { to { transform: rotate(360deg); } }
@keyframes soPulse { 0% { transform: scale(.92); opacity: .5; } 100% { transform: scale(1.25); opacity: 0; } }
@keyframes soPop   { from { transform: scale(.85); opacity: 0; } to { transform: scale(1); opacity: 1; } }

@keyframes zxingScan {
    0%   { top: 4px;  opacity: 1; }
    48%  { opacity: 1; }
    50%  { top: calc(100% - 6px); opacity: .4; }
    52%  { opacity: 1; }
    100% { top: 4px;  opacity: 1; }
}
@keyframes zxingPulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%       { opacity: .4; transform: scale(.7); }
}
</style>

<!-- Bottom Navigation -->
<div class="bottom-nav-bg"></div>
<nav class="bottom-nav" aria-label="Hauptnavigation">
    <?php foreach ($nav as $item): ?>
        <?php if (!empty($item['scan'])): ?>
        <button type="button" id="navScanBtn" class="bottom-nav__scan" aria-label="Barcode scannen">
            <i class="bi bi-upc-scan"></i>
        </button>
        <?php else: $on = $activeNav === $item['key']; ?>
        <a href="<?= $item['href'] ?>" class="bottom-nav__item <?= $on ? 'active' : '' ?>"
           aria-label="<?= $item['label'] ?>" <?= $on ? 'aria-current="page"' : '' ?>>
            <i class="bi <?= $on ? $item['iconOn'] : $item['icon'] ?>"></i>
        </a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
<script src="/assets/js/app.js?v=9" defer></script>
<script src="/assets/js/scanner.js?v=2" defer></script>
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
