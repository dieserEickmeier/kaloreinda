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

// ─── POST: Einstellungen speichern ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfCheck();

    if ($_POST['action'] === 'passwort') {
        $altPw  = $_POST['alt_passwort']  ?? '';
        $neuPw  = $_POST['neu_passwort']  ?? '';
        $neuPw2 = $_POST['neu_passwort2'] ?? '';

        $stmtAlt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmtAlt->bind_param('i', $userId); $stmtAlt->execute();
        $altHash = $stmtAlt->get_result()->fetch_assoc()['password_hash'] ?? '';

        if (!password_verify($altPw, $altHash)) {
            $pwFehler = 'Das aktuelle Passwort ist falsch.';
        } elseif (strlen($neuPw) < 8) {
            $pwFehler = 'Neues Passwort muss mindestens 8 Zeichen haben.';
        } elseif ($neuPw !== $neuPw2) {
            $pwFehler = 'Die neuen Passwörter stimmen nicht überein.';
        } else {
            $newHash = password_hash($neuPw, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmtPw  = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmtPw->bind_param('si', $newHash, $userId);
            $stmtPw->execute();
            header('Location: /einstellungen.php?pwsaved=1'); exit;
        }
    }

    if ($_POST['action'] === 'profil') {
    $groesse    = (int)($_POST['groesse']    ?? 175);
    $aktivitaet = in_array($_POST['aktivitaet'] ?? '', ['sitzend','leicht','moderat','aktiv','sehr_aktiv','tracking'])
                  ? $_POST['aktivitaet'] : 'moderat';
    $defizit    = (int)($_POST['defizit']    ?? 500);
    $geschlecht = trim($_POST['geschlecht'] ?? 'm') === 'w' ? 'w' : 'm';
    $geburtsjahr = (int)($_POST['geburtsjahr'] ?? 1990);
    $gruppieren  = isset($_POST['eintraege_gruppieren']) ? 1 : 0;
    $makros      = isset($_POST['makros_anzeigen'])      ? 1 : 0;
    $startseite  = array_key_exists($_POST['startseite'] ?? '', startseiten()) ? $_POST['startseite'] : 'heute';

    $stmtEx = $db->prepare("SELECT id FROM profil WHERE user_id = ? LIMIT 1");
    $stmtEx->bind_param("i", $userId); $stmtEx->execute();
    $existing = $stmtEx->get_result()->fetch_assoc();
    if ($existing) {
        $stmt = $db->prepare("UPDATE profil SET groesse_cm=?, aktivitaet=?, defizit_kcal=?, geschlecht=?, geburtsjahr=?, eintraege_gruppieren=?, makros_anzeigen=?, startseite=? WHERE id=? AND user_id=?");
        $existingId = $existing['id'];
        $stmt->bind_param('isisiiisii', $groesse, $aktivitaet, $defizit, $geschlecht, $geburtsjahr, $gruppieren, $makros, $startseite, $existingId, $userId);
    } else {
        $stmt = $db->prepare("INSERT INTO profil (user_id, groesse_cm, aktivitaet, defizit_kcal, geschlecht, geburtsjahr, eintraege_gruppieren, makros_anzeigen, startseite) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('iisisiiis', $userId, $groesse, $aktivitaet, $defizit, $geschlecht, $geburtsjahr, $gruppieren, $makros, $startseite);
    }
    $stmt->execute();
    echo '<script>location.href="einstellungen.php?saved=1"</script>'; exit;
    } // end if profil
}

// ─── Daten laden ──────────────────────────────────────────────────────────────
$stmtPr = $db->prepare("SELECT * FROM profil WHERE user_id = ? LIMIT 1");
$stmtPr->bind_param("i", $userId); $stmtPr->execute();
$profil = $stmtPr->get_result()->fetch_assoc();

$aktivLabels = [
    'sitzend'    => 'Sitzend (kein Sport)',
    'leicht'     => 'Leicht aktiv (1–3×/Woche)',
    'moderat'    => 'Moderat aktiv (3–5×/Woche)',
    'aktiv'      => 'Aktiv (6–7×/Woche)',
    'sehr_aktiv' => 'Sehr aktiv (2× täglich)',
    'tracking'   => 'Tracking (Smartwatch)',
];

renderHeader('Einstellungen', 'profil');

$saved = isset($_GET['saved']) ? 'Gespeichert' : (isset($_GET['pwsaved']) ? 'Passwort geändert' : '');
$defizitAkt = (int)($profil['defizit_kcal'] ?? 500);
?>

<?php renderPageHeader('Einstellungen',
    $saved !== '' ? '<span class="badge-acc"><i class="bi bi-check-circle-fill"></i> ' . $saved . '</span>' : '',
    '', '/profil.php'); ?>

<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="profil">

    <!-- ── Meine Daten ──────────────────────────────────────────── -->
    <div class="section-label"><span>Meine Daten</span></div>
    <div class="kt-card">
        <div class="mb-3">
            <span class="form-label">Geschlecht</span>
            <div class="choice-row">
                <?php foreach (['m' => 'Männlich', 'w' => 'Weiblich'] as $val => $lbl): ?>
                <label class="choice" id="lbl-geschlecht-<?= $val ?>">
                    <input type="radio" name="geschlecht" value="<?= $val ?>"
                           <?= ($profil['geschlecht'] ?? 'm') === $val ? 'checked' : '' ?>
                           onchange="highlightGeschlecht()">
                    <?= $lbl ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-6">
                <label class="form-label" for="fGeburtsjahr">Geburtsjahr</label>
                <input type="number" name="geburtsjahr" id="fGeburtsjahr" min="1930" max="2010"
                       value="<?= (int)($profil['geburtsjahr'] ?? 1990) ?>"
                       class="form-control" inputmode="numeric">
            </div>
            <div class="col-6">
                <label class="form-label" for="fGroesse">Körpergröße (cm)</label>
                <input type="number" name="groesse" id="fGroesse" min="100" max="250"
                       value="<?= (int)($profil['groesse_cm'] ?? 175) ?>"
                       class="form-control" inputmode="numeric">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label" for="fAktivitaet">Aktivitätslevel</label>
            <div style="display:flex;align-items:center;gap:.5rem;">
                <select name="aktivitaet" id="fAktivitaet" class="form-select" style="flex:1;">
                    <?php foreach ($aktivLabels as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= ($profil['aktivitaet'] ?? 'moderat') === $val ? 'selected' : '' ?>>
                        <?= $lbl ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <span class="kt-info-inline" style="flex-shrink:0;"><button class="kt-info-btn" type="button" aria-label="Info"><i class="bi bi-info-circle"></i></button><div class="kt-tooltip" style="right:0;left:auto;">„Tracking" verwendet nur den reinen Grundumsatz – ideal wenn du Aktivitätskalorien per Smartwatch separat erfasst (Doppelzählung wird vermieden).</div></span>
            </div>
        </div>

        <div>
            <label class="form-label" for="defizitSlider" style="display:flex;justify-content:space-between;">
                <span>Kaloriendefizit</span>
                <span><b id="defizitLabel" class="num" style="color:var(--text);"><?= $defizitAkt ?></b> kcal/Tag
                    <span class="text-muted">(≈ <span id="defizitKg"><?= number_format($defizitAkt * 7 / 7700, 2, ',', '') ?></span> kg/Woche)</span></span>
            </label>
            <input type="range" name="defizit" id="defizitSlider" class="kt-range"
                   min="0" max="1000" step="50"
                   value="<?= $defizitAkt ?>"
                   oninput="document.getElementById('defizitLabel').textContent = this.value;
                            document.getElementById('defizitKg').textContent = (this.value * 7 / 7700).toFixed(2).replace('.', ',');">
            <div class="range-legend"><span>0 (Erhalt)</span><span>500 (empfohlen)</span><span>1000</span></div>
        </div>
    </div>

    <!-- ── Ansicht ──────────────────────────────────────────────── -->
    <!-- Versteckte Checkboxen für Formular-Submit -->
    <input type="checkbox" name="eintraege_gruppieren" id="cbGruppieren"
           style="display:none;" <?= !empty($profil['eintraege_gruppieren']) ? 'checked' : '' ?>>
    <input type="checkbox" name="makros_anzeigen" id="cbMakros"
           style="display:none;" <?= ($profil['makros_anzeigen'] ?? 1) ? 'checked' : '' ?>>

    <div class="section-label"><span>Ansicht</span></div>
    <div class="list-tile">
        <div class="list-row">
            <span class="list-row__text">
                <label class="list-row__title" for="fStartseite">Startseite</label>
                <span class="list-row__sub">Diese Seite öffnet sich beim Start der App</span>
            </span>
            <select name="startseite" id="fStartseite" class="form-select" style="width:auto;min-width:8.5rem;">
                <?php foreach (startseiten() as $key => $sp): ?>
                <option value="<?= $key ?>" <?= ($profil['startseite'] ?? 'heute') === $key ? 'selected' : '' ?>><?= $sp['label'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="button" class="list-row" onclick="toggleSwitch('cbGruppieren')" role="switch" id="row-cbGruppieren">
            <span class="list-row__text">
                <span class="list-row__title">Gleiche Lebensmittel gruppieren</span>
                <span class="list-row__sub">Mehrfache Einträge desselben Produkts auf „Heute“ zusammenfassen</span>
            </span>
            <span id="sw-cbGruppieren" class="kt-switch"><span class="kt-switch-knob"></span></span>
        </button>
        <button type="button" class="list-row" onclick="toggleSwitch('cbMakros')" role="switch" id="row-cbMakros">
            <span class="list-row__text">
                <span class="list-row__title">Makros anzeigen</span>
                <span class="list-row__sub">Eiweiß, Fett und Kohlenhydrate auf „Heute“ und im Verlauf einblenden</span>
            </span>
            <span id="sw-cbMakros" class="kt-switch"><span class="kt-switch-knob"></span></span>
        </button>
    </div>

    <button type="submit" class="scan-btn">
        <i class="bi bi-check-circle-fill"></i> Einstellungen speichern
    </button>
</form>

<!-- ── Konto ─────────────────────────────────────────────────── -->
<div class="section-label"><span>Konto</span></div>
<div class="list-tile">
    <button type="button" class="list-row" onclick="document.getElementById('pwModal').style.display='flex'">
        <span class="list-row__icon"><i class="bi bi-key"></i></span>
        <span class="list-row__text"><span class="list-row__title">Passwort ändern</span></span>
        <i class="bi bi-chevron-right list-row__end"></i>
    </button>
    <a href="/api_docs.php" class="list-row">
        <span class="list-row__icon"><i class="bi bi-code-slash"></i></span>
        <span class="list-row__text"><span class="list-row__title">API-Dokumentation</span></span>
        <i class="bi bi-chevron-right list-row__end"></i>
    </a>
    <a href="/logout.php" class="list-row">
        <span class="list-row__icon" style="color:var(--danger);"><i class="bi bi-box-arrow-right"></i></span>
        <span class="list-row__text">
            <span class="list-row__title" style="color:var(--danger);">Abmelden</span>
            <span class="list-row__sub"><?= htmlspecialchars($currentUser['display_name'] ?? $currentUser['username']) ?></span>
        </span>
    </a>
</div>

<!-- ── Passwort-Modal ────────────────────────────────────────── -->
<div id="pwModal" class="kt-overlay" onclick="if(event.target===this)closePwModal()"
     style="display:none;align-items:flex-end;justify-content:center;">
    <div class="sheet">
        <div class="sheet-head">
            <div><h2>Passwort ändern</h2></div>
            <button type="button" class="pf-close" onclick="closePwModal()" aria-label="Schließen"><i class="bi bi-x-lg"></i></button>
        </div>
        <?php if (!empty($pwFehler)): ?>
        <div class="alert-danger-soft">
            <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($pwFehler) ?>
        </div>
        <?php endif; ?>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="passwort">
            <div class="mb-3">
                <label class="form-label" for="pwAlt">Aktuelles Passwort</label>
                <input type="password" name="alt_passwort" id="pwAlt" class="form-control" autocomplete="current-password" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="pwNeu">Neues Passwort</label>
                <input type="password" name="neu_passwort" id="pwNeu" class="form-control" autocomplete="new-password"
                       minlength="8" placeholder="Mindestens 8 Zeichen">
            </div>
            <div class="mb-4">
                <label class="form-label" for="pwNeu2">Neues Passwort bestätigen</label>
                <input type="password" name="neu_passwort2" id="pwNeu2" class="form-control" autocomplete="new-password"
                       placeholder="Passwort wiederholen">
            </div>
            <div class="pf-actions">
                <button type="submit" class="scan-btn">Speichern</button>
                <button type="button" onclick="closePwModal()" class="scan-btn secondary">Abbrechen</button>
            </div>
        </form>
    </div>
</div>
<script>
function closePwModal() { document.getElementById('pwModal').style.display = 'none'; }
<?php if (!empty($pwFehler)): ?>
// Fehler vorhanden → Modal direkt öffnen
document.getElementById('pwModal').style.display = 'flex';
<?php endif; ?>
</script>

<style>
.choice-row { display: flex; gap: .5rem; }
.choice {
    flex: 1; display: flex; align-items: center; justify-content: center; gap: .5rem;
    background: var(--surface2); border: 1px solid var(--border);
    border-radius: 14px; padding: .7rem .9rem; min-height: 2.9rem; cursor: pointer; font-weight: 600;
}
.choice input { position: absolute; opacity: 0; pointer-events: none; }
.choice.on { background: var(--text); color: var(--bg); border-color: var(--text); }
.kt-range { width: 100%; accent-color: var(--accent); height: 2rem; }
.range-legend { display: flex; justify-content: space-between; font-size: .72rem; color: var(--muted); }
.alert-danger-soft {
    background: var(--danger-soft); border: 1px solid var(--danger); border-radius: 12px;
    padding: .65rem 1rem; font-size: .88rem; color: var(--danger); margin-bottom: .75rem;
}
</style>

<script>
function highlightGeschlecht() {
    ['m','w'].forEach(v => {
        const lbl = document.getElementById('lbl-geschlecht-' + v);
        const inp = lbl?.querySelector('input');
        if (lbl && inp) lbl.classList.toggle('on', inp.checked);
    });
}
highlightGeschlecht();

function updateSwitch(id) {
    const cb = document.getElementById(id);
    const sw = document.getElementById('sw-' + id);
    if (!cb || !sw) return;
    sw.classList.toggle('on', cb.checked);
    document.getElementById('row-' + id)?.setAttribute('aria-checked', cb.checked ? 'true' : 'false');
}
function toggleSwitch(id) {
    const cb = document.getElementById(id);
    if (!cb) return;
    cb.checked = !cb.checked;
    updateSwitch(id);
}
['cbGruppieren','cbMakros'].forEach(id => updateSwitch(id));
</script>

<?php renderFooter('profil'); ?>
