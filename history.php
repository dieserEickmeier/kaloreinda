<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/bmr.php';
require_once __DIR__ . '/includes/ui.php';
$currentUser = requireLogin();
$userId = $currentUser['id'];

$db = db();

// Profil + Makro-Setting laden
$stmtPr = $db->prepare("SELECT * FROM profil WHERE user_id = ? LIMIT 1");
$stmtPr->bind_param('i', $userId); $stmtPr->execute();
$profil = $stmtPr->get_result()->fetch_assoc() ?: [];
$zeigeMakros = (int)($profil['makros_anzeigen'] ?? 1);

// ── Tageswerte für bis zu 365 Tage (ohne heute) – Grundlage für Charts,
//    Kennzahlen und die Tageskarten der letzten 7 Tage ─────────────────────
$gestern = date('Y-m-d', strtotime('-1 day'));
$tageAll = ladeTageswerte($db, $userId, $profil, date('Y-m-d', strtotime('-365 day')), $gestern);

// Serie: aufeinanderfolgende Tage im Ziel bis gestern (leere Tage beenden sie)
$serie = 0;
foreach (array_reverse($tageAll) as $t) {
    if ($t['leer'] || $t['uebrig'] < 0) break;
    $serie++;
}

// Gewichtsmessungen (echte Messpunkte, nicht fortgeschrieben) für den Trend-Chart
$von365   = date('Y-m-d', strtotime('-365 day'));
$gewichte = array_values(array_map(fn($g) => ['d' => $g['datum'], 'kg' => $g['kg'], 't' => $g['trend']],
    array_filter(gewichtsTrend($db, $userId), fn($g) => $g['datum'] >= $von365)));

// Letzte 7 Tage (neueste zuerst) für die Tageskarten
$tage7 = array_reverse(array_slice($tageAll, -7, 7, true), true);

