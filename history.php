<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/bmr.php';
$currentUser = requireLogin();
$userId = $currentUser['id'];

$db = db();

// Profil + Makro-Setting laden
$stmtPr = $db->prepare("SELECT * FROM profil WHERE user_id = ? LIMIT 1");
$stmtPr->bind_param('i', $userId); $stmtPr->execute();
$profil = $stmtPr->get_result()->fetch_assoc();
$zeigeMakros = (int)($profil['makros_anzeigen'] ?? 1);

// Gewicht pro Tag: für jeden Tag das zuletzt vor/an diesem Datum eingetragene Gewicht
$stmtGew = $db->prepare("
    SELECT e.datum,
           (SELECT kg FROM gewicht
            WHERE user_id = ? AND datum <= e.datum
            ORDER BY datum DESC, id DESC LIMIT 1) AS kg
    FROM (
        SELECT DISTINCT datum FROM eintraege
        WHERE user_id = ?
          AND datum >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
          AND datum < CURDATE()
    ) e
");
$stmtGew->bind_param('ii', $userId, $userId);
$stmtGew->execute();
$gewichtProTag = [];
foreach ($stmtGew->get_result()->fetch_all(MYSQLI_ASSOC) as $g) {
    $gewichtProTag[$g['datum']] = $g['kg'] ? (float)$g['kg'] : null;
}

// Fallback: aktuelles Gewicht für Tage ohne Eintrag in gewicht-Tabelle
$stmtGewFallback = $db->prepare("SELECT kg FROM gewicht WHERE user_id = ? ORDER BY datum DESC, id DESC LIMIT 1");
$stmtGewFallback->bind_param('i', $userId);
$stmtGewFallback->execute();
$gewFallback = (float)($stmtGewFallback->get_result()->fetch_assoc()['kg'] ?? 0) ?: null;

$defizit = (int)($profil['defizit_kcal'] ?? 500);

// Letzte 7 Tage (ohne heute)
$stmt = $db->prepare("
    SELECT datum,
           ROUND(SUM(kcal),1)    AS kcal,
           ROUND(SUM(eiweiss),1) AS eiweiss,
           ROUND(SUM(fett),1)    AS fett,
           ROUND(SUM(kh),1)      AS kh,
           COUNT(*)              AS anzahl
    FROM eintraege
    WHERE datum >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
      AND datum < CURDATE()
      AND user_id = ?
    GROUP BY datum
    ORDER BY datum DESC
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$tage = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Lückenlose 7 Tage: GROUP BY liefert nur Tage MIT Einträgen –
// Tage ohne Erfassung würden sonst fehlen (z.B. 6 statt 7 Tage sichtbar).
$tageMap = array_column($tage, null, 'datum');
$tage = [];
for ($i = 1; $i <= 7; $i++) {
    $d = date('Y-m-d', strtotime("-{$i} day"));
    $tage[] = $tageMap[$d] ?? [
        'datum' => $d, 'kcal' => 0, 'eiweiss' => 0,
        'fett' => 0, 'kh' => 0, 'anzahl' => 0,
    ];
}

// Aktivitätskalorien pro Tag
$aktivMap = [];
if (!empty($tage)) {
    $minDatum = end($tage)['datum'];
    $stmtA = $db->prepare("
        SELECT datum, ROUND(SUM(kcal),1) AS aktiv_kcal
        FROM aktivitaet_log
        WHERE datum >= ? AND datum < CURDATE() AND user_id = ?
        GROUP BY datum
    ");
    $stmtA->bind_param('si', $minDatum, $userId);
    $stmtA->execute();
    foreach ($stmtA->get_result()->fetch_all(MYSQLI_ASSOC) as $a) {
        $aktivMap[$a['datum']] = (float)$a['aktiv_kcal'];
    }
}

// Einträge für jeden Tag (mit Produkt-Join für Icons)
$eintraege = [];
if (!empty($tage)) {
    $minDatum = end($tage)['datum'];
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
}

// ── Vorberechnung aller Tageswerte (für Chart + Cards) ──────────────────
$tageCalc = [];
foreach ($tage as $tag) {
    $datum     = $tag['datum'];
    $aktivKcal = $aktivMap[$datum] ?? 0;
    $kgTag     = $gewichtProTag[$datum] ?? $gewFallback;
    $tdeeTag   = berechneTdee($profil, $kgTag);
    $basisZiel = $tdeeTag ? max(1200, $tdeeTag - $defizit) : TAGESZIEL_KCAL;
    $ziel      = $basisZiel + $aktivKcal;
    $gegessen  = (float)$tag['kcal'];
    $tageCalc[] = [
        'tag'      => $tag,
        'datum'    => $datum,
        'basisZiel'=> $basisZiel,
        'aktivKcal'=> $aktivKcal,
        'ziel'     => $ziel,
        'gegessen' => $gegessen,
        'uebrig'   => $ziel - $gegessen,
        'leer'     => (int)$tag['anzahl'] === 0,
    ];
}

// Wochen-Kennzahlen (nur Tage mit Einträgen)
$aktiveT   = array_filter($tageCalc, fn($t) => !$t['leer']);
$avgKcal   = count($aktiveT) ? array_sum(array_column($aktiveT, 'gegessen')) / count($aktiveT) : 0;
$imZiel    = count(array_filter($aktiveT, fn($t) => $t['uebrig'] >= 0));
$avgBilanz = count($aktiveT) ? array_sum(array_column($aktiveT, 'uebrig')) / count($aktiveT) : 0;
$chartMax  = max(1, max(array_merge(
    array_column($tageCalc, 'gegessen'),
    array_column($tageCalc, 'ziel')
)));

renderHeader('Verlauf', 'history');

function entryIcon(?int $gerichtId, ?string $quelle, ?int $produktId): string {
    if ($gerichtId) {
        $icon  = 'bi-journal-richtext';
        $color = 'var(--accent)';
        $title = 'Gericht';
    } elseif ($produktId === null && $quelle === null) {
        $icon  = 'bi-lightning-fill';
        $color = 'var(--warn)';
        $title = 'Schneller Eintrag';
    } elseif ($quelle === 'manuell' || $quelle === null) {
        $icon  = 'bi-pencil';
        $color = 'var(--muted)';
        $title = 'Manuell erfasst';
    } else {
        $icon  = 'bi-upc-scan';
        $color = '#60a5fa';
        $title = 'Barcode / OpenFoodFacts';
    }
    return "<span title=\"{$title}\" style=\"display:inline-flex;align-items:center;justify-content:center;
        width:2rem;height:2rem;border-radius:50%;background:var(--surface2);flex-shrink:0;\">
        <i class=\"bi {$icon}\" style=\"color:{$color};font-size:.8rem;\"></i>
        </span>";
}
?>

<style>
/* ── Verlauf Redesign ─────────────────────────────────────────── */
.hx-week {
    background: linear-gradient(160deg, var(--surface) 0%, rgba(74,222,128,.045) 100%);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 1rem 1.1rem .9rem;
    margin-bottom: 1rem;
}
.hx-week__stats { display: flex; gap: .4rem; margin-bottom: 1rem; }
.hx-stat {
    flex: 1; text-align: center;
    background: rgba(0,0,0,.18);
    border-radius: 12px; padding: .5rem .25rem;
}
.hx-stat b { display: block; font-size: 1.02rem; font-weight: 800; line-height: 1.2; }
.hx-stat span { font-size: .62rem; color: var(--muted); letter-spacing: .02em; }

.hx-chart {
    display: flex; align-items: flex-end; gap: .45rem;
    height: 86px; padding-top: .25rem;
}
.hx-col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: .3rem; height: 100%; }
.hx-col__track { position: relative; width: 100%; flex: 1; display: flex; align-items: flex-end; }
.hx-col__bar {
    width: 100%; border-radius: 6px 6px 3px 3px;
    min-height: 3px;
    transition: height .5s cubic-bezier(.22,1,.36,1);
}
.hx-col__goal {
    position: absolute; left: -2px; right: -2px; height: 2px;
    background: rgba(255,255,255,.38); border-radius: 2px;
}
.hx-col__lbl { font-size: .6rem; color: var(--muted); font-weight: 600; }

.hx-day {
    position: relative;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    margin: 0 1rem .7rem;
    overflow: hidden;
}
.hx-day::before {
    content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px;
    background: var(--hx-accent, var(--border));
}
.hx-day--leer { opacity: .55; }
.hx-day__head {
    display: flex; align-items: center; justify-content: space-between;
    padding: .8rem 1rem .55rem 1.1rem;
}
.hx-day__date b { font-size: .95rem; font-weight: 800; }
.hx-day__date span { font-size: .7rem; color: var(--muted); margin-left: .4rem; }
.hx-day__kcal { text-align: right; }
.hx-day__kcal b { font-size: 1.05rem; font-weight: 800; }
.hx-day__kcal small { font-size: .62rem; color: var(--muted); display: block; margin-top: -2px; }
.hx-delta {
    display: inline-block; font-size: .66rem; font-weight: 800;
    border-radius: 99px; padding: .12rem .5rem; margin-left: .45rem;
    vertical-align: 2px;
}
.hx-chips {
    display: flex; gap: .35rem; flex-wrap: wrap;
    padding: 0 1rem .6rem 1.1rem;
}
.hx-chip {
    font-size: .68rem; color: var(--muted);
    background: var(--surface2);
    border-radius: 99px; padding: .2rem .6rem;
}
.hx-chip b { color: var(--text); font-weight: 700; }
.hx-macro { padding: 0 1rem .75rem 1.1rem; }
.hx-macro__bar {
    display: flex; height: 7px; border-radius: 99px; overflow: hidden;
    background: var(--surface2);
}
.hx-macro__bar i { height: 100%; }
.hx-macro__legend {
    display: flex; gap: .8rem; margin-top: .35rem;
    font-size: .64rem; color: var(--muted);
}
.hx-macro__legend b { color: var(--text); font-weight: 700; }
.hx-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; margin-right: .25rem; }
.hx-empty {
    padding: .3rem 1rem .8rem 1.1rem;
    font-size: .78rem; color: var(--muted);
}
details.hx-entries summary {
    padding: .55rem 1rem .7rem 1.1rem;
    font-size: .78rem; color: var(--muted); cursor: pointer;
    list-style: none; display: flex; align-items: center; gap: .4rem;
    border-top: 1px solid var(--border);
}
details.hx-entries summary::-webkit-details-marker { display: none; }
</style>

<div class="page-header">
    <h1><i class="bi bi-calendar3 text-accent me-1"></i> Verlauf</h1>
    <span class="date-badge">7 Tage</span>
</div>

<?php if (empty(array_filter($tageCalc, fn($t) => !$t['leer']))): ?>
<div class="empty-state">
    <i class="bi bi-calendar-x"></i>
    <p>Noch keine Einträge vorhanden.</p>
</div>
<?php else: ?>

<!-- ── Tages-Cards ──────────────────────────────────────────────── -->
<?php foreach ($tageCalc as $i => $t):
    $tag       = $t['tag'];
    $datum     = $t['datum'];
    $datumObj  = new DateTime($datum);
    $gestern   = date('Y-m-d', strtotime('-1 day'));
    $wdLang    = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'][(int)date('w', strtotime($datum))];
    $label     = $datum === $gestern ? 'Gestern' : $wdLang;
    $accent    = $t['leer'] ? 'var(--border)' : ($t['uebrig'] >= 0 ? '#4ade80' : '#ef4444');

    // Makro-Anteile nach kcal-Beitrag
    $eK = $tag['eiweiss'] * 4; $fK = $tag['fett'] * 9; $kK = $tag['kh'] * 4;
    $mSum = $eK + $fK + $kK;
?>
<div class="hx-day <?= $t['leer'] ? 'hx-day--leer' : '' ?>" id="day-<?= $datum ?>" style="--hx-accent:<?= $accent ?>;<?= $i === 0 ? 'margin-top:.75rem;' : '' ?>">
    <div class="hx-day__head">
        <div class="hx-day__date">
            <b><?= $label ?></b>
            <span><?= $datumObj->format('d.m.') ?></span>
        </div>
        <div class="hx-day__kcal">
            <b><?= round($t['gegessen']) ?> kcal</b><?php if (!$t['leer']): ?><span class="hx-delta" style="background:<?= $t['uebrig'] >= 0 ? 'rgba(74,222,128,.15)' : 'rgba(239,68,68,.18)' ?>;color:<?= $t['uebrig'] >= 0 ? '#4ade80' : '#f87171' ?>;"><?= $t['uebrig'] >= 0 ? round($t['uebrig']) . ' übrig' : '+' . round(-$t['uebrig']) ?></span><?php endif; ?>
            <small>von <?= round($t['ziel']) ?> kcal</small>
        </div>
    </div>

    <?php if ($t['leer']): ?>
    <div class="hx-empty"><i class="bi bi-moon-stars me-1"></i> Keine Einträge an diesem Tag</div>
    <?php else: ?>

    <div class="hx-chips">
        <span class="hx-chip">Ziel <b><?= round($t['basisZiel']) ?></b></span>
        <?php if ($t['aktivKcal'] > 0): ?>
        <span class="hx-chip" style="color:var(--warn);">Aktiv <b style="color:var(--warn);">+<?= round($t['aktivKcal']) ?></b></span>
        <?php endif; ?>
        <span class="hx-chip"><?= (int)$tag['anzahl'] ?> Einträge</span>
    </div>

    <?php if ($zeigeMakros && $mSum > 0): ?>
    <div class="hx-macro">
        <div class="hx-macro__bar">
            <i style="width:<?= round($eK / $mSum * 100, 1) ?>%;background:#60a5fa;"></i>
            <i style="width:<?= round($fK / $mSum * 100, 1) ?>%;background:#fb923c;"></i>
            <i style="width:<?= round($kK / $mSum * 100, 1) ?>%;background:#a78bfa;"></i>
        </div>
        <div class="hx-macro__legend">
            <span><i class="hx-dot" style="background:#60a5fa;"></i>Eiweiß <b><?= round($tag['eiweiss']) ?>g</b></span>
            <span><i class="hx-dot" style="background:#fb923c;"></i>Fett <b><?= round($tag['fett']) ?>g</b></span>
            <span><i class="hx-dot" style="background:#a78bfa;"></i>KH <b><?= round($tag['kh']) ?>g</b></span>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($eintraege[$datum])): ?>
    <details class="hx-entries">
        <summary>
            <i class="bi bi-chevron-right" style="font-size:.7rem;transition:transform .2s;"></i>
            Einträge anzeigen
        </summary>
        <?php foreach ($eintraege[$datum] as $e): ?>
        <div class="swipe-entry" id="entry-<?= $e['id'] ?>">
            <div class="swipe-entry__content">
                <?= entryIcon((int)($e['gericht_id'] ?? 0) ?: null, $e['quelle'] ?? null, isset($e['produkt_id']) ? ($e['produkt_id'] === null ? null : (int)$e['produkt_id']) : null) ?>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="entry-name text-truncate"><?= htmlspecialchars($e['name']) ?></div>
                    <div class="entry-meta">
                        <?= rtrim(rtrim(number_format($e['menge_g'],1,',',''),'0'),',') ?>g
                        &middot; <?= round($e['kcal']) ?> kcal
                    </div>
                </div>
            </div>
            <div class="swipe-entry__actions">
                <button class="swipe-action-edit"
                        data-entry-id="<?= $e['id'] ?>"
                        data-entry-name="<?= htmlspecialchars($e['name'], ENT_QUOTES) ?>"
                        data-menge-g="<?= (float)$e['menge_g'] ?>"
                        data-kcal100g="<?= $e['kcal'] > 0 && $e['menge_g'] > 0 ? round($e['kcal'] / $e['menge_g'] * 100, 2) : 0 ?>">
                    <i class="bi bi-pencil"></i>Bearbeiten
                </button>
                <button class="swipe-action-delete"
                        data-entry-id="<?= $e['id'] ?>">
                    <i class="bi bi-trash3"></i>Löschen
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </details>
    <?php endif; ?>

    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>

<!-- Toast -->
<div class="toast-container position-fixed bottom-0 start-50 translate-middle-x mb-5 pb-4">
    <div id="toast" class="toast align-items-center text-bg-dark border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="toastMsg"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
// Details chevron
document.querySelectorAll('details').forEach(d => {
    d.addEventListener('toggle', () => {
        const icon = d.querySelector('summary .bi-chevron-right');
        if (icon) icon.style.transform = d.open ? 'rotate(90deg)' : '';
    });
});

function showToast(msg) {
    document.getElementById('toastMsg').textContent = msg;
    bootstrap.Toast.getOrCreateInstance(document.getElementById('toast')).show();
}

// Details öffnen aktualisiert Swipe-Bindings für dynamisch sichtbare Einträge.
// initSwipeEntries selbst wird durch app.js beim Laden aufgerufen.
document.querySelectorAll('details').forEach(d => {
    d.addEventListener('toggle', () => { if (d.open) initSwipeEntries(); });
});
</script>

<?php renderFooter('history'); ?>
