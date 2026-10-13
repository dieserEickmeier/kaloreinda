// ── KalorienTracker – OCR im Web Worker ──────────────────────────────────
// Der Live-Nährwertscan analysiert laufend Kamerabilder (~1 s pro Bild). Auf
// dem Haupt-Thread würde die Seite dabei jedes Mal kurz einfrieren, daher
// läuft die Engine (ppu-ocr.js, siehe log.php) hier.
//
// Nachrichten:
//   → {typ:'init', version, model, wasmPaths}      ← {typ:'bereit'}
//   → {typ:'scan', id, bitmap}  (ImageBitmap, transferiert)
//                                                  ← {typ:'ergebnis', id, items}  (inkl. konf)
//   ← {typ:'fehler', id?, msg}
let svc = null;

self.onmessage = async (e) => {
    const m = e.data;
    try {
        if (m.typ === 'init') {
            const v = '?v=' + encodeURIComponent(m.version);
            importScripts('/assets/js/ocr/ppu-ocr.js' + v, '/assets/js/ocr/ocr-erkennen.js' + v);
            PpuOcr.ort.env.wasm.wasmPaths  = m.wasmPaths;
            PpuOcr.ort.env.wasm.numThreads = 1;
            svc = new PpuOcr.PaddleOcrService({
                model:   m.model,
                session: { executionProviders: ['wasm'] },
            });
            await svc.initialize();
            // Aufwärmlauf: die erste Inferenz ist deutlich langsamer (WASM-
            // Kompilierung, Speicher) – lieber jetzt als beim ersten Kamerabild
            try {
                const w = new OffscreenCanvas(64, 64);
                const g = w.getContext('2d');
                g.fillStyle = '#fff'; g.fillRect(0, 0, 64, 64);
                g.fillStyle = '#000'; g.fillRect(8, 26, 48, 12);
                await ocrErkennen(svc, w);
            } catch (e) { /* optional */ }
            self.postMessage({ typ: 'bereit' });
        } else if (m.typ === 'scan') {
            const bmp = m.bitmap;
            const c = new OffscreenCanvas(bmp.width, bmp.height);
            c.getContext('2d').drawImage(bmp, 0, 0);
            bmp.close();
            self.postMessage({ typ: 'ergebnis', id: m.id, items: await ocrErkennen(svc, c) });
        }
    } catch (err) {
        self.postMessage({ typ: 'fehler', id: m.id, msg: String((err && err.message) || err) });
    }
};
