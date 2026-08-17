<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
$currentUser = requireLogin();
$userId = $currentUser['id'];
renderHeader('Gerichte', 'log');
?>

<div class="page-header">
    <div style="display:flex;align-items:center;gap:.6rem;">
        <a href="/log.php" style="color:var(--muted);text-decoration:none;font-size:1.1rem;">
            <i class="bi bi-chevron-left"></i>
        </a>
        <h1 style="margin:0;"><i class="bi bi-journal-richtext text-accent me-1"></i> Gerichte</h1>
    </div>
    <div style="display:flex;gap:.5rem;">
        <button onclick="openImportModal()"
                style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;
                       padding:.4rem .8rem;color:var(--text);font-weight:600;font-size:.88rem;">
            <i class="bi bi-box-arrow-in-down"></i> Import
        </button>
        <button onclick="openGerichtModal()"
                style="background:var(--accent);border:none;border-radius:10px;
                       padding:.4rem .9rem;color:#000;font-weight:700;font-size:.88rem;">
            <i class="bi bi-plus-lg"></i> Neu
        </button>
    </div>
</div>

<!-- ── Gerichtsliste ─────────────────────────────────────────── -->
<div id="gerichteList" style="padding:1rem 0 1rem;">
    <div style="text-align:center;color:var(--muted);padding:2rem 0;" id="gerichteLoading">
        <div class="spinner-border spinner-border-sm spinner-accent"></div>
    </div>
</div>

<!-- ── Modal: Gericht importieren ────────────────────────────── -->
<div id="importModal" style="display:none;position:fixed;inset:0;z-index:500;
     background:rgba(0,0,0,.7);padding:env(safe-area-inset-top,0) 0 env(safe-area-inset-bottom,0);">
    <div style="background:var(--bg);border-radius:20px 20px 0 0;position:absolute;
                bottom:0;left:0;right:0;padding:1.5rem 1rem 2rem;max-height:90vh;overflow-y:auto;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
            <div>
                <h2 style="font-size:1.05rem;font-weight:700;margin:0;">Gericht importieren</h2>
                <p style="font-size:.75rem;color:var(--muted);margin:.2rem 0 0;">
                    Geteiltes Gericht (JSON) hier einfügen
                </p>
            </div>
            <button onclick="closeImportModal()"
                    style="background:var(--surface2);border:none;border-radius:50%;
                           width:2rem;height:2rem;color:var(--muted);font-size:1rem;
                           display:flex;align-items:center;justify-content:center;">
                <i class="bi bi-x"></i>
            </button>
        </div>


        <!-- Syntax-Hilfe: Vorlage für KI-Prompts -->
        <div style="margin-bottom:.75rem;">
            <button onclick="toggleImportSyntax()" type="button"
                    style="display:inline-flex;align-items:center;gap:.35rem;
                           background:transparent;border:none;padding:0;
                           color:var(--muted);font-size:.75rem;cursor:pointer;">
                <i class="bi bi-info-circle"></i> Syntax-Beispiel &amp; KI-Vorlage
                <i class="bi bi-chevron-down" id="importSyntaxChevron" style="font-size:.65rem;transition:transform .2s;"></i>
            </button>
            <div id="importSyntaxBox" style="display:none;margin-top:.5rem;">
                <pre style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;
                            padding:.6rem .7rem;font-size:.68rem;line-height:1.45;color:var(--text);
                            overflow-x:auto;margin:0 0 .5rem;white-space:pre;">{
  "typ": "kt-gericht",
  "version": 1,
  "name": "Spaghetti Bolognese",
  "portionen": 4,
  "zutaten": [
    {"name": "Spaghetti (roh)", "menge_g": 400,
     "kcal_100g": 358, "eiweiss_100g": 13,
     "fett_100g": 1.6, "kh_100g": 72}
  ]
}</pre>
                <button onclick="copyImportPrompt()" type="button"
                        style="width:100%;background:var(--surface2);border:1px solid var(--border);
                               border-radius:10px;padding:.5rem;color:var(--text);
                               font-size:.78rem;font-weight:600;cursor:pointer;">
                    <i class="bi bi-clipboard"></i> KI-Vorlage kopieren
                </button>
                <div style="font-size:.68rem;color:var(--muted);margin-top:.4rem;line-height:1.4;">
                    Kopiert einen fertigen Prompt: an eine KI schicken, Rezept anhängen,
                    das JSON aus der Antwort hier einfügen.
                </div>
            </div>
        </div>

        <textarea id="importInput" rows="8" class="form-control"
                  placeholder='{"typ":"kt-gericht", "name":"…", "zutaten":[…]}'
                  style="font-size:.78rem;font-family:ui-monospace,monospace;margin-bottom:.75rem;"></textarea>

        <!-- Live-Vorschau -->
        <div id="importPreview" style="display:none;background:var(--surface2);border-radius:10px;
             padding:.6rem .8rem;font-size:.82rem;margin-bottom:1rem;line-height:1.4;"></div>

        <button id="btnImport" onclick="importGericht()" class="scan-btn mb-2">
            <i class="bi bi-box-arrow-in-down"></i> Importieren
        </button>
        <button onclick="closeImportModal()" class="scan-btn secondary">
            Abbrechen
        </button>
    </div>
