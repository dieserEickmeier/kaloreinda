<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/bmr.php';
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
?>

<div class="page-header">
    <h1><i class="bi bi-person-circle text-accent me-1"></i> Profil</h1>
    <a href="/einstellungen.php"
       style="color:var(--muted);text-decoration:none;font-size:1.4rem;padding:.25rem .5rem;"
       title="Einstellungen">
        <i class="bi bi-gear"></i>
    </a>
</div>



<!-- ── Berechnete Werte ───────────────────────────────────────── -->
<?php if ($tdee): ?>
<div class="kt-card" style="margin-bottom:.75rem;margin-top:.75rem;position:relative;padding-top:1rem;">
    <div class="kt-info-card-wrap" style="top:-.1rem;right:.1rem"><button class="kt-info-btn" type="button" aria-label="Info"><i class="bi bi-info-circle"></i></button><div class="kt-tooltip" style="width:300px;">Berechnet per <strong>Mifflin-St.-Jeor-Formel</strong> aus Gewicht, Größe, Alter und Geschlecht.<br><br>
<strong>Grundumsatz (BMR)</strong> – Energieverbrauch in völliger Ruhe, ohne jede Bewegung.<br><br>
<strong>Gesamtumsatz (TDEE)</strong> – BMR × Aktivitätsfaktor. Sitzend = 1,2 · Sehr aktiv = bis 1,9. Je ehrlicher die Einschätzung, desto genauer das Ziel.<br><br>
<strong>Kalorienziel</strong> – TDEE minus Defizit. 300–500 kcal Defizit = nachhaltiges Abnehmen ohne Muskelverlust. Über 700 kcal ist langfristig kontraproduktiv.<br><br>
<strong>BMI</strong> (Körpergewicht ÷ Größe²) nach WHO: unter 18,5 = Untergewicht · 18,5–24,9 = Normalgewicht · 25–29,9 = Übergewicht · 30–34,9 = Adipositas I · 35–39,9 = Adipositas II · ab 40 = Adipositas III. Der BMI berücksichtigt keine Muskelmasse – bei sportlichen Menschen nur bedingt aussagekräftig.<br><br>
<strong>Tipp:</strong> Gewicht regelmäßig eintragen – besonders bei größeren Veränderungen verbessert das die Genauigkeit deutlich.</div></div>
<div style="font-size:.78rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem;">Kalorienziel</div>

    <div style="display:flex;justify-content:space-between;margin-bottom:.6rem;">
        <span style="color:var(--muted);font-size:.82rem;">Grundumsatz (TDEE)</span>
        <span style="font-weight:700;"><?= number_format($tdee, 0, ',', '.') ?> kcal</span>
    </div>
    <div style="display:flex;justify-content:space-between;margin-bottom:.6rem;">
        <span style="color:var(--muted);font-size:.82rem;">Defizit</span>
        <span style="font-weight:700;color:var(--warn);">− <?= $defizit ?> kcal</span>
    </div>
    <div style="border-top:1px solid var(--border);padding-top:.6rem;display:flex;justify-content:space-between;">
        <span style="color:var(--muted);font-size:.82rem;">Kalorienziel heute</span>
        <span style="font-weight:800;font-size:1.05rem;color:var(--accent);"><?= number_format($zielKcal, 0, ',', '.') ?> kcal</span>
    </div>
    <?php if ($kg):
        $bmi = $kg / (($profil['groesse_cm'] / 100) ** 2);
        // WHO-Klassifikation
        if     ($bmi < 18.5) { $bmiLabel = 'Untergewicht';        $bmiColor = '#60a5fa'; }
        elseif ($bmi < 25)   { $bmiLabel = 'Normalgewicht';       $bmiColor = 'var(--accent)'; }
        elseif ($bmi < 30)   { $bmiLabel = 'Übergewicht';         $bmiColor = '#facc15'; }
        elseif ($bmi < 35)   { $bmiLabel = 'Adipositas Grad I';   $bmiColor = '#fb923c'; }
        elseif ($bmi < 40)   { $bmiLabel = 'Adipositas Grad II';  $bmiColor = '#f87171'; }
        else                 { $bmiLabel = 'Adipositas Grad III'; $bmiColor = '#ef4444'; }
    ?>
    <div style="margin-top:.5rem;font-size:.75rem;color:var(--muted);text-align:center;">
        BMI: <span style="font-weight:700;color:<?= $bmiColor ?>;"><?= number_format($bmi, 1, ',', '') ?></span>
        <span style="color:<?= $bmiColor ?>;">(<?= $bmiLabel ?>)</span>
    </div>
    <?php endif; ?>

