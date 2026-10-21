<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/bmr.php';
require_once __DIR__ . '/includes/ui.php';
$currentUser = requireLogin();
$userId = $currentUser['id'];

$heute  = date('Y-m-d');
$morgen = date('Y-m-d', strtotime('+1 day'));
$db     = db();

// Angezeigter Tag: ?datum=Y-m-d (Tagesauswahl oben, höchstens morgen), sonst heute.
// Die Seite ist für jeden Tag dieselbe „Heute“-Ansicht.
$tag      = gueltigesDatum($_GET['datum'] ?? '') && $_GET['datum'] <= $morgen ? $_GET['datum'] : $heute;
$istHeute = $tag === $heute;

// ─── Query 1: Profil + letztes Gewicht + Tagesziel + Aktivität (1 Query) ─────
$stmt = $db->prepare("
    SELECT
        p.*,
        (SELECT kg   FROM gewicht       WHERE user_id = ? AND datum <= ? ORDER BY datum DESC, id DESC LIMIT 1) AS aktuelles_kg,
        (SELECT kcal_ziel FROM tagesziele WHERE user_id = ? AND datum = ? LIMIT 1)              AS manuelles_ziel,
        (SELECT COALESCE(SUM(kcal),0) FROM aktivitaet_log WHERE user_id = ? AND datum = ?)     AS aktiv_kcal
    FROM (SELECT 1) AS dummy
    LEFT JOIN profil p ON p.user_id = ?
    LIMIT 1
");
$stmt->bind_param('isisisi', $userId, $tag, $userId, $tag, $userId, $tag, $userId);
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

// ─── Query 2: Einträge des Tages ──────────────────────────────────────────────
$stmt = $db->prepare("SELECT e.id, e.name, e.menge_g, e.kcal, e.eiweiss, e.fett, e.kh, e.gericht_id, e.produkt_id, e.erstellt_am, p.quelle FROM eintraege e LEFT JOIN produkte p ON p.id = e.produkt_id WHERE e.datum = ? AND e.user_id = ? ORDER BY e.erstellt_am DESC, e.id DESC");
$stmt->bind_param('si', $tag, $userId);
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

// ─── Tagesauswahl: 7 Tage – normal die letzten 6 + heute; ältere Tage (über den
// Kalender gewählt) stehen etwa in der Mitte, morgen am rechten Rand ──────────
$wocheBis  = $tag > $heute ? $tag
           : ($tag >= date('Y-m-d', strtotime('-6 day')) ? $heute : min($heute, date('Y-m-d', strtotime($tag . ' +3 day'))));
$wocheTage = ladeTageswerte($db, $userId, $profil, date('Y-m-d', strtotime($wocheBis . ' -6 day')), $wocheBis);

// ─── Gewicht: Trend (über die gesamte Historie) der 30 Tage bis zum Tag ───────
$von30    = date('Y-m-d', strtotime($tag . ' -30 day'));
$gew30    = array_values(array_filter(gewichtsTrend($db, $userId), fn($g) => $g['datum'] >= $von30 && $g['datum'] <= $tag));
$gewTrend = array_column($gew30, 'trend');
// y-Bereich wie im Profil-Chart (gewogene Werte + Rand), damit die Kurve
// dieselbe Form hat und kleine Trendschwankungen nicht überzeichnet werden
if ($gew30) {
    $kgMin  = min(array_column($gew30, 'kg'));
    $kgMax  = max(array_column($gew30, 'kg'));
    $kgPad  = max(0.5, ($kgMax - $kgMin) * 0.2);
    $kgMin -= $kgPad; $kgMax += $kgPad;
}

// ─── Aktivitäten des Tages ───────────────────────────────────────────────────
$stmt = $db->prepare("SELECT bezeichnung FROM aktivitaet_log WHERE user_id = ? AND datum = ? ORDER BY erstellt_am");
$stmt->bind_param('is', $userId, $tag);
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

// ─── Morgen: schon vorgeplante Einträge (nur in der Ansicht von heute) ────────
// Ganz normale Einträge mit dem Datum von morgen (Erfassen → „Morgen“). Ziel
// wie bei jedem Tag, nur ohne Aktivität – die kommt erst morgen dazu.
$plan = [];   // Gerichte (alle Zutaten einer Buchung) als eine Zeile
if ($istHeute) {
$stmt = $db->prepare("SELECT e.id, e.name, e.menge_g, e.kcal, e.gericht_id, e.produkt_id, e.erstellt_am, p.quelle FROM eintraege e LEFT JOIN produkte p ON p.id = e.produkt_id WHERE e.datum = ? AND e.user_id = ? ORDER BY e.erstellt_am, e.id");
$stmt->bind_param('si', $morgen, $userId);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $e) {
    $key = $e['gericht_id'] ? 'g' . $e['gericht_id'] . '-' . $e['erstellt_am'] : 'e' . $e['id'];
    $plan[$key][] = $e;
}
}
$planKcal  = array_sum(array_map(fn($g) => array_sum(array_column($g, 'kcal')), $plan));
$planZiel  = $plan ? ladeTageswerte($db, $userId, $profil ?: [], $morgen, $morgen)[$morgen]['basisZiel'] : 0;
$planRest  = $planZiel - $planKcal;

