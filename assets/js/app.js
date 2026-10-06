

// ── KalorienTracker – App-weite Hilfsfunktionen ──────────────────────────

document.addEventListener('click', (e) => {
    const btn = e.target.closest('button, a.scan-btn');
    if (btn && !btn.dataset.noBlock) {
        btn.style.opacity = '0.7';
        setTimeout(() => btn.style.opacity = '', 300);
    }
});

let lastTouch = 0;

// ── Swipe-to-reveal ───────────────────────────────────────────────────────

let activeSwipeItem = null;

function closeAllSwipes(except) {
    document.querySelectorAll('.swipe-entry.open').forEach(el => {
        if (el !== except) el.classList.remove('open');
    });
    if (activeSwipeItem && activeSwipeItem !== except) activeSwipeItem = null;
}

function initSwipeEntries() {
    document.querySelectorAll('.swipe-entry').forEach(item => {
        if (item.dataset.swipeInit) return;
        item.dataset.swipeInit = '1';

        let startX = 0, startY = 0, wasSwiping = false;
        const THRESHOLD = 40;

        item.addEventListener('touchstart', e => {
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            wasSwiping = false;
        }, { passive: true });

        item.addEventListener('touchmove', e => {
            const dx = e.touches[0].clientX - startX;
            const dy = Math.abs(e.touches[0].clientY - startY);
            if (Math.abs(dx) > dy && Math.abs(dx) > 8) {
                wasSwiping = true;
                e.preventDefault();
            }
        }, { passive: false });

        item.addEventListener('touchend', e => {
            const dx = e.changedTouches[0].clientX - startX;
            if (wasSwiping) {
                if (dx < -THRESHOLD) {
                    closeAllSwipes(item);
                    item.classList.add('open');
                    activeSwipeItem = item;
                } else if (dx > THRESHOLD) {
                    item.classList.remove('open');
                    activeSwipeItem = null;
                }
            }
            wasSwiping = false;
        }, { passive: true });

        const editBtn = item.querySelector('.swipe-action-edit');
        const delBtn  = item.querySelector('.swipe-action-delete');

        if (editBtn) {
            editBtn.addEventListener('touchend', e => {
                if (wasSwiping) return;
                e.preventDefault();
                e.stopPropagation();
                const d = editBtn.dataset;
                openEditModal(parseInt(d.entryId), d.entryName, parseFloat(d.mengeG), parseFloat(d.kcal100g));
            }, { passive: false });
            editBtn.addEventListener('click', e => {
                e.stopPropagation();
                const d = editBtn.dataset;
                openEditModal(parseInt(d.entryId), d.entryName, parseFloat(d.mengeG), parseFloat(d.kcal100g));
            });
        }

        if (delBtn) {
            delBtn.addEventListener('touchend', e => {
                if (wasSwiping) return;
                e.preventDefault();
                e.stopPropagation();
                deleteEntryGlobal(parseInt(delBtn.dataset.entryId));
            }, { passive: false });
            delBtn.addEventListener('click', e => {
                e.stopPropagation();
                deleteEntryGlobal(parseInt(delBtn.dataset.entryId));
            });
        }
    });

    document.addEventListener('touchstart', e => {
        if (!e.target.closest('.swipe-entry')) closeAllSwipes();
    }, { passive: true });
}

// ── Bearbeiten-Modal ──────────────────────────────────────────────────────

function openEditModal(id, name, mengeG, kcal100g) {
    const modal = document.getElementById('editEntryModal');
    modal.dataset.entryId  = id;
    modal.dataset.kcal100g = kcal100g || 0;
    document.getElementById('editEntryName').textContent = name;
    document.getElementById('editMengeInput').value      = mengeG;
    updateEditPreview();
    modal.style.display = 'flex';
    document.getElementById('editMengeInput').dispatchEvent(new Event('input'));
}

function closeEditModal() {
    const modal = document.getElementById('editEntryModal');
    if (modal) modal.style.display = 'none';
    closeAllSwipes();
}

function updateEditPreview() {
    const kcal100g = parseFloat(document.getElementById('editEntryModal')?.dataset.kcal100g) || 0;
    const menge    = parseFloat(document.getElementById('editMengeInput')?.value) || 0;
    const el       = document.getElementById('editKcalPreview');
    if (el) el.textContent = kcal100g > 0 ? Math.round(kcal100g * menge / 100) : '–';
}