</div>
<?php else: ?>
<div class="kt-card" style="margin-bottom:.75rem;margin-top:.75rem;text-align:center;color:var(--muted);">
    <i class="bi bi-calculator" style="font-size:1.8rem;display:block;margin-bottom:.5rem;"></i>
    <div style="font-size:.88rem;">
        Trage dein Gewicht ein um deinen Grundumsatz und dein Kalorienziel zu berechnen.
    </div>
</div>
<?php endif; ?>

<!-- ── Gewicht: Erfassung + Verlauf (eine Card) ─────────────── -->
<div class="kt-card" style="margin:1rem 1rem .75rem;position:relative;">

    <!-- Info-Icon -->
    <div class="kt-info-card-wrap" style="top:-.1rem;right:.1rem"><button class="kt-info-btn" type="button" aria-label="Info"><i class="bi bi-info-circle"></i></button><div class="kt-tooltip" style="width:300px">Trage dein Gewicht täglich ein – am besten morgens nüchtern für vergleichbare Werte.<br><br>
<strong>Grüne Linie</strong> – dein eingetragenes Gewicht. Schwankungen von 1–2 kg täglich sind normal (Wasser, Verdauung).<br><br>
<strong>Orange Linie</strong> – EWMA-Trend: filtert Schwankungen heraus und zeigt die echte Richtung. Neuere Messungen werden stärker gewichtet.<br><br>
<strong>Trend/Woche</strong> – wöchentliche Veränderung des geglätteten Gewichts. Realistisches Abnahmetempo: 0,3–0,7 kg/Woche.<br><br>
<strong>Verlauf</strong> – zeigt deine letzten 7 Einträge. Zum Löschen nach links wischen.</div></div>

    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem">
        <div style="font-size:.78rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.05em;">Gewicht</div>
        <button onclick="toggleGewichtHistorie()"
                style="display:flex;align-items:center;gap:.3rem;background:var(--surface2);
                       border:1px solid var(--border);border-radius:8px;padding:.25rem .6rem;
                       color:var(--muted);font-size:.72rem;font-weight:600;cursor:pointer;">
            <i class="bi bi-clock-history" style="font-size:.75rem;"></i> Verlauf
        </button>
    </div>

    <!-- Eingabe -->
    <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.5rem;">
        <input type="number" id="gewichtInput" step="0.1" min="20" max="300" inputmode="decimal"
               value="<?= $kg ? number_format($kg, 1, '.', '') : '' ?>"
               placeholder="82,5"
               style="flex:1;background:var(--surface2);border:1px solid var(--border);
                      color:var(--text);border-radius:12px;padding:.6rem .85rem;
                      font-size:1.2rem;font-weight:700;outline:none;">
        <span style="color:var(--muted);font-size:.9rem;flex-shrink:0;">kg</span>
        <button id="btnSaveGewicht" onclick="saveGewicht()"
                style="background:var(--accent);border:none;border-radius:12px;
                       padding:.6rem 1rem;color:#000;font-weight:700;font-size:.88rem;
                       white-space:nowrap;flex-shrink:0;">
            <i class="bi bi-plus-lg" id="gewichtIcon"></i>
        </button>
    </div>
    <?php if ($kg && !empty($gewicht['datum'])): ?>
    <div style="font-size:.75rem;color:var(--muted);margin-bottom:.75rem;">
        Zuletzt: <?= number_format((float)$gewicht['kg'], 1, ',', '') ?> kg
        am <?= date('d.m.Y', strtotime($gewicht['datum'])) ?>
    </div>
    <?php endif; ?>

    <!-- 7-Tage-Historie (eingeklappt) -->
    <div id="gewichtHistorieWrap" style="display:none;margin-bottom:.75rem;">
        <div style="font-size:.75rem;color:var(--muted);font-weight:600;margin-bottom:.5rem;">
            Letzte 7 Tage
        </div>
        <div id="gewichtHistorieList" style="display:flex;flex-direction:column;gap:.35rem;"></div>
    </div>

    <!-- Trennlinie vor Chart -->
    <?php if (count($gewichtHistory) > 1): ?>
    <div style="border-top:1px solid var(--border);margin-bottom:.75rem;"></div>

    <!-- Zeitraum-Auswahl -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;">
        <div style="font-size:.78rem;color:var(--muted);font-weight:600;" id="chartTitle">
            Verlauf (<?= count($gewichtHistory) ?> Einträge)
        </div>
        <div style="display:flex;gap:.3rem;">
            <?php foreach (['1M'=>'1M','3M'=>'3M','6M'=>'6M','all'=>'Alle'] as $key => $lbl): ?>
            <button onclick="setPeriod('<?= $key ?>')" id="btn-<?= $key ?>"
                    style="padding:.25rem .55rem;font-size:.72rem;font-weight:700;border-radius:7px;
                           border:1px solid var(--border);background:var(--surface);
                           color:var(--muted);cursor:pointer;transition:all .15s;">
                <?= $lbl ?>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
    <canvas id="weightChart" height="120"></canvas>
    <div id="chartStats" style="display:flex;justify-content:space-around;margin-top:.75rem;
         font-size:.75rem;text-align:center;color:var(--muted);"></div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Alle Daten (älteste zuerst)
