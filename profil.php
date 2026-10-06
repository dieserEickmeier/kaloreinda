<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/bmr.php';
require_once __DIR__ . '/includes/ui.php';
$currentUser = requireLogin();
$userId      = $currentUser['id'];
$db          = db();

// ─── Daten laden ──────────────────────────────────────────────────────────────
$stmtPr = $db->prepare("SELECT * FROM profil WHERE user_id = ? LIMIT 1");
$stmtPr->bind_param("i", $userId); $stmtPr->execute();
$profil = $stmtPr->get_result()->fetch_assoc();

$stmtGw = $db->prepare("SELECT kg, datum FROM gewicht WHERE user_id = ? ORDER BY datum DESC, id DESC LIMIT 1");
$stmtGw->bind_param("i", $userId); $stmtGw->execute();
$gewicht = $stmtGw->get_result()->fetch_assoc();

$stmtGh = $db->prepare("SELECT datum, kg FROM gewicht WHERE user_id = ? ORDER BY datum DESC");
$stmtGh->bind_param("i", $userId); $stmtGh->execute();
$gewichtHistory = $stmtGh->get_result()->fetch_all(MYSQLI_ASSOC);

$kg       = $gewicht ? (float)$gewicht['kg'] : null;
$tdee     = berechneTdee($profil, $kg);
$defizit  = (int)($profil['defizit_kcal'] ?? 500);
$zielKcal = $tdee ? max(1200, $tdee - $defizit) : null;

renderHeader('Profil', 'profil');

if ($kg && !empty($profil['groesse_cm'])) {
    $bmi = $kg / (($profil['groesse_cm'] / 100) ** 2);
    // WHO-Klassifikation
    if     ($bmi < 18.5) { $bmiLabel = 'Untergewicht';        $bmiColor = 'var(--prot)'; }
    elseif ($bmi < 25)   { $bmiLabel = 'Normalgewicht';       $bmiColor = 'var(--accent)'; }
    elseif ($bmi < 30)   { $bmiLabel = 'Übergewicht';         $bmiColor = 'var(--quick)'; }
    elseif ($bmi < 35)   { $bmiLabel = 'Adipositas Grad I';   $bmiColor = 'var(--fat)'; }
    elseif ($bmi < 40)   { $bmiLabel = 'Adipositas Grad II';  $bmiColor = 'var(--danger)'; }
    else                 { $bmiLabel = 'Adipositas Grad III'; $bmiColor = 'var(--danger)'; }
}
?>

<?php renderPageHeader('Profil',
    '<a href="/einstellungen.php" class="icon-btn" aria-label="Einstellungen"><i class="bi bi-gear"></i></a>',
    $currentUser['display_name'] ?? ''); ?>

<!-- ── Berechnete Werte ───────────────────────────────────────── -->
<?php if ($tdee): ?>
<section class="bento" style="margin-top:.35rem;">
    <div class="tile tile--wide">
        <div class="kt-info-card-wrap"><button class="kt-info-btn" type="button" aria-label="Info zur Berechnung"><i class="bi bi-info-circle"></i></button><div class="kt-tooltip">Berechnet per <strong>Mifflin-St.-Jeor-Formel</strong> aus Gewicht, Größe, Alter und Geschlecht.<br><br>