// Überschrift: Heute / Gestern / Morgen, sonst der Wochentag
$gestern = date('Y-m-d', strtotime('-1 day'));
$titel   = $istHeute ? 'Heute' : ($tag === $gestern ? 'Gestern' : ($tag === $morgen ? 'Morgen' : explode(',', datumLang($tag))[0]));
$datumLang = datumLang($tag);

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

/** Uhrzeit in der Zeitleiste – vorgeplante Einträge (gestern angelegt) mit Kalender statt Uhrzeit. */
function zeitZelle(string $ts): string {
    global $tag;
    return date('Y-m-d', strtotime($ts)) < $tag
        ? '<i class="bi bi-calendar-check" title="Vorgeplant" aria-label="Vorgeplant"></i>'
        : uhrzeit($ts);
}
?>

<?php renderPageHeader($titel,
    ($istHeute ? '' : '<a href="/index.php" class="icon-btn" aria-label="Zurück zu heute"><i class="bi bi-arrow-counterclockwise"></i></a>')
  . '<label class="icon-btn date-pick" aria-label="Tag wählen"><i class="bi bi-calendar3"></i>'
  . '<input type="date" id="tagWahl" value="' . $tag . '" max="' . $morgen . '"></label>',
    $datumLang); ?>

<!-- ── Tagesauswahl ────────────────────────────────────────────── -->
<nav class="week-strip" id="weekStrip" aria-label="Tag wählen">
    <?php foreach ($wocheTage as $t):
        $d = $t['datum'];
        // gewählter Tag: Werte der Seite (aktuelles Gewicht wie im Hero)
        [$k, $z] = $d === $tag ? [$sumKcal, $zielMitAktiv] : [$t['kcal'], $t['ziel']];
    ?>
    <a href="<?= $d === $heute ? '/index.php' : '/index.php?datum=' . $d ?>"
       class="<?= $d === $tag ? 'sel' : '' ?><?= $d === $heute ? ' today' : '' ?>"
       <?= $d === $tag ? 'aria-current="date"' : '' ?>
       aria-label="<?= datumLang($d) ?>: <?= fmtZahl($k) ?> von <?= fmtZahl($z) ?> kcal">
        <?= miniRing($z > 0 ? $k / $z : 0, $k > $z) ?>
        <?= wochentagKurz($d) ?>
    </a>
    <?php endforeach; ?>
</nav>

<!-- ── Hero: übrig ─────────────────────────────────────────────── -->
<section class="hero" id="kcalRingWrap" data-aktiv-kcal="<?= $aktivKcal ?>" data-datum="<?= $tag ?>">
    <div class="big-label"><?= $rest < 0 ? 'Über dem Ziel' : ($tag < $heute ? 'Übrig' : 'Noch übrig') ?></div>
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
        <div class="tile__sub text-truncate"><?= $aktivNamen ? htmlspecialchars(implode(' · ', $aktivNamen)) : ($istHeute ? 'Noch keine heute' : 'Keine') ?></div>
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
            <polyline points="<?= sparklinePoints($gewTrend, 120, 40, 3, $kgMin, $kgMax) ?>" fill="none" style="stroke:var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
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
    <?php if ($istHeute): ?>
    <p>Noch nichts geloggt.</p>
    <div class="btn-row">
        <button type="button" class="scan-btn" onclick="zxingStart()"><i class="bi bi-upc-scan"></i>Scannen</button>
        <a href="/log.php?focus=search" class="scan-btn secondary"><i class="bi bi-search"></i>Suchen</a>
    </div>
    <?php else: ?>
    <p><?= $tag > $heute ? 'Für morgen noch nichts geplant.' : 'An diesem Tag nichts geloggt.' ?></p>
    <?php endif; ?>
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
            <span class="tl-time"><?= zeitZelle($item['zeit']) ?></span>
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
            <span class="tl-time"><?= zeitZelle($item['zeit']) ?></span>
            <span class="tl-dot <?= $cls ?>"></span>
            <div class="tl-text"><span class="tl-name"><?= htmlspecialchars($g['name']) ?></span><span class="tl-meta"><?= fmtMenge($g['menge_g']) ?> g · <?= count($g['sub']) ?>×</span></div>
            <span class="tl-kcal num"><?= round($g['kcal']) ?></span>
            <i class="bi bi-chevron-down tl-chev" id="chevron-<?= $idx ?>"></i>
        </div>
        <div class="tl-sub" id="subs-<?= $idx ?>" style="display:none;">
            <?php foreach ($g['sub'] as $e): ?>
            <div class="swipe-entry" id="entry-<?= $e['id'] ?>">
                <div class="swipe-entry__content" <?= editAttrs($e) ?>>
                    <div class="tl-text"><span class="tl-name"><?= fmtMenge($e['menge_g']) ?> g</span><span class="tl-meta"><?= zeitZelle($e['erstellt_am']) ?></span></div>
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
            <span class="tl-time"><?= zeitZelle($e['erstellt_am']) ?></span>
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