async function saveEditEntry() {
    const modal = document.getElementById('editEntryModal');
    const id    = parseInt(modal?.dataset.entryId);
    const menge = parseFloat(document.getElementById('editMengeInput').value);
    if (!id || !menge || menge <= 0) return;
    try {
        const r = await fetch('/api/entry.php', {
            method: 'PUT',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ id, menge_g: menge }),
        });
        const d = await r.json();
        if (d.ok) { closeEditModal(); location.reload(); }
        else alert('Fehler: ' + (d.error || 'Unbekannt'));
    } catch(e) { alert('Verbindungsfehler'); }
}

async function deleteEntryGlobal(id) {
    if (!confirm('Eintrag löschen?')) return;
    try {
        const r = await fetch('/api/entry.php', {
            method: 'DELETE',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ id }),
        });
        const d = await r.json();
        if (d.ok) {
            document.getElementById('entry-' + id)?.remove();
            closeAllSwipes();
            setTimeout(() => location.reload(), 400);
        }
    } catch(e) { alert('Verbindungsfehler'); }
}

// ── Portionswahl-Toggle ──────────────────────────────────────────────────

function setupPortionToggle(portionG, mengeInputId) {
    const toggleWrap   = document.getElementById('portionToggleWrap');
    if (!toggleWrap) return;
    const btnGramm     = document.getElementById('toggleGramm');
    const btnPortion   = document.getElementById('togglePortion');
    const portionInput = document.getElementById('portionSizeInput');
    const mengeInput   = document.getElementById(mengeInputId);
    toggleWrap.style.display = 'flex';
    if (portionInput) { portionInput.value = ''; portionInput.style.display = 'none'; }

    let mode = 'gramm';

    function getPortionSize() {
        if (portionG && portionG > 0) return portionG;
        return portionInput ? (parseFloat(portionInput.value) || 0) : 0;
    }

    // Im Portion-Modus enthält das Mengenfeld die Anzahl Portionen,
    // gebucht wird immer in Gramm.
    function getMengeG() {
        const val = parseFloat(mengeInput.value) || 0;
        return mode === 'portion' ? val * getPortionSize() : val;
    }

    async function savePortionIfNeeded(produktId, size) {
        if (!produktId || !size || size <= 0) return;
        if (portionG && portionG > 0) return;
        try {
            await fetch('/api/produkt_save.php', {
                method: 'PATCH',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ produkt_id: produktId, portion_g: size }),
            });
        } catch(e) {}
    }

    window._pfPortionMode = () => mode;
    window._getMengeG = getMengeG;
    window._getAndSavePortion = async (produktId) => {
        if (mode !== 'portion') return 0;
        const size = getPortionSize();
        await savePortionIfNeeded(produktId, size);
        return size;
    };

    function updatePortionLabel() {
        const ps = getPortionSize();
        btnPortion.textContent = ps > 0 ? `Portionen (${Number(ps).toLocaleString('de-DE')} g)` : 'Portionen';
    }

    function setMode(newMode) {
        const bisherG = getMengeG();
        mode = newMode;
        const isPortion = mode === 'portion';
        btnPortion.classList.toggle('active', isPortion);
        btnGramm.classList.toggle('active', !isPortion);

        const chipsG = document.getElementById('pfQuickChips');
        const chipsP = document.getElementById('pfQuickChipsPortion');
        const unit   = document.getElementById('mengeUnitLabel');
        if (chipsG) chipsG.style.display = isPortion ? 'none' : 'flex';
        if (chipsP) chipsP.style.display = isPortion ? 'flex' : 'none';
        if (unit)   unit.textContent = isPortion ? 'Portion(en)' : 'g';

        if (isPortion) {
            mengeInput.min = 0.5; mengeInput.max = 50; mengeInput.step = 0.5;
            mengeInput.value = 1;
            if ((!portionG || portionG <= 0) && portionInput) {
                portionInput.style.display = 'block';
                portionInput.focus();
            }
        } else {
            mengeInput.min = 1; mengeInput.max = 5000; mengeInput.step = 1;
            // Beim Zurückwechseln die bisher gewählte Menge in Gramm übernehmen
            mengeInput.value = bisherG > 0 ? Math.round(bisherG * 10) / 10 : 100;
            if (portionInput) portionInput.style.display = 'none';
        }
        mengeInput.dispatchEvent(new Event('input'));
    }

    if (portionInput) {
        portionInput.oninput = () => {
            updatePortionLabel();
            mengeInput.dispatchEvent(new Event('input'));
        };
    }

    updatePortionLabel();
    btnGramm.onclick   = () => setMode('gramm');
    btnPortion.onclick = () => setMode('portion');
    setMode('gramm');
}

