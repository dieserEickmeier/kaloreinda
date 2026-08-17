<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
$currentUser = requireLogin();
$userId      = $currentUser['id'];
$db          = db();

// Bereits OOBE abgeschlossen → direkt zur Startseite
// Defensiv: falls die Spalte noch nicht migriert wurde, einfach OOBE zeigen
$oobeOk = false;
try {
    $stmtCheck = $db->prepare("SELECT oobe_abgeschlossen FROM users WHERE id = ? LIMIT 1");
    if ($stmtCheck) {
        $stmtCheck->bind_param('i', $userId);
        $stmtCheck->execute();
        $userRow = $stmtCheck->get_result()->fetch_assoc();
        $oobeOk  = !empty($userRow['oobe_abgeschlossen']);
    }
} catch (Exception $e) { /* Spalte existiert noch nicht */ }
if ($oobeOk) { header('Location: /index.php'); exit; }

// ─── POST: Profil anlegen ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $geschlecht  = trim($_POST['geschlecht']  ?? 'm') === 'w' ? 'w' : 'm';
    $geburtsjahr = (int)($_POST['geburtsjahr']  ?? 1990);
    $groesse     = (int)($_POST['groesse']      ?? 175);
    $aktivitaet  = in_array($_POST['aktivitaet'] ?? '', ['sitzend','leicht','moderat','aktiv','sehr_aktiv','tracking'])
                   ? $_POST['aktivitaet'] : 'moderat';
    $defizit     = max(0, min(1000, (int)($_POST['defizit'] ?? 500)));
    $kg          = (float)str_replace(',', '.', $_POST['kg'] ?? '0');

    // Profil speichern (UPDATE falls bereits vorhanden)
    $stmt = $db->prepare("
        INSERT INTO profil (user_id, groesse_cm, aktivitaet, defizit_kcal, geschlecht, geburtsjahr, hilfetext_anzeigen)
        VALUES (?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            groesse_cm=VALUES(groesse_cm), aktivitaet=VALUES(aktivitaet),
            defizit_kcal=VALUES(defizit_kcal), geschlecht=VALUES(geschlecht),
            geburtsjahr=VALUES(geburtsjahr)
    ");
    $stmt->bind_param('iisisi', $userId, $groesse, $aktivitaet, $defizit, $geschlecht, $geburtsjahr);
    $stmt->execute();

    // Gewicht speichern (falls angegeben)
    if ($kg > 20 && $kg < 300) {
        $wStmt = $db->prepare("
            INSERT INTO gewicht (user_id, datum, kg) VALUES (?, CURDATE(), ?)
            ON DUPLICATE KEY UPDATE kg = VALUES(kg)
        ");
        $wStmt->bind_param('id', $userId, $kg);
        $wStmt->execute();
    }

    // OOBE als abgeschlossen markieren (defensiv falls Spalte noch nicht migriert)
    try {
        $oobeStmt = $db->prepare("UPDATE users SET oobe_abgeschlossen = 1 WHERE id = ?");
        if ($oobeStmt) { $oobeStmt->bind_param('i', $userId); $oobeStmt->execute(); }
    } catch (Exception $e) { /* ignorieren */ }

    header('Location: /index.php'); exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Willkommen – KalorienTracker</title>
    <link rel="stylesheet" href="/assets/css/app.css?v=4">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: var(--bg); color: var(--text); min-height: 100vh;
               display: flex; flex-direction: column; align-items: center; justify-content: center;
               padding: 1.5rem 1rem env(safe-area-inset-bottom); }

        .oobe-wrap { width: 100%; max-width: 420px; }

        /* Fortschrittsbalken */
        .oobe-progress { display: flex; gap: .35rem; margin-bottom: 2rem; }
        .oobe-dot { flex: 1; height: 3px; border-radius: 999px;
                    background: var(--border); transition: background .3s; }
        .oobe-dot.done   { background: var(--accent); }
        .oobe-dot.active { background: var(--accent); opacity: .5; }

        /* Schritt-Karten */
        .oobe-step { display: none; animation: oobeFade .25s ease; }
        .oobe-step.active { display: block; }
        @keyframes oobeFade { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:none; } }

        .oobe-icon  { font-size: 2.5rem; color: var(--accent); margin-bottom: .75rem; display: block; }
        .oobe-title { font-size: 1.3rem; font-weight: 800; margin-bottom: .35rem; }
        .oobe-sub   { font-size: .88rem; color: var(--muted); margin-bottom: 1.5rem; line-height: 1.5; }

        /* Grosse Optionen-Buttons */
        .oobe-options { display: flex; flex-direction: column; gap: .6rem; margin-bottom: 1.5rem; }
        .oobe-option  { display: flex; align-items: center; gap: .75rem;
                        background: var(--surface); border: 1.5px solid var(--border);
                        border-radius: 14px; padding: .85rem 1rem; cursor: pointer;
                        transition: border-color .15s, background .15s; text-align: left; }
        .oobe-option.selected, .oobe-option:has(input:checked) {
            border-color: var(--accent); background: rgba(74,222,128,.08); }
        .oobe-option input { position: absolute; opacity: 0; width: 0; }
        .oobe-option-icon { font-size: 1.3rem; flex-shrink: 0; }
        .oobe-option-text { font-size: .9rem; font-weight: 600; }
        .oobe-option-sub  { font-size: .75rem; color: var(--muted); margin-top: .1rem; }

        /* Nummern-Eingabe groß */
        .oobe-number { width: 100%; background: var(--surface); border: 1.5px solid var(--border);
                       border-radius: 14px; padding: 1rem 1.25rem; color: var(--text);
                       font-size: 1.8rem; font-weight: 700; text-align: center;
                       outline: none; margin-bottom: .5rem; }
        .oobe-number:focus { border-color: var(--accent); }
        .oobe-unit  { text-align: center; color: var(--muted); font-size: .88rem; margin-bottom: 1.5rem; }

        /* Slider */
        .oobe-slider { width: 100%; accent-color: var(--accent); margin-bottom: .5rem; }
        .oobe-slider-val { text-align: center; font-size: 1.6rem; font-weight: 800;
                           color: var(--accent); margin-bottom: .35rem; }
        .oobe-slider-sub { text-align: center; font-size: .78rem; color: var(--muted); margin-bottom: 1.5rem; }

        /* Nav-Buttons */
        .oobe-nav { display: flex; gap: .6rem; }
        .oobe-btn-back { flex: 0 0 3rem; background: var(--surface); border: 1px solid var(--border);
                         border-radius: 14px; color: var(--muted); font-size: 1.1rem; cursor: pointer; }
        .oobe-btn-next { flex: 1; background: var(--accent); border: none; border-radius: 14px;
                         padding: 1rem; color: #000; font-size: 1rem; font-weight: 700; cursor: pointer; }
        .oobe-btn-next:active { opacity: .85; }
    </style>
</head>
<body>
<div class="oobe-wrap">

    <!-- Fortschrittsbalken (6 Schritte) -->
    <div class="oobe-progress" id="progress">
        <?php for ($i = 0; $i < 6; $i++): ?>
        <div class="oobe-dot" id="dot-<?= $i ?>"></div>
        <?php endfor; ?>
    </div>

    <form method="post" id="oobeForm">

    <!-- ── Schritt 1: Geschlecht ────────────────────────────────── -->
    <div class="oobe-step" id="step-0">
        <i class="bi bi-person oobe-icon"></i>
        <div class="oobe-title">Willkommen, <?= htmlspecialchars($currentUser['display_name'] ?: $currentUser['username']) ?>!</div>
        <div class="oobe-sub">Lass uns kurz dein Profil einrichten, damit dein Kalorienziel möglichst genau berechnet werden kann.</div>
        <div class="oobe-sub" style="margin-bottom:1rem;margin-top:-.5rem;">Was bist du?</div>
        <div class="oobe-options">
            <label class="oobe-option">
                <input type="radio" name="geschlecht" value="m" checked>
                <span class="oobe-option-icon">👨</span>
                <div><div class="oobe-option-text">Männlich</div></div>
            </label>
            <label class="oobe-option">
                <input type="radio" name="geschlecht" value="w">
                <span class="oobe-option-icon">👩</span>
                <div><div class="oobe-option-text">Weiblich</div></div>
            </label>
        </div>
    </div>

    <!-- ── Schritt 2: Geburtsjahr ───────────────────────────────── -->
    <div class="oobe-step" id="step-1">
        <i class="bi bi-calendar-heart oobe-icon"></i>
        <div class="oobe-title">Geburtsjahr</div>
        <div class="oobe-sub">Wird für die Kalorienberechnung benötigt.</div>
        <input type="number" name="geburtsjahr" class="oobe-number"
               value="1990" min="1930" max="2010" inputmode="numeric">
        <div class="oobe-unit">Jahr</div>
    </div>

    <!-- ── Schritt 3: Körpergröße ───────────────────────────────── -->
    <div class="oobe-step" id="step-2">
        <i class="bi bi-rulers oobe-icon"></i>
        <div class="oobe-title">Körpergröße</div>
        <div class="oobe-sub">Wie groß bist du?</div>
        <input type="number" name="groesse" class="oobe-number"
               value="175" min="100" max="250" inputmode="numeric">
        <div class="oobe-unit">cm</div>
    </div>

    <!-- ── Schritt 4: Gewicht ───────────────────────────────────── -->
    <div class="oobe-step" id="step-3">
        <i class="bi bi-speedometer2 oobe-icon"></i>
        <div class="oobe-title">Aktuelles Gewicht</div>
        <div class="oobe-sub">Kannst du auch später noch anpassen.</div>
        <input type="number" name="kg" class="oobe-number"
               placeholder="82,5" step="0.1" min="20" max="300" inputmode="decimal">
        <div class="oobe-unit">kg</div>
    </div>

    <!-- ── Schritt 5: Aktivitätslevel ──────────────────────────── -->
    <div class="oobe-step" id="step-4">
        <i class="bi bi-lightning-charge oobe-icon"></i>
        <div class="oobe-title">Aktivitätslevel</div>
        <div class="oobe-sub">Wie aktiv bist du im Alltag?</div>
        <div class="oobe-options" id="aktivOptions">
            <?php
            $aktivOpts = [
                'sitzend'    => ['icon'=>'🪑', 'label'=>'Kaum Bewegung',    'sub'=>'Bürojob, kein Sport'],
                'leicht'     => ['icon'=>'🚶', 'label'=>'Leicht aktiv',      'sub'=>'1–3× Sport/Woche'],
                'moderat'    => ['icon'=>'🚴', 'label'=>'Moderat aktiv',     'sub'=>'3–5× Sport/Woche'],
                'aktiv'      => ['icon'=>'🏋️', 'label'=>'Sehr aktiv',        'sub'=>'6–7× Sport/Woche'],
                'sehr_aktiv' => ['icon'=>'🔥', 'label'=>'Extrem aktiv',      'sub'=>'2× täglich Training'],
                'tracking'   => ['icon'=>'⌚', 'label'=>'Smartwatch-Tracking','sub'=>'Nur Grundumsatz, Aktivität per App'],
            ];
            foreach ($aktivOpts as $val => $opt): ?>
            <label class="oobe-option">
                <input type="radio" name="aktivitaet" value="<?= $val ?>"
                       <?= $val === 'moderat' ? 'checked' : '' ?>>
                <span class="oobe-option-icon"><?= $opt['icon'] ?></span>
                <div>
                    <div class="oobe-option-text"><?= $opt['label'] ?></div>
                    <div class="oobe-option-sub"><?= $opt['sub'] ?></div>
                </div>
            </label>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── Schritt 6: Kaloriendefizit ──────────────────────────── -->
    <div class="oobe-step" id="step-5">
        <i class="bi bi-bullseye oobe-icon"></i>
        <div class="oobe-title">Kalorienziel</div>
        <div class="oobe-sub">Wie viel möchtest du täglich unter deinem Verbrauch bleiben?</div>
        <div class="oobe-slider-val" id="defizitVal">500 kcal</div>
        <input type="range" name="defizit" class="oobe-slider" id="defizitSlider"
               min="0" max="1000" step="50" value="500"
               oninput="updateDefizit(this.value)">
        <div class="oobe-slider-sub" id="defizitSub">≈ 0,45 kg Abnahme/Woche · empfohlen</div>
        <div style="display:flex;justify-content:space-between;font-size:.7rem;color:var(--muted);margin-bottom:1.5rem;">
            <span>0 kcal (Gewicht halten)</span><span>1000 kcal</span>
        </div>
    </div>

    <!-- ── Navigation ───────────────────────────────────────────── -->
    <div class="oobe-nav">
        <button type="button" class="oobe-btn-back" id="btnBack" onclick="prevStep()" style="display:none;">
            <i class="bi bi-chevron-left"></i>
        </button>
        <button type="button" class="oobe-btn-next" id="btnNext" onclick="nextStep()">
            Weiter <i class="bi bi-chevron-right"></i>
        </button>
    </div>

    </form>
</div>

<script>
let currentStep = 0;
const totalSteps = 6;

function showStep(n) {
    document.querySelectorAll('.oobe-step').forEach((el, i) => {
        el.classList.toggle('active', i === n);
    });
    // Dots
    for (let i = 0; i < totalSteps; i++) {
        const dot = document.getElementById('dot-' + i);
        dot.className = 'oobe-dot' + (i < n ? ' done' : i === n ? ' active' : '');
    }
    document.getElementById('btnBack').style.display = n === 0 ? 'none' : '';
    document.getElementById('btnNext').innerHTML = n === totalSteps - 1
        ? '<i class="bi bi-check-circle-fill me-1"></i> Fertig!'
        : 'Weiter <i class="bi bi-chevron-right"></i>';

    // Autofocus auf Zahlenfelder
    const input = document.querySelector(`#step-${n} input[type=number]`);
    if (input) setTimeout(() => input.focus(), 200);
}

function nextStep() {
    // Pflichtfeld-Prüfung für das aktuelle Step
    const step = document.getElementById('step-' + currentStep);

    // Zahlenfelder müssen ausgefüllt sein
    const numInput = step.querySelector('input[type=number]');
    if (numInput) {
        const val = numInput.value.trim().replace(',', '.');
        const num = parseFloat(val);
        const min = parseFloat(numInput.min || '-Infinity');
        const max = parseFloat(numInput.max || 'Infinity');
        if (!val || isNaN(num) || num < min || num > max) {
            numInput.style.borderColor = '#ef4444';
            numInput.focus();
            // Fehlermeldung kurz anzeigen
            let err = document.getElementById('oobe-err-' + currentStep);
            if (!err) {
                err = document.createElement('div');
                err.id = 'oobe-err-' + currentStep;
                err.style.cssText = 'color:#ef4444;font-size:.8rem;text-align:center;margin-top:.35rem;';
                numInput.parentNode.insertBefore(err, numInput.nextSibling);
            }
            err.textContent = `Bitte einen gültigen Wert zwischen ${numInput.min} und ${numInput.max} eingeben.`;
            setTimeout(() => { numInput.style.borderColor = ''; }, 2000);
            return;
        }
        numInput.style.borderColor = '';
        const err = document.getElementById('oobe-err-' + currentStep);
        if (err) err.textContent = '';
    }

    if (currentStep < totalSteps - 1) {
        currentStep++;
        showStep(currentStep);
    } else {
        document.getElementById('oobeForm').submit();
    }
}

function prevStep() {
    if (currentStep > 0) {
        currentStep--;
        showStep(currentStep);
    }
}

function updateDefizit(val) {
    const v = parseInt(val);
    const kg = (v * 7 / 7700).toFixed(2);
    document.getElementById('defizitVal').textContent = v + ' kcal';
    let sub = `≈ ${kg} kg Abnahme/Woche`;
    if (v === 0)   sub = 'Gewicht halten';
    if (v === 500) sub += ' · empfohlen';
    if (v >= 750)  sub += ' · aggressiv';
    document.getElementById('defizitSub').textContent = sub;
}

// Enter-Taste wechselt zum nächsten Schritt
document.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); nextStep(); }
});

showStep(0);
</script>
</body>
</html>