</div>

<!-- ── Modal: Gericht anlegen/bearbeiten ─────────────────────── -->
<div id="gerichtModal" onclick="if(event.target===this)closeGerichtModal()"
     style="display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.7);
            align-items:flex-end;justify-content:center;">
    <div style="background:var(--bg);border-radius:20px 20px 0 0;width:100%;max-width:520px;
                max-height:92vh;display:flex;flex-direction:column;">
        <div style="overflow-y:auto;padding:1.5rem 1rem 2rem;flex:1;">

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
            <h2 style="font-size:1.05rem;font-weight:700;margin:0;" id="gerichtModalTitle">Neues Gericht</h2>
            <button onclick="closeGerichtModal()"
                    style="background:var(--surface2);border:none;border-radius:50%;width:2rem;height:2rem;
                           color:var(--muted);font-size:1rem;display:flex;align-items:center;justify-content:center;">
                <i class="bi bi-x"></i>
            </button>
        </div>

        <div class="mb-3">
            <label class="form-label" style="color:var(--muted);font-size:.82rem;">Name des Gerichts</label>
            <input type="text" id="gName" class="form-control" placeholder="z.B. Haferflocken-Bowl"
                   style="background:var(--surface);border-color:var(--border);color:var(--text);border-radius:12px;">
        </div>
        <div class="mb-4" style="display:flex;align-items:center;gap:.75rem;">
            <div style="flex:1;">
                <label class="form-label" style="color:var(--muted);font-size:.82rem;">Ergibt Portionen</label>
                <input type="number" id="gPortionen" class="form-control" value="1" min="0.5" step="0.5"
                       style="background:var(--surface);border-color:var(--border);color:var(--text);border-radius:12px;">
            </div>
            <div style="flex:1;text-align:center;margin-top:1.4rem;">
                <div style="font-size:.75rem;color:var(--muted);">Gesamt</div>
                <div style="font-weight:800;font-size:1.1rem;color:var(--accent);" id="gKcalTotal">0 kcal</div>
                <div style="font-size:.72rem;color:var(--muted);" id="gKcalPortion">0 kcal / Portion</div>
            </div>
        </div>

        <!-- Zutatenliste -->
        <div style="font-size:.82rem;font-weight:600;color:var(--muted);margin-bottom:.5rem;">Zutaten</div>
        <div id="zutatenList" style="margin-bottom:.75rem;"></div>

        <!-- Zutat hinzufügen -->
        <div class="kt-card" style="margin:0 0 1rem;">
            <button type="button" onclick="goScanZutat()" class="scan-btn" style="margin:0;width:100%;">
                <i class="bi bi-search"></i> Zutat hinzufügen
            </button>
            <div style="font-size:.68rem;color:var(--muted);text-align:center;margin-top:.5rem;">
                Suche, Barcode-Scan, Foto oder manuelle Eingabe – alles an einem Ort
            </div>
        </div>

        <button onclick="saveGericht()" class="scan-btn" style="margin:0;width:100%;">
            <i class="bi bi-check-circle-fill"></i> Gericht speichern
        </button>
        </div><!-- end scroll wrapper -->
    </div>
</div>