// ── Lineal-Slider für Mengenfelder ───────────────────────────────────────
// Markup: <div class="ruler" data-ruler-for="<input-id>"></div>
// Das Lineal liest min/max/step direkt vom Input (und baut sich neu, wenn
// z.B. setupPortionToggle() sie ändert). Wischen setzt input.value und
// feuert 'input' – bestehende Handler (kcal-Vorschau) laufen unverändert.
// Werte außerhalb des Lineal-Bereichs (z.B. 1500 g getippt) bleiben
// erhalten: der Wert wird nur bei aktiver Nutzer-Geste vom Lineal gesetzt.
function initRuler(el) {
    const input = document.getElementById(el.dataset.rulerFor);
    if (!input || el.dataset.rulerInit) return;
    el.dataset.rulerInit = '1';
    el.innerHTML = '<div class="ruler__scroll"><div class="ruler__track" style="display:flex;height:100%;">' +
        '<div style="flex:none;width:50%;"></div><div class="ruler__ticks" style="flex:none;position:relative;height:100%;"></div>' +
        '<div style="flex:none;width:50%;"></div></div></div><div class="ruler__needle"></div>';
    const sc    = el.querySelector('.ruler__scroll');
    const ticks = el.querySelector('.ruler__ticks');
    let cfg = null, userActive = false, endTimer = 0, programmatic = false;

    function build() {
        const st     = parseFloat(input.step) || 1;
        // Feine Skala nur für Portionsschritte (½), nicht für Gramm-Felder mit step=0.1
        const fine   = st >= 0.25 && st < 1;
        const step   = fine ? st : 5;
        const min    = Math.max(0, parseFloat(input.min) || 0);
        const maxIn  = parseFloat(input.max) || 1000;
        const max    = Math.min(maxIn, fine ? 10 : 1000);
        const start  = 0;
        const px     = fine ? 18 : 8;
        const major  = fine ? Math.round(1 / step) : 10;
        const n      = Math.round((max - start) / step);
        cfg = { step, start, min, max, px, fine };
        let html = '';
        for (let i = 0; i <= n; i++) {
            const v = start + i * step;
            const cls = i % major === 0 ? ' major' : (i % (major / 2) === 0 ? ' mid' : '');
            html += `<span class="ruler__tick${cls}" style="left:${i * px}px"></span>`;
            if (i % major === 0) html += `<span class="ruler__lab" style="left:${i * px}px">${fine ? v.toLocaleString('de-DE') : v}</span>`;
        }
        ticks.style.width = (n * px) + 'px';
        ticks.innerHTML = html;
        sync();
    }

    function sync() {
        if (!cfg || userActive) return;
        const v = parseFloat(String(input.value).replace(',', '.')) || 0;
        const x = (Math.min(Math.max(v, cfg.start), cfg.max) - cfg.start) / cfg.step * cfg.px;
        programmatic = true;
        sc.scrollLeft = x;
        requestAnimationFrame(() => { programmatic = false; });
    }

    function valueFromScroll() {
        const raw = cfg.start + Math.round(sc.scrollLeft / cfg.px) * cfg.step;
        return Math.min(Math.max(raw, cfg.min || cfg.step), cfg.max);
    }

    sc.addEventListener('scroll', () => {
        if (programmatic || !userActive || !cfg) return;
        const v = valueFromScroll();
        const r = Math.round(v * 10) / 10;
        if (parseFloat(input.value) !== r) {
            input.value = r;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
        clearTimeout(endTimer);
        endTimer = setTimeout(() => { userActive = false; sync(); }, 160);
    }, { passive: true });

    ['touchstart', 'pointerdown', 'wheel'].forEach(ev =>
        sc.addEventListener(ev, () => { userActive = true; }, { passive: true }));

    // Maus-Ziehen (Desktop)
    let dragX = null, dragLeft = 0;
    sc.addEventListener('pointerdown', e => {
        if (e.pointerType !== 'mouse') return;
        dragX = e.clientX; dragLeft = sc.scrollLeft; sc.setPointerCapture(e.pointerId);
    });
    sc.addEventListener('pointermove', e => { if (dragX !== null) sc.scrollLeft = dragLeft - (e.clientX - dragX); });
    sc.addEventListener('pointerup',   () => { dragX = null; });

    input.addEventListener('input', () => { if (!userActive) sync(); });
    new MutationObserver(build).observe(input, { attributes: true, attributeFilter: ['min', 'max', 'step'] });
    if (window.ResizeObserver) new ResizeObserver(() => sync()).observe(el);
    build();
}

function initRulers(root) {
    (root || document).querySelectorAll('.ruler[data-ruler-for]').forEach(initRuler);
}

// ── Tipp auf Zeitleisten-Eintrag öffnet „Bearbeiten“ ─────────────────────
document.addEventListener('click', e => {
    const row = e.target.closest('[data-edit-id]');
    if (!row || e.target.closest('.swipe-entry__actions')) return;
    const sw = row.closest('.swipe-entry');
    if (sw && sw.classList.contains('open')) { sw.classList.remove('open'); return; }
    const d = row.dataset;
    openEditModal(parseInt(d.editId), d.editName, parseFloat(d.editMenge), parseFloat(d.editKcal100g));
});

// ── Auto-Init ─────────────────────────────────────────────────────────────
initSwipeEntries();
initRulers();

// ── Info-Tooltips ─────────────────────────────────────────────────────────
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.kt-info-btn');
    if (btn) {
        e.stopPropagation();
        var tooltip = btn.parentElement.querySelector('.kt-tooltip');
        if (!tooltip) return;
        var isOpen = tooltip.classList.contains('open');
        document.querySelectorAll('.kt-tooltip.open').forEach(function(t) {
            t.classList.remove('open');
            var b = t.parentElement.querySelector('.kt-info-btn');
            if (b) b.classList.remove('open');
        });
        if (!isOpen) {
            tooltip.classList.add('open');
            btn.classList.add('open');
        }
        return;
    }
    // Außerhalb → alle schließen
    if (!e.target.closest('.kt-tooltip')) {
        document.querySelectorAll('.kt-tooltip.open').forEach(function(t) {
            t.classList.remove('open');
            var b = t.parentElement.querySelector('.kt-info-btn');
            if (b) b.classList.remove('open');
        });
    }
});