// Einträge der letzten 7 Tage (mit Produkt-Join für Quell-Punkte)
$eintraege = [];
$minDatum  = array_key_last($tage7);
$stmt2 = $db->prepare("
    SELECT e.*, p.quelle
    FROM eintraege e
    LEFT JOIN produkte p ON p.id = e.produkt_id
    WHERE e.datum >= ? AND e.datum < CURDATE() AND e.user_id = ?
    ORDER BY e.datum DESC, e.erstellt_am DESC
");
$stmt2->bind_param('si', $minDatum, $userId);
$stmt2->execute();
foreach ($stmt2->get_result()->fetch_all(MYSQLI_ASSOC) as $e) {
    $eintraege[$e['datum']][] = $e;
}

// Kompakte Chart-Daten fürs Frontend
$chartTage = array_values(array_map(fn($t) => [
    'd' => $t['datum'], 'k' => $t['leer'] ? null : round($t['kcal']), 'z' => round($t['ziel']),
], $tageAll));

$hatDaten = (bool)array_filter($tageAll, fn($t) => !$t['leer']);

renderHeader('Verlauf', 'history');
?>

<?php renderPageHeader('Verlauf', '', 'Dein Fortschritt'); ?>

<?php if (!$hatDaten): ?>
<div class="empty-state">
    <i class="bi bi-calendar-x"></i>
    <p>Noch keine Einträge vorhanden.</p>
    <div class="btn-row"><button type="button" class="scan-btn" onclick="zxingStart()"><i class="bi bi-upc-scan"></i>Erstes Produkt scannen</button></div>
</div>
<?php else: ?>

<!-- ── Zeitraum + Kennzahlen ────────────────────────────────────── -->
<div class="seg" role="tablist" aria-label="Zeitraum" style="margin:.25rem .85rem 0;" id="rangeSeg">
    <button type="button" data-range="7"   role="tab">7 T</button>
    <button type="button" data-range="30"  role="tab" class="active">30 T</button>
    <button type="button" data-range="90"  role="tab">90 T</button>
    <button type="button" data-range="365" role="tab">Jahr</button>
</div>

<div class="stat3">
    <div><small>Ø kcal</small><b id="stAvg">–</b></div>
    <div><small>Im Ziel</small><b id="stZiel">–</b></div>
    <div><small>Serie</small><b><?= $serie ?> <span>T</span></b></div>
</div>

<div class="tile chart-tile">
    <div class="ch"><span><b>Kalorien</b> vs. Ziel</span><span id="chRange"></span></div>
    <div style="height:150px;"><canvas id="kcalChart" aria-label="Kalorien pro Tag im Vergleich zum Ziel" role="img"></canvas></div>
</div>

<?php if (count($gewichte) > 1): ?>
<div class="tile chart-tile">
    <div class="ch"><span><b>Gewicht</b> · Trend</span><span id="chGewDelta"></span></div>
    <div style="height:110px;"><canvas id="weightChart" aria-label="Gewichtsmessungen mit Trendlinie" role="img"></canvas></div>
</div>
<?php endif; ?>

<!-- ── Tages-Karten der letzten 7 Tage ──────────────────────────── -->
<div class="section-label"><span>Letzte 7 Tage</span></div>

<?php foreach ($tage7 as $datum => $t):
    $label = $datum === $gestern ? 'Gestern'
        : ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'][(int)date('w', strtotime($datum))];
    $over  = !$t['leer'] && $t['uebrig'] < 0;
    $eK = $t['eiweiss'] * 4; $fK = $t['fett'] * 9; $kK = $t['kh'] * 4;
    $mSum = $eK + $fK + $kK;
?>
<div class="hx-day <?= $t['leer'] ? 'hx-day--leer' : '' ?>" id="day-<?= $datum ?>">
    <div class="hx-day__head">
        <div class="hx-day__date">
            <b><?= $label ?></b>
            <span><?= date('d.m.', strtotime($datum)) ?></span>
        </div>
        <div class="hx-day__kcal">
            <b class="num"><?= fmtZahl($t['kcal']) ?></b>
            <?php if (!$t['leer']): ?>
            <span class="hx-delta <?= $over ? 'over' : '' ?>"><?= $over ? '+' . fmtZahl(-$t['uebrig']) : fmtZahl($t['uebrig']) . ' übrig' ?></span>
            <?php endif; ?>
            <small>von <?= fmtZahl($t['ziel']) ?> kcal</small>
        </div>
    </div>

    <?php if ($t['leer']): ?>
    <div class="hx-empty"><i class="bi bi-moon-stars me-1"></i> Keine Einträge an diesem Tag</div>
    <?php else: ?>

    <div class="hx-body">
        <div class="pbar thin"><i class="<?= $over ? 'pb-over' : 'pb-eaten' ?>" style="width:<?= min(100, round($t['kcal'] / max(1, $t['ziel']) * 100)) ?>%;<?= $over ? '' : 'background:var(--accent);' ?>"></i></div>
        <div class="hx-chips">
            <span class="hx-chip">Ziel <b class="num"><?= fmtZahl($t['basisZiel']) ?></b></span>
            <?php if ($t['aktivKcal'] > 0): ?>
            <span class="hx-chip">Aktiv <b class="text-accent num">+<?= fmtZahl($t['aktivKcal']) ?></b></span>
            <?php endif; ?>
            <span class="hx-chip"><?= $t['anzahl'] ?> Einträge</span>
        </div>

        <?php if ($zeigeMakros && $mSum > 0): ?>
        <div class="hx-macro">
            <div class="hx-macro__bar">
                <i class="c-prot bg-c" style="width:<?= round($eK / $mSum * 100, 1) ?>%;"></i>
                <i class="c-fat bg-c"  style="width:<?= round($fK / $mSum * 100, 1) ?>%;"></i>
                <i class="c-carb bg-c" style="width:<?= round($kK / $mSum * 100, 1) ?>%;"></i>
            </div>
            <div class="hx-macro__legend">
                <span><i class="hx-dot c-prot bg-c"></i>Eiweiß <b><?= round($t['eiweiss']) ?> g</b></span>
                <span><i class="hx-dot c-fat bg-c"></i>Fett <b><?= round($t['fett']) ?> g</b></span>
                <span><i class="hx-dot c-carb bg-c"></i>KH <b><?= round($t['kh']) ?> g</b></span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($eintraege[$datum])): ?>
    <details class="hx-entries">
        <summary>
            <i class="bi bi-chevron-right"></i>
            Einträge anzeigen
        </summary>
        <div class="timeline" style="margin:0;">
        <?php foreach ($eintraege[$datum] as $e):
            $cls  = entrySourceClass((int)($e['gericht_id'] ?? 0) ?: null, $e['quelle'] ?? null, $e['produkt_id'] === null ? null : (int)$e['produkt_id']);
            $k100 = $e['kcal'] > 0 && $e['menge_g'] > 0 ? round($e['kcal'] / $e['menge_g'] * 100, 2) : 0;
            $name = htmlspecialchars($e['name'], ENT_QUOTES);
        ?>
        <div class="swipe-entry<?= $cls === 'src-quick' ? ' single-action' : '' ?>" id="entry-<?= $e['id'] ?>">
            <div class="swipe-entry__content"
                 <?php if ($cls !== 'src-quick'): ?>data-edit-id="<?= $e['id'] ?>" data-edit-name="<?= $name ?>"
                 data-edit-menge="<?= (float)$e['menge_g'] ?>" data-edit-kcal100g="<?= $k100 ?>"<?php endif; ?>>
                <span class="tl-time"><?= date('H:i', strtotime($e['erstellt_am'])) ?></span>
                <span class="tl-dot <?= $cls ?>" title="<?= entrySourceLabel($cls) ?>"></span>
                <div class="tl-text">
                    <span class="tl-name"><?= htmlspecialchars($e['name']) ?></span>
                    <span class="tl-meta"><?= $cls === 'src-quick' ? 'Schneller Eintrag' : fmtMenge($e['menge_g']) . ' g' ?></span>
                </div>
                <span class="tl-kcal num"><?= round($e['kcal']) ?></span>
            </div>
            <div class="swipe-entry__actions">
                <?php if ($cls !== 'src-quick'): ?>
                <button class="swipe-action-edit"
                        data-entry-id="<?= $e['id'] ?>"
                        data-entry-name="<?= $name ?>"
                        data-menge-g="<?= (float)$e['menge_g'] ?>"
                        data-kcal100g="<?= $k100 ?>">
                    <i class="bi bi-pencil"></i>Bearbeiten
                </button>
                <?php endif; ?>
                <button class="swipe-action-delete" data-entry-id="<?= $e['id'] ?>">
                    <i class="bi bi-trash3"></i>Löschen
                </button>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </details>
    <?php endif; ?>

    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>