<!-- ── Modal: Gericht eintragen (angeglichen an log.php) ──────── -->
<div id="gerichtEintragenModal" class="product-found">
  <div class="pf-card">
    <div class="pf-head">
        <div class="pf-head__icon"><i class="bi bi-journal-richtext"></i></div>
        <div class="pf-head__text">
            <div id="gerichtEintragenName">–</div>
            <div id="gerichtEintragenInfo">–</div>
        </div>
        <button class="pf-close" id="btnGerichtVerwerfen" aria-label="Schließen">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <div class="pf-kcal">
        <div class="pf-kcal__num" id="geKcalPreview">0</div>
        <div class="pf-kcal__unit">kcal</div>
    </div>

    <div class="pf-macros">
        <div class="pf-macro">
            <div class="pf-macro__ring" style="--c:var(--accent);"><span id="geKcalPortion">–</span></div>
            <span class="pf-macro__lab">kcal/Port.</span>
        </div>
        <div class="pf-macro">
            <div class="pf-macro__ring" style="--c:#60a5fa;"><span id="geKcalGesamt">–</span></div>
            <span class="pf-macro__lab">kcal ges.</span>
        </div>
        <div class="pf-macro">
            <div class="pf-macro__ring" style="--c:#a78bfa;"><span id="gePortionen">–</span></div>
            <span class="pf-macro__lab">Portionen</span>
        </div>
    </div>

    <!-- Modus-Umschalter: Portionen oder Gramm -->
    <div class="pf-portion-wrap" style="display:flex;">
        <button type="button" id="geModeGramm" onclick="setGerichtModus('gramm')" class="portion-toggle">
            Gramm
        </button>
        <button type="button" id="geModePortion" onclick="setGerichtModus('portion')" class="portion-toggle active">
            Portionen
        </button>
    </div>

    <!-- Menge: Stepper + Schnellwahl -->
    <div class="pf-menge">
        <button type="button" class="pf-stepper" id="btnGeMinus" aria-label="Weniger">
            <i class="bi bi-dash-lg"></i>
        </button>
        <div class="pf-menge__field">
            <input type="number" inputmode="decimal" id="gerichtEintragenPortionen" value="1" min="0.5" step="0.5">
            <span id="gerichtEintragenEinheit">Portion(en)</span>
        </div>
        <button type="button" class="pf-stepper" id="btnGePlus" aria-label="Mehr">
            <i class="bi bi-plus-lg"></i>
        </button>
    </div>
    <div class="pf-chips" id="geQuickChipsPortion">
        <button type="button" class="pf-chip" data-val="0.5">½</button>
        <button type="button" class="pf-chip active" data-val="1">1</button>
        <button type="button" class="pf-chip" data-val="1.5">1½</button>
        <button type="button" class="pf-chip" data-val="2">2</button>
        <button type="button" class="pf-chip" data-val="3">3</button>
    </div>
    <div class="pf-chips" id="geQuickChipsGramm" style="display:none;">
        <button type="button" class="pf-chip" data-val="50">50</button>
        <button type="button" class="pf-chip" data-val="100">100</button>
        <button type="button" class="pf-chip" data-val="150">150</button>
        <button type="button" class="pf-chip" data-val="200">200</button>
        <button type="button" class="pf-chip" data-val="250">250</button>
    </div>

    <div class="pf-actions">
        <button class="scan-btn" id="btnGerichtEintragen">
            <i class="bi bi-plus-circle-fill"></i> Eintragen
        </button>
    </div>
  </div>
</div>

<!-- ── Toast ─────────────────────────────────────────────────── -->
<div class="toast-container position-fixed bottom-0 start-50 translate-middle-x mb-5 pb-4">
    <div id="toast" class="toast align-items-center text-bg-dark border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="toastMsg"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
function showToast(msg) {
    document.getElementById('toastMsg').textContent = msg;
    bootstrap.Toast.getOrCreateInstance(document.getElementById('toast')).show();
}
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ── Gerichtsliste laden ──────────────────────────────────────────────────────
async function loadGerichte() {
    const r = await fetch('/api/gericht.php');
    const d = await r.json();
    const el = document.getElementById('gerichteList');
    const loadingEl = document.getElementById('gerichteLoading');
    if (loadingEl) loadingEl.style.display = 'none';

    if (!d.ok || !d.gerichte.length) {
        el.innerHTML = `<div style="text-align:center;color:var(--muted);padding:2rem 0;">
            <i class="bi bi-journal-x" style="font-size:2.5rem;display:block;margin-bottom:.5rem;"></i>
            Noch keine Gerichte angelegt.<br>
            <small>Tippe oben auf „+ Neu" um dein erstes Gericht zu erstellen.</small>
        </div>`;
        return;
    }

    el.innerHTML = d.gerichte.map(g => {
        const kcalPortion = g.portionen > 0 ? Math.round(g.kcal_gesamt / g.portionen) : 0;
        return `
        <div class="swipe-entry four-actions" id="gericht-${g.id}">
            <div class="swipe-entry__content" style="padding:.7rem 1.25rem;cursor:pointer;"
                 data-gericht-id="${g.id}" data-gericht-name="${escHtml(g.name)}"
                 data-portionen="${g.portionen}" data-kcal-gesamt="${g.kcal_gesamt || 0}"
                 data-gewicht-gesamt="${g.gewicht_gesamt || 0}">
                <div style="flex:1;overflow:hidden;">
                    <div style="font-weight:700;font-size:.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        ${escHtml(g.name)}
                    </div>
                    <div style="font-size:.75rem;color:var(--muted);margin-top:.15rem;">
                        ${g.zutat_anzahl} Zutaten · ${Math.round(g.kcal_gesamt)} kcal gesamt · ${kcalPortion} kcal/Portion
                    </div>
                </div>
                <i class="bi bi-plus-circle" style="color:var(--accent);font-size:1.2rem;flex-shrink:0;"></i>
            </div>
            <div class="swipe-entry__actions">
                <button class="swipe-action-share"
                        data-gericht-id="${g.id}"
                        style="width:4.5rem;background:#60a5fa;border:none;color:#000;
                               display:flex;flex-direction:column;align-items:center;
                               justify-content:center;gap:.15rem;font-size:.68rem;">
                    <i class="bi bi-share-fill"></i>
                    <span>Teilen</span>
                </button>
                <button class="swipe-action-duplicate"
                        data-gericht-id="${g.id}"
                        style="width:4.5rem;background:#a78bfa;border:none;color:#000;
                               display:flex;flex-direction:column;align-items:center;
                               justify-content:center;gap:.15rem;font-size:.68rem;">
                    <i class="bi bi-copy"></i>
                    <span>Duplizieren</span>
                </button>
                <button class="swipe-action-edit"
                        data-gericht-id="${g.id}"
                        style="width:5rem;">
                    <i class="bi bi-pencil"></i>
                    <span>Bearbeiten</span>
                </button>
                <button class="swipe-action-delete"
                        data-gericht-id="${g.id}"
                        data-gericht-name="${escHtml(g.name)}"
                        style="width:5rem;">
                    <i class="bi bi-trash3"></i>
                    <span>Löschen</span>
                </button>
            </div>
        </div>`;
    }).join('');

    // Swipe initialisieren
    initGerichteSwipe();
}

