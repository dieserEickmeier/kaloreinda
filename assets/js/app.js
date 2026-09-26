

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
    setTimeout(() => document.getElementById('editMengeInput').select(), 100);
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
    if (el) el.textContent = kcal100g > 0 ? Math.round(kcal100g * menge / 100) + ' kcal' : menge + ' g';
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
        btnPortion.textContent = ps > 0 ? `Portionen (${ps} g)` : 'Portionen';
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

// ── Auto-Init ─────────────────────────────────────────────────────────────
initSwipeEntries();

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
        ['kcalRingWrap', 'makroCard'].forEach(id => {
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