<style>
/* ── Verlauf: Tageskarten ─────────────────────────────────────── */
.hx-day {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin: 0 .85rem .6rem;
    overflow: hidden;
    scroll-margin-top: 5rem;
}
.hx-day--leer { opacity: .55; }
.hx-day:target { border-color: var(--accent); }
.hx-day__head {
    display: flex; align-items: flex-start; justify-content: space-between;
    padding: .85rem 1rem .5rem;
}
.hx-day__date b { font-size: 1rem; font-weight: 700; display: block; }
.hx-day__date span { font-size: .78rem; color: var(--muted); }
.hx-day__kcal { text-align: right; }
.hx-day__kcal b { font-size: 1.25rem; font-weight: 700; }
.hx-day__kcal small { font-size: .72rem; color: var(--muted); display: block; }
.hx-delta {
    display: inline-block; font-size: .72rem; font-weight: 600;
    border-radius: 99px; padding: .12rem .5rem; margin-left: .35rem;
    vertical-align: 3px;
    background: var(--accent-soft); color: var(--accent);
}
.hx-delta.over { background: var(--danger-soft); color: var(--danger); }
.hx-body { padding: 0 1rem .75rem; }
.hx-body .pbar { margin-top: 0; }
.hx-chips { display: flex; gap: .35rem; flex-wrap: wrap; margin-top: .6rem; }
.hx-chip {
    font-size: .75rem; color: var(--muted);
    background: var(--surface2);
    border-radius: 99px; padding: .2rem .6rem;
}
.hx-chip b { color: var(--text); font-weight: 600; }
.hx-macro { margin-top: .65rem; }
.hx-macro__bar { display: flex; height: 6px; border-radius: 99px; overflow: hidden; background: var(--surface2); gap: 2px; }
.hx-macro__bar i { height: 100%; }
.hx-macro__legend { display: flex; gap: .8rem; margin-top: .4rem; font-size: .75rem; color: var(--muted); flex-wrap: wrap; }
.hx-macro__legend b { color: var(--text); font-weight: 600; }
.hx-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; margin-right: .3rem; }
.hx-empty { padding: 0 1rem .85rem; font-size: .82rem; color: var(--muted); }
details.hx-entries summary {
    padding: .7rem 1rem; min-height: 2.75rem;
    font-size: .82rem; color: var(--muted); cursor: pointer;
    list-style: none; display: flex; align-items: center; gap: .45rem;
    border-top: 1px solid var(--border);
}
details.hx-entries summary::-webkit-details-marker { display: none; }
details.hx-entries summary i { font-size: .7rem; transition: transform .2s; }
details.hx-entries[open] summary i { transform: rotate(90deg); }
.hx-entries .swipe-entry__content { background: var(--surface) !important; padding-left: 1rem !important; }
</style>