// ── Gericht anlegen/bearbeiten ───────────────────────────────────────────────
let zutaten = [];
let editGerichtId = null;

function openGerichtModal(id = null) {
    editGerichtId = id;
    zutaten = [];
    document.getElementById('gName').value      = '';
    document.getElementById('gPortionen').value = '1';
    document.getElementById('gerichtModalTitle').textContent = id ? 'Gericht bearbeiten' : 'Neues Gericht';
    renderZutaten();
    document.getElementById('gerichtModal').style.display = 'flex';
    setTimeout(() => document.getElementById('gName').focus(), 150);
}

function closeGerichtModal() {
    document.getElementById('gerichtModal').style.display = 'none';
}

async function editGericht(id) {
    const r = await fetch('/api/gericht.php?id=' + id);
    const d = await r.json();
    if (!d.ok) return;
    openGerichtModal(id);
    document.getElementById('gName').value      = d.gericht.name;
    document.getElementById('gPortionen').value = d.gericht.portionen;
    zutaten = d.gericht.zutaten.map(z => ({
        produkt_id: z.produkt_id, name: z.name, menge_g: parseFloat(z.menge_g),
        kcal_100g: parseFloat(z.kcal_100g), eiweiss_100g: parseFloat(z.eiweiss_100g),
        fett_100g: parseFloat(z.fett_100g), kh_100g: parseFloat(z.kh_100g),
    }));
    renderZutaten();
}

function renderZutaten() {
    const el = document.getElementById('zutatenList');
    if (!zutaten.length) {
        el.innerHTML = '<div style="color:var(--muted);font-size:.82rem;text-align:center;padding:.5rem;">Noch keine Zutaten</div>';
        updateGerichtKcal(); return;
    }
    el.innerHTML = zutaten.map((z, i) => `
        <div style="display:flex;align-items:center;gap:.5rem;padding:.4rem 0;
                    border-bottom:1px solid var(--border);">
            <div style="flex:1;overflow:hidden;">
                <div style="font-size:.85rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(z.name)}</div>
                <div style="font-size:.72rem;color:var(--muted);">${z.menge_g}g · ${Math.round(z.kcal_100g * z.menge_g / 100)} kcal</div>
            </div>
            <input type="number" value="${z.menge_g}" min="1" step="1"
                   onchange="updateZutatMenge(${i}, this.value)"
                   style="width:4.5rem;background:var(--surface2);border:1px solid var(--border);
                          border-radius:8px;padding:.3rem .4rem;color:var(--text);font-size:.82rem;text-align:center;">
            <span style="color:var(--muted);font-size:.75rem;">g</span>
            <button onclick="removeZutat(${i})"
                    style="background:none;border:none;color:#f87171;font-size:.9rem;padding:.2rem .4rem;">
                <i class="bi bi-x-circle"></i>
            </button>
        </div>`).join('');
    updateGerichtKcal();
}

function updateZutatMenge(i, val) {
    zutaten[i].menge_g = parseFloat(val) || 0;
    updateGerichtKcal();
}

function removeZutat(i) {
    zutaten.splice(i, 1);
    renderZutaten();
}

function updateGerichtKcal() {
    const total   = zutaten.reduce((s, z) => s + z.kcal_100g * z.menge_g / 100, 0);
    const port    = parseFloat(document.getElementById('gPortionen').value) || 1;
    document.getElementById('gKcalTotal').textContent   = Math.round(total) + ' kcal';
    document.getElementById('gKcalPortion').textContent = Math.round(total / port) + ' kcal / Portion';
}
document.getElementById('gPortionen').addEventListener('input', updateGerichtKcal);

// ── Zutat hinzufügen: Suche, Barcode, OCR, manuell – alles über log.php ──
// Gericht-Entwurf zwischenspeichern, zu log.php im Pick-Modus wechseln
// (dort ohne Häufige-Gerichte-Bereich). Ergebnis kommt über sessionStorage
// zurück, s. restorePickFlow() unten.
function goScanZutat() {
    sessionStorage.setItem('kt_gericht_draft', JSON.stringify({
        name: document.getElementById('gName').value,
        portionen: document.getElementById('gPortionen').value,
        editGerichtId,
        zutaten,
    }));
    location.href = '/log.php?pick=1';
}