<strong>Grundumsatz (BMR)</strong> – Energieverbrauch in völliger Ruhe, ohne jede Bewegung.<br><br>
<strong>Gesamtumsatz (TDEE)</strong> – BMR × Aktivitätsfaktor. Sitzend = 1,2 · Sehr aktiv = bis 1,9. Je ehrlicher die Einschätzung, desto genauer das Ziel.<br><br>
<strong>Kalorienziel</strong> – TDEE minus Defizit. 300–500 kcal Defizit = nachhaltiges Abnehmen ohne Muskelverlust. Über 700 kcal ist langfristig kontraproduktiv.<br><br>
<strong>BMI</strong> (Körpergewicht ÷ Größe²) nach WHO: unter 18,5 = Untergewicht · 18,5–24,9 = Normalgewicht · 25–29,9 = Übergewicht · 30–34,9 = Adipositas I · 35–39,9 = Adipositas II · ab 40 = Adipositas III. Der BMI berücksichtigt keine Muskelmasse – bei sportlichen Menschen nur bedingt aussagekräftig.<br><br>
<strong>Tipp:</strong> Gewicht regelmäßig eintragen – besonders bei größeren Veränderungen verbessert das die Genauigkeit deutlich.</div></div>
        <div class="tile__label"><span>Kalorienziel heute</span></div>
        <span class="tile__val" style="font-size:2.4rem;color:var(--accent);"><?= fmtZahl($zielKcal) ?><small> kcal</small></span>
        <div class="tile__sub">TDEE − Defizit, plus Aktivität des Tages</div>
    </div>
    <div class="tile">
        <div class="tile__label"><span>Gesamtumsatz</span><span>TDEE</span></div>
        <span class="tile__val"><?= fmtZahl($tdee) ?><small> kcal</small></span>
    </div>
    <div class="tile">
        <div class="tile__label"><span>Defizit</span></div>
        <span class="tile__val" style="color:var(--quick);">−<?= $defizit ?><small> kcal</small></span>
    </div>
    <?php if (isset($bmi)): ?>
    <div class="tile tile--wide" style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;">
        <div>
            <div class="tile__label"><span>BMI</span></div>
            <span class="tile__val" style="color:<?= $bmiColor ?>;"><?= number_format($bmi, 1, ',', '') ?></span>
        </div>
        <span class="hx-chip" style="color:<?= $bmiColor ?>;font-size:.8rem;"><?= $bmiLabel ?></span>
    </div>
    <?php endif; ?>
</section>
<?php else: ?>
<div class="kt-card text-center text-muted" style="margin-top:.5rem;">
    <i class="bi bi-calculator" style="font-size:1.8rem;display:block;margin-bottom:.5rem;"></i>
    <div style="font-size:.9rem;">
        Trage dein Gewicht ein um deinen Grundumsatz und dein Kalorienziel zu berechnen.
    </div>
</div>
<?php endif; ?>

<!-- ── Gewicht: Erfassung + Verlauf (eine Card) ─────────────── -->
<div class="section-label"><span>Gewicht</span>
    <button type="button" class="chip-btn" onclick="toggleGewichtHistorie()"><i class="bi bi-clock-history"></i> Verlauf</button>
</div>
<div class="kt-card">
    <div class="kt-info-card-wrap"><button class="kt-info-btn" type="button" aria-label="Info zum Gewicht"><i class="bi bi-info-circle"></i></button><div class="kt-tooltip">Trage dein Gewicht täglich ein – am besten morgens nüchtern für vergleichbare Werte.<br><br>
<strong>Graue gestrichelte Linie</strong> – dein eingetragenes Gewicht. Schwankungen von 1–2 kg täglich sind normal (Wasser, Verdauung).<br><br>
<strong>Limettengrüne Linie</strong> – EWMA-Trend: filtert Schwankungen heraus und zeigt die echte Richtung. Neuere Messungen werden stärker gewichtet.<br><br>
<strong>Trend/Woche</strong> – wöchentliche Veränderung des geglätteten Gewichts. Realistisches Abnahmetempo: 0,3–0,7 kg/Woche.<br><br>
<strong>Verlauf</strong> – zeigt deine letzten 7 Einträge. Zum Löschen nach links wischen.</div></div>

    <!-- Eingabe -->
    <div class="input-row">
        <div class="big-input">
            <input type="number" id="gewichtInput" step="0.1" min="20" max="300" inputmode="decimal"
                   value="<?= $kg ? number_format($kg, 1, '.', '') : '' ?>"
                   placeholder="82,5" aria-label="Gewicht in kg">
            <span>kg</span>
        </div>
        <button id="btnSaveGewicht" class="icon-btn icon-btn--accent" onclick="saveGewicht()" aria-label="Gewicht speichern">
            <i class="bi bi-plus-lg" id="gewichtIcon"></i>
        </button>
    </div>
    <?php if ($kg && !empty($gewicht['datum'])): ?>
    <div class="tile__sub" style="margin-top:.4rem;">
        Zuletzt: <?= number_format((float)$gewicht['kg'], 1, ',', '') ?> kg
        am <?= date('d.m.Y', strtotime($gewicht['datum'])) ?>
    </div>
    <?php endif; ?>

    <!-- 7-Tage-Historie (eingeklappt) -->
    <div id="gewichtHistorieWrap" style="display:none;margin-top:.9rem;">
        <div class="tile__label" style="margin-bottom:.45rem;">Letzte 7 Einträge</div>
        <div id="gewichtHistorieList" class="mini-list"></div>
    </div>

    <?php if (count($gewichtHistory) > 1): ?>
    <div class="seg" style="margin-top:1rem;" id="periodSeg">
        <?php foreach (['1M'=>'1 M','3M'=>'3 M','6M'=>'6 M','all'=>'Alle'] as $key => $lbl): ?>
        <button type="button" onclick="setPeriod('<?= $key ?>')" id="btn-<?= $key ?>"><?= $lbl ?></button>
        <?php endforeach; ?>
    </div>
    <div class="tile__sub" id="chartTitle" style="margin:.6rem 0 .3rem;">
        Verlauf (<?= count($gewichtHistory) ?> Einträge)
    </div>
    <canvas id="weightChart" height="150"></canvas>
    <div id="chartStats" class="mini-stats"></div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Alle Messungen (älteste zuerst) inkl. Trend – der Trend läuft über die