<!-- Toast -->
<div class="toast-container position-fixed bottom-0 start-50 translate-middle-x mb-5 pb-4">
    <div id="toast" class="toast align-items-center text-bg-dark border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="toastMsg"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<?php if ($hatDaten): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const TAGE     = <?= json_encode($chartTage) ?>;
const GEWICHTE = <?= json_encode($gewichte) ?>;

const css  = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
const C    = { accent: css('--accent'), danger: css('--danger'), text: css('--text'), muted: css('--muted'), border: css('--border'), faint: css('--faint') };
const fmt  = n => Math.round(n).toLocaleString('de-DE');
const dLbl = d => { const [y, m, t] = d.split('-'); return t + '.' + m + '.'; };

Chart.defaults.font.family = css('--font');
Chart.defaults.color = C.muted;

function hexA(hex, a) {
    const h = hex.replace('#', '');
    const n = parseInt(h.length === 3 ? h.split('').map(c => c + c).join('') : h, 16);
    return `rgba(${n >> 16 & 255},${n >> 8 & 255},${n & 255},${a})`;
}

const baseOpts = {
    responsive: true, maintainAspectRatio: false, animation: { duration: 300 },
    interaction: { mode: 'index', intersect: false },
    plugins: { legend: { display: false },
        tooltip: { backgroundColor: css('--surface2'), borderColor: C.border, borderWidth: 1,
                   titleColor: C.text, bodyColor: C.text, padding: 10, displayColors: false } },
    scales: {
        x: { grid: { display: false }, border: { display: false }, ticks: { maxTicksLimit: 5, maxRotation: 0, font: { size: 10 } } },
        y: { grid: { color: C.border }, border: { display: false }, ticks: { maxTicksLimit: 4, font: { size: 10 } } },
    },
};

let kcalChart = null, weightChart = null;

