<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/bmr.php';
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

    $stmtEx = $db->prepare("SELECT id FROM profil WHERE user_id = ? LIMIT 1");
    $stmtEx->bind_param("i", $userId); $stmtEx->execute();
    $existing = $stmtEx->get_result()->fetch_assoc();
    if ($existing) {
        $stmt = $db->prepare("UPDATE profil SET groesse_cm=?, aktivitaet=?, defizit_kcal=?, geschlecht=?, geburtsjahr=?, eintraege_gruppieren=?, makros_anzeigen=? WHERE id=? AND user_id=?");
        $existingId = $existing['id'];
        $stmt->bind_param('isisiiiii', $groesse, $aktivitaet, $defizit, $geschlecht, $geburtsjahr, $gruppieren, $makros, $existingId, $userId);
    } else {
        $stmt = $db->prepare("INSERT INTO profil (user_id, groesse_cm, aktivitaet, defizit_kcal, geschlecht, geburtsjahr, eintraege_gruppieren, makros_anzeigen) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param('iisisiii', $userId, $groesse, $aktivitaet, $defizit, $geschlecht, $geburtsjahr, $gruppieren, $makros);
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
?>

<div class="page-header">
    <div style="display:flex;align-items:center;gap:.6rem;">
        <a href="/profil.php" style="color:var(--muted);text-decoration:none;font-size:1.1rem;">
            <i class="bi bi-chevron-left"></i>
        </a>
        <h1 style="margin:0;"><i class="bi bi-gear text-accent me-1"></i> Einstellungen</h1>
    </div>
    <?php if (isset($_GET['saved'])): ?>
    <span style="color:var(--accent);font-size:.82rem;"><i class="bi bi-check-circle-fill"></i> Gespeichert</span>
    <?php elseif (isset($_GET['pwsaved'])): ?>
    <span style="color:var(--accent);font-size:.82rem;"><i class="bi bi-check-circle-fill"></i> Passwort geändert</span>
    <?php endif; ?>
</div>

<form method="post" style="margin:0 1rem 1rem;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="profil">

    <!-- ── Meine Daten ──────────────────────────────────────────── -->
    <div class="profil-section-title">Meine Daten</div>

    <div class="mb-3">
        <label class="form-label" style="color:var(--muted);font-size:.82rem;">Geschlecht</label>
        <div style="display:flex;gap:.75rem;">
            <?php foreach (['m' => 'Männlich', 'w' => 'Weiblich'] as $val => $lbl): ?>
            <label style="flex:1;display:flex;align-items:center;gap:.5rem;
                          background:var(--surface);border:1px solid var(--border);
                          border-radius:12px;padding:.65rem .9rem;cursor:pointer;"
                   id="lbl-geschlecht-<?= $val ?>">
                <input type="radio" name="geschlecht" value="<?= $val ?>"
                       <?= ($profil['geschlecht'] ?? 'm') === $val ? 'checked' : '' ?>
                       onchange="highlightGeschlecht()">
                <?= $lbl ?>
            </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label" style="color:var(--muted);font-size:.82rem;">Geburtsjahr</label>
        <input type="number" name="geburtsjahr" min="1930" max="2010"
               value="<?= (int)($profil['geburtsjahr'] ?? 1990) ?>"
               class="form-control" inputmode="numeric"
               style="background:var(--surface);border-color:var(--border);color:var(--text);border-radius:12px;">
    </div>

    <div class="mb-3">
        <label class="form-label" style="color:var(--muted);font-size:.82rem;">Körpergröße (cm)</label>
        <input type="number" name="groesse" min="100" max="250"
               value="<?= (int)($profil['groesse_cm'] ?? 175) ?>"
               class="form-control" inputmode="numeric"
               style="background:var(--surface);border-color:var(--border);color:var(--text);border-radius:12px;">
    </div>

    <div class="mb-3">
        <label class="form-label" style="color:var(--muted);font-size:.82rem;">Aktivitätslevel</label>
        <div style="display:flex;align-items:center;gap:.5rem;">
            <select name="aktivitaet" class="form-select" style="flex:1;background:var(--surface);border-color:var(--border);color:var(--text);border-radius:12px;">
                <?php foreach ($aktivLabels as $val => $lbl): ?>
                <option value="<?= $val ?>" <?= ($profil['aktivitaet'] ?? 'moderat') === $val ? 'selected' : '' ?>>
                    <?= $lbl ?>
                </option>
                <?php endforeach; ?>
            </select>
            <span class="kt-info-inline" style="flex-shrink:0;"><button class="kt-info-btn" type="button" aria-label="Info"><i class="bi bi-info-circle-fill"></i></button><div class="kt-tooltip" style="right:0;left:auto;">„Tracking" verwendet nur den reinen Grundumsatz – ideal wenn du Aktivitätskalorien per Smartwatch separat erfasst (Doppelzählung wird vermieden).</div></span>
        </div>
    </div>

    <div class="mb-4">
        <label class="form-label" style="color:var(--muted);font-size:.82rem;">
            Kaloriendefizit: <strong id="defizitLabel"><?= (int)($profil['defizit_kcal'] ?? 500) ?></strong> kcal/Tag
            <span style="font-size:.72rem;">(≈ <?= round((int)($profil['defizit_kcal'] ?? 500) * 7 / 7700, 2) ?> kg/Woche)</span>
        </label>
        <input type="range" name="defizit" id="defizitSlider"
               min="0" max="1000" step="50"
               value="<?= (int)($profil['defizit_kcal'] ?? 500) ?>"
               style="width:100%;accent-color:var(--accent);"
               oninput="document.getElementById('defizitLabel').textContent = this.value">
        <div style="display:flex;justify-content:space-between;font-size:.7rem;color:var(--muted);margin-top:.25rem;">
            <span>0 (Erhalt)</span><span>500 (empfohlen)</span><span>1000</span>
        </div>
    </div>

    <!-- ── Ansicht ──────────────────────────────────────────────── -->
    <!-- Versteckte Checkboxen für Formular-Submit -->
    <input type="checkbox" name="eintraege_gruppieren" id="cbGruppieren"
           style="display:none;" <?= !empty($profil['eintraege_gruppieren']) ? 'checked' : '' ?>>
    <input type="checkbox" name="makros_anzeigen" id="cbMakros"
           style="display:none;" <?= ($profil['makros_anzeigen'] ?? 1) ? 'checked' : '' ?>>

    <div class="kt-card" style="margin:1rem 0;">
        <div style="font-size:.78rem;color:var(--muted);margin-bottom:.75rem;font-weight:600;
                    text-transform:uppercase;letter-spacing:.05em;">Ansicht</div>

        <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;
                    margin-bottom:1rem;cursor:pointer;" onclick="toggleSwitch('cbGruppieren')">
            <div style="flex:1;">
                <div style="font-size:.9rem;font-weight:600;">Gleiche Lebensmittel gruppieren</div>
                <div style="font-size:.78rem;color:var(--muted);margin-top:.15rem;">
                    Mehrfache Einträge desselben Produkts auf der Startseite zusammenfassen
                </div>
            </div>
            <div id="sw-cbGruppieren" class="kt-switch"><div class="kt-switch-knob"></div></div>
        </div>


        <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;
                    cursor:pointer;" onclick="toggleSwitch('cbMakros')">
            <div style="flex:1;">
                <div style="font-size:.9rem;font-weight:600;">Makros anzeigen</div>
                <div style="font-size:.78rem;color:var(--muted);margin-top:.15rem;">
                    Eiweiß, Fett und Kohlenhydrate auf der Startseite und im Verlauf einblenden
                </div>
            </div>
            <div id="sw-cbMakros" class="kt-switch"><div class="kt-switch-knob"></div></div>
        </div>
    </div>

    <button type="submit" class="scan-btn" style="display:block;width:calc(100% - 2rem);margin:.75rem 1rem .5rem;">
        <i class="bi bi-check-circle-fill"></i> Einstellungen speichern
    </button>
</form>

<button onclick="document.getElementById('pwModal').style.display='flex'"
        class="scan-btn secondary" style="display:block;width:calc(100% - 2rem);margin:.5rem 1rem;">
    <i class="bi bi-key"></i> Passwort ändern
</button>

<!-- ── Passwort-Modal ────────────────────────────────────────── -->
<div id="pwModal" onclick="if(event.target===this)closePwModal()"
     style="display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.7);
            align-items:center;justify-content:center;padding:1.25rem;">
    <div style="background:var(--bg);border-radius:20px;padding:1.5rem;width:100%;max-width:420px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
            <h2 style="font-size:1.05rem;font-weight:700;margin:0;">Passwort ändern</h2>
            <button onclick="closePwModal()"
                    style="background:var(--surface2);border:none;border-radius:50%;
                           width:2rem;height:2rem;color:var(--muted);font-size:1rem;
                           display:flex;align-items:center;justify-content:center;">
                <i class="bi bi-x"></i>
            </button>
        </div>
        <?php if (!empty($pwFehler)): ?>
        <div style="background:rgba(248,113,113,.15);border:1px solid #f87171;border-radius:12px;
                    padding:.65rem 1rem;font-size:.85rem;color:#f87171;margin-bottom:.75rem;">
            <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($pwFehler) ?>
        </div>
        <?php endif; ?>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="passwort">
            <div class="mb-3">
                <label class="form-label" style="color:var(--muted);font-size:.82rem;">Aktuelles Passwort</label>
                <input type="password" name="alt_passwort" class="form-control" autocomplete="current-password"
                       required
                       style="background:var(--surface);border-color:var(--border);color:var(--text);border-radius:12px;">
            </div>
            <div class="mb-3">
                <label class="form-label" style="color:var(--muted);font-size:.82rem;">Neues Passwort</label>
                <input type="password" name="neu_passwort" class="form-control" autocomplete="new-password"
                       minlength="8" placeholder="Mindestens 8 Zeichen"
                       style="background:var(--surface);border-color:var(--border);color:var(--text);border-radius:12px;">
            </div>
            <div class="mb-4">
                <label class="form-label" style="color:var(--muted);font-size:.82rem;">Neues Passwort bestätigen</label>
                <input type="password" name="neu_passwort2" class="form-control" autocomplete="new-password"
                       placeholder="Passwort wiederholen"
                       style="background:var(--surface);border-color:var(--border);color:var(--text);border-radius:12px;">
            </div>
            <div style="display:flex;flex-direction:column;gap:.5rem;">
                <button type="submit" class="scan-btn" style="margin:0;width:100%;">
                    <i class="bi bi-check-circle-fill"></i> Speichern
                </button>
                <button type="button" onclick="closePwModal()" class="scan-btn secondary" style="margin:0;width:100%;">
                    <i class="bi bi-x-circle"></i> Abbrechen
                </button>
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

<!-- ── Weitere Optionen ──────────────────────────────────────── -->
<a href="/api_docs.php" class="scan-btn secondary"
   style="display:block;width:calc(100% - 2rem);margin:.5rem 1rem;text-align:center;text-decoration:none;">
    <i class="bi bi-code-slash"></i> API-Dokumentation
</a>

<a href="/logout.php" class="scan-btn secondary"
   style="display:block;width:calc(100% - 2rem);margin:.5rem 1rem 1.5rem;text-decoration:none;text-align:center;color:var(--danger);">
        <i class="bi bi-box-arrow-right"></i> Abmelden (<?= htmlspecialchars($currentUser['display_name'] ?? $currentUser['username']) ?>)
    </a>

<script>
function highlightGeschlecht() {
    ['m','w'].forEach(v => {
        const lbl = document.getElementById('lbl-geschlecht-' + v);
        const inp = lbl?.querySelector('input');
        if (!lbl || !inp) return;
        lbl.style.borderColor = inp.checked ? 'var(--accent)' : 'var(--border)';
        lbl.style.color       = inp.checked ? 'var(--accent)' : '';
    });
}
highlightGeschlecht();

function updateSwitch(id) {
    const cb = document.getElementById(id);
    const sw = document.getElementById('sw-' + id);
    if (!cb || !sw) return;
    sw.style.background = cb.checked ? 'var(--accent)' : 'var(--surface2)';
    sw.querySelector('.kt-switch-knob').style.transform =
        cb.checked ? 'translateX(1.4rem)' : 'translateX(0)';
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