// gesamte Historie (gewichtsTrend() in includes/ui.php), damit „Trend aktuell“
// unabhängig vom gewählten Zeitraum ist und zur Kachel auf „Heute“ passt.
const allData = <?= json_encode(gewichtsTrend($db, $userId)) ?>;

let chart = null;
let activePeriod = '1M';
const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
const C   = { accent: css('--accent'), danger: css('--danger'), muted: css('--muted'), faint: css('--faint'), border: css('--border'), text: css('--text') };

// Zeitraum in Tagen (1M = 30 Tage wie auf „Heute“). Vergleich als
// YYYY-MM-DD-Text – new Date('YYYY-MM-DD') wäre UTC-Mitternacht und hat in
// der Ortszeit den ersten Tag des Zeitraums verschluckt.
function filterByPeriod(period) {
    if (period === 'all') return allData;
    const days = { '1M': 30, '3M': 90, '6M': 180 }[period];
    const c = new Date(); c.setDate(c.getDate() - days);
    const cutoff = c.getFullYear() + '-' + String(c.getMonth() + 1).padStart(2, '0') + '-' + String(c.getDate()).padStart(2, '0');
    return allData.filter(d => d.datum >= cutoff);
}

function setPeriod(period) {
    activePeriod = period;

    // Segment-Umschalter
    ['1M','3M','6M','all'].forEach(p => {
        document.getElementById('btn-' + p).classList.toggle('active', p === period);
    });

    const data = filterByPeriod(period);
    if (data.length < 2) {
        document.getElementById('chartTitle').textContent =
            'Zu wenig Daten für diesen Zeitraum';
        if (chart) { chart.data.labels = []; chart.data.datasets[0].data = [];
                     chart.data.datasets[1].data = []; chart.update(); }
        document.getElementById('chartStats').innerHTML = '';
        return;
    }

    const labels  = data.map(d => {
        const [y,m,day] = d.datum.split('-');
        return day + '.' + m + (period === 'all' ? '.' + y.slice(2) : '');
    });
    const weights = data.map(d => d.kg);
    const minW = Math.min(...weights), maxW = Math.max(...weights);
    const yPad = Math.max(0.5, (maxW - minW) * 0.2);

    // EWMA-Trend kommt fertig aus PHP (über die gesamte Historie berechnet)
    const trendData = data.map(d => d.trend);

    // Trend pro Woche: Steigung des EWMA über den Zeitraum
    const daySpan = (new Date(data[data.length-1].datum) - new Date(data[0].datum))
                    / (1000*60*60*24) || 1;
    const ewmaSlope    = (trendData[trendData.length-1] - trendData[0]) / daySpan;
    const slopePerWeek = ewmaSlope * 7;
    const trendStr  = (slopePerWeek >= 0 ? '+' : '') + slopePerWeek.toFixed(2);
    const trendColor = slopePerWeek < -0.05 ? 'var(--accent)'
                     : slopePerWeek >  0.05 ? 'var(--danger)'
                     : 'var(--muted)';
    const trendIcon  = slopePerWeek < -0.05 ? '↓' : slopePerWeek > 0.05 ? '↑' : '→';

    document.getElementById('chartTitle').textContent =
        `Verlauf (${data.length} Einträge)`;

    // Statistik inkl. Trend
    const first = data[0].kg, last = data[data.length-1].kg;
    const diff  = last - first;
    const diffStr = (diff >= 0 ? '+' : '') + diff.toFixed(1);
    const diffColor = diff < 0 ? 'var(--accent)' : diff > 0 ? 'var(--danger)' : 'var(--muted)';
    const currentTrend = trendData[trendData.length - 1];
    const de = v => v.replace('.', ',');
    document.getElementById('chartStats').innerHTML = `
        <div><b>${de(first.toFixed(1))}</b><small>Start</small></div>
        <div><b style="color:${diffColor};">${de(diffStr)}</b><small>Veränderung</small></div>
        <div><b>${de(currentTrend.toFixed(1))}</b><small>Trend aktuell</small></div>
        <div><b style="color:${trendColor};">${trendIcon} ${de(trendStr)}</b><small>kg / Woche</small></div>
    `;

    const paddedLabels  = ['', ...labels,  ''];
    const paddedWeights = [null, ...weights, null];
    const paddedTrend   = [null, ...trendData, null];

    if (chart) {
        chart.data.labels = paddedLabels;
        chart.data.datasets[0].data = paddedWeights;
        chart.data.datasets[1].data = paddedTrend;
        chart.options.scales.y.min = minW - yPad;
        chart.options.scales.y.max = maxW + yPad;
        chart.update('active');
    } else {
        chart = new Chart(document.getElementById('weightChart'), {
            type: 'line',
            data: {
                labels: paddedLabels,
                datasets: [
                    {
                        label: 'Gewicht',
                        data: paddedWeights,
                        borderColor: C.faint,
                        backgroundColor: 'transparent',
                        borderWidth: 1.5,
                        borderDash: [5, 4],
                        pointRadius: 0,
                        fill: false,
                        tension: 0,
                        spanGaps: false
                    },
                    {
                        label: 'Trend',
                        data: paddedTrend,
                        borderColor: C.accent, backgroundColor: css('--accent-soft'),
                        borderWidth: 2.2, pointRadius: 0, pointHoverRadius: 0, pointBackgroundColor: C.accent,
                        fill: true, tension: 0.35, spanGaps: false
                    }
                ]
            },
            options: {
                responsive: true,
                animation: { duration: 300 },
                elements: { point: { radius: 0, hoverRadius: 0 } },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => ctx.datasetIndex === 0
                                ? `${ctx.parsed.y?.toFixed(1)} kg`
                                : `Trend: ${ctx.parsed.y?.toFixed(2)} kg`
                        }
                    }
                },
                scales: {
                    x: { ticks: { color: C.muted, font:{size:10, family: css('--font')}, maxTicksLimit: 6, maxRotation: 0 },
                         grid: { display: false }, border: { display: false }, offset: true },
                    y: { ticks: { color: C.muted, font:{size:10, family: css('--font')}, maxTicksLimit: 5 },
                         grid: { color: C.border }, border: { display: false },
                         min: minW - yPad, max: maxW + yPad }
                }
            }
        });
    }
}