function render(range) {
    const tage = TAGE.slice(-range);
    const lbl  = tage.map(t => dLbl(t.d));
    const over = tage.map(t => t.k !== null && t.k > t.z);
    const ctx  = document.getElementById('kcalChart').getContext('2d');
    const grad = ctx.createLinearGradient(0, 0, 0, 150);
    grad.addColorStop(0, hexA(C.accent, .35)); grad.addColorStop(1, hexA(C.accent, 0));

    const ds = [
        { data: tage.map(t => t.k), borderColor: C.accent, backgroundColor: grad, fill: 'origin',
          borderWidth: 2, tension: .3, spanGaps: true,
          pointRadius: over.map(o => o ? (range > 90 ? 2 : 3.5) : 0), pointBackgroundColor: C.danger, pointBorderWidth: 0,
          label: 'Gegessen' },
        { data: tage.map(t => t.z), borderColor: C.muted, borderDash: [3, 4], borderWidth: 1,
          pointRadius: 0, stepped: true, fill: false, label: 'Ziel' },
    ];
    if (kcalChart) { kcalChart.data.labels = lbl; kcalChart.data.datasets = ds; kcalChart.update(); }
    else kcalChart = new Chart(ctx, { type: 'line', data: { labels: lbl, datasets: ds },
        options: { ...baseOpts, plugins: { ...baseOpts.plugins, tooltip: { ...baseOpts.plugins.tooltip,
            callbacks: { label: c => c.raw === null ? null : `${c.dataset.label}: ${fmt(c.raw)} kcal` } } } } });

    // Kennzahlen für den Zeitraum (nur Tage mit Einträgen)
    const aktiv = tage.filter(t => t.k !== null);
    document.getElementById('stAvg').textContent  = aktiv.length ? fmt(aktiv.reduce((s, t) => s + t.k, 0) / aktiv.length) : '–';
    document.getElementById('stZiel').textContent = aktiv.length ? Math.round(aktiv.filter(t => t.k <= t.z).length / aktiv.length * 100) + '%' : '–';
    document.getElementById('chRange').textContent = tage.length ? dLbl(tage[0].d) + ' – ' + dLbl(tage[tage.length - 1].d) : '';

    // Gewicht
    const wEl = document.getElementById('weightChart');
    if (!wEl) return;
    const von = tage.length ? tage[0].d : '';
    const gw  = GEWICHTE.filter(g => g.d >= von);
    const dEl = document.getElementById('chGewDelta');
    if (gw.length < 2) {
        if (weightChart) { weightChart.data.labels = []; weightChart.data.datasets.forEach(d => d.data = []); weightChart.update(); }
        dEl.textContent = 'zu wenig Messungen';
        return;
    }
    const trend = gw.map(g => g.t);   // Trend über die gesamte Historie (PHP)
    const delta = trend[trend.length - 1] - trend[0];
    dEl.textContent = (delta > 0 ? '+' : '−') + Math.abs(delta).toFixed(1).replace('.', ',') + ' kg';
    dEl.style.color = delta <= 0 ? C.accent : C.muted;
    const wds = [
        { data: gw.map(g => g.kg), showLine: false, pointRadius: 2.5, pointBackgroundColor: C.faint, pointBorderWidth: 0, label: 'Messung' },
        { data: trend.map(v => +v.toFixed(2)), borderColor: C.text, borderWidth: 2.2, pointRadius: 0, tension: .3, label: 'Trend' },
    ];
    const wl = gw.map(g => dLbl(g.d));
    if (weightChart) { weightChart.data.labels = wl; weightChart.data.datasets = wds; weightChart.update(); }
    else weightChart = new Chart(wEl, { type: 'line', data: { labels: wl, datasets: wds },
        options: { ...baseOpts, plugins: { ...baseOpts.plugins, tooltip: { ...baseOpts.plugins.tooltip,
            callbacks: { label: c => `${c.dataset.label}: ${String(c.raw).replace('.', ',')} kg` } } } } });
}

document.querySelectorAll('#rangeSeg button').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('#rangeSeg button').forEach(x => { x.classList.toggle('active', x === b); x.setAttribute('aria-selected', x === b); });
    render(parseInt(b.dataset.range));
}));
render(30);
</script>
<?php endif; ?>

<script>
function showToast(msg) {
    document.getElementById('toastMsg').textContent = msg;
    bootstrap.Toast.getOrCreateInstance(document.getElementById('toast')).show();
}

// Details öffnen aktualisiert Swipe-Bindings für dynamisch sichtbare Einträge.
// initSwipeEntries selbst wird durch app.js beim Laden aufgerufen.
document.querySelectorAll('details').forEach(d => {
    d.addEventListener('toggle', () => { if (d.open) initSwipeEntries(); });
});

// Sprung aus dem Wochenstreifen (#day-YYYY-MM-DD): Einträge direkt aufklappen
if (location.hash.startsWith('#day-')) {
    document.querySelector(location.hash + ' details')?.setAttribute('open', '');
}
</script>

<?php renderFooter('history'); ?>
