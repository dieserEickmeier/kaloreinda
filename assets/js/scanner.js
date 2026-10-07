// ── KalorienTracker – Barcode-Live-Scanner (global, auf jeder Seite) ─────
// Wird über den Scan-Button der Navigation geöffnet. „Scanner schließen“
// schließt nur das Overlay, die aktuelle Seite bleibt. Bei einem Treffer:
//   · auf log.php setzt die Seite window.onScanProduct → Produkt-Sheet direkt
//   · überall sonst → Weiterleitung nach /log.php?barcode=<code>
// Die ZXing-Bibliothek wird erst beim ersten Scannen geladen.

// ── Zentrales Such-/Status-Overlay (auch von der OCR in log.php genutzt) ──
function scanOverlayShow(text, opts = {}) {
    const o = document.getElementById('scanOverlay');
    o.style.setProperty('--so-color', opts.color || 'var(--accent)');
    document.getElementById('soIcon').className = 'bi ' + (opts.icon || 'bi-upc-scan');
    document.getElementById('soText').textContent = text;
    o.style.display = 'flex';
}
function scanOverlayUpdate(text) {
    document.getElementById('soText').textContent = text;
}
function scanOverlayHide() {
    document.getElementById('scanOverlay').style.display = 'none';
}

// ── ZXing nachladen ──────────────────────────────────────────────────────
let _zxingLoading = null;
function loadZxing() {
    if (typeof ZXingWASM !== 'undefined') return Promise.resolve();
    _zxingLoading ??= new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = '/assets/js/zxing-wasm.js';
        s.onload = resolve;
        s.onerror = () => { _zxingLoading = null; reject(new Error('Scanner konnte nicht geladen werden')); };
        document.head.appendChild(s);
    });
    return _zxingLoading;
}

function onScanFound(product, code) {
    if (typeof window.onScanProduct === 'function') window.onScanProduct(product, code);
    else location.href = '/log.php?barcode=' + encodeURIComponent(code);
}

// ── ZXing WASM Beta Live-Scanner ──────────────────────────────────────────
let _zxingActive = false, _zxingStream = null, _zxingFrame = null;
let _zxingLockUntil = 0, _zxingLastResult = null;

async function zxingStart() {
    if (_zxingActive) { zxingStop(); return; }

    const wrap = document.getElementById('zxingWrap');
    const vid  = document.getElementById('zxingVideo');
    const sta  = document.getElementById('zxingStatus');

    // Scan-Effekte zurücksetzen – direkt am Anfang damit
    // der Browser einen vollständigen Reflow macht bevor die Kamera startet
    const line  = document.getElementById('zxingScanLine');
    const frame = document.getElementById('zxingFrame');
    const flash = document.getElementById('zxingFlash');
    const dot   = document.getElementById('zxingDot');
    if (line)  { line.style.animation  = 'none'; }
    if (frame) { frame.style.borderColor = ''; frame.style.boxShadow = ''; }
    if (flash) { flash.style.opacity = '0'; }
    if (dot)   { dot.style.background = 'var(--accent)'; dot.style.animation = 'none'; }

    wrap.style.display = 'flex';
    // Reflow hier: wrap ist jetzt sichtbar, Browser rendert die UI neu
    // Das garantiert dass die Animation-Zurücksetzung wirksam ist
    await new Promise(r => requestAnimationFrame(r));
    await new Promise(r => requestAnimationFrame(r));

    // Animationen jetzt neu starten
    if (line) line.style.animation = 'zxingScan 1.8s ease-in-out infinite';
    if (dot)  dot.style.animation  = 'zxingPulse 1.4s ease-in-out infinite';

    sta.textContent = 'Kamera wird gestartet…';

    // WASM bei jedem Start frisch initialisieren – stellt sicher dass
    // das Modul nach einem vorherigen Stop noch korrekt arbeitet
    try {
        await loadZxing();
        await ZXingWASM.readBarcodesFromImageData(new ImageData(1, 1), {formats: []});
    } catch(e) {
        sta.textContent = 'WASM Fehler: ' + e.message;
        return;
    }

    try {
        _zxingStream = await navigator.mediaDevices.getUserMedia({
            video: {facingMode: 'environment'}
        });
        vid.srcObject = _zxingStream;
        await vid.play();
        _zxingActive = true;

        sta.textContent = 'Barcode positionieren…';

        const canvas = document.createElement('canvas');
        const ctx    = canvas.getContext('2d', {willReadFrequently: true});

        async function loop() {
            if (!_zxingActive) return;
            if (vid.readyState >= 2) {
                canvas.width  = vid.videoWidth;
                canvas.height = vid.videoHeight;
                ctx.drawImage(vid, 0, 0);
                try {
                    const results = await ZXingWASM.readBarcodesFromImageData(
                        ctx.getImageData(0, 0, canvas.width, canvas.height),
                        {formats: [], tryHarder: true, tryRotate: true, tryInvert: true}
                    );
                    if (results && results.length > 0) {
                        const code = results[0].text;
                        if (code && code.length > 2) {
                            // ── Erfolgs-Animation ──────────────────────
                            // 1. Rahmen grün aufleuchten
                            const frame = document.getElementById('zxingFrame');
                            if (frame) {
                                frame.style.borderColor = 'var(--accent)';
                                frame.style.boxShadow   = '0 0 0 9999px rgba(0,0,0,.45), 0 0 24px var(--accent)';
                            }
                            // 2. Weißer Flash über das gesamte Bild
                            const flash = document.getElementById('zxingFlash');
                            if (flash) {
                                flash.style.opacity = '0.6';
                                setTimeout(() => { flash.style.opacity = '0'; }, 150);
                            }
                            // 3. Scan-Linie stoppen
                            const line = document.getElementById('zxingScanLine');
                            if (line) line.style.animation = 'none';

                            sta.textContent = '✓ ' + code;
                            const dot = document.getElementById('zxingDot');
                            if (dot) { dot.style.background = '#fff'; dot.style.animation = 'none'; }
                            // kurz warten damit Erfolgs-Animation sichtbar ist
                            await new Promise(r => setTimeout(r, 300));
                            try {
                                const sta = document.getElementById('zxingStatus');
                                const dot = document.getElementById('zxingDot');

                                // ── Zentrales Such-Overlay ─────────────────
                                scanOverlayShow('Suche „' + code + '" in Datenbank…', {
                                    icon: 'bi-upc-scan', color: 'var(--accent)',
                                });
                                if (sta) sta.textContent = 'Suche…';

                                document.getElementById('notFound')?.classList.add('d-none');
                                let d;
                                try {
                                    const r = await fetch('/api/barcode.php?code=' + encodeURIComponent(code));
                                    d = await r.json();
                                } finally {
                                    scanOverlayHide();
                                }

                                if (d.ok) {
                                    // ── Gefunden: Scanner schließen, Produkt übergeben ──
                                    zxingStop();
                                    onScanFound(d.product, code);
                                } else {
                                    await _zxingRetry(sta, dot, '✗ Barcode nicht in DB');
                                    // Loop neu starten
                                    if (_zxingActive) _zxingFrame = requestAnimationFrame(loop);
                                    return;
                                }
                            } catch(apiE) {
                                const sta = document.getElementById('zxingStatus');
                                const dot = document.getElementById('zxingDot');
                                await _zxingRetry(sta, dot, '✗ Verbindungsfehler');
                                // Loop neu starten
                                if (_zxingActive) _zxingFrame = requestAnimationFrame(loop);
                                return;
                            }
                            return;
                        }
                    }
                } catch(e) { /* Frame-Fehler ignorieren */ }
            }
            setTimeout(() => { if (_zxingActive) _zxingFrame = requestAnimationFrame(loop); }, 300);
        }
        _zxingFrame = requestAnimationFrame(loop);

    } catch(err) {
        sta.textContent = 'Kamera-Fehler: ' + err.message;
    }
}

