<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/bmr.php';
require_once __DIR__ . '/includes/ui.php';
$currentUser = requireLogin();
$userId = $currentUser['id'];

$heute = date('Y-m-d');
$db    = db();

// ─── Query 1: Profil + letztes Gewicht + Tagesziel + Aktivität (1 Query) ─────
$stmt = $db->prepare("
    SELECT
        p.*,
        (SELECT kg   FROM gewicht       WHERE user_id = ? ORDER BY datum DESC, id DESC LIMIT 1) AS aktuelles_kg,
        (SELECT kcal_ziel FROM tagesziele WHERE user_id = ? AND datum = ? LIMIT 1)              AS manuelles_ziel,
        (SELECT COALESCE(SUM(kcal),0) FROM aktivitaet_log WHERE user_id = ? AND datum = ?)     AS aktiv_kcal
    FROM (SELECT 1) AS dummy
    LEFT JOIN profil p ON p.user_id = ?
    LIMIT 1
");
$stmt->bind_param('iisisi', $userId, $userId, $heute, $userId, $heute, $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

// LEFT JOIN: Gewicht, Tagesziel und Aktivität kommen auch ohne Profil-Eintrag
// (neuer User); p.id ist dann NULL → Profil als leer behandeln.
$profil           = $row['id'] !== null ? $row : [];
$aktuellesGewicht = $row['aktuelles_kg'] !== null ? (float)$row['aktuelles_kg'] : null;
$manuellesZiel    = $row['manuelles_ziel'] !== null ? (int)$row['manuelles_ziel'] : null;
$aktivKcal        = (int)$row['aktiv_kcal'];

// ─── Grundumsatz & Ziel ───────────────────────────────────────────────────────
$grundumsatzKcal = berechneTdee($profil, $aktuellesGewicht);
$defizit         = (int)($profil['defizit_kcal'] ?? 500);

if ($grundumsatzKcal && !$manuellesZiel) {
    $ziel = max(1200, $grundumsatzKcal - $defizit);
} else {
    $ziel = $manuellesZiel ?? TAGESZIEL_KCAL;
}
$zielMitAktiv = $ziel + $aktivKcal;

// ─── Query 2: Einträge heute ─────────────────────────────────────────────────
$stmt = $db->prepare("SELECT e.id, e.name, e.menge_g, e.kcal, e.eiweiss, e.fett, e.kh, e.gericht_id, e.produkt_id, e.erstellt_am, p.quelle FROM eintraege e LEFT JOIN produkte p ON p.id = e.produkt_id WHERE e.datum = ? AND e.user_id = ? ORDER BY e.erstellt_am DESC, e.id DESC");
$stmt->bind_param('si', $heute, $userId);
$stmt->execute();
$eintraege = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Gerichte immer als Gruppe + Einzeleinträge ggf. nach Name gruppieren
$gruppieren = !empty($profil['eintraege_gruppieren']);

$gerichtGruppen  = [];
$einzelEintraege = [];
foreach ($eintraege as $e) {
    if ($e['gericht_id']) $gerichtGruppen[$e['gericht_id']][] = $e;
    else $einzelEintraege[] = $e;
}

// Einzeleinträge aufbereiten
if ($gruppieren) {
    $grouped = [];
    foreach ($einzelEintraege as $e) {
        $key = strtolower(trim($e['name']));
        if (!isset($grouped[$key])) {
            $grouped[$key] = array_merge($e, ['sub' => [$e]]);
        } else {
            foreach (['menge_g','kcal','eiweiss','fett','kh'] as $col)
                $grouped[$key][$col] += $e[$col];
            $grouped[$key]['sub'][] = $e;
        }
    }
    $anzeige = array_values($grouped);
} else {
    $anzeige = array_map(fn($e) => array_merge($e, ['sub' => [$e]]), $einzelEintraege);
}

// ─── Summen ──────────────────────────────────────────────────────────────────
$sumKcal    = array_sum(array_column($eintraege, 'kcal'));
$sumEiweiss = array_sum(array_column($eintraege, 'eiweiss'));
$sumFett    = array_sum(array_column($eintraege, 'fett'));
$sumKh      = array_sum(array_column($eintraege, 'kh'));
$rest       = $zielMitAktiv - $sumKcal;
$prozent    = min(100, round(($sumKcal / max(1, $zielMitAktiv)) * 100));
$ringClass  = $prozent >= 100 ? 'danger' : ($prozent >= 85 ? 'warn' : '');

// Makroziele nach DGE
$makros = berechneMakroziele($profil, $aktuellesGewicht, $zielMitAktiv);
$zielEiweiss = $makros['eiweiss'];
$zielFett    = $makros['fett'];
$zielKh      = $makros['kh'];
$zeigeMakros = (int)($profil['makros_anzeigen'] ?? 1);

// ─── Wochenstreifen: die letzten 6 Tage + heute ──────────────────────────────
$wocheVon   = date('Y-m-d', strtotime('-6 day'));
$wocheTage  = ladeTageswerte($db, $userId, $profil, $wocheVon, date('Y-m-d', strtotime('-1 day')));

// ─── Gewicht: letzte 30 Tage für Trend-Kachel ────────────────────────────────
$stmt = $db->prepare("SELECT kg FROM gewicht WHERE user_id = ? AND datum >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) ORDER BY datum, id");
$stmt->bind_param('i', $userId);
$stmt->execute();
$gewichte30 = array_map(fn($r) => (float)$r['kg'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
$gewTrend   = ewma($gewichte30);

// ─── Aktivitäten heute ───────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT bezeichnung FROM aktivitaet_log WHERE user_id = ? AND datum = ? ORDER BY erstellt_am");
$stmt->bind_param('is', $userId, $heute);
$stmt->execute();
$aktivNamen = array_unique(array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'bezeichnung'));

// ─── Hero-Balken: gegessen / Rest / Aktiv-Bonus / drüber ──────────────────────
$basisZiel = $zielMitAktiv - $aktivKcal;
$pbTotal   = max(1, $zielMitAktiv, $sumKcal);
$pbEaten   = min($sumKcal, $zielMitAktiv) / $pbTotal * 100;
$pbRest    = max(0, $basisZiel - $sumKcal) / $pbTotal * 100;
$pbBonus   = max(0, $zielMitAktiv - max($sumKcal, $basisZiel)) / $pbTotal * 100;
$pbOver    = max(0, $sumKcal - $zielMitAktiv) / $pbTotal * 100;

// ─── Zeitleiste: Gerichte, gruppierte und einzelne Einträge nach Uhrzeit ──────
$zeitleiste = [];
foreach ($gerichtGruppen as $gid => $gE) {
    $zeitleiste[] = ['typ' => 'gericht', 'id' => $gid, 'eintraege' => $gE, 'zeit' => max(array_column($gE, 'erstellt_am'))];
}
foreach ($anzeige as $idx => $g) {
    $mehrfach = $gruppieren && count($g['sub']) > 1;
    $zeitleiste[] = ['typ' => $mehrfach ? 'gruppe' : 'einzel', 'id' => $idx, 'g' => $g,
                     'zeit' => max(array_column($g['sub'], 'erstellt_am'))];
}
usort($zeitleiste, fn($a, $b) => strcmp($b['zeit'], $a['zeit']));

$wtLang   = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'][(int)date('w')];
$monate   = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
$datumLang = $wtLang . ', ' . date('j') . '. ' . $monate[(int)date('n') - 1];

renderHeader('Heute', 'home');

/** data-Attribute für „Tipp öffnet Bearbeiten“ an einer Zeitleisten-Zeile. */
function editAttrs(array $e): string {
    $k100 = $e['kcal'] > 0 && $e['menge_g'] > 0 ? round($e['kcal'] / $e['menge_g'] * 100, 2) : 0;
    return sprintf('data-edit-id="%d" data-edit-name="%s" data-edit-menge="%s" data-edit-kcal100g="%s"',
        $e['id'], htmlspecialchars($e['name'], ENT_QUOTES), (float)$e['menge_g'], $k100);
}

/** Swipe-Aktionen Bearbeiten + Löschen (Markup wie bisher, für app.js). */
function swipeActions(array $e, bool $mitEdit = true): string {
    $id   = (int)$e['id'];
    $html = '<div class="swipe-entry__actions">';
    if ($mitEdit) {
        $k100 = $e['kcal'] > 0 && $e['menge_g'] > 0 ? round($e['kcal'] / $e['menge_g'] * 100, 2) : 0;
        $html .= sprintf('<button class="swipe-action-edit" data-entry-id="%d" data-entry-name="%s" data-menge-g="%s" data-kcal100g="%s"><i class="bi bi-pencil"></i>Bearbeiten</button>',
            $id, htmlspecialchars($e['name'], ENT_QUOTES), (float)$e['menge_g'], $k100);
    }
    $html .= sprintf('<button class="swipe-action-delete" data-entry-id="%d"><i class="bi bi-trash3"></i>Löschen</button>', $id);
    return $html . '</div>';
}

function uhrzeit(string $ts): string { return date('H:i', strtotime($ts)); }
?>

<?php renderPageHeader('Heute',
    '<a href="/history.php" class="icon-btn" aria-label="Verlauf"><i class="bi bi-calendar3"></i></a>',
    $datumLang); ?>

<!-- ── Wochenstreifen ──────────────────────────────────────────── -->
<nav class="week-strip" id="weekStrip" aria-label="Letzte 7 Tage">
    <?php foreach ($wocheTage as $t): ?>
    <a href="/history.php#day-<?= $t['datum'] ?>" aria-label="<?= wochentagKurz($t['datum']) ?>: <?= fmtZahl($t['kcal']) ?> von <?= fmtZahl($t['ziel']) ?> kcal">
        <?= miniRing($t['ziel'] > 0 ? $t['kcal'] / $t['ziel'] : 0, !$t['leer'] && $t['uebrig'] < 0) ?>
        <?= wochentagKurz($t['datum']) ?>
    </a>
    <?php endforeach; ?>
    <div class="today" aria-label="Heute">
        <?= miniRing($zielMitAktiv > 0 ? $sumKcal / $zielMitAktiv : 0, $rest < 0) ?>
        <?= wochentagKurz($heute) ?>
    </div>
</nav>

<!-- ── Hero: übrig ─────────────────────────────────────────────── -->
<section class="hero" id="kcalRingWrap" data-aktiv-kcal="<?= $aktivKcal ?>">
    <div class="big-label"><?= $rest >= 0 ? 'Noch übrig' : 'Über dem Ziel' ?></div>
    <div class="big-num<?= $rest < 0 ? ' over' : '' ?>"><?= fmtZahl(abs($rest)) ?><span>kcal</span></div>
    <div class="pbar" role="img" aria-label="<?= fmtZahl($sumKcal) ?> von <?= fmtZahl($zielMitAktiv) ?> kcal gegessen">
        <i class="pb-eaten" style="width:<?= round($pbEaten, 1) ?>%"></i>
        <?php if ($pbRest > 0): ?><i class="pb-rest" style="width:<?= round($pbRest, 1) ?>%"></i><?php endif; ?>
        <?php if ($pbBonus > 0): ?><i class="pb-bonus" style="width:<?= round($pbBonus, 1) ?>%"></i><?php endif; ?>
        <?php if ($pbOver > 0): ?><i class="pb-over" style="width:<?= round($pbOver, 1) ?>%"></i><?php endif; ?>
    </div>
    <div class="pleg">
        <span><b class="num"><?= fmtZahl($sumKcal) ?></b> gegessen</span>
        <span>Ziel <b class="num"><?= fmtZahl($basisZiel) ?></b><?php if ($aktivKcal > 0): ?> + <b class="acc num"><?= fmtZahl($aktivKcal) ?></b> aktiv<?php endif; ?></span>
    </div>
</section>

<!-- ── Bento: Makros, Aktivität, Gewicht ───────────────────────── -->
<section class="bento" id="makroCard">
    <?php if ($zeigeMakros && $zielMitAktiv > 0):
        $makroItems = [
            ['label' => 'Eiweiß',        'ist' => $sumEiweiss, 'ziel' => $zielEiweiss, 'c' => 'c-prot'],
            ['label' => 'Kohlenhydrate', 'ist' => $sumKh,      'ziel' => $zielKh,      'c' => 'c-carb'],
            ['label' => 'Fett',          'ist' => $sumFett,    'ziel' => $zielFett,    'c' => 'c-fat'],
        ];
        foreach ($makroItems as $i => $m):
            $pct  = $m['ziel'] > 0 ? round($m['ist'] / $m['ziel'] * 100) : 0;
            $over = $m['ist'] > $m['ziel'];
    ?>
    <div class="tile <?= $over ? 'c-danger' : $m['c'] ?>">
        <div class="tile__label"><span><?= $m['label'] ?></span><span class="num"><?= $pct ?>%</span>
            <?php if ($i === 0): ?>
            <span class="kt-info-card-wrap" style="position:static;"><button class="kt-info-btn" type="button" aria-label="Info zu Makrozielen"><i class="bi bi-info-circle"></i></button><span class="kt-tooltip" style="left:0;right:auto;">Ziele nach <strong>DGE-Empfehlung</strong>: Eiweiß 0,8&nbsp;g/kg&nbsp;KG · Fett max. 30% · Kohlenhydrate &gt;50% der Energie. <a href="https://www.dge.de/wissenschaft/referenzwerte/" target="_blank" rel="noopener">dge.de →</a></span></span>
            <?php endif; ?>
        </div>
        <span class="tile__val<?= $over ? ' fg-c' : '' ?>"><?= round($m['ist']) ?><small>/<?= $m['ziel'] ?> g</small></span>
        <div class="tile__bar"><i class="bg-c" style="width:<?= min(100, $pct) ?>%"></i></div>
    </div>
    <?php endforeach; endif; ?>

    <a href="/profil.php#aktivitaet" class="tile tile--link">
        <div class="tile__label"><span>Aktivität</span><i class="bi bi-fire text-accent"></i></div>
        <span class="tile__val"><?= fmtZahl($aktivKcal) ?><small> kcal</small></span>
        <div class="tile__sub text-truncate"><?= $aktivNamen ? htmlspecialchars(implode(' · ', $aktivNamen)) : 'Noch keine heute' ?></div>
    </a>

    <?php if ($gewTrend):
        $gTrendAkt = end($gewTrend);
        $gDelta    = count($gewTrend) > 1 ? $gTrendAkt - $gewTrend[0] : 0;
    ?>
    <a href="/profil.php" class="tile tile--wide tile--link" style="display:flex;align-items:center;gap:.75rem;">
        <div style="flex:1;min-width:0;">
            <div class="tile__label"><span>Gewicht · 30 Tage</span></div>
            <span class="tile__val"><?= number_format($gTrendAkt, 1, ',', '') ?><small> kg</small>
                <?php if (abs($gDelta) >= 0.05): ?>
                <small class="<?= $gDelta < 0 ? 'text-accent' : '' ?>" style="font-weight:600;"><?= $gDelta < 0 ? '▼' : '▲' ?> <?= number_format(abs($gDelta), 1, ',', '') ?></small>
                <?php endif; ?>
            </span>
        </div>
        <?php if (count($gewTrend) > 1): ?>
        <svg width="120" height="40" viewBox="0 0 120 40" aria-hidden="true" style="flex-shrink:0;">
            <polyline points="<?= sparklinePoints($gewTrend, 120, 40) ?>" fill="none" style="stroke:var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <?php endif; ?>
    </a>
    <?php elseif (!$zeigeMakros): ?>
    <a href="/profil.php" class="tile tile--link">
        <div class="tile__label"><span>Gewicht</span></div>
        <span class="tile__val">–</span>
        <div class="tile__sub">Jetzt eintragen</div>
    </a>
    <?php endif; ?>
</section>

<!-- ── Einträge als Zeitleiste ─────────────────────────────────── -->
<?php $hatEintraege = !empty($zeitleiste); ?>
<?php if (!$hatEintraege): ?>
<div class="empty-state">
    <i class="bi bi-egg-fried"></i>
    <p>Noch nichts geloggt.</p>
    <div class="btn-row">
        <button type="button" class="scan-btn" onclick="zxingStart()"><i class="bi bi-upc-scan"></i>Scannen</button>
        <a href="/log.php?focus=search" class="scan-btn secondary"><i class="bi bi-search"></i>Suchen</a>
    </div>
</div>
<?php else: ?>
<div class="section-label"><span>Einträge</span><b class="num"><?= count($zeitleiste) ?> · <?= fmtZahl($sumKcal) ?> kcal</b></div>
<div class="timeline">
<?php foreach ($zeitleiste as $item): ?>

    <?php if ($item['typ'] === 'gericht'):
        $gE   = $item['eintraege'];
        $gid  = $item['id'];
        $name = preg_replace('/:.*$/', '', $gE[0]['name']); // Name vor dem ":"
    ?>
    <div class="group-entry" id="gericht-group-<?= $gid ?>">
        <div class="tl-item" onclick="toggleGroup('g<?= $gid ?>')" role="button" aria-expanded="false" aria-controls="subs-g<?= $gid ?>">
            <span class="tl-time"><?= uhrzeit($item['zeit']) ?></span>
            <span class="tl-dot src-dish"></span>
            <div class="tl-text"><span class="tl-name"><?= htmlspecialchars($name) ?></span><span class="tl-meta">Gericht · <?= count($gE) ?> Zutaten</span></div>
            <span class="tl-kcal num"><?= round(array_sum(array_column($gE, 'kcal'))) ?></span>
            <i class="bi bi-chevron-down tl-chev" id="chevron-g<?= $gid ?>"></i>
        </div>
        <div class="tl-sub" id="subs-g<?= $gid ?>" style="display:none;">
            <?php foreach ($gE as $e): ?>
            <div class="swipe-entry single-action" id="entry-<?= $e['id'] ?>">
                <div class="swipe-entry__content">
                    <div class="tl-text"><span class="tl-name"><?= htmlspecialchars(preg_replace('/^[^:]+:\s*/', '', $e['name'])) ?></span><span class="tl-meta"><?= fmtMenge($e['menge_g']) ?> g</span></div>
                    <span class="tl-kcal num"><?= round($e['kcal']) ?></span>
                </div>
                <?= swipeActions($e, false) ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php elseif ($item['typ'] === 'gruppe'):
        $g = $item['g']; $idx = $item['id'];
        $cls = entrySourceClass(null, $g['quelle'] ?? null, $g['produkt_id'] === null ? null : (int)$g['produkt_id']);
    ?>
    <div class="group-entry" id="group-<?= $idx ?>">
        <div class="tl-item" onclick="toggleGroup(<?= $idx ?>)" role="button" aria-expanded="false" aria-controls="subs-<?= $idx ?>">
            <span class="tl-time"><?= uhrzeit($item['zeit']) ?></span>
            <span class="tl-dot <?= $cls ?>"></span>
            <div class="tl-text"><span class="tl-name"><?= htmlspecialchars($g['name']) ?></span><span class="tl-meta"><?= fmtMenge($g['menge_g']) ?> g · <?= count($g['sub']) ?>×</span></div>
            <span class="tl-kcal num"><?= round($g['kcal']) ?></span>
            <i class="bi bi-chevron-down tl-chev" id="chevron-<?= $idx ?>"></i>
        </div>
        <div class="tl-sub" id="subs-<?= $idx ?>" style="display:none;">
            <?php foreach ($g['sub'] as $e): ?>
            <div class="swipe-entry" id="entry-<?= $e['id'] ?>">
                <div class="swipe-entry__content" <?= editAttrs($e) ?>>
                    <div class="tl-text"><span class="tl-name"><?= fmtMenge($e['menge_g']) ?> g</span><span class="tl-meta"><?= uhrzeit($e['erstellt_am']) ?></span></div>
                    <span class="tl-kcal num"><?= round($e['kcal']) ?></span>
                </div>
                <?= swipeActions($e) ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php else:
        $e   = $item['g']['sub'][0];
        $cls = entrySourceClass(null, $e['quelle'] ?? null, $e['produkt_id'] === null ? null : (int)$e['produkt_id']);
        $quick = $cls === 'src-quick';
        $meta  = $quick ? 'Schneller Eintrag' : fmtMenge($e['menge_g']) . ' g';
        // Schnelle Einträge sind als „1 g = Gesamt-kcal“ gespeichert – eine
        // Mengen-Bearbeitung in Gramm ergibt dort keinen Sinn, nur Löschen.
    ?>
    <div class="swipe-entry<?= $quick ? ' single-action' : '' ?>" id="entry-<?= $e['id'] ?>">
        <div class="swipe-entry__content" <?= $quick ? '' : editAttrs($e) ?>>
            <span class="tl-time"><?= uhrzeit($e['erstellt_am']) ?></span>
            <span class="tl-dot <?= $cls ?>" title="<?= entrySourceLabel($cls) ?>"></span>
            <div class="tl-text"><span class="tl-name"><?= htmlspecialchars($e['name']) ?></span><span class="tl-meta"><?= $meta ?></span></div>
            <span class="tl-kcal num"><?= round($e['kcal']) ?></span>
        </div>
        <?= swipeActions($e, !$quick) ?>
    </div>
    <?php endif; ?>

<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Toast ─────────────────────────────────────────────────────── -->
<div class="toast-container position-fixed bottom-0 start-50 translate-middle-x mb-5 pb-4">
    <div id="toast" class="toast align-items-center text-bg-dark border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="toastMsg"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
function toggleGroup(idx) {
    const subs    = document.getElementById('subs-' + idx);
    const chevron = document.getElementById('chevron-' + idx);
    const open    = subs.style.display === 'none';
    subs.style.display = open ? 'block' : 'none';
    chevron.style.transform = open ? 'rotate(180deg)' : '';
    subs.previousElementSibling?.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) initSwipeEntries(); // neu sichtbare Sub-Einträge binden
}
</script>

<?php renderFooter('home'); ?>