async function saveGericht() {
    const name     = document.getElementById('gName').value.trim();
    const portionen = parseFloat(document.getElementById('gPortionen').value) || 1;
    if (!name) { showToast('Bitte einen Namen eingeben'); return; }
    if (!zutaten.length) { showToast('Mindestens eine Zutat erforderlich'); return; }

    const method  = editGerichtId ? 'PUT' : 'POST';
    const payload = { name, portionen, zutaten };
    if (editGerichtId) payload.id = editGerichtId;

    const r = await fetch('/api/gericht.php', {
        method, headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload),
    });
    const d = await r.json();
    if (d.ok) {
        showToast(editGerichtId ? '✓ Gericht aktualisiert' : '✓ Gericht gespeichert');
        document.getElementById('gerichtModal').style.display = 'none';
        editGerichtId = null;
        zutaten = [];
        await loadGerichte();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } else {
        showToast('Fehler: ' + (d.error || 'Unbekannt'));
    }
}

async function deleteGericht(id, name) {
    if (!confirm(`„${name}" löschen?`)) return;
    const r = await fetch('/api/gericht.php', {
        method: 'DELETE', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ id }),
    });
    const d = await r.json();
    if (d.ok) {
        showToast('Gericht gelöscht');
        await loadGerichte();
    } else {
        showToast('Fehler beim Löschen');
    }
}

async function duplicateGericht(id) {
    const r = await fetch('/api/gericht.php?id=' + id);
    const d = await r.json();
    if (!d.ok) { showToast('Gericht nicht ladbar'); return; }
    const g = d.gericht;
    const rc = await fetch('/api/gericht.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({
            name: g.name + ' (Kopie)',
            portionen: parseFloat(g.portionen) || 1,
            zutaten: (g.zutaten || []).map(z => ({
                produkt_id:   z.produkt_id || null,
                name:         z.name,
                menge_g:      parseFloat(z.menge_g)      || 0,
                kcal_100g:    parseFloat(z.kcal_100g)    || 0,
                eiweiss_100g: parseFloat(z.eiweiss_100g) || 0,
                fett_100g:    parseFloat(z.fett_100g)    || 0,
                kh_100g:      parseFloat(z.kh_100g)      || 0,
            })),
        }),
    });
    const dc = await rc.json();
    if (dc.ok) {
        showToast('✓ „' + g.name + ' (Kopie)" angelegt');
        await loadGerichte();
    } else {
        showToast('Fehler: ' + (dc.error || 'Unbekannt'));
    }
}

// ── Gericht eintragen (angeglichen an log.php) ───────────────────────────────
let _currentGericht = null;
let _gerichtModus = 'portion';

function openGerichtEintragen(id, name, portionen, kcalGesamt, gewichtGesamt) {
    _currentGericht = { id, portionen, kcalGesamt, gewichtGesamt: parseFloat(gewichtGesamt) || 0 };
    const kcalPro = portionen > 0 ? Math.round(kcalGesamt / portionen) : 0;
    document.getElementById('gerichtEintragenName').textContent = name;
    document.getElementById('gerichtEintragenInfo').textContent =
        'Gericht · ' + portionen + ' Portion' + (portionen !== 1 ? 'en' : '')
        + (_currentGericht.gewichtGesamt > 0 ? ' · ' + Math.round(_currentGericht.gewichtGesamt) + ' g gesamt' : '');
    document.getElementById('geKcalPortion').textContent = kcalPro;
    document.getElementById('geKcalGesamt').textContent  = Math.round(kcalGesamt);
    document.getElementById('gePortionen').textContent   = portionen;
    // Gramm-Modus nur anbieten wenn Gesamtgewicht bekannt
    document.getElementById('geModeGramm').style.display =
        _currentGericht.gewichtGesamt > 0 ? 'block' : 'none';
    setGerichtModus('portion');
    document.getElementById('gerichtEintragenModal').classList.add('visible');
}

function closeGerichtEintragenModal() {
    document.getElementById('gerichtEintragenModal').classList.remove('visible');
    _currentGericht = null;
}

function setGerichtModus(modus) {
    _gerichtModus = modus;
    const inp     = document.getElementById('gerichtEintragenPortionen');
    const einheit = document.getElementById('gerichtEintragenEinheit');
    const bP = document.getElementById('geModePortion');
    const bG = document.getElementById('geModeGramm');
    const gramOk = _currentGericht && _currentGericht.gewichtGesamt > 0;

    bP.classList.toggle('active', modus === 'portion');
    bG.classList.toggle('active', modus === 'gramm');
    bG.style.display = gramOk ? '' : 'none';

    document.getElementById('geQuickChipsPortion').style.display = modus === 'portion' ? 'flex' : 'none';
    document.getElementById('geQuickChipsGramm').style.display   = modus === 'gramm'   ? 'flex' : 'none';

    if (modus === 'gramm') {
        einheit.textContent = 'g';
        inp.value = 100; inp.min = 1; inp.step = 1;
    } else {
        einheit.textContent = 'Portion(en)';
        inp.value = 1; inp.min = 0.5; inp.step = 0.5;
    }
    syncGeChipsActive();
    updateGerichtPreview();
}