// Standard: 1M, oder 'all' wenn weniger als 2 Monate Daten
const oldest = new Date(allData[0]?.datum);
const monthsOfData = (new Date() - oldest) / (1000*60*60*24*30);
// Chart (und Zeitraum-Buttons) existiert nur ab 2 Messungen
if (allData.length > 1) setPeriod(monthsOfData < 1.5 ? 'all' : '1M');
</script>

<script>
// ── saveGewicht ───────────────────────────────────────────────────────────
async function saveGewicht() {
    const val  = parseFloat(document.getElementById('gewichtInput').value.replace(',', '.'));
    const btn  = document.getElementById('btnSaveGewicht');
    const icon = document.getElementById('gewichtIcon');
    if (!val || val < 20 || val > 300) {
        icon.className = 'bi bi-x-lg'; btn.style.background = 'var(--danger)';
        setTimeout(() => { icon.className = 'bi bi-plus-lg'; btn.style.background = ''; }, 1500);
        return;
    }
    btn.disabled = true;
    try {
        const r = await fetch('/api/weight.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ kg: val, datum: new Date().toISOString().split('T')[0] }),
        });
        const d = await r.json();
        if (d.ok) {
            icon.className = 'bi bi-check2-all';
            setTimeout(() => location.reload(), 400);
        } else { throw new Error(d.error); }
    } catch(e) {
        icon.className = 'bi bi-x-lg'; btn.style.background = 'var(--danger)';
        setTimeout(() => { icon.className = 'bi bi-plus-lg'; btn.style.background = ''; }, 1500);
    } finally { btn.disabled = false; }
}
document.getElementById('gewichtInput').addEventListener('keyup', e => {
    if (e.key === 'Enter') saveGewicht();
});

