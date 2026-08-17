

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

function setupPortionToggle(portionG, mengeInputId, previewId, kcal100g) {
    const toggleWrap   = document.getElementById('portionToggleWrap');
    if (!toggleWrap) return;
    const btnGramm     = document.getElementById('toggleGramm');
    const btnPortion   = document.getElementById('togglePortion');
    const portionInput = document.getElementById('portionSizeInput');
    const mengeInput   = document.getElementById(mengeInputId);
    toggleWrap.style.display = 'flex';

    if (portionG && portionG > 0) {
        btnPortion.textContent = `1 Portion (${portionG} g)`;
        if (portionInput) portionInput.style.display = 'none';
    } else {
        btnPortion.textContent = '1 Portion';
        if (portionInput) portionInput.style.display = 'none';
    }

    function getPortionSize() {
        if (portionG && portionG > 0) return portionG;
        return portionInput ? (parseFloat(portionInput.value) || 0) : 0;
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

    window._getAndSavePortion = async (produktId) => {
        const size = getPortionSize();
        await savePortionIfNeeded(produktId, size);
        return size;
    };

    function updatePreview() {
        const menge = parseFloat(mengeInput.value) || 0;
        const el = document.getElementById(previewId);
        if (el) el.textContent = Math.round(kcal100g * menge / 100);
    }

    // Stepper/Chips/Einheit ausblenden im Portion-Modus – dort ergibt
    // eine feste "1 Portion" mehr Sinn als Gramm-Feintuning.
    function setQuickControlsVisible(visible) {
        const stepMinus = document.getElementById('btnMengeMinus');
        const stepPlus  = document.getElementById('btnMengePlus');
        const chips     = document.getElementById('pfQuickChips');
        const unitLabel = document.getElementById('mengeUnitLabel');
        if (stepMinus) stepMinus.style.visibility = visible ? 'visible' : 'hidden';
        if (stepPlus)  stepPlus.style.visibility  = visible ? 'visible' : 'hidden';
        if (chips)     chips.style.display = visible ? 'flex' : 'none';
        if (unitLabel) unitLabel.textContent = visible ? 'g' : 'Portion';
    }

    function setMode(mode) {
        if (mode === 'portion') {
            btnPortion.classList.add('active');
            btnGramm.classList.remove('active');
            mengeInput.readOnly = true;
            mengeInput.style.opacity = '.6';
            setQuickControlsVisible(false);
            if ((!portionG || portionG <= 0) && portionInput) {
                portionInput.style.display = 'block';
                portionInput.focus();
                portionInput.oninput = () => {
                    const ps = parseFloat(portionInput.value) || 0;
                    mengeInput.value = ps;
                    btnPortion.textContent = ps > 0 ? `1 Portion (${ps} g)` : '1 Portion';
                    updatePreview();
                };
            } else {
                mengeInput.value = getPortionSize();
                updatePreview();
            }
        } else {
            btnGramm.classList.add('active');
            btnPortion.classList.remove('active');
            mengeInput.readOnly = false;
            mengeInput.style.opacity = '';
            setQuickControlsVisible(true);
            if (portionInput) portionInput.style.display = 'none';
            updatePreview();
        }
    }

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