// ─── Aktiv-Refresh ohne Reload (Ring + Makros neu holen bei Foreground) ──────
const kcalRingWrap = document.getElementById('kcalRingWrap');

async function refreshHeute() {
    try {
        const r = await fetch('/index.php', { cache: 'no-store' });
        const html = await r.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        ['kcalRingWrap', 'makroCard', 'weekStrip'].forEach(id => {
            const neu = doc.getElementById(id);
            const alt = document.getElementById(id);
            if (neu && alt) alt.replaceWith(neu);
        });
    } catch (e) { console.error('refreshHeute fehlgeschlagen', e); }
}

// Leichter Check: nur total_kcal von heute abfragen, kein voller HTML-Fetch
async function checkAktivKcal() {
    if (!kcalRingWrap) return;
    try {
        const r = await fetch('/api/activity.php?datum=' + new Date().toISOString().slice(0, 10), { cache: 'no-store' });
        const d = await r.json();
        if (!d.ok) return;
        const aktuell = parseInt(kcalRingWrap.dataset.aktivKcal || '0', 10);
        if (d.total_kcal !== aktuell) refreshHeute();
    } catch (e) { /* still, egal – nächster Poll versucht's wieder */ }
}

if (kcalRingWrap) {
    // App-Relaunch / Tab-Wechsel: sofort prüfen
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) checkAktivKcal();
    });
    window.addEventListener('pageshow', () => checkAktivKcal());
    window.addEventListener('focus', () => checkAktivKcal());

    // Fallback-Poll: alle 5s solange Seite sichtbar ist (deckt iOS-PWA-Fälle ab,
    // in denen kein Lifecycle-Event feuert)
    setInterval(() => { if (!document.hidden) checkAktivKcal(); }, 5000);
}