// Countdown + Reset nach fehlgeschlagenem Scan
async function _zxingRetry(sta, dot, msg) {
    _zxingLockUntil = Date.now() + 3500;
    if (dot) { dot.style.background = 'var(--danger)'; dot.style.animation = 'none'; }

    // 3-Sekunden-Countdown
    for (let i = 3; i >= 1; i--) {
        if (!_zxingActive) return;
        if (sta) sta.innerHTML =
            '<span style="color:var(--danger);">' + msg + ' – neuer Scan in ' + i + 's</span>';
        await new Promise(r => setTimeout(r, 1000));
    }
    if (!_zxingActive) return;

    // Reset: Farben, Status, Scan-Linie
    if (dot) { dot.style.background = 'var(--accent)'; dot.style.animation = 'zxingPulse 1.4s ease-in-out infinite'; }
    if (sta) sta.textContent = 'Barcode positionieren…';
    const line = document.getElementById('zxingScanLine');
    if (line) { line.style.animation = 'none'; void line.offsetHeight; line.style.animation = 'zxingScan 1.8s ease-in-out infinite'; }

    // Lock aufheben + Loop neu anstoßen
    _zxingLastResult = null;
    _zxingLockUntil  = 0;
}

function zxingStop() {
    if (typeof scanOverlayHide === 'function') scanOverlayHide();
    _zxingActive = false;
    if (_zxingFrame) { cancelAnimationFrame(_zxingFrame); _zxingFrame = null; }
    if (_zxingStream) { _zxingStream.getTracks().forEach(t => t.stop()); _zxingStream = null; }
    const wrap = document.getElementById('zxingWrap');
    if (wrap) wrap.style.display = 'none';
    // Scan-Effekte für nächsten Aufruf zurücksetzen
    const line  = document.getElementById('zxingScanLine');
    const frame = document.getElementById('zxingFrame');
    const flash = document.getElementById('zxingFlash');
    // Animation-Reset passiert in zxingStart() damit Browser-Reflow garantiert ist
    if (frame) { frame.style.borderColor = ''; frame.style.boxShadow = ''; }
    if (flash) { flash.style.opacity = '0'; }
}

window.addEventListener('pagehide', zxingStop);
window.addEventListener('beforeunload', zxingStop);

// Scan-Button der Navigation
document.getElementById('navScanBtn')?.addEventListener('click', zxingStart);

// Quick Action „Scannen“ (Manifest-Shortcut → /log.php?scan=1): Kamera
// direkt starten. Den Parameter danach entfernen, damit ein Neuladen nicht
// erneut scannt. Verweigert der Browser die Kamera ohne Antippen, zeigt das
// Scanner-Overlay den Fehler mit „Scanner schließen“.
(function () {
  const qs = new URLSearchParams(location.search);
  if (qs.get('scan') !== '1') return;
  qs.delete('scan');
  history.replaceState(null, '', location.pathname + (qs.toString() ? '?' + qs : '') + location.hash);
  zxingStart();
})();