// ── Gewicht-Historie (7 Tage) ─────────────────────────────────────────────
let _historieVisible = false;

function toggleGewichtHistorie() {
    _historieVisible = !_historieVisible;
    const wrap = document.getElementById('gewichtHistorieWrap');
    wrap.style.display = _historieVisible ? 'block' : 'none';
    if (_historieVisible) {
        loadGewichtHistorie();
        setTimeout(() => {
            wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 150);
    }
}

async function loadGewichtHistorie() {
    const list = document.getElementById('gewichtHistorieList');
    list.innerHTML = '<div class="tile__sub">Lädt…</div>';
    try {
        const r = await fetch('/api/weight.php?limit=7');
        const d = await r.json();
        if (!d.ok || !d.eintraege.length) {
            list.innerHTML = '<div class="tile__sub">Keine Einträge</div>';
            return;
        }
        list.innerHTML = '';
        d.eintraege.forEach(e => {
            const datum   = new Date(e.datum + 'T12:00:00');
            const dateStr = datum.toLocaleDateString('de-DE', {weekday:'short', day:'2-digit', month:'2-digit'});
            const item    = document.createElement('div');
            item.dataset.id = e.id;
            item.className = 'mini-swipe';
            item.innerHTML = `
                <button type="button" class="gw-del mini-swipe__del" aria-label="Löschen"><i class="bi bi-trash3"></i></button>
                <div class="gw-row mini-swipe__row">
                    <span class="mini-swipe__date">${dateStr}</span>
                    <b>${parseFloat(e.kg).toFixed(1).replace('.', ',')} kg</b>
                </div>`;
            item.querySelector('.gw-del').addEventListener('click', () => deleteGewicht(e.id, item));
            list.appendChild(item);
            initGewichtSwipe(item);
        });
    } catch(err) {
        list.innerHTML = '<div class="tile__sub text-danger">Fehler beim Laden</div>';
    }
}

function initGewichtSwipe(item) {
    const row = item.querySelector('.gw-row');
    let startX = 0, dragging = false;
    const THRESHOLD = 50;
    function onStart(x) { startX = x; dragging = true; }
    function onMove(x) {
        if (!dragging) return;
        const dx = Math.min(0, Math.max(-70, x - startX));
        row.style.transform = `translateX(${dx}px)`;
    }
    function onEnd(x) {
        if (!dragging) return;
        dragging = false;
        row.style.transform = (x - startX) < -THRESHOLD ? 'translateX(-70px)' : '';
    }
    row.addEventListener('touchstart', e => onStart(e.touches[0].clientX),  {passive:true});
    row.addEventListener('touchmove',  e => { e.stopPropagation(); onMove(e.touches[0].clientX); }, {passive:true});
    row.addEventListener('touchend',   e => onEnd(e.changedTouches[0].clientX));
}

async function deleteGewicht(id, item) {
    try {
        const r = await fetch('/api/weight.php', {
            method: 'DELETE', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({id}),
        });
        const d = await r.json();
        if (d.ok) {
            item.style.transition = 'opacity .3s';
            item.style.opacity    = '0';
            setTimeout(() => { item.remove(); location.reload(); }, 350);
        } else { showToast('Fehler: ' + (d.error || 'Unbekannt')); }
    } catch(e) { showToast('Verbindungsfehler'); }
}
</script>

<!-- ── Aktivitätskalorien ──────────────────────────────────────── -->
<div class="section-label" id="aktivitaet"><span>Aktivität</span>
    <button type="button" class="chip-btn" onclick="toggleAktivHistorie()"><i class="bi bi-clock-history"></i> Verlauf</button>
</div>
<div class="kt-card">
    <div class="kt-info-card-wrap"><button class="kt-info-btn" type="button" aria-label="Info zu Aktivitätskalorien"><i class="bi bi-info-circle"></i></button><div class="kt-tooltip" style="top:auto;bottom:calc(100% + .3rem);">Aktivitätskalorien werden zum täglichen Kalorienziel addiert – du darfst also mehr essen wenn du dich bewegt hast.<br><br><strong>Eintragen</strong> – Bezeichnung optional, kcal erforderlich. Der Eintrag gilt für den heutigen Tag.<br><br><strong>Verlauf</strong> – zeigt deine letzten 7 Aktivitätseinträge. Zum Löschen nach links wischen.<br><br><strong>Tipp:</strong> Du kannst Aktivitätskalorien auch automatisch per Apple Shortcuts oder API eintragen lassen – z.B. direkt von deiner Smartwatch.</div></div>

    <!-- Eingabe -->
    <div class="input-row" style="padding-right:1.6rem;">
        <input type="text" id="aktivBezeichnung" class="kt-input" placeholder="Bezeichnung (optional)" style="flex:1;min-width:0;">
        <input type="number" id="aktivKcal" class="kt-input" min="1" max="10000" inputmode="decimal"
               placeholder="kcal" style="width:5.2rem;font-weight:700;" aria-label="Kalorien">
        <button id="btnSaveAktiv" class="icon-btn icon-btn--accent" onclick="saveAktiv()" aria-label="Aktivität speichern">
            <i class="bi bi-plus-lg" id="aktivIcon"></i>
        </button>
    </div>
    <div class="tile__sub" style="margin-top:.45rem;">Wird zum heutigen Kalorienziel addiert.</div>

    <!-- Verlauf (eingeklappt) -->
    <div id="aktivHistorieWrap" style="display:none;margin-top:.9rem;">
        <div class="tile__label" style="margin-bottom:.45rem;">Letzte 7 Tage</div>
        <div id="aktivHistorieList" class="mini-list"></div>
    </div>
</div>

<style>
.chip-btn {
    display: inline-flex; align-items: center; gap: .35rem;
    background: var(--surface); border: 1px solid var(--border); border-radius: 99px;
    color: var(--muted); font: 600 .75rem var(--font); letter-spacing: 0; text-transform: none;
    padding: .35rem .8rem; min-height: 2.25rem; cursor: pointer;
}
.input-row { display: flex; align-items: center; gap: .5rem; padding-right: 1.6rem; }
.big-input {
    flex: 1; display: flex; align-items: baseline; gap: .35rem;
    background: var(--surface2); border: 1px solid var(--border); border-radius: 14px;
    padding: .35rem .9rem;
}
.big-input:focus-within { border-color: var(--accent); }
.big-input input {
    flex: 1; min-width: 0; background: transparent; border: none; color: var(--text);
    font: 700 1.6rem var(--font); padding: 0; outline: none;
    font-variant-numeric: tabular-nums;
}
.big-input span { color: var(--muted); }
.mini-list { display: flex; flex-direction: column; gap: .35rem; }
.mini-swipe { position: relative; overflow: hidden; border-radius: 12px; }
.mini-swipe__del {
    position: absolute; right: 0; top: 0; bottom: 0; width: 70px;
    background: var(--danger); color: #2a0610; border: none;
    display: flex; align-items: center; justify-content: center; font-size: 1rem;
}
.mini-swipe__row {
    position: relative; background: var(--surface2); border-radius: 12px;
    padding: .65rem .85rem; min-height: 2.75rem;
    display: flex; align-items: center; justify-content: space-between; gap: .5rem;
    transition: transform .25s ease;
}
.mini-swipe__date { font-size: .8rem; color: var(--muted); flex-shrink: 0; }
.mini-swipe__text { font-size: .88rem; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mini-swipe__row b { font-variant-numeric: tabular-nums; flex-shrink: 0; }
.mini-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: .35rem; margin-top: .75rem; text-align: center; }
.mini-stats > div { background: var(--surface2); border-radius: 12px; padding: .45rem .2rem; }
.mini-stats b { display: block; font-size: .92rem; font-variant-numeric: tabular-nums; }
.mini-stats small { font-size: .68rem; color: var(--muted); }
</style>

<script>
// ── Aktivität speichern ───────────────────────────────────────────────────────
async function saveAktiv() {
    const kcal = parseInt(document.getElementById('aktivKcal').value);
    const bez  = document.getElementById('aktivBezeichnung').value.trim() || 'Training';
    const btn  = document.getElementById('btnSaveAktiv');
    const icon = document.getElementById('aktivIcon');
    if (!kcal || kcal < 1 || kcal > 10000) {
        icon.className = 'bi bi-x-lg'; btn.style.background = 'var(--danger)';
        setTimeout(() => { icon.className = 'bi bi-plus-lg'; btn.style.background = ''; }, 1500);
        return;
    }
    btn.disabled = true;
    try {
        const r = await fetch('/api/activity.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ kcal, bezeichnung: bez, datum: new Date().toISOString().split('T')[0] }),
        });
        const d = await r.json();
        if (d.ok) {
            icon.className = 'bi bi-check2-all';
            document.getElementById('aktivKcal').value = '';
            document.getElementById('aktivBezeichnung').value = '';
            setTimeout(() => {
                icon.className = 'bi bi-plus-lg';
                if (_aktivHistorieVisible) loadAktivHistorie();
            }, 400);
        } else throw new Error(d.error);
    } catch(e) {
        icon.className = 'bi bi-x-lg'; btn.style.background = 'var(--danger)';
        setTimeout(() => { icon.className = 'bi bi-plus-lg'; btn.style.background = ''; }, 1500);
    } finally { btn.disabled = false; }
}
document.getElementById('aktivKcal').addEventListener('keyup', e => {
    if (e.key === 'Enter') saveAktiv();
});

