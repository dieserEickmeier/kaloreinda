// ── KalorienTracker – Texterkennung eines Bildes ─────────────────────────
// Wie svc.recognize(canvas, {flatten: true, minimumConfidence: 0}), nur mit
// gesonderter Behandlung hoher Textstellen. Geladen im Worker (ocr-worker.js)
// und beim Haupt-Thread-Rückfall in log.php.
//
// Die Bibliothek fasst erkannte Boxen zu Zeilen zusammen, solange der
// y-Abstand zur vorigen Box unter der halben mittleren Boxhöhe der Gruppe
// liegt. Eine sehr hohe Box (senkrechter Text am Etikettrand, „bezogen auf
// das abgetropfte Produkt“ beim Gurkenglas) hebt diese Schwelle so stark an,
// dass die ganze Nährwerttabelle zu einer „Zeile“ verkettet wird – deren
// Erkennung liefert dann nur Unsinn („网 区 团“). Solche Boxen werden daher
// einzeln erkannt (die Bibliothek dreht senkrechte Ausschnitte selbst).
//
// Nutzt interne Felder der Bibliothek (svc.recognitor, svc.options) – bei
// einem Update von ppu-ocr.js prüfen. Rückgabe: [{text, x, y, w, h, konf}]
globalThis.ocrErkennen = async function (svc, canvas) {
    const { boxes } = await svc.detect(canvas);
    if (!boxes.length) return [];
    const hoehen = boxes.map(b => b.height).sort((a, b) => a - b);
    const median = hoehen[Math.floor(hoehen.length / 2)];
    const hoch   = b => b.height > 2.5 * median;
    // minimumConfidence 0: alle Textstellen inkl. Konfidenz zurück, gefiltert
    // wird in log.php (die Bibliothek bewertet Zeilen stapelweise, ihr
    // Standardfilter 0,5 wirft sonst ganze Zeilengruppen weg)
    const opt    = { minimumConfidence: 0 };
    const dict   = svc.options.recognition?.charactersDictionary;
    const zeilen = await svc.recognitor.run(canvas, boxes.filter(b => !hoch(b)), dict, 'per-line', opt);
    const einzel = boxes.some(hoch)
        ? await svc.recognitor.run(canvas, boxes.filter(hoch), dict, 'per-box', opt) : [];
    return [...zeilen, ...einzel].map(r => ({
        text: r.text, x: r.box.x, y: r.box.y, w: r.box.width, h: r.box.height, konf: r.confidence,
    }));
};