function updateGerichtPreview() {
    if (!_currentGericht) return;
    const val = parseFloat(document.getElementById('gerichtEintragenPortionen').value) || 0;
    let kcal = 0;
    if (_gerichtModus === 'gramm' && _currentGericht.gewichtGesamt > 0) {
        kcal = _currentGericht.kcalGesamt * val / _currentGericht.gewichtGesamt;
    } else {
        const kcalPro = _currentGericht.portionen > 0
            ? _currentGericht.kcalGesamt / _currentGericht.portionen : 0;
        kcal = kcalPro * val;
    }
    document.getElementById('geKcalPreview').textContent = Math.round(kcal);
}

document.getElementById('gerichtEintragenPortionen').addEventListener('input', () => {
    updateGerichtPreview();
    syncGeChipsActive();
});

// ── Stepper (+/-) ──────────────────────────────────────────────────────────
function geStep(delta) {
    const inp  = document.getElementById('gerichtEintragenPortionen');
    const cur  = parseFloat(inp.value) || 0;
    const step = _gerichtModus === 'gramm' ? (cur >= 200 ? 25 : 10) : 0.5;
    const min  = _gerichtModus === 'gramm' ? 1 : 0.5;
    inp.value  = Math.max(min, cur + delta * step);
    inp.dispatchEvent(new Event('input'));
}
document.getElementById('btnGeMinus').addEventListener('click', () => geStep(-1));
document.getElementById('btnGePlus').addEventListener('click', () => geStep(1));

// ── Schnellwahl-Chips (Portionen und Gramm getrennt) ───────────────────────
function syncGeChipsActive() {
    const val = parseFloat(document.getElementById('gerichtEintragenPortionen').value);
    const wrap = _gerichtModus === 'gramm' ? 'geQuickChipsGramm' : 'geQuickChipsPortion';
    document.querySelectorAll('#' + wrap + ' .pf-chip').forEach(chip => {
        chip.classList.toggle('active', parseFloat(chip.dataset.val) === val);
    });
}
document.querySelectorAll('#geQuickChipsPortion .pf-chip, #geQuickChipsGramm .pf-chip').forEach(chip => {
    chip.addEventListener('click', () => {
        document.getElementById('gerichtEintragenPortionen').value = chip.dataset.val;
        document.getElementById('gerichtEintragenPortionen').dispatchEvent(new Event('input'));
    });
});

document.getElementById('btnGerichtVerwerfen').addEventListener('click', closeGerichtEintragenModal);
document.getElementById('gerichtEintragenModal').addEventListener('click', function(e) {
    if (e.target === this) closeGerichtEintragenModal();
});

