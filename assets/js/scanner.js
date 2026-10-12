// ── KalorienTracker – Barcode-Live-Scanner (global, auf jeder Seite) ─────
// Wird über den Scan-Button der Navigation geöffnet. „Scanner schließen“
// schließt nur das Overlay, die aktuelle Seite bleibt. Bei einem Treffer
// bzw. unbekanntem Barcode:
//   · auf log.php setzt die Seite window.onScanProduct / onScanNotFound
//     → Produkt-Sheet bzw. „nicht gefunden – anlegen“ direkt
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

// Barcode unbekannt: auf log.php bietet die Seite „Nährwerte scannen“ /
// „Manuell anlegen“ mit diesem Barcode an, überall sonst dorthin wechseln.
function scanNichtGefunden(code) {
    if (typeof window.onScanNotFound === 'function') window.onScanNotFound(code);
    else location.href = '/log.php?barcode=' + encodeURIComponent(code);
}

// Lebensmittel tragen EAN/UPC – nur diese Formate suchen ist schneller und
// verhindert Fehltreffer durch QR-/DataMatrix-Codes auf der Packung.
const ZXING_FORMATS = ['EAN-13', 'EAN-8', 'UPC-A', 'UPC-E'];

// Auszuwertender Bildbereich in Video-Koordinaten: volle Breite, vertikal ein
// großzügiges Band um den sichtbaren Rahmen (Barcodes werden selten exakt im
// Rahmen gehalten). Video ist object-fit:contain → ggf. Letterbox abziehen.
function zxingScanRegion(vid, frameEl) {
    const W = vid.videoWidth, H = vid.videoHeight;
    if (!frameEl) return {x: 0, y: 0, w: W, h: H};
    const c = vid.getBoundingClientRect(), f = frameEl.getBoundingClientRect();
    const scale = Math.min(c.width / W, c.height / H);
    const offY  = (c.height - H * scale) / 2;
    const midY  = (f.top + f.height / 2 - c.top - offY) / scale;
    const bandH = Math.min(H, Math.max(f.height / scale * 2, H * 0.4));
    const y     = Math.max(0, Math.min(H - bandH, midY - bandH / 2));
    return {x: 0, y: Math.round(y), w: W, h: Math.round(bandH)};
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
        await ZXingWASM.readBarcodesFromImageData(new ImageData(1, 1), {formats: ZXING_FORMATS});
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
            if (vid.readyState >= 2 && vid.videoWidth) {
                // Nur das Band um den Rahmen auswerten, max. 1280 px breit
                const reg = zxingScanRegion(vid, document.getElementById('zxingFrame'));
                const k   = Math.min(1, 1280 / reg.w);
                const cw  = Math.round(reg.w * k), ch = Math.round(reg.h * k);
                if (canvas.width !== cw)  canvas.width  = cw;
                if (canvas.height !== ch) canvas.height = ch;
                ctx.drawImage(vid, reg.x, reg.y, reg.w, reg.h, 0, 0, cw, ch);
                try {
                    const results = await ZXingWASM.readBarcodesFromImageData(
                        ctx.getImageData(0, 0, cw, ch),
                        {formats: ZXING_FORMATS, tryHarder: true, tryRotate: true, tryInvert: true}
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
                                // ── Zentrales Such-Overlay ─────────────────
                                scanOverlayShow('Suche „' + code + '" in Datenbank…', {
                                    icon: 'bi-upc-scan', color: 'var(--accent)',
                                });
                                sta.textContent = 'Suche…';

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
                                } else if (d.offline) {
                                    // OpenFoodFacts nicht erreichbar → gleich nochmal versuchen
                                    await _zxingRetry(sta, dot, '✗ OpenFoodFacts nicht erreichbar');
                                    if (_zxingActive) _zxingFrame = requestAnimationFrame(loop);
                                } else {
                                    // Unbekannt: Scanner schließen, Anlegen anbieten
                                    zxingStop();
                                    scanNichtGefunden(code);
                                }
                            } catch(apiE) {
                                await _zxingRetry(sta, dot, '✗ Verbindungsfehler');
                                if (_zxingActive) _zxingFrame = requestAnimationFrame(loop);
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
