<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/bmr.php';
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
    FROM profil p
    WHERE p.user_id = ?
    LIMIT 1
");
$stmt->bind_param('iisssi', $userId, $userId, $heute, $userId, $heute, $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

// Fallback wenn noch kein Profil-Eintrag vorhanden (neuer User)
$profil           = $row ?? [];
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
$stmt = $db->prepare("SELECT e.id, e.name, e.menge_g, e.kcal, e.eiweiss, e.fett, e.kh, e.gericht_id, e.produkt_id, p.quelle FROM eintraege e LEFT JOIN produkte p ON p.id = e.produkt_id WHERE e.datum = ? AND e.user_id = ? ORDER BY e.gericht_id, e.erstellt_am");
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

renderHeader('Heute', 'home');

// Farbiges Kaloriendichte-Icon für Einträge
function entryIcon(?int $gerichtId, ?string $quelle, ?int $produktId): string {
    if ($gerichtId) {
        $icon  = 'bi-journal-richtext';
        $color = 'var(--accent)';
        $title = 'Gericht';
    } elseif ($produktId === null && $quelle === null) {
        // Schneller Eintrag: kein Produkt, keine Quelle
        $icon  = 'bi-lightning-fill';
        $color = 'var(--warn)';
        $title = 'Schneller Eintrag';
    } elseif ($quelle === 'manuell' || $quelle === null) {
        $icon  = 'bi-pencil';
        $color = 'var(--muted)';
        $title = 'Manuell erfasst';
    } else {
        // openfoodfacts, vision, api etc.
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

<div class="page-header">
    <h1><i class="bi bi-lightning-fill text-accent me-1"></i> Heute</h1>
    <div style="display:flex;align-items:center;gap:.5rem;">
        <span class="date-badge"><?= date('d.m.Y') ?></span>
        <a href="profil.php" style="color:var(--muted);font-size:1.2rem;line-height:1;">
            <i class="bi bi-person-circle"></i>
        </a>
    </div>
</div>

<!-- ── Kalorienzähler ──────────────────────────────────────────── -->
<div class="kcal-ring-wrap" id="kcalRingWrap" data-aktiv-kcal="<?= $aktivKcal ?>">
    <div class="kcal-ring">
        <svg viewBox="0 0 96 96" width="96" height="96">
            <circle class="kcal-ring__track" cx="48" cy="48" r="39"/>
            <circle class="kcal-ring__bar <?= $ringClass ?>" cx="48" cy="48" r="39"
                stroke-dasharray="<?= round(2*M_PI*39, 2) ?>"
                stroke-dashoffset="<?= round((1 - $prozent/100) * 2*M_PI*39, 2) ?>"/>
        </svg>
        <div class="kcal-ring__center">
            <span class="kcal-ring__num"><?= round($sumKcal) ?></span>
            <span class="kcal-ring__unit">kcal</span>
        </div>
    </div>

    <div class="kcal-stats">
        <div class="kcal-stat-row">
            <span class="kcal-stat-label"><i class="bi bi-bullseye me-1" style="color:var(--muted);font-size:.75rem;"></i>TDEE</span>
            <span class="kcal-stat-val"><?= $zielMitAktiv ?> kcal</span>
        </div>
        <div class="kcal-stat-row">
            <span class="kcal-stat-label"><i class="bi bi-check-circle me-1" style="color:var(--muted);font-size:.75rem;"></i>Gegessen</span>
            <span class="kcal-stat-val"><?= round($sumKcal) ?> kcal</span>
        </div>
        <div class="kcal-stat-row">
            <span class="kcal-stat-label"><i class="bi bi-hourglass-split me-1" style="color:var(--muted);font-size:.75rem;"></i><?= $rest >= 0 ? 'Übrig' : 'Überschritten' ?></span>
            <span class="kcal-remaining <?= $ringClass ?>"><?= abs(round($rest)) ?> kcal</span>
        </div>
        <?php if ($aktivKcal > 0): ?>
        <div class="kcal-stat-row">
            <span class="aktiv-badge"><i class="bi bi-fire"></i> +<?= $aktivKcal ?> Aktiv</span>
        </div>
        <?php else: ?>

        <?php endif; ?>
    </div>
</div>

<!-- ── Makro-Balken ────────────────────────────────────────────── -->
<?php if ($zeigeMakros && $zielMitAktiv > 0): ?>
<div class="kt-card" id="makroCard" style="margin:.75rem 1rem;padding:1rem 1rem .75rem;position:relative;"><div class="kt-info-card-wrap" style="top:0rem;right:.1rem"><button class="kt-info-btn" type="button" aria-label="Info"><i class="bi bi-info-circle"></i></button><div class="kt-tooltip">Ziele nach <strong>DGE-Empfehlung</strong>: Eiweiß 0,8&nbsp;g/kg&nbsp;KG · Fett max. 30% · Kohlenhydrate &gt;50% der Energie. <a href="https://www.dge.de/wissenschaft/referenzwerte/" target="_blank" style="color:var(--accent);text-decoration:none;">dge.de →</a></div></div>
    <?php
    $makroItems = [
        ['label'=>'Eiweiß',        'ist'=>$sumEiweiss, 'ziel'=>$zielEiweiss, 'color'=>'#60a5fa'],
        ['label'=>'Fett',          'ist'=>$sumFett,    'ziel'=>$zielFett,    'color'=>'#fb923c'],
        ['label'=>'Kohlenhydrate', 'ist'=>$sumKh,      'ziel'=>$zielKh,      'color'=>'#a78bfa'],
    ];
    ?>
    <div style="display:flex;gap:.5rem;">
    <?php foreach ($makroItems as $m):
        $pct      = $m['ziel'] > 0 ? min(100, round($m['ist'] / $m['ziel'] * 100)) : 0;
        $over     = $m['ist'] > $m['ziel'];
        $barColor = $over ? '#f87171' : $m['color'];
    ?>
        <div style="flex:1;min-width:0;text-align:center;">
            <div style="font-size:.7rem;color:var(--muted);margin-bottom:.2rem;">
                <?= $m['label'] ?>
            </div>
            <div style="font-size:.82rem;font-weight:700;
                        color:<?= $over ? '#f87171' : 'var(--text)' ?>;">
                <?= round($m['ist']) ?><span style="font-weight:400;color:var(--muted);font-size:.72rem;">/<?= $m['ziel'] ?>g</span>
            </div>
            <div style="height:4px;background:var(--surface2);border-radius:999px;
                        overflow:hidden;margin-top:.3rem;">
                <div style="height:100%;width:<?= $pct ?>%;background:<?= $barColor ?>;
                            border-radius:999px;transition:width .4s ease;"></div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

</div>
<?php endif; ?>

<!-- ── Einträge ──────────────────────────────────────────────────── -->
<?php
// Gerichte aus eintraege laden (gericht_id gruppieren)
$gerichtGruppen = [];
$einzelEintraege = [];
foreach ($eintraege as $e) {
    if (!empty($e['gericht_id'])) $gerichtGruppen[$e['gericht_id']][] = $e;
    else $einzelEintraege[] = $e;
}
$hatEintraege = !empty($gerichtGruppen) || !empty($anzeige);
?>
<?php if (!$hatEintraege): ?>
<div class="empty-state">
    <i class="bi bi-egg-fried"></i>
    <p>Noch nichts geloggt.<br>Scanne ein Produkt oder trage es manuell ein.</p>
</div>
<?php else: ?>
<div class="meal-section mt-3">

<?php // ── Gerichte ────────────────────────────────────────────────── ?>
<?php foreach ($gerichtGruppen as $gerichtId => $gEintraege): ?>
<?php
    $gerichtName = preg_replace('/:.*$/', '', $gEintraege[0]['name']); // Name vor dem ":"
    $gKcal    = array_sum(array_column($gEintraege, 'kcal'));
    $gMenge   = array_sum(array_column($gEintraege, 'menge_g'));
?>
<div class="group-entry" id="gericht-group-<?= $gerichtId ?>">
    <div class="group-entry__head" onclick="toggleGroup('g<?= $gerichtId ?>')">
        <div style="display:inline-flex;align-items:center;justify-content:center;
             width:2rem;height:2rem;border-radius:50%;background:rgba(74,222,128,.15);flex-shrink:0;">
            <i class="bi bi-journal-richtext" style="color:var(--accent);font-size:.75rem;"></i>
        </div>
        <div class="flex-grow-1 overflow-hidden">
            <div class="entry-name text-truncate"><?= htmlspecialchars($gerichtName) ?></div>
            <div class="entry-meta"><?= count($gEintraege) ?> Zutaten</div>
        </div>
        <span class="entry-kcal"><?= round($gKcal) ?><small class="text-muted"> kcal</small></span>
        <i class="bi bi-chevron-down group-chevron ms-2" id="chevron-g<?= $gerichtId ?>"
           style="color:var(--muted);font-size:.85rem;transition:transform .2s;flex-shrink:0;"></i>
    </div>
    <div class="group-entry__subs" id="subs-g<?= $gerichtId ?>" style="display:none;">
        <?php foreach ($gEintraege as $e): ?>
        <?php $subName = preg_replace('/^[^:]+:\s*/', '', $e['name']); ?>
        <div class="swipe-entry single-action" id="entry-<?= $e['id'] ?>">
            <div class="swipe-entry__content" style="padding-left:2.5rem;">
                <?= entryIcon((int)($e['gericht_id'] ?? 0) ?: null, $e['quelle'] ?? null, isset($e['produkt_id']) ? ($e['produkt_id'] === null ? null : (int)$e['produkt_id']) : null) ?>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="entry-meta" style="font-size:.82rem;color:var(--text);">
                        <?= htmlspecialchars($subName) ?>
                    </div>
                    <div class="entry-meta">
                        <?= rtrim(rtrim(number_format($e['menge_g'],1,',',''),'0'),',') ?>g
                    </div>
                </div>
                <span class="entry-kcal" style="font-size:.9rem;"><?= round($e['kcal']) ?><small class="text-muted"> kcal</small></span>
            </div>
            <div class="swipe-entry__actions">
                <button class="swipe-action-delete" data-entry-id="<?= $e['id'] ?>">
                    <i class="bi bi-trash3"></i>Löschen
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>


    <?php foreach ($anzeige as $idx => $g):
        $mehrfach = $gruppieren && count($g['sub']) > 1;
        $singleId = !$mehrfach ? $g['sub'][0]['id'] : null;
    ?>

    <?php if ($mehrfach): ?>
    <!-- ── Gruppierter Haupteintrag mit Dropdown ── -->
    <div class="group-entry" id="group-<?= $idx ?>">
        <!-- Hauptzeile (kein Swipe) -->
        <div class="group-entry__head" onclick="toggleGroup(<?= $idx ?>)">
            <?= entryIcon((int)($g['gericht_id'] ?? 0) ?: null, $g['quelle'] ?? null, isset($g['produkt_id']) ? ($g['produkt_id'] === null ? null : (int)$g['produkt_id']) : null) ?>
            <div class="flex-grow-1 overflow-hidden">
                <div class="entry-name text-truncate"><?= htmlspecialchars($g['name']) ?></div>
                <div class="entry-meta">
                    <?= rtrim(rtrim(number_format($g['menge_g'],1,',',''),'0'),',') ?>g
                    · <?= count($g['sub']) ?>×
                </div>
            </div>
            <span class="entry-kcal"><?= round($g['kcal']) ?><small class="text-muted"> kcal</small></span>
            <i class="bi bi-chevron-down group-chevron ms-2" id="chevron-<?= $idx ?>"
               style="color:var(--muted);font-size:.85rem;transition:transform .2s;flex-shrink:0;"></i>
        </div>
        <!-- Aufklappbare Sub-Einträge -->
        <div class="group-entry__subs" id="subs-<?= $idx ?>" style="display:none;">
            <?php foreach ($g['sub'] as $e): ?>
            <div class="swipe-entry" id="entry-<?= $e['id'] ?>">
                <div class="swipe-entry__content" style="padding-left:2.5rem;">
                    <div class="flex-grow-1 overflow-hidden">
                        <div class="entry-meta" style="font-size:.82rem;color:var(--text);">
                            <?= rtrim(rtrim(number_format($e['menge_g'],1,',',''),'0'),',') ?>g
                        </div>
                    </div>
                    <span class="entry-kcal" style="font-size:.9rem;"><?= round($e['kcal']) ?><small class="text-muted"> kcal</small></span>
                </div>
                <div class="swipe-entry__actions">
                    <button class="swipe-action-edit"
                            data-entry-id="<?= $e['id'] ?>"
                            data-entry-name="<?= htmlspecialchars($e['name'], ENT_QUOTES) ?>"
                            data-menge-g="<?= (float)$e['menge_g'] ?>"
                            data-kcal100g="<?= $e['kcal'] > 0 && $e['menge_g'] > 0 ? round($e['kcal'] / $e['menge_g'] * 100, 2) : 0 ?>">
                        <i class="bi bi-pencil"></i>Bearbeiten
                    </button>
                    <button class="swipe-action-delete" data-entry-id="<?= $e['id'] ?>">
                        <i class="bi bi-trash3"></i>Löschen
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php else: ?>
    <!-- ── Einzelner Eintrag (normaler Swipe) ── -->
    <div class="swipe-entry" id="entry-<?= $singleId ?>">
        <div class="swipe-entry__content">
            <?= entryIcon((int)($g['gericht_id'] ?? 0) ?: null, $g['quelle'] ?? null, isset($g['produkt_id']) ? ($g['produkt_id'] === null ? null : (int)$g['produkt_id']) : null) ?>
            <div class="flex-grow-1 overflow-hidden">
                <div class="entry-name text-truncate"><?= htmlspecialchars($g['name']) ?></div>
                <div class="entry-meta"><?= rtrim(rtrim(number_format($g['menge_g'],1,',',''),'0'),',') ?>g</div>
            </div>
            <span class="entry-kcal"><?= round($g['kcal']) ?><small class="text-muted"> kcal</small></span>
        </div>
        <div class="swipe-entry__actions">
            <button class="swipe-action-edit"
                    data-entry-id="<?= $singleId ?>"
                    data-entry-name="<?= htmlspecialchars($g['name'], ENT_QUOTES) ?>"
                    data-menge-g="<?= (float)$g['menge_g'] ?>"
                    data-kcal100g="<?= $g['kcal'] > 0 && $g['menge_g'] > 0 ? round($g['kcal'] / $g['menge_g'] * 100, 2) : 0 ?>">
                <i class="bi bi-pencil"></i>Bearbeiten
            </button>
            <button class="swipe-action-delete" data-entry-id="<?= $singleId ?>">
                <i class="bi bi-trash3"></i>Löschen
            </button>
        </div>
    </div>
    <?php endif; ?>

    <?php endforeach; ?>
</div>

<?php endif; ?>

<!-- ── Erfassen-Button ───────────────────────────────────────────── -->
<div style="padding:0rem 1rem 0;">
    <a href="/log.php" class="scan-btn" style="text-decoration:none;">
        <i class="bi bi-plus-circle-fill"></i> Erfassen
    </a>
</div>

<!-- ── Toast ─────────────────────────────────────────────────────── -->
<div class="toast-container position-fixed bottom-0 start-50 translate-middle-x mb-5 pb-4">
    <div id="toast" class="toast align-items-center text-bg-dark border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="toastMsg"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<style>
.group-entry {
    overflow: hidden;
}
.group-entry__head {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .7rem 1.25rem;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    user-select: none;
}
.group-entry__head:active { background: var(--surface2); }
.group-entry__subs .swipe-entry {
    overflow: hidden;
    position: relative;
}
.group-entry__subs .swipe-entry__content {
    background: var(--surface2);
}
.group-entry__subs .swipe-entry:last-child .swipe-entry__content {
    border-bottom: 2px solid var(--border);
}
</style>
<script>
function toggleGroup(idx) {
    const subs    = document.getElementById('subs-' + idx);
    const chevron = document.getElementById('chevron-' + idx);
    const open    = subs.style.display === 'none';
    subs.style.display = open ? 'block' : 'none';
    chevron.style.transform = open ? 'rotate(180deg)' : '';
    if (open) initSwipeEntries(); // neu sichtbare Sub-Einträge binden
}
</script>

<?php renderFooter('home'); ?>