// ── Aktivitäts-Verlauf ────────────────────────────────────────────────────────
let _aktivHistorieVisible = false;

function toggleAktivHistorie() {
    _aktivHistorieVisible = !_aktivHistorieVisible;
    const wrap = document.getElementById('aktivHistorieWrap');
    wrap.style.display = _aktivHistorieVisible ? 'block' : 'none';
    if (_aktivHistorieVisible) {
        loadAktivHistorie();
        // Kurz warten bis der Inhalt gerendert ist, dann hinscrolling
        setTimeout(() => {
            wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 150);
    }
}

async function loadAktivHistorie() {
    const list = document.getElementById('aktivHistorieList');
    list.innerHTML = '<div class="tile__sub">Lädt…</div>';
    try {
        // Alle Einträge der letzten 7 Tage in EINEM Request
        const r = await fetch('/api/activity.php?days=7');
        const d = await r.json();
        const eintraege = (d.ok ? d.eintraege : []).slice(0, 7);

        if (!eintraege.length) {
            list.innerHTML = '<div class="tile__sub">Keine Einträge</div>';
            return;
        }
        list.innerHTML = '';
        eintraege.forEach(e => {
            const datum   = new Date(e.datum + 'T12:00:00');
						const dateStr = datum.toLocaleDateString('de-DE', {weekday:'short', day:'2-digit', month:'2-digit'})
              + ' ' + new Date(e.erstellt_am).toLocaleTimeString('de-DE', {hour:'2-digit', minute:'2-digit'});
            const item    = document.createElement('div');
            item.dataset.id = e.id;
            item.className = 'mini-swipe';
            item.innerHTML = `
                <button type="button" class="ak-del mini-swipe__del" aria-label="Löschen"><i class="bi bi-trash3"></i></button>
                <div class="ak-row mini-swipe__row">
                    <span class="mini-swipe__date">${dateStr}</span>
                    <span class="mini-swipe__text">${escHtml(e.bezeichnung)}</span>
                    <b>${e.kcal} kcal</b>
                </div>`;
            item.querySelector('.ak-del').addEventListener('click', () => deleteAktiv(e.id, item));
            list.appendChild(item);
            initAktivSwipe(item);
        });
    } catch(err) {
        list.innerHTML = '<div class="tile__sub text-danger">Fehler beim Laden</div>';
    }
}

function initAktivSwipe(item) {
    const row = item.querySelector('.ak-row');
    let startX = 0, dragging = false;
    function onStart(x) { startX = x; dragging = true; }
    function onMove(x) {
        if (!dragging) return;
        row.style.transform = `translateX(${Math.min(0, Math.max(-70, x - startX))}px)`;
    }
    function onEnd(x) {
        if (!dragging) return;
        dragging = false;
        row.style.transform = (x - startX) < -50 ? 'translateX(-70px)' : '';
    }
    row.addEventListener('touchstart', e => onStart(e.touches[0].clientX),  {passive:true});
    row.addEventListener('touchmove',  e => { e.stopPropagation(); onMove(e.touches[0].clientX); }, {passive:true});
    row.addEventListener('touchend',   e => onEnd(e.changedTouches[0].clientX));
}

async function deleteAktiv(id, item) {
    try {
        const r = await fetch('/api/activity.php', {
            method: 'DELETE', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({id}),
        });
        const d = await r.json();
        if (d.ok) {
            item.style.transition = 'opacity .3s';
            item.style.opacity    = '0';
            setTimeout(() => item.remove(), 300);
        } else showToast('Fehler: ' + (d.error || 'Unbekannt'));
    } catch(e) { showToast('Verbindungsfehler'); }
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php renderFooter('profil'); ?>