document.getElementById('btnGerichtEintragen').addEventListener('click', async () => {
    if (!_currentGericht) return;
    const val   = parseFloat(document.getElementById('gerichtEintragenPortionen').value) || 1;
    const datum = new Date().toISOString().slice(0, 10);
    const payload = { gericht_id: _currentGericht.id, datum };
    if (_gerichtModus === 'gramm') payload.menge_g = val;
    else                           payload.portionen = val;
    const r = await fetch('/api/gericht.php?action=eintragen', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    const d = await r.json();
    closeGerichtEintragenModal();
    if (d.ok) {
        showToast(`Gericht eingetragen (${d.eingetragen} Zutaten)`);
        setTimeout(() => { location.href = '/'; }, 800);
    } else showToast('Fehler: ' + (d.error || 'Unbekannt'));
});

// ── Gericht teilen (Export als JSON) ──────────────────────────────────────
async function shareGericht(id) {
    try {
        const r = await fetch('/api/gericht.php?id=' + id);
        const d = await r.json();
        if (!d.ok) { showToast('Gericht nicht ladbar'); return; }
        const g = d.gericht;

        const exportObj = {
            typ: 'kt-gericht',
            version: 1,
            name: g.name,
            portionen: parseFloat(g.portionen) || 1,
            zutaten: (g.zutaten || []).map(z => ({
                name:         z.name,
                menge_g:      parseFloat(z.menge_g)      || 0,
                kcal_100g:    parseFloat(z.kcal_100g)    || 0,
                eiweiss_100g: parseFloat(z.eiweiss_100g) || 0,
                fett_100g:    parseFloat(z.fett_100g)    || 0,
                kh_100g:      parseFloat(z.kh_100g)      || 0,
            })),
        };
        const json = JSON.stringify(exportObj, null, 2);

        // iOS Share-Sheet, Fallback: Zwischenablage
        if (navigator.share) {
            await navigator.share({ title: 'Gericht: ' + g.name, text: json });
        } else {
            await navigator.clipboard.writeText(json);
            showToast('✓ In Zwischenablage kopiert');
        }
    } catch(e) {
        if (e.name !== 'AbortError') showToast('Teilen fehlgeschlagen');
    }
}

// ── Gericht importieren ────────────────────────────────────────────────────
function openImportModal() {
    document.getElementById('importInput').value = '';
    document.getElementById('importPreview').style.display = 'none';
    document.getElementById('importModal').style.display = 'block';
    setTimeout(() => document.getElementById('importInput').focus(), 100);
}
function closeImportModal() {
    document.getElementById('importModal').style.display = 'none';
}
document.getElementById('importModal').addEventListener('click', function(e) {
    if (e.target === this) closeImportModal();
});

function validateImport(text) {
    // iOS "Intelligente Interpunktion" ersetzt gerade Anführungszeichen
    // durch typografische (\u201C \u201D \u201E) – vor dem Parsen normalisieren.
    text = text
        .replace(/[\u201C\u201D\u201E\u00AB\u00BB]/g, '"')
        .replace(/[\u2018\u2019\u201A]/g, "'");
    let obj;
    try { obj = JSON.parse(text); }
    catch { throw new Error('Kein gültiges JSON'); }
    if (obj.typ !== 'kt-gericht') throw new Error('Kein KalorienTracker-Gericht (typ fehlt)');
    if (!obj.name || typeof obj.name !== 'string') throw new Error('Name fehlt');
    if (!Array.isArray(obj.zutaten) || obj.zutaten.length === 0) throw new Error('Keine Zutaten');
    for (const z of obj.zutaten) {
        if (!z.name) throw new Error('Zutat ohne Namen');
        if (!(parseFloat(z.menge_g) > 0)) throw new Error('Zutat „' + z.name + '": Menge fehlt');
    }
    return obj;
}

// Live-Vorschau beim Einfügen
document.getElementById('importInput').addEventListener('input', () => {
    const prev = document.getElementById('importPreview');
    const text = document.getElementById('importInput').value.trim();
    if (!text) { prev.style.display = 'none'; return; }
    try {
        const obj = validateImport(text);
        const kcal = obj.zutaten.reduce((s, z) =>
            s + (parseFloat(z.kcal_100g) || 0) * (parseFloat(z.menge_g) || 0) / 100, 0);
        prev.innerHTML = '<i class="bi bi-check-circle-fill" style="color:var(--accent);"></i> '
            + '<strong>' + escHtml(obj.name) + '</strong> – '
            + obj.zutaten.length + ' Zutaten, ' + Math.round(kcal) + ' kcal gesamt, '
            + (obj.portionen || 1) + ' Portion(en)';
        prev.style.color = 'var(--text)';
        prev.style.display = 'block';
    } catch(e) {
        prev.innerHTML = '<i class="bi bi-x-circle-fill" style="color:var(--danger);"></i> ' + escHtml(e.message);
        prev.style.color = 'var(--muted)';
        prev.style.display = 'block';
    }
});


// ── Syntax-Hilfe im Import-Modal ──────────────────────────────────────────
function toggleImportSyntax() {
    const box = document.getElementById('importSyntaxBox');
    const chev = document.getElementById('importSyntaxChevron');
    const open = box.style.display === 'block';
    box.style.display = open ? 'none' : 'block';
    chev.style.transform = open ? '' : 'rotate(180deg)';
}

async function copyImportPrompt() {
    const prompt = `Erstelle mir aus meinem Rezept ein Gericht im folgenden JSON-Format fuer meinen KalorienTracker. Recherchiere realistische Naehrwerte pro 100g fuer jede Zutat:

{
  "typ": "kt-gericht",
  "version": 1,
  "name": "Name des Gerichts",
  "portionen": 4,
  "zutaten": [
    {"name": "Zutat", "menge_g": 400, "kcal_100g": 358, "eiweiss_100g": 13, "fett_100g": 1.6, "kh_100g": 72}
  ]
}

Regeln:
- "typ" muss exakt "kt-gericht" sein
- "menge_g" = im Rezept verwendete Menge in Gramm (Stueck/EL/ml in Gramm umrechnen)
- kcal_100g, eiweiss_100g, fett_100g, kh_100g = Naehrwerte pro 100 g der Zutat
- "portionen" = Anzahl Portionen des Rezepts
- Antworte NUR mit dem JSON, ohne weiteren Text

Mein Rezept:
`;
    try {
        await navigator.clipboard.writeText(prompt);
        showToast('✓ KI-Vorlage kopiert');
    } catch(e) {
        showToast('Kopieren fehlgeschlagen');
    }
}

async function importGericht() {
    const text = document.getElementById('importInput').value.trim();
    let obj;
    try { obj = validateImport(text); }
    catch(e) { showToast(e.message); return; }

    const btn = document.getElementById('btnImport');
    btn.disabled = true;
    try {
        const r = await fetch('/api/gericht.php', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                name: obj.name,
                portionen: parseFloat(obj.portionen) || 1,
                zutaten: obj.zutaten.map(z => ({
                    produkt_id:   null,
                    name:         String(z.name).slice(0, 255),
                    menge_g:      parseFloat(z.menge_g)      || 0,
                    kcal_100g:    parseFloat(z.kcal_100g)    || 0,
                    eiweiss_100g: parseFloat(z.eiweiss_100g) || 0,
                    fett_100g:    parseFloat(z.fett_100g)    || 0,
                    kh_100g:      parseFloat(z.kh_100g)      || 0,
                })),
            }),
        });
        const d = await r.json();
        if (d.ok) {
            showToast('✓ „' + obj.name + '" importiert');
            closeImportModal();
            await loadGerichte();
            initGerichteSwipe();
        } else showToast('Fehler: ' + (d.error || 'Unbekannt'));
    } catch(e) { showToast('Verbindungsfehler'); }
    finally { btn.disabled = false; }
}