const allData = <?= json_encode(array_reverse(array_map(
    fn($r) => ['datum' => $r['datum'], 'kg' => (float)$r['kg']],
    $gewichtHistory
))) ?>;

let chart = null;
let activePeriod = '1M';

function filterByPeriod(period) {
    if (period === 'all') return allData;
    const months = period === '1M' ? 1 : period === '3M' ? 3 : 6;
    const cutoff = new Date();
    cutoff.setMonth(cutoff.getMonth() - months);
    return allData.filter(d => new Date(d.datum) >= cutoff);
}

// Exponentiell gewichteter gleitender Durchschnitt (EWMA)
// alpha: Glättungsfaktor 0–1. Höher = reaktiver auf neue Werte.
// Wir wählen alpha dynamisch: bei wenigen Punkten stärker glätten.
function ewma(weights, alpha) {
    const result = [weights[0]];
    for (let i = 1; i < weights.length; i++) {
        result.push(alpha * weights[i] + (1 - alpha) * result[i - 1]);
    }
    return result.map(v => parseFloat(v.toFixed(2)));
}

function setPeriod(period) {
    activePeriod = period;

    // Buttons stylen
    ['1M','3M','6M','all'].forEach(p => {
        const btn = document.getElementById('btn-' + p);
        btn.style.background = p === period ? 'var(--accent)' : 'var(--surface)';
        btn.style.color      = p === period ? '#000'          : 'var(--muted)';
        btn.style.borderColor= p === period ? 'var(--accent)' : 'var(--border)';
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

    // EWMA Trendlinie
    // alpha dynamisch: mehr Datenpunkte → stärkere Glättung (kleineres alpha)
    const alpha    = Math.max(0.1, Math.min(0.4, 2 / (weights.length + 1)));
    const trendData = ewma(weights, alpha);

    // Trend pro Woche: Steigung des EWMA über den Zeitraum
    const daySpan = (new Date(data[data.length-1].datum) - new Date(data[0].datum))
                    / (1000*60*60*24) || 1;
    const ewmaSlope    = (trendData[trendData.length-1] - trendData[0]) / daySpan;
    const slopePerWeek = ewmaSlope * 7;
    const trendStr  = (slopePerWeek >= 0 ? '+' : '') + slopePerWeek.toFixed(2);
    const trendColor = slopePerWeek < -0.05 ? '#4ade80'
                     : slopePerWeek >  0.05 ? '#f87171'
                     : 'var(--muted)';
    const trendIcon  = slopePerWeek < -0.05 ? '↓' : slopePerWeek > 0.05 ? '↑' : '→';

    document.getElementById('chartTitle').textContent =
        `Verlauf (${data.length} Einträge)`;

    // Statistik inkl. Trend
    const first = data[0].kg, last = data[data.length-1].kg;
    const diff  = last - first;
    const diffStr = (diff >= 0 ? '+' : '') + diff.toFixed(1);
    const diffColor = diff < 0 ? '#4ade80' : diff > 0 ? '#f87171' : 'var(--muted)';
    document.getElementById('chartStats').innerHTML = `
        <div><div style="font-weight:700;color:var(--text);">${first.toFixed(1)} kg</div><div>Start</div></div>
        <div><div style="font-weight:700;color:${diffColor};">${diffStr} kg</div><div>Veränderung</div></div>
        <div><div style="font-weight:700;color:var(--text);">${last.toFixed(1)} kg</div><div>Aktuell</div></div>
        <div><div style="font-weight:700;color:${trendColor};">${trendIcon} ${trendStr} kg</div><div>Trend/Woche</div></div>
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
                        borderColor: '#4ade80', backgroundColor: 'rgba(74,222,128,.1)',
                        borderWidth: 2, pointRadius: 0, pointHoverRadius: 0, pointBackgroundColor: '#4ade80',
                        fill: true, tension: 0.35, spanGaps: false
                    },
                    {
                        label: 'Trend',
                        data: paddedTrend,
                        borderColor: 'rgba(251,146,60,0.8)',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        borderDash: [6, 4],
                        pointRadius: 0,
                        fill: false,
                        tension: 0,
                        spanGaps: false
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
                    x: { ticks: { color:'#7c7f8e', font:{size:10}, maxTicksLimit: 8 },
                         grid: { color:'rgba(255,255,255,.05)' }, offset: true },
                    y: { ticks: { color:'#7c7f8e', font:{size:10} },
                         grid: { color:'rgba(255,255,255,.05)' },
                         min: minW - yPad, max: maxW + yPad }
                }
            }
        });
    }
}

// Standard: 1M, oder 'all' wenn weniger als 2 Monate Daten
const oldest = new Date(allData[0]?.datum);
const monthsOfData = (new Date() - oldest) / (1000*60*60*24*30);
setPeriod(monthsOfData < 1.5 ? 'all' : '1M');
</script>

<script>
// ── saveGewicht ───────────────────────────────────────────────────────────
async function saveGewicht() {
    const val  = parseFloat(document.getElementById('gewichtInput').value.replace(',', '.'));
    const btn  = document.getElementById('btnSaveGewicht');
    const icon = document.getElementById('gewichtIcon');
    if (!val || val < 20 || val > 300) {
        icon.className = 'bi bi-x-lg'; btn.style.background = '#ef4444';
        setTimeout(() => { icon.className = 'bi bi-plus-lg'; btn.style.background = 'var(--accent)'; }, 1500);
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
        icon.className = 'bi bi-x-lg'; btn.style.background = '#ef4444';
        setTimeout(() => { icon.className = 'bi bi-plus-lg'; btn.style.background = 'var(--accent)'; }, 1500);
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
    list.innerHTML = '<div style="color:var(--muted);font-size:.8rem;padding:.25rem 0;">Lädt…</div>';
    try {
        const r = await fetch('/api/weight.php?limit=7');
        const d = await r.json();
        if (!d.ok || !d.eintraege.length) {
            list.innerHTML = '<div style="color:var(--muted);font-size:.8rem;">Keine Einträge</div>';
            return;
        }
        list.innerHTML = '';
        d.eintraege.forEach(e => {
            const datum   = new Date(e.datum + 'T12:00:00');
            const dateStr = datum.toLocaleDateString('de-DE', {weekday:'short', day:'2-digit', month:'2-digit'});
            const item    = document.createElement('div');
            item.dataset.id = e.id;
            item.style.cssText = 'position:relative;overflow:hidden;border-radius:10px;';
            item.innerHTML = `
                <div class="gw-del" style="position:absolute;right:0;top:0;bottom:0;width:70px;
                    background:var(--danger);display:flex;align-items:center;justify-content:center;
                    border-radius:0 10px 10px 0;cursor:pointer;">
                    <i class="bi bi-trash3" style="color:#fff;font-size:1rem;"></i>
                </div>
                <div class="gw-row" style="position:relative;background:var(--surface2);
                    border-radius:10px;padding:.5rem .75rem;display:flex;align-items:center;
                    justify-content:space-between;transition:transform .25s ease;">
                    <span style="font-size:.82rem;color:var(--muted);">${dateStr}</span>
                    <span style="font-size:.9rem;font-weight:700;color:var(--text);">${parseFloat(e.kg).toFixed(1)} kg</span>
                </div>`;
            item.querySelector('.gw-del').addEventListener('click', () => deleteGewicht(e.id, item));
            list.appendChild(item);
            initGewichtSwipe(item);
        });
    } catch(err) {
        list.innerHTML = '<div style="color:var(--danger);font-size:.8rem;">Fehler beim Laden</div>';
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
<div class="kt-card" style="margin:.75rem 1rem;position:relative;padding-top:1rem;">

    <!-- Info-Icon -->
    <div class="kt-info-card-wrap" style="top:-.1rem;right:.1rem;"><button class="kt-info-btn" type="button" aria-label="Info"><i class="bi bi-info-circle"></i></button><div class="kt-tooltip" style="top:auto;bottom:calc(100% + .3rem);">Aktivitätskalorien werden zum täglichen Kalorienziel addiert – du darfst also mehr essen wenn du dich bewegt hast.<br><br><strong>Eintragen</strong> – Bezeichnung optional, kcal erforderlich. Der Eintrag gilt für den heutigen Tag.<br><br><strong>Verlauf</strong> – zeigt deine letzten 7 Aktivitätseinträge. Zum Löschen nach links wischen.<br><br><strong>Tipp:</strong> Du kannst Aktivitätskalorien auch automatisch per Apple Shortcuts oder API eintragen lassen – z.B. direkt von deiner Smartwatch.</div></div>

    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;">
        <div style="font-size:.78rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.05em;">
            Aktivitätskalorien
        </div>
        <button onclick="toggleAktivHistorie()"
                style="display:flex;align-items:center;gap:.3rem;background:var(--surface2);
                       border:1px solid var(--border);border-radius:8px;padding:.25rem .6rem;
                       color:var(--muted);font-size:.72rem;font-weight:600;cursor:pointer;">
            <i class="bi bi-clock-history" style="font-size:.75rem;"></i> Verlauf
        </button>
    </div>

    <!-- Eingabe -->
    <div style="display:grid;grid-template-columns:1fr auto auto;gap:.5rem;
                margin-bottom:.5rem;align-items:center;">
        <input type="text" id="aktivBezeichnung"
               placeholder="Bezeichnung (optional)"
               style="min-width:0;background:var(--surface2);border:1px solid var(--border);
                      color:var(--text);border-radius:12px;padding:.6rem .85rem;
                      font-size:.9rem;outline:none;">
        <input type="number" id="aktivKcal" min="1" max="10000" inputmode="decimal"
               placeholder="kcal"
               style="width:72px;background:var(--surface2);border:1px solid var(--border);
                      color:var(--text);border-radius:12px;padding:.6rem .6rem;
                      font-size:1rem;font-weight:700;outline:none;">
        <button id="btnSaveAktiv" onclick="saveAktiv()"
                style="background:var(--accent);border:none;border-radius:12px;
                       padding:.6rem .9rem;color:#000;font-weight:700;font-size:.88rem;">
            <i class="bi bi-plus-lg" id="aktivIcon"></i>
        </button>
    </div>
    <div style="font-size:.75rem;color:var(--muted);">
        Wird zum heutigen Kalorienziel addiert.
    </div>

    <!-- Verlauf (eingeklappt) -->
    <div id="aktivHistorieWrap" style="display:none;margin-top:.75rem;">
        <div style="font-size:.75rem;color:var(--muted);font-weight:600;margin-bottom:.5rem;">
            Letzte 7 Tage
        </div>
        <div id="aktivHistorieList" style="display:flex;flex-direction:column;gap:.35rem;"></div>
    </div>

</div>

<script>
// ── Aktivität speichern ───────────────────────────────────────────────────────
async function saveAktiv() {
    const kcal = parseInt(document.getElementById('aktivKcal').value);
    const bez  = document.getElementById('aktivBezeichnung').value.trim() || 'Training';
    const btn  = document.getElementById('btnSaveAktiv');
    const icon = document.getElementById('aktivIcon');
    if (!kcal || kcal < 1 || kcal > 10000) {
        icon.className = 'bi bi-x-lg'; btn.style.background = '#ef4444';
        setTimeout(() => { icon.className = 'bi bi-plus-lg'; btn.style.background = 'var(--accent)'; }, 1500);
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
        icon.className = 'bi bi-x-lg'; btn.style.background = '#ef4444';
        setTimeout(() => { icon.className = 'bi bi-plus-lg'; btn.style.background = 'var(--accent)'; }, 1500);
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
    list.innerHTML = '<div style="color:var(--muted);font-size:.8rem;padding:.25rem 0;">Lädt…</div>';
    try {
        // Alle Einträge der letzten 7 Tage in EINEM Request
        const r = await fetch('/api/activity.php?days=7');
        const d = await r.json();
        const eintraege = (d.ok ? d.eintraege : []).slice(0, 7);

        if (!eintraege.length) {
            list.innerHTML = '<div style="color:var(--muted);font-size:.8rem;">Keine Einträge</div>';
            return;
        }
        list.innerHTML = '';
        eintraege.forEach(e => {
            const datum   = new Date(e.datum + 'T12:00:00');
						const dateStr = datum.toLocaleDateString('de-DE', {weekday:'short', day:'2-digit', month:'2-digit'})
              + ' ' + new Date(e.erstellt_am).toLocaleTimeString('de-DE', {hour:'2-digit', minute:'2-digit'});
            const item    = document.createElement('div');
            item.dataset.id = e.id;
            item.style.cssText = 'position:relative;overflow:hidden;border-radius:10px;';
            item.innerHTML = `
                <div class="ak-del" style="position:absolute;right:0;top:0;bottom:0;width:70px;
                    background:var(--danger);display:flex;align-items:center;justify-content:center;
                    border-radius:0 10px 10px 0;cursor:pointer;">
                    <i class="bi bi-trash3" style="color:#fff;font-size:1rem;"></i>
                </div>
                <div class="ak-row" style="position:relative;background:var(--surface2);
                    border-radius:10px;padding:.5rem .75rem;display:flex;align-items:center;
                    justify-content:space-between;gap:.5rem;transition:transform .25s ease;">
                    <span style="font-size:.75rem;color:var(--muted);flex-shrink:0;">${dateStr}</span>
                    <span style="font-size:.82rem;color:var(--text);flex:1;
                                 overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escHtml(e.bezeichnung)}</span>
                    <span style="font-size:.9rem;font-weight:700;color:white;flex-shrink:0;">${e.kcal} kcal</span>
                </div>`;
            item.querySelector('.ak-del').addEventListener('click', () => deleteAktiv(e.id, item));
            list.appendChild(item);
            initAktivSwipe(item);
        });
    } catch(err) {
        list.innerHTML = '<div style="color:var(--danger);font-size:.8rem;">Fehler beim Laden</div>';
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