<!-- ── Anderer Tag: nachtragen bzw. für morgen eintragen ─────────── -->
<?php if (!$istHeute): ?>
<a href="<?= erfassenLink($tag) ?>" class="plan-cta">
    <i class="bi <?= $tag > $heute ? 'bi-calendar-plus' : 'bi-clock-history' ?>"></i>
    <span><b><?= $tag > $heute ? 'Für morgen eintragen' : 'Nachtragen' ?></b><small>Einträge für <?= $datumLang ?></small></span>
    <i class="bi bi-chevron-right"></i>
</a>

<!-- ── Morgen: vorgeplante Einträge ────────────────────────────── -->
<?php elseif (!$plan): ?>
<a href="/log.php?tag=morgen" class="plan-cta" id="morgen">
    <i class="bi bi-calendar-plus"></i>
    <span><b>Morgen vorplanen</b><small>Schon heute für <?= datumLang($morgen) ?> eintragen</small></span>
    <i class="bi bi-chevron-right"></i>
</a>
<?php else:
    $pTotal = max(1, $planZiel, $planKcal);
?>
<section class="plan-card" id="morgen">
    <div class="plan-head">
        <div>
            <span class="plan-over">Morgen · <?= wochentagKurz($morgen) ?> <?= date('j.', strtotime($morgen)) ?></span>
            <b class="num"><?= fmtZahl($planKcal) ?> <small>kcal geplant</small></b>
        </div>
        <a href="/log.php?tag=morgen" class="icon-btn sm" aria-label="Weitere für morgen eintragen"><i class="bi bi-plus-lg"></i></a>
    </div>
    <div class="pbar" role="img" aria-label="<?= fmtZahl($planKcal) ?> von <?= fmtZahl($planZiel) ?> kcal geplant">
        <i class="pb-plan" style="width:<?= round(min($planKcal, $planZiel) / $pTotal * 100, 1) ?>%"></i>
        <?php if ($planKcal > $planZiel): ?><i class="pb-over" style="width:<?= round(($planKcal - $planZiel) / $pTotal * 100, 1) ?>%"></i><?php endif; ?>
    </div>
    <div class="pleg">
        <span><?= $planRest >= 0 ? 'noch <b class="num">' . fmtZahl($planRest) . '</b> frei' : '<b class="num over">' . fmtZahl(-$planRest) . '</b> über dem Ziel' ?></span>
        <span>Ziel <b class="num"><?= fmtZahl($planZiel) ?></b> + Aktivität</span>
    </div>
    <div class="plan-list">
    <?php foreach ($plan as $g):
        $e     = $g[0];
        $ids   = implode(',', array_column($g, 'id'));
        $dish  = (bool)$e['gericht_id'];
        $cls   = $dish ? 'src-dish' : entrySourceClass(null, $e['quelle'] ?? null, $e['produkt_id'] === null ? null : (int)$e['produkt_id']);
        $name  = $dish ? preg_replace('/:.*$/', '', $e['name']) : $e['name'];
        $meta  = $dish ? 'Gericht · ' . count($g) . ' Zutaten' : ($cls === 'src-quick' ? 'Schneller Eintrag' : fmtMenge($e['menge_g']) . ' g');
        $edit  = !$dish && $cls !== 'src-quick';
    ?>
        <div class="swipe-entry<?= $edit ? '' : ' single-action' ?>" id="entry-<?= $e['id'] ?>">
            <div class="swipe-entry__content" <?= $edit ? editAttrs($e) : '' ?>>
                <span class="tl-dot <?= $cls ?>"></span>
                <div class="tl-text"><span class="tl-name"><?= htmlspecialchars($name) ?></span><span class="tl-meta"><?= $meta ?></span></div>
                <span class="tl-kcal num"><?= round(array_sum(array_column($g, 'kcal'))) ?></span>
            </div>
            <div class="swipe-entry__actions">
                <?php if ($edit): ?>
                <button class="swipe-action-edit" data-entry-id="<?= $e['id'] ?>" data-entry-name="<?= htmlspecialchars($e['name'], ENT_QUOTES) ?>" data-menge-g="<?= (float)$e['menge_g'] ?>" data-kcal100g="<?= $e['menge_g'] > 0 ? round($e['kcal'] / $e['menge_g'] * 100, 2) : 0 ?>"><i class="bi bi-pencil"></i>Bearbeiten</button>
                <?php endif; ?>
                <button class="swipe-action-delete" data-entry-ids="<?= $ids ?>"><i class="bi bi-trash3"></i>Löschen</button>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</section>
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
// Kalender in der Kopfzeile: beliebigen Tag wählen (natives Datumsfeld liegt
// unsichtbar über dem Knopf, damit iOS den eigenen Datumswähler öffnet)
document.getElementById('tagWahl').addEventListener('change', e => {
    const d = e.target.value;
    if (d) location.href = d === heuteISO() ? '/index.php' : '/index.php?datum=' + d;
});

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