function initGerichteSwipe() {
    function closeAllGerichteSwipes(except) {
        document.querySelectorAll('#gerichteList .swipe-entry.open').forEach(el => {
            if (el !== except) el.classList.remove('open');
        });
    }

    document.querySelectorAll('#gerichteList .swipe-entry').forEach(item => {
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
                if (dx < -THRESHOLD) { closeAllGerichteSwipes(item); item.classList.add('open'); }
                else if (dx > THRESHOLD) { item.classList.remove('open'); }
            }
            wasSwiping = false;
        }, { passive: true });

        const contentEl = item.querySelector('.swipe-entry__content');
        if (contentEl) {
            const onTapContent = e => {
                e.stopPropagation();
                if (wasSwiping) return; // war Wischgeste, kein Tap
                if (item.classList.contains('open')) { item.classList.remove('open'); return; }
                openGerichtEintragen(
                    parseInt(contentEl.dataset.gerichtId),
                    contentEl.dataset.gerichtName,
                    parseFloat(contentEl.dataset.portionen),
                    parseFloat(contentEl.dataset.kcalGesamt),
                    parseFloat(contentEl.dataset.gewichtGesamt)
                );
            };
            contentEl.addEventListener('click', onTapContent);
        }

        const editBtn  = item.querySelector('.swipe-action-edit');
        const delBtn   = item.querySelector('.swipe-action-delete');
        const shareBtn = item.querySelector('.swipe-action-share');
        const dupBtn   = item.querySelector('.swipe-action-duplicate');

        if (shareBtn) {
            shareBtn.addEventListener('click', e => { e.stopPropagation(); shareGericht(parseInt(shareBtn.dataset.gerichtId)); });
            shareBtn.addEventListener('touchend', e => { e.preventDefault(); e.stopPropagation(); shareGericht(parseInt(shareBtn.dataset.gerichtId)); }, { passive: false });
        }
        if (dupBtn) {
            dupBtn.addEventListener('click', e => { e.stopPropagation(); duplicateGericht(parseInt(dupBtn.dataset.gerichtId)); });
            dupBtn.addEventListener('touchend', e => { e.preventDefault(); e.stopPropagation(); duplicateGericht(parseInt(dupBtn.dataset.gerichtId)); }, { passive: false });
        }
        if (editBtn) {
            editBtn.addEventListener('click', e => { e.stopPropagation(); editGericht(parseInt(editBtn.dataset.gerichtId)); });
            editBtn.addEventListener('touchend', e => { e.preventDefault(); e.stopPropagation(); editGericht(parseInt(editBtn.dataset.gerichtId)); }, { passive: false });
        }
        if (delBtn) {
            delBtn.addEventListener('click', e => { e.stopPropagation(); deleteGericht(parseInt(delBtn.dataset.gerichtId), delBtn.dataset.gerichtName); });
            delBtn.addEventListener('touchend', e => { e.preventDefault(); e.stopPropagation(); deleteGericht(parseInt(delBtn.dataset.gerichtId), delBtn.dataset.gerichtName); }, { passive: false });
        }
    });

    if (!document._gerichteSwipeGlobal) {
        document._gerichteSwipeGlobal = true;
        document.addEventListener('touchstart', e => {
            if (!e.target.closest('#gerichteList .swipe-entry')) closeAllGerichteSwipes();
        }, { passive: true });
    }
}

function restorePickFlow() {
    const params = new URLSearchParams(location.search);
    if (params.get('pickedZutat') !== '1') return;

    const draftRaw = sessionStorage.getItem('kt_gericht_draft');
    if (draftRaw) {
        try {
            const draft = JSON.parse(draftRaw);
            openGerichtModal(draft.editGerichtId || null);
            document.getElementById('gName').value      = draft.name || '';
            document.getElementById('gPortionen').value = draft.portionen || 1;
            zutaten       = draft.zutaten || [];
            editGerichtId = draft.editGerichtId || null;
            document.getElementById('gerichtModalTitle').textContent =
                editGerichtId ? 'Gericht bearbeiten' : 'Neues Gericht';
        } catch(e) {}
        sessionStorage.removeItem('kt_gericht_draft');
    }

    const pickedRaw = sessionStorage.getItem('kt_pick_result');
    if (pickedRaw) {
        try {
            const z = JSON.parse(pickedRaw);
            zutaten.push(z);
            showToast('✓ „' + z.name + '" hinzugefügt');
        } catch(e) {}
        sessionStorage.removeItem('kt_pick_result');
    }

    renderZutaten();
    history.replaceState({}, '', '/gerichte.php');
}

loadGerichte();
restorePickFlow();
</script>

<?php renderFooter('log'); ?>
