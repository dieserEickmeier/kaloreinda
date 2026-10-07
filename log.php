<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
$currentUser = requireLogin();
$userId = $currentUser['id'];

$db = db();
$stmtP = $db->prepare("SELECT eintraege_gruppieren, makros_anzeigen FROM profil WHERE user_id = ? LIMIT 1");
$stmtP->bind_param('i', $userId);
$stmtP->execute();
$profilRow  = $stmtP->get_result()->fetch_assoc();
$zeigeMakros = (int)($profilRow['makros_anzeigen'] ?? 1);

// Pick-Modus: Seite wird aus dem Gericht-Editor heraus aufgerufen, um eine
// Zutat per Barcode/OCR/Suche/manuell zu erfassen. Ergebnis geht zurück an
// gerichte.php statt als Tageseintrag gebucht zu werden.
$pickMode = ($_GET['pick'] ?? '') === '1';

// Tagesrest für „danach X übrig“ im Produkt-Sheet (gleiche Logik wie „Heute“)
require_once __DIR__ . '/includes/ui.php';
$stmtPf = $db->prepare("SELECT * FROM profil WHERE user_id = ? LIMIT 1");
$stmtPf->bind_param('i', $userId);
$stmtPf->execute();
$profilFull = $stmtPf->get_result()->fetch_assoc() ?: [];
$restHeute  = $pickMode ? null
    : ladeTageswerte($db, $userId, $profilFull, date('Y-m-d'), date('Y-m-d'))[date('Y-m-d')]['uebrig'];

renderHeader('Erfassen', 'log');
?>
<?php renderPageHeader($pickMode ? 'Zutat wählen' : 'Erfassen',
    $pickMode ? '' : '<a href="/gerichte.php" class="icon-btn" aria-label="Gerichte verwalten"><i class="bi bi-journal-richtext"></i></a>'); ?>
<?php if ($pickMode): ?>
<div class="pick-banner">
    <span><i class="bi bi-journal-richtext text-accent me-1"></i> Zutat für Gericht auswählen</span>
    <button type="button" id="btnPickAbbrechen" class="btn-link-muted">Abbrechen</button>
</div>
<?php endif; ?>

<!-- ── Erfassen-Aktionen ───────────────────────────────────────── -->
<div class="log-actions">
    <!-- Barcode Foto (ausgeblendet, Funktion erhalten) -->
    <button id="btnBarcodeFoto" style="display:none;"></button>
    <div class="action-row">
        <button id="btnBarcodeEingeben" class="action-tile" type="button">
            <i class="bi bi-keyboard"></i><span>Barcode<br>eingeben</span>
        </button>
        <button id="btnOcrScan" class="action-tile" type="button">
            <span class="badge-beta">BETA</span>
            <i class="bi bi-body-text"></i><span>Nährwerte<br>scannen</span>
        </button>
        <button id="ddSchnell" class="action-tile" type="button">
            <i class="bi bi-lightning-fill" style="color:var(--quick);"></i><span>Schneller<br>Eintrag</span>
        </button>
        <button id="ddManuell" class="action-tile" type="button">
            <i class="bi bi-pencil-square"></i><span>Manuell<br>erfassen</span>
        </button>
    </div>
</div>

<!-- Versteckte Elemente für Barcode-Foto-Verarbeitung -->
<input type="file" id="barcodeFileInput" accept="image/*" capture="environment" class="d-none">
<canvas id="barcodeCanvas" style="display:none;"></canvas>

<!-- Lade-Status -->
<div id="barcodeStatus" class="log-status" style="display:none;">
    <div class="spinner-border spinner-border-sm spinner-accent me-1"></div>
    <span id="barcodeStatusText">Barcode wird erkannt…</span>
</div>

<!-- Fehler: nicht gefunden -->
<div id="notFound" class="d-none kt-card text-center" style="margin:.5rem .85rem 1rem;">
    <i class="bi bi-upc-scan" style="font-size:2rem;display:block;margin-bottom:.5rem;color:var(--muted);"></i>
    <div style="font-weight:700;margin-bottom:.4rem;">Produkt nicht gefunden</div>
    <div class="text-muted" style="font-size:.85rem;line-height:1.5;">
        Der Barcode konnte nicht erkannt oder das Produkt nicht in OpenFoodFacts gefunden werden.<br>
        Versuche es nochmal mit besserem Licht oder gib das Produkt manuell ein.
    </div>
</div>

<!-- ── Live-Scan ─────────────────────────────────────────────── -->
<div id="scanLiveArea" class="d-none">
    <div class="scanner-wrap" id="liveScannerWrap"><div id="liveQrReader"></div></div>
    <div id="liveScanStatus" class="text-center py-3 px-3"
         style="color:var(--muted);font-size:.88rem;min-height:3rem;line-height:1.5;">
        <i class="bi bi-camera-video me-1"></i> Kamera wird gestartet…
    </div>
</div>

<!-- ── Suche ─────────────────────────────────────────────────── -->
<div class="log-search">
    <div class="search-field">
        <i class="bi bi-search"></i>
        <input type="search" id="searchInput" placeholder="Lebensmittel &amp; Gerichte suchen…"
               autocomplete="off" autocorrect="off" spellcheck="false" aria-label="Suchen">
        <span class="kt-info-inline" style="flex-shrink:0;"><button class="kt-info-btn" type="button" aria-label="Info"><i class="bi bi-info-circle"></i></button><div class="kt-tooltip" style="right:0;left:auto;top:calc(100% + .3rem);"> Durchsucht alle Lebensmittel die du bereits gescannt oder manuell angelegt hast – sowie deine gespeicherten Gerichte.<br><br>
<strong>Häufige Lebensmittel</strong> – deine 8 meistgenutzten Produkte aus direkten Einträgen. Produkte die nur als Teil eines Gerichts verwendet wurden erscheinen hier nicht.<br><br>
<strong>Häufige Gerichte</strong> – deine zuletzt angelegten Gerichte. Antippen zum Eintragen.<br><br>
<strong>Was gespeichert wird:</strong><br>
· Barcode-Scan – global, für alle Nutzer sichtbar<br>
· Manuell erfassen – nur für dich sichtbar<br>
· Schneller Eintrag – wird nicht als Produkt gespeichert<br>
· Gerichte – immer privat<br><br>
<strong>„Nur anlegen“</strong> – Produkt speichern ohne Buchung für heute, z.B. beim Vorkochen für die Woche. Am jeweiligen Tag dann einfach über die Suche eintragen.
</div></span>
    </div>
    <div id="searchResults" class="list-tile d-none" style="margin:.6rem 0 0;"></div>
</div>

<!-- ── Top 8 ─────────────────────────────────────────────────── -->
<div class="section-label"><span>Häufige Lebensmittel</span></div>
<div id="top5Chips" class="pick-grid">
    <span class="pick-empty">Wird geladen…</span>
</div>

<?php if (!$pickMode): ?>
<!-- ── Häufige Gerichte ───────────────────────────────────────── -->
<div id="gerichteSectionWrapper">
    <div class="section-label"><span>Häufige Gerichte</span><a href="/gerichte.php" class="section-link">Alle <i class="bi bi-chevron-right"></i></a></div>
    <div id="topGerichteChips" class="pick-grid pick-grid--1">
        <span class="pick-empty">Wird geladen…</span>
    </div>
</div>
<?php endif; ?>

<!-- ── Modal: Gericht eintragen ──────────────────────────────── -->
<div id="gerichtEintragenModal" class="product-found">
  <div class="pf-card">
    <div class="pf-head">
        <div class="pf-head__text">
            <div id="gerichtEintragenInfo" class="pf-src">–</div>
            <div id="gerichtEintragenName" class="pf-name">–</div>
        </div>
        <button class="pf-close" id="btnGerichtVerwerfen" aria-label="Schließen">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <div class="pf-kcal">
        <div class="pf-kcal__num" id="geKcalPreview">0</div>
        <div class="pf-kcal__unit">kcal</div>
        <?php if ($restHeute !== null): ?><div class="pf-kcal__after" id="geAfter">danach<br><b>–</b></div><?php endif; ?>
    </div>

    <div class="pf-macros">
        <div class="pf-macro">
            <div class="pf-macro__ring" style="--c:var(--accent);"><span id="geKcalPortion">–</span></div>
            <span class="pf-macro__lab">kcal/Port.</span>
        </div>
        <div class="pf-macro">
            <div class="pf-macro__ring" style="--c:var(--prot);"><span id="geKcalGesamt">–</span></div>
            <span class="pf-macro__lab">kcal ges.</span>
        </div>
        <div class="pf-macro">
            <div class="pf-macro__ring" style="--c:var(--carb);"><span id="gePortionen">–</span></div>
            <span class="pf-macro__lab">Portionen</span>
        </div>
    </div>

    <!-- Modus-Umschalter: Portionen oder Gramm -->
    <div class="pf-portion-wrap" style="display:flex;">
        <button type="button" id="geModeGramm" onclick="setGerichtModus('gramm')" class="portion-toggle">
            Gramm
        </button>
        <button type="button" id="geModePortion" onclick="setGerichtModus('portion')" class="portion-toggle active">
            Portionen
        </button>
    </div>

    <!-- Menge: Stepper + Schnellwahl -->
    <div class="pf-menge">
        <button type="button" class="pf-stepper" id="btnGeMinus" aria-label="Weniger">
            <i class="bi bi-dash-lg"></i>
        </button>
        <div class="pf-menge__field">
            <input type="number" inputmode="decimal" id="gerichtEintragenPortionen" value="1" min="0.5" step="0.5" aria-label="Menge">
            <span id="gerichtEintragenEinheit">Portion(en)</span>
        </div>
        <button type="button" class="pf-stepper" id="btnGePlus" aria-label="Mehr">
            <i class="bi bi-plus-lg"></i>
        </button>
    </div>
    <div class="ruler" data-ruler-for="gerichtEintragenPortionen"></div>
    <div class="pf-chips" id="geQuickChipsPortion">
        <button type="button" class="pf-chip" data-val="0.5">½</button>
        <button type="button" class="pf-chip active" data-val="1">1</button>
        <button type="button" class="pf-chip" data-val="1.5">1½</button>
        <button type="button" class="pf-chip" data-val="2">2</button>
        <button type="button" class="pf-chip" data-val="3">3</button>
    </div>
    <div class="pf-chips" id="geQuickChipsGramm" style="display:none;">
        <button type="button" class="pf-chip" data-val="50">50</button>
        <button type="button" class="pf-chip" data-val="100">100</button>
        <button type="button" class="pf-chip" data-val="150">150</button>
        <button type="button" class="pf-chip" data-val="200">200</button>
        <button type="button" class="pf-chip" data-val="250">250</button>
    </div>

    <div class="pf-actions">
        <button class="scan-btn btn-split" id="btnGerichtEintragen">
            <span>Hinzufügen</span>
            <span><span id="geBtnKcal" class="num">0</span> kcal <i class="bi bi-arrow-right"></i></span>
        </button>
    </div>
  </div>
</div>







<!-- ── Modal: manuelle Erfassung ─────────────────────────────── -->
<div id="manualModal" class="kt-overlay" style="display:none;">
    <div class="sheet sheet--abs">
        <div class="sheet-head">
            <h2>Lebensmittel manuell erfassen</h2>
            <button type="button" class="pf-close" onclick="closeManualModal()" aria-label="Schließen"><i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="kt-form">
            <div class="mb-3">
                <label class="form-label">Produktname *</label>
                <input type="text" id="fName" class="form-control" placeholder="z.B. Vollkornbrot" autocomplete="off" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Menge (g)</label>
                <input type="number" id="fMenge" class="form-control" value="100" min="1" max="5000" step="1" inputmode="decimal">
            </div>
            <div class="mb-3">
                <label class="form-label">Kalorien (kcal / 100g) *</label>
                <input type="number" id="fKcal" class="form-control" placeholder="z.B. 250" min="0" step="0.1" inputmode="decimal">
            </div>
            <div class="mb-4">
                <label class="form-label">Portionsgröße (g) <span class="text-muted">optional</span></label>
                <input type="number" id="fPortion" class="form-control" placeholder="z.B. 30" min="1" step="0.5" inputmode="decimal">
            </div>
            <?php if ($zeigeMakros): ?>
            <div class="mb-4">
                <label class="form-label">Makros / 100 g <span class="text-muted">(optional)</span></label>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem;">
                    <div>
                        <label class="kt-label fg-c c-prot">Eiweiß g</label>
                        <input type="number" id="fEiweiss" class="form-control" placeholder="0" min="0" step="0.1" inputmode="decimal"
                              >
                    </div>
                    <div>
                        <label class="kt-label fg-c c-fat">Fett g</label>
                        <input type="number" id="fFett" class="form-control" placeholder="0" min="0" step="0.1" inputmode="decimal"
                              >
                    </div>
                    <div>
                        <label class="kt-label fg-c c-carb">KH g</label>
                        <input type="number" id="fKh" class="form-control" placeholder="0" min="0" step="0.1" inputmode="decimal"
                              >
                    </div>
                </div>
            </div>
            <?php else: ?>
            <input type="hidden" id="fEiweiss" value="0">
            <input type="hidden" id="fFett"    value="0">
            <input type="hidden" id="fKh"      value="0">
            <?php endif; ?>
            <div id="kcalPreviewManual" class="sheet-preview" style="display:none;">– kcal</div>
            <?php if (!$pickMode): ?>
            <div class="ziel-toggle-wrap">
                <button type="button" class="portion-toggle active" id="manualZielHeute">
                    <i class="bi bi-calendar-check"></i> Heute eintragen
                </button>
                <button type="button" class="portion-toggle" id="manualZielSpeichern">
                    <i class="bi bi-archive"></i> Nur anlegen
                </button>
            </div>
            <?php endif; ?>
            <button class="scan-btn mb-2" id="btnSpeichern">
                <i class="bi bi-check-circle-fill"></i>
                <?= $pickMode ? 'Als Zutat übernehmen' : 'Eintragen' ?>
            </button>
            <button class="scan-btn secondary" onclick="closeManualModal()">
                <i class="bi bi-x-circle"></i> Abbrechen
            </button>
        </div>
    </div>
</div>

<!-- ── Modal: Nährwerte scannen (OCR Beta) ───────────────────── -->
<div id="ocrModal" style="display:none;position:fixed;inset:0;z-index:500;
     background:rgba(0,0,0,.85);flex-direction:column;">

    <!-- Kamera-Bereich -->
    <div style="position:relative;flex:1;overflow:hidden;background:#000;
                display:flex;align-items:center;justify-content:center;">
        <video id="ocrVideo" autoplay playsinline muted
               style="width:100%;height:100%;object-fit:cover;display:block;"></video>

        <!-- Scan-Rahmen für Nährwerttabelle (hochformat) -->
        <div style="position:absolute;inset:0;pointer-events:none;
                    display:flex;align-items:center;justify-content:center;">
            <div id="ocrFrame" style="
                width:85%;aspect-ratio:3/4;
                border:2px solid var(--carb);border-radius:12px;
                box-shadow:0 0 0 9999px rgba(0,0,0,.5);
                transition:border-color .2s,box-shadow .2s;">
                <!-- Ecken -->
                <div style="position:absolute;top:0;left:0;width:22px;height:22px;
                            border-top:3px solid var(--carb);border-left:3px solid var(--carb);border-radius:4px 0 0 0;"></div>
                <div style="position:absolute;top:0;right:0;width:22px;height:22px;
                            border-top:3px solid var(--carb);border-right:3px solid var(--carb);border-radius:0 4px 0 0;"></div>
                <div style="position:absolute;bottom:0;left:0;width:22px;height:22px;
                            border-bottom:3px solid var(--carb);border-left:3px solid var(--carb);border-radius:0 0 0 4px;"></div>
                <div style="position:absolute;bottom:0;right:0;width:22px;height:22px;
                            border-bottom:3px solid var(--carb);border-right:3px solid var(--carb);border-radius:0 0 4px 0;"></div>
                <!-- Hinweistext im Rahmen -->
                <div style="position:absolute;bottom:.5rem;left:0;right:0;text-align:center;
                            font-size:.7rem;color:rgba(167,139,250,.8);">
                    Nährwerttabelle im Rahmen platzieren
                </div>
            </div>
        </div>
        <!-- Flash -->
        <div id="ocrFlash" style="position:absolute;inset:0;background:#fff;
             opacity:0;pointer-events:none;transition:opacity .1s;"></div>
    </div>

    <!-- Controls -->
    <div style="background:var(--bg);padding:1rem 1.5rem calc(1rem + env(safe-area-inset-bottom,0px));
                display:flex;flex-direction:column;align-items:center;gap:.75rem;">
        <div style="display:flex;align-items:center;gap:.5rem;">
            <div id="ocrDot" style="width:8px;height:8px;border-radius:50%;
                 background:var(--carb);flex-shrink:0;"></div>
            <span id="ocrStatus" style="font-size:.9rem;color:var(--text);font-weight:500;">
                Bereit – Foto aufnehmen
            </span>
        </div>
        <div style="display:flex;gap:.75rem;width:100%;max-width:320px;">
            <button id="btnOcrCapture" onclick="ocrCapture()"
                    style="flex:1;background:var(--carb);border:none;border-radius:14px;
                           color:#160b33;font-size:1rem;font-weight:700;padding:.85rem;
                           cursor:pointer;-webkit-tap-highlight-color:transparent;">
                <i class="bi bi-camera-fill"></i> Foto aufnehmen
            </button>
            <button onclick="ocrStop()"
                    style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);
                           border-radius:14px;color:var(--text);font-size:.9rem;font-weight:600;
                           padding:.85rem 1rem;cursor:pointer;-webkit-tap-highlight-color:transparent;">
                ✕
            </button>
        </div>
    </div>
</div>

<!-- ── Modal: OCR Ergebnis bearbeiten ─────────────────────────────── -->
<div id="ocrResultModal" class="kt-overlay" style="display:none;z-index:600;">
    <div class="sheet sheet--abs">
        <div class="sheet-head">
            <div>
                <h2>Erkannte Nährwerte</h2>
                <p class="sheet-sub">
                    Bitte prüfen und ggf. korrigieren
                </p>
            </div>
            <button type="button" class="pf-close" onclick="closeOcrResult()" aria-label="Schließen"><i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="mb-3">
            <label class="form-label">Produktname *</label>
            <input type="text" id="ocrName" class="form-control"
                   placeholder="z.B. Vollkornbrot" autocomplete="off">
        </div>
        <div class="mb-3">
            <label class="form-label">Kalorien / 100g *</label>
            <input type="number" id="ocrKcal" class="form-control"
                   placeholder="0" min="0" step="1" inputmode="decimal">
        </div>
        <?php if ($zeigeMakros): ?>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem;" class="mb-4">
            <div>
                <label class="kt-label fg-c c-prot">Eiweiß g/100g</label>
                <input type="number" id="ocrEiweiss" class="form-control" placeholder="0"
                       min="0" step="0.1" inputmode="decimal">
            </div>
            <div>
                <label class="kt-label fg-c c-fat">Fett g/100g</label>
                <input type="number" id="ocrFett" class="form-control" placeholder="0"
                       min="0" step="0.1" inputmode="decimal">
            </div>
            <div>
                <label class="kt-label fg-c c-carb">KH g/100g</label>
                <input type="number" id="ocrKh" class="form-control" placeholder="0"
                       min="0" step="0.1" inputmode="decimal">
            </div>
        </div>
        <?php else: ?>
        <input type="hidden" id="ocrEiweiss" value="0">
        <input type="hidden" id="ocrFett" value="0">
        <input type="hidden" id="ocrKh" value="0">
        <?php endif; ?>

        <!-- Menge -->
        <div class="mb-4">
            <label class="form-label">Menge (g)</label>
            <input type="number" id="ocrMenge" class="form-control"
                   placeholder="100" value="100" min="1" step="1" inputmode="decimal">
        </div>

        <!-- Vorschau -->
        <div id="ocrPreview" class="sheet-preview" style="display:none;"></div>

        <?php if (!$pickMode): ?>
        <div class="ziel-toggle-wrap">
            <button type="button" class="portion-toggle active" id="ocrZielHeute">
                <i class="bi bi-calendar-check"></i> Heute eintragen
            </button>
            <button type="button" class="portion-toggle" id="ocrZielSpeichern">
                <i class="bi bi-archive"></i> Nur anlegen
            </button>
        </div>
        <?php endif; ?>
        <button onclick="ocrSave()" class="scan-btn mb-2" id="btnOcrSave">
            <i class="bi bi-check-circle-fill"></i>
            <?= $pickMode ? 'Als Zutat übernehmen' : 'Eintragen' ?>
        </button>
        <button onclick="closeOcrResult()" class="scan-btn secondary">
            Abbrechen
        </button>
    </div>
</div>

<!-- ── Modal: Schneller Eintrag ──────────────────────────────── -->
<div id="schnellModal" class="kt-overlay" style="display:none;">
    <div class="sheet sheet--abs">
        <div class="sheet-head">
            <div>
                <h2>Schneller Eintrag</h2>
                <p class="sheet-sub">
                    Wird nur heute eingetragen, nicht als Produkt gespeichert.
                </p>
            </div>
            <button type="button" class="pf-close" onclick="closeSchnellModal()" aria-label="Schließen"><i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="kt-form">
            <div class="mb-3">
                <label class="form-label">Name <span class="text-muted">(optional)</span></label>
                <input type="text" id="sfName" class="form-control"
                       placeholder="z.B. Apfel" autocomplete="off">
            </div>
            <div class="mb-3">
                <label class="form-label">Kalorien (kcal gesamt) *</label>
                <input type="number" id="sfKcal" class="form-control"
                       placeholder="z.B. 150" min="0" step="1" inputmode="decimal">
            </div>
            <?php if ($zeigeMakros): ?>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem;" class="mb-4">
                <div>
                    <label class="kt-label fg-c c-prot">Eiweiß g</label>
                    <input type="number" id="sfEiweiss" class="form-control" placeholder="0"
                           min="0" step="0.1" inputmode="decimal">
                </div>
                <div>
                    <label class="kt-label fg-c c-fat">Fett g</label>
                    <input type="number" id="sfFett" class="form-control" placeholder="0"
                           min="0" step="0.1" inputmode="decimal">
                </div>
                <div>
                    <label class="kt-label fg-c c-carb">KH g</label>
                    <input type="number" id="sfKh" class="form-control" placeholder="0"
                           min="0" step="0.1" inputmode="decimal">
                </div>
            </div>
            <?php else: ?>
            <input type="hidden" id="sfEiweiss" value="0">
            <input type="hidden" id="sfFett"    value="0">
            <input type="hidden" id="sfKh"      value="0">
            <?php endif; ?>
            <div id="schnellKcalPreview" class="sheet-preview" style="display:none;">– kcal</div>
            <button class="scan-btn mb-2" id="btnSchnellSpeichern">
                <i class="bi bi-lightning-fill"></i> Schnell eintragen
            </button>
            <button class="scan-btn secondary" onclick="closeSchnellModal()">
                <i class="bi bi-x-circle"></i> Abbrechen
            </button>
        </div>
    </div>
</div>

<!-- ── Produktkarte als Modal ─────────────────────────────────── -->
<div id="productFound" class="product-found">
  <div class="pf-card">
    <!-- Kopf: Icon, Name, Quelle, Schließen -->
    <div class="pf-head">
        <div class="pf-head__text">
            <div id="pfSrc">–</div>
            <div id="pfName">–</div>
        </div>
        <button class="pf-close" id="btnVerwerfen" aria-label="Schließen">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <!-- Große kcal-Anzeige, live berechnet -->
    <div class="pf-kcal">
        <div class="pf-kcal__num" id="kcalPreview">0</div>
        <div class="pf-kcal__unit">kcal</div>
        <?php if ($restHeute !== null): ?><div class="pf-kcal__after" id="pfAfter">danach<br><b>–</b></div><?php endif; ?>
    </div>

    <!-- Makro-Ringe pro 100g -->
    <div class="pf-macros">
        <div class="pf-macro">
            <div class="pf-macro__ring" style="--c:var(--prot);"><span id="pfEiweiss">–</span></div>
            <span class="pf-macro__lab">Eiweiß</span>
        </div>
        <div class="pf-macro">
            <div class="pf-macro__ring" style="--c:var(--fat);"><span id="pfFett">–</span></div>
            <span class="pf-macro__lab">Fett</span>
        </div>
        <div class="pf-macro">
            <div class="pf-macro__ring" style="--c:var(--carb);"><span id="pfKh">–</span></div>
            <span class="pf-macro__lab">KH</span>
        </div>
    </div>
    <span id="pfKcal" style="display:none;"><!-- bleibt im DOM: showProduct() befüllt es weiterhin, wird aber nicht mehr angezeigt --></span>
    <div class="pf-macros__hint">je 100 g</div>

    <!-- Portion: Gramm oder Portionsgröße -->
    <div id="portionToggleWrap" class="pf-portion-wrap">
        <button id="toggleGramm" class="portion-toggle active">Gramm</button>
        <button id="togglePortion" class="portion-toggle">Portion</button>
        <input type="number" id="portionSizeInput" inputmode="decimal" min="1" step="0.5"
               placeholder="Portionsgröße in g" class="pf-portion-input">
    </div>

    <!-- Menge: Zahl + Stepper, Lineal, Schnellwahl-Chips -->
    <div class="pf-menge">
        <button type="button" class="pf-stepper" id="btnMengeMinus" aria-label="Weniger">
            <i class="bi bi-dash-lg"></i>
        </button>
        <div class="pf-menge__field">
            <input type="number" inputmode="decimal" id="mengeInput" value="100" min="1" max="5000" aria-label="Menge">
            <span id="mengeUnitLabel">g</span>
        </div>
        <button type="button" class="pf-stepper" id="btnMengePlus" aria-label="Mehr">
            <i class="bi bi-plus-lg"></i>
        </button>
    </div>
    <div class="ruler" data-ruler-for="mengeInput"></div>
    <div class="pf-chips" id="pfQuickChips">
        <button type="button" class="pf-chip" data-val="50">50</button>
        <button type="button" class="pf-chip" data-val="100">100</button>
        <button type="button" class="pf-chip" data-val="150">150</button>
        <button type="button" class="pf-chip" data-val="200">200</button>
        <button type="button" class="pf-chip" data-val="250">250</button>
    </div>
    <div class="pf-chips" id="pfQuickChipsPortion" style="display:none;">
        <button type="button" class="pf-chip" data-val="0.5">½</button>
        <button type="button" class="pf-chip" data-val="1">1</button>
        <button type="button" class="pf-chip" data-val="1.5">1½</button>
        <button type="button" class="pf-chip" data-val="2">2</button>
        <button type="button" class="pf-chip" data-val="3">3</button>
    </div>

    <!-- Aktionen -->
    <div class="pf-actions">
        <button class="scan-btn btn-split" id="btnEintragen">
            <span><?= $pickMode ? 'Als Zutat übernehmen' : 'Hinzufügen' ?></span>
            <span><span id="pfBtnKcal" class="num">0</span> kcal <i class="bi bi-arrow-right"></i></span>
        </button>
        <button class="scan-btn danger" id="btnDeleteProdukt" style="display:none;">
            <i class="bi bi-trash3"></i> Manuellen Eintrag löschen
        </button>
    </div>
  </div>
</div>

<!-- ── Modal: Barcode manuell eingeben ───────────────────────── -->
<div id="barcodeManuellModal"
     class="kt-overlay" style="display:none;">
    <div class="sheet sheet--abs">
        <div class="sheet-head">
            <h2>Barcode eingeben</h2>
            <button type="button" class="pf-close" onclick="closeBarcodeManuellModal()" aria-label="Schließen"><i class="bi bi-x-lg"></i>
            </button>
        </div>
        <input type="text" id="manualBarcodeInput" inputmode="numeric" pattern="[0-9]*"
               placeholder="z.B. 4005500201809"
               class="form-control mb-3" style="letter-spacing:.1em;font-size:1.1rem;">
        <button class="scan-btn" id="btnLookupManual">
            <i class="bi bi-search"></i> Produkt suchen
        </button>
    </div>
</div>

<!-- ── Toast ─────────────────────────────────────────────────── -->
<div class="toast-container position-fixed bottom-0 start-50 translate-middle-x mb-5 pb-4">
    <div id="toast" class="toast align-items-center text-bg-dark border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="toastMsg"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<style>
.mode-btn {
    flex:1;display:flex;flex-direction:column;align-items:center;
    gap:.3rem;padding:.65rem .5rem;
    background:var(--surface);border:1px solid var(--border);
    border-radius:14px;color:var(--muted);
    font-size:.72rem;font-weight:600;cursor:pointer;transition:all .15s;
}
.mode-btn i { font-size:1.3rem; }
.mode-btn.active { background:var(--accent-soft);border-color:var(--accent);color:var(--accent); }
.mode-btn:active { opacity:.75; }
</style>

<script src="https://cdn.jsdelivr.net/npm/@ericblade/quagga2@1.4.2/dist/quagga.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
let currentProduct = null;
const pickMode = new URLSearchParams(location.search).get('pick') === '1';

// ── Ziel-Toggle: "heute eintragen" vs. "nur anlegen" ───────────────────────
// Deckt den Meal-Prep-Fall ab: Lebensmittel soll in der Datenbank existieren,
// aber nicht sofort auf heute gebucht werden – die Buchung passiert später,
// am jeweiligen Tag, über die Suche. Statt eines zusätzlichen Buttons wird
// die bestehende Sichern-Aktion umgeschaltet (Label + Verhalten), damit pro
// Erfassungsweg weiterhin nur eine Aktion sichtbar bleibt. Zustand bleibt
// zwischen Erfassungen bestehen, damit mehrere Gerichte hintereinander im
// selben Modus angelegt werden können, ohne jedes Mal neu umzuschalten.
function initZielToggle(idHeute, idSpeichern, onChange) {
    const btnHeute     = document.getElementById(idHeute);
    const btnSpeichern = document.getElementById(idSpeichern);
    let ziel = 'heute';
    function set(z) {
        ziel = z;
        btnHeute.classList.toggle('active', z === 'heute');
        btnSpeichern.classList.toggle('active', z === 'speichern');
        onChange(z);
    }
    btnHeute.addEventListener('click', () => set('heute'));
    btnSpeichern.addEventListener('click', () => set('speichern'));
    return { get: () => ziel };
}

let manualZielCtrl = null, ocrZielCtrl = null;
if (document.getElementById('manualZielHeute')) {
    manualZielCtrl = initZielToggle('manualZielHeute', 'manualZielSpeichern', z => {
        document.getElementById('btnSpeichern').innerHTML = z === 'speichern'
            ? '<i class="bi bi-archive-fill"></i> Nur anlegen'
            : '<i class="bi bi-check-circle-fill"></i> Eintragen';
    });
}
if (document.getElementById('ocrZielHeute')) {
    ocrZielCtrl = initZielToggle('ocrZielHeute', 'ocrZielSpeichern', z => {
        document.getElementById('btnOcrSave').innerHTML = z === 'speichern'
            ? '<i class="bi bi-archive-fill"></i> Nur anlegen'
            : '<i class="bi bi-check-circle-fill"></i> Eintragen';
    });
}

// Zutat zurück an gerichte.php geben: dort liegt der Gericht-Entwurf in
// sessionStorage (s. goScanZutat() dort), das Ergebnis kommt genauso zurück.
function returnPickedZutat(zutat) {
    sessionStorage.setItem('kt_pick_result', JSON.stringify(zutat));
    location.href = '/gerichte.php?pickedZutat=1';
}
function returnPickCancel() {
    location.href = '/gerichte.php';
}
if (pickMode) {
    document.getElementById('btnPickAbbrechen').addEventListener('click', returnPickCancel);
}

// ── Helpers ──────────────────────────────────────────────────────────────
function showToast(msg) {
    document.getElementById('toastMsg').textContent = msg;
    bootstrap.Toast.getOrCreateInstance(document.getElementById('toast')).show();
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// Argumente für openGerichtEintragen(...) in einem onclick-Attribut.
// Der Name wird als JSON-String-Literal übergeben und das Ganze HTML-escaped:
// Der Browser dekodiert Entities im Attribut VOR der JS-Ausführung, ein
// bloßes escHtml('...') innerhalb von '…' schützt daher nicht vor Ausbruch.
function gerichtArgs(g) {
    return escHtml([
        Number(g.id) || 0, JSON.stringify(String(g.name ?? '')), Number(g.portionen) || 0,
        Number(g.kcal_gesamt) || 0, Number(g.gewicht_gesamt) || 0,
    ].join(','));
}

function showProduct(p) {
    currentProduct = p;
    const quellMap = {openfoodfacts:'OpenFoodFacts',manuell:'Manuell erfasst'};
    document.getElementById('pfName').textContent    = p.name;
    document.getElementById('pfSrc').textContent     = quellMap[p.quelle] || p.quelle || 'Eigene Datenbank';
    document.getElementById('pfKcal').textContent    = Math.round(p.kcal_100g);
    document.getElementById('pfEiweiss').textContent = Math.round(p.eiweiss_100g) + 'g';
    document.getElementById('pfFett').textContent    = Math.round(p.fett_100g) + 'g';
    document.getElementById('pfKh').textContent      = Math.round(p.kh_100g) + 'g';
    document.getElementById('mengeInput').value = 100;
    setupPortionToggle(p.portion_g || null, 'mengeInput');
    updateKcalPreview();
    document.getElementById('productFound').classList.add('visible');
    document.getElementById('notFound').classList.add('d-none');
    document.getElementById('searchResults').classList.add('d-none');
    document.getElementById('searchInput').value = '';
    // Barcode-Infokarte ausblenden
    // Löschen-Button nur für manuell erfasste Produkte zeigen —
    // OpenFoodFacts-Einträge sind lokaler Cache und können nicht
    // aus OpenFoodFacts selbst gelöscht werden, was zu Verwirrung führt.
    document.getElementById('btnDeleteProdukt').style.display =
        (p.id && p.quelle === 'manuell') ? 'block' : 'none';
}

// Gewählte Menge in Gramm – im Portion-Modus Anzahl × Portionsgröße
function getPfMengeG() {
    if (window._getMengeG) return window._getMengeG();
    return parseFloat(document.getElementById('mengeInput').value) || 0;
}

function updateKcalPreview() {
    if (!currentProduct) return;
    const menge = getPfMengeG();
    const kcal  = Math.round(currentProduct.kcal_100g * menge / 100);
    document.getElementById('kcalPreview').textContent = kcal;
    document.getElementById('pfBtnKcal').textContent   = kcal;
    updateDanach('pfAfter', kcal);
}

// „danach X übrig“ im Sheet – Tagesrest kommt aus PHP (wie auf „Heute“)
const REST_HEUTE = <?= $restHeute === null ? 'null' : (int)round($restHeute) ?>;
function updateDanach(elId, kcal) {
    const el = document.getElementById(elId);
    if (!el || REST_HEUTE === null) return;
    const r = REST_HEUTE - kcal;
    const b = el.querySelector('b');
    b.textContent = Math.abs(r).toLocaleString('de-DE') + (r >= 0 ? ' übrig' : ' drüber');
    b.classList.toggle('over', r < 0);
}

// ── Scan-Modus ───────────────────────────────────────────────────────────
let activeScanMode = null;

function setScanMode(mode) {
    const liveArea = document.getElementById('scanLiveArea');

    if (mode === activeScanMode) {
        liveArea.classList.add('d-none');
        stopLiveScanner();
        activeScanMode = null;
        return;
    }

    activeScanMode = mode;
    if (mode === 'live') {
        liveArea.classList.remove('d-none');
        startLiveScanner();
    }

    document.getElementById('productFound').classList.remove('visible');
    document.getElementById('notFound').classList.add('d-none');
}

// ── Barcode manuell Modal ────────────────────────────────────────────────
function openBarcodeManuellModal() {
    document.getElementById('manualBarcodeInput').value = '';
    document.getElementById('barcodeManuellModal').style.display = 'block';
    // Focus direkt ohne setTimeout – iOS öffnet die Tastatur nur wenn focus()
    // synchron im User-Gesture-Handler aufgerufen wird, nicht nach einem Delay.
    document.getElementById('manualBarcodeInput').focus();
}
function closeBarcodeManuellModal() {
    document.getElementById('barcodeManuellModal').style.display = 'none';
}
document.getElementById('barcodeManuellModal').addEventListener('click', function(e) {
    if (e.target === this) closeBarcodeManuellModal();
});

// ── Foto → Base64 ────────────────────────────────────────────────────────
function fileToBase64(file) {
    return new Promise((resolve, reject) => {
        const r = new FileReader();
        r.onload  = e => resolve({ base64: e.target.result.split(',')[1], mime: file.type });
        r.onerror = reject;
        r.readAsDataURL(file);
    });
}

// ── Barcode: Quagga2 ─────────────────────────────────────────────────────
async function handleBarcodePhoto(file) {
    const statusEl  = document.getElementById('barcodeStatus');
    const statusTxt = document.getElementById('barcodeStatusText');
    statusEl.style.display = 'block';
    statusTxt.textContent  = 'Barcode wird erkannt…';
    document.getElementById('productFound').classList.remove('visible');
    document.getElementById('notFound').classList.add('d-none');

    async function prepareImage(maxW) {
        const img = new Image();
        img.src = URL.createObjectURL(file);
        await new Promise(r => { img.onload = r; });
        const scale  = img.naturalWidth > maxW ? maxW / img.naturalWidth : 1;
        const canvas = document.getElementById('barcodeCanvas');
        canvas.width  = Math.round(img.naturalWidth  * scale);
        canvas.height = Math.round(img.naturalHeight * scale);
        canvas.getContext('2d', { willReadFrequently: true })
              .drawImage(img, 0, 0, canvas.width, canvas.height);
        return canvas.toDataURL('image/jpeg', 0.92);
    }

    async function tryQuagga() {
        for (const maxW of [1600, 800, 2400]) {
            const dataUrl = await prepareImage(maxW);
            const code = await new Promise((resolve) => {
                Quagga.decodeSingle({
                    src: dataUrl, numOfWorkers: 0,
                    inputStream: { size: maxW },
                    decoder: {
                        readers: ['ean_reader','ean_8_reader','upc_reader',
                                  'upc_e_reader','code_128_reader','code_39_reader'],
                        multiple: false,
                    }, locate: true,
                }, (res) => resolve(res && res.codeResult ? res.codeResult.code : null));
            });
            if (code) return code;
        }
        return null;
    }

    try {
        const barcode = await tryQuagga();
        if (barcode) {
            statusTxt.textContent = 'Produkt wird gesucht…';
            const r = await fetch('/api/barcode.php?code=' + encodeURIComponent(barcode));
            const d = await r.json();
            statusEl.style.display = 'none';
            if (d.ok) { showProduct(d.product); return; }
            document.getElementById('notFound').classList.remove('d-none');
            showToast('Barcode ' + barcode + ' nicht in OpenFoodFacts');
            return;
        }
        statusEl.style.display = 'none';
        document.getElementById('notFound').classList.remove('d-none');
        showToast('Barcode nicht erkannt — bitte nochmal fotografieren');
    } catch (e) {
        statusEl.style.display = 'none';
        document.getElementById('notFound').classList.remove('d-none');
        showToast('Fehler: ' + (e.message || e));
    }
}

// ── Live-Scan ────────────────────────────────────────────────────────────
let liveScanner = null, liveScanLocked = false, liveLastFrameLog = 0;

function liveSetStatus(html) {
    const el = document.getElementById('liveScanStatus');
    if (el) el.innerHTML = html;
}
function liveDbg(msg) { console.log('[LiveScan]', msg); }
function liveVibrate() { if (navigator.vibrate) try { navigator.vibrate(120); } catch(_){} }
let liveAudioCtx = null;
function liveBeep() {
    try {
        liveAudioCtx = liveAudioCtx || new (window.AudioContext || window.webkitAudioContext)();
        const osc = liveAudioCtx.createOscillator(), gain = liveAudioCtx.createGain();
        osc.connect(gain); gain.connect(liveAudioCtx.destination);
        osc.frequency.value = 880;
        gain.gain.setValueAtTime(0.25, liveAudioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, liveAudioCtx.currentTime + 0.15);
        osc.start(); osc.stop(liveAudioCtx.currentTime + 0.15);
    } catch(_) {}
}
function liveFlash() {
    const wrap = document.getElementById('liveScannerWrap');
    if (!wrap) return;
    const flash = document.createElement('div');
    Object.assign(flash.style, {position:'absolute',inset:'0',background:'rgba(212,245,60,.35)',
        zIndex:'50',pointerEvents:'none',transition:'opacity .25s ease-out'});
    wrap.appendChild(flash);
    requestAnimationFrame(() => { flash.style.opacity = '0'; });
    setTimeout(() => flash.remove(), 300);
}
function liveSearchOverlayShow() {
    const wrap = document.getElementById('liveScannerWrap');
    if (!wrap || document.getElementById('liveSearchOverlay')) return;
    const o = document.createElement('div');
    o.id = 'liveSearchOverlay';
    Object.assign(o.style, {position:'absolute',inset:'0',background:'rgba(0,0,0,.55)',
        zIndex:'45',display:'flex',alignItems:'center',justifyContent:'center'});
    o.innerHTML = '<div class="spinner-border spinner-accent" style="width:2.5rem;height:2.5rem;"></div>';
    wrap.appendChild(o);
}
function liveSearchOverlayHide() { document.getElementById('liveSearchOverlay')?.remove(); }

async function startLiveScanner() {
    if (liveScanner) return;
    liveScanLocked = false;
    liveSetStatus('<i class="bi bi-hourglass-split me-1"></i> Kamera wird gestartet…');
    try {
        liveAudioCtx = liveAudioCtx || new (window.AudioContext || window.webkitAudioContext)();
        if (liveAudioCtx.state === 'suspended') liveAudioCtx.resume();
    } catch(_) {}

    document.getElementById('liveQrReader').innerHTML = '';
    liveScanner = new Html5Qrcode('liveQrReader', { verbose: false });

    const config = {
        fps: 12, qrbox: { width: 280, height: 280 },
        formatsToSupport: [
            Html5QrcodeSupportedFormats.EAN_13, Html5QrcodeSupportedFormats.EAN_8,
            Html5QrcodeSupportedFormats.UPC_A, Html5QrcodeSupportedFormats.UPC_E,
            Html5QrcodeSupportedFormats.CODE_128, Html5QrcodeSupportedFormats.CODE_39,
        ],
        videoConstraints: {
            width: { min:1280, ideal:1920, max:2560 },
            height: { min:720, ideal:1080, max:1440 },
            facingMode: 'environment',
        },
    };

    async function onLiveBarcodeDetected(decodedText) {
        if (liveScanLocked) return;
        liveScanLocked = true;
        liveVibrate(); liveBeep(); liveFlash();
        liveSetStatus(`<i class="bi bi-check-circle-fill me-1" style="color:var(--accent)"></i> Barcode erkannt: <strong>${decodedText}</strong>`);
        try { await liveScanner.pause(true); } catch(_) {}
        await new Promise(r => setTimeout(r, 500));
        liveSetStatus(`<div class="spinner-border spinner-border-sm spinner-accent me-1" style="width:.9rem;height:.9rem;"></div> Suche „${decodedText}" bei OpenFoodFacts…`);
        liveSearchOverlayShow();
        try {
            const r = await fetch('/api/barcode.php?code=' + encodeURIComponent(decodedText));
            const d = await r.json();
            liveSearchOverlayHide();
            if (d.ok) {
                showProduct(d.product);
                document.getElementById('liveScannerWrap').classList.add('d-none');
                document.getElementById('liveScanStatus').classList.add('d-none');
            } else {
                document.getElementById('notFound').classList.remove('d-none');
                liveSetStatus('Barcode ' + decodedText + ' nicht in OpenFoodFacts gefunden.');
            }
        } catch(e) {
            liveSearchOverlayHide();
            showToast('Verbindungsfehler bei der Suche');
        }
        setTimeout(() => {
            if (!document.getElementById('productFound').classList.contains('visible')) {
                liveScanLocked = false;
                if (liveScanner) { try { liveScanner.resume(); } catch(_) {} }
                liveSetStatus('<i class="bi bi-camera-video me-1"></i> Barcode in den Rahmen halten…');
            }
        }, 1500);
    }

    function onLiveFrameError(msg) {
        const now = Date.now();
        if (!liveLastFrameLog || now - liveLastFrameLog > 2000) {
            liveLastFrameLog = now;
        }
    }

    try {
        await liveScanner.start({ facingMode: 'environment' }, config, onLiveBarcodeDetected, onLiveFrameError);
        liveSetStatus('<i class="bi bi-camera-video me-1"></i> Barcode in den Rahmen halten…');
    } catch(e) {
        let msg = 'Kamera-Fehler: ' + (e.message || e);
        if (e.name === 'NotAllowedError') {
            msg = '<i class="bi bi-exclamation-triangle me-1" style="color:var(--warn)"></i> Kamerazugriff verweigert.<br><small>Einstellungen → Safari → Kamera → Erlauben</small>';
        }
        liveSetStatus(msg);
        liveScanner = null;
    }
}

async function stopLiveScanner() {
    if (!liveScanner) return;
    try {
        const state = liveScanner.getState();
        if (state === 2 || state === 3) await liveScanner.stop();
    } catch(_) {}
    liveScanner = null; liveScanLocked = false; liveSearchOverlayHide();
}


// ── Top-6 laden ──────────────────────────────────────────────────────────
async function loadTop5() {
    try {
        const r = await fetch('/api/search.php');
        const d = await r.json();
        const container = document.getElementById('top5Chips');
        if (!d.ok || !d.results.length) {
            container.innerHTML = '<span class="pick-empty">Noch keine Einträge vorhanden.</span>';
            return;
        }
        container.innerHTML = d.results.map(p => `
            <button type="button" class="pick-tile" onclick="selectProduct(${escHtml(JSON.stringify(p))})">
                <b>${escHtml(p.name)}</b>
                <small>${Math.round(p.kcal_100g)} kcal/100 g</small>
            </button>`).join('');
    } catch(e) {}
}

function selectProduct(p) {
    showProduct(p);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Suche ────────────────────────────────────────────────────────────────
let searchTimer = null;
document.getElementById('searchInput').addEventListener('input', function() {
    clearTimeout(searchTimer);
    const q = this.value.trim();
    if (!q) { document.getElementById('searchResults').classList.add('d-none'); return; }
    searchTimer = setTimeout(() => doSearch(q), 280);
});

async function doSearch(q) {
    try {
        const box = document.getElementById('searchResults');
        const [rP, rG] = await Promise.all([
            fetch('/api/search.php?q=' + encodeURIComponent(q)),
            fetch('/api/gericht.php')
        ]);
        const dP = await rP.json();
        const dG = await rG.json();

        const produkte = (dP.ok && dP.results) ? dP.results : [];
        const gerichte = (dG.ok && dG.gerichte && !pickMode)
            ? dG.gerichte.filter(g => g.name.toLowerCase().includes(q.toLowerCase()))
            : [];

        if (!produkte.length && !gerichte.length) {
            box.innerHTML = '<div class="list-row"><span class="list-row__sub">Keine Treffer</span></div>';
            box.classList.remove('d-none'); return;
        }

        let html = '';

        if (gerichte.length) {
            html += `<div class="list-group-label">Gerichte</div>`;
            html += gerichte.map(g => {
                const kcal = g.kcal_gesamt ? Math.round(g.kcal_gesamt / Math.max(1, g.portionen)) : 0;
                return `<button type="button" class="list-row" onclick="openGerichtEintragen(${gerichtArgs(g)});document.getElementById('searchInput').value='';document.getElementById('searchResults').classList.add('d-none');">
                            <span class="tl-dot src-dish"></span>
                            <span class="list-row__text"><span class="list-row__title text-truncate">${escHtml(g.name)}</span></span>
                            <span class="list-row__end">${kcal} kcal/P</span>
                        </button>`;
            }).join('');
        }

        if (produkte.length) {
            if (gerichte.length) html += `<div class="list-group-label">Lebensmittel</div>`;
            html += produkte.map(p => `
                <button type="button" class="list-row" onclick="selectProduct(${escHtml(JSON.stringify(p))})">
                    <span class="tl-dot ${p.quelle === 'manuell' ? 'src-man' : 'src-scan'}"></span>
                    <span class="list-row__text"><span class="list-row__title text-truncate">${escHtml(p.name)}</span></span>
                    <span class="list-row__end">${Math.round(p.kcal_100g)} kcal/100 g</span>
                </button>`).join('');
        }

        box.innerHTML = html;
        box.classList.remove('d-none');
    } catch(e) {}
}

// ── Manuelles Modal ──────────────────────────────────────────────────────
function openSchnellModal() {
    document.getElementById('sfName').value    = '';
    document.getElementById('sfKcal').value    = '';
    document.getElementById('sfEiweiss').value = '';
    document.getElementById('sfFett').value    = '';
    document.getElementById('sfKh').value      = '';
    document.getElementById('schnellKcalPreview').style.display = 'none';
    document.getElementById('schnellModal').style.display = 'block';
    setTimeout(() => document.getElementById('sfKcal').focus(), 100);
}
function closeSchnellModal() { document.getElementById('schnellModal').style.display = 'none'; }
document.getElementById('schnellModal').addEventListener('click', function(e) {
    if (e.target === this) closeSchnellModal();
});

// Kcal-Preview im Schnell-Modal
document.getElementById('sfKcal').addEventListener('input', () => {
    const kcal = parseFloat(document.getElementById('sfKcal').value);
    const prev = document.getElementById('schnellKcalPreview');
    if (kcal > 0) { prev.textContent = Math.round(kcal) + ' kcal'; prev.style.display = 'block'; }
    else prev.style.display = 'none';
});

document.getElementById('btnSchnellSpeichern').addEventListener('click', async () => {
    const kcal = parseFloat(document.getElementById('sfKcal').value);
    if (!kcal)  { showToast('Bitte Kalorien eingeben'); return; }

    // Name optional – Fallback auf Zeitstempel
    const name = document.getElementById('sfName').value.trim() || (() => {
        const now = new Date(), pad = n => String(n).padStart(2,'0');
        return `Eintrag ${pad(now.getHours())}:${pad(now.getMinutes())}`;
    })();

    const btn = document.getElementById('btnSchnellSpeichern');
    btn.disabled = true;
    try {
        // Kein produkt_save – direkt als Eintrag ohne produkt_id
        const r = await fetch('/api/entry.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                produkt_id: null,
                name,
                menge_g: 100,
                kcal:    Math.round(kcal * 10) / 10,
                eiweiss: parseFloat(document.getElementById('sfEiweiss').value) || 0,
                fett:    parseFloat(document.getElementById('sfFett').value)    || 0,
                kh:      parseFloat(document.getElementById('sfKh').value)      || 0,
                datum:   new Date().toISOString().split('T')[0],
            }),
        });
        const d = await r.json();
        if (d.ok) {
            showToast('✓ Eingetragen!');
            closeSchnellModal();
            setTimeout(() => location.href = '/index.php', 900);
        } else showToast('Fehler: ' + (d.error || 'Unbekannt'));
    } catch(e) { showToast('Verbindungsfehler'); }
    finally { btn.disabled = false; }
});

function openManualModal() {
    document.getElementById('fName').value     = '';
    document.getElementById('fMenge').value    = '100';
    document.getElementById('fKcal').value     = '';
    document.getElementById('fPortion').value  = '';
    document.getElementById('fEiweiss').value  = '';
    document.getElementById('fFett').value     = '';
    document.getElementById('fKh').value       = '';
    document.getElementById('kcalPreviewManual').style.display = 'none';
    document.getElementById('manualModal').style.display = 'block';
    setTimeout(() => document.getElementById('fKcal').focus(), 100);
}
function closeManualModal() { document.getElementById('manualModal').style.display = 'none'; }
document.getElementById('manualModal').addEventListener('click', function(e) {
    if (e.target === this) closeManualModal();
});
// ── Action Sheet ─────────────────────────────────────────────────────────────
document.getElementById('btnOcrScan').addEventListener('click', ocrStart);

// ── Dropdown-Einträge ─────────────────────────────────────────────────────
document.getElementById('ddSchnell').addEventListener('click', openSchnellModal);
document.getElementById('ddManuell').addEventListener('click', openManualModal);

// ── OCR Nährwerte Scanner (PaddleOCR via ONNX Runtime, Beta) ─────────────
let _ocrStream  = null;
let _ocrWorker  = null;
let _ocrRunning = false;

async function ocrStart() {
    const modal  = document.getElementById('ocrModal');
    const vid    = document.getElementById('ocrVideo');
    const status = document.getElementById('zxingStatus'); // eigener Status
    const ocrSta = document.getElementById('ocrStatus');
    modal.style.display = 'flex';
    ocrSta.textContent  = 'Kamera wird gestartet…';

    // PaddleOCR initialisieren (Bundle wird lazy geladen – 18MB,
    // nur beim ersten Öffnen des Scanners, danach Browser-Cache)
    if (!_ocrWorker) {
        try {
            if (typeof PaddleOCR === 'undefined') {
                ocrSta.textContent = 'Lade OCR-Engine… (einmalig)';
                await new Promise((resolve, reject) => {
                    const s = document.createElement('script');
                    s.src = '/assets/js/paddle-ocr.js?v=4';
                    s.onload = resolve;
                    s.onerror = () => reject(new Error('paddle-ocr.js nicht ladbar'));
                    document.head.appendChild(s);
                });
            }
            // ONNX Runtime: WASM lokal, single-threaded (kein COOP/COEP nötig)
            ort.env.wasm.wasmPaths = '/assets/js/';
            ort.env.wasm.numThreads = 1;

            ocrSta.textContent = 'Initialisiere Modelle…';
            _ocrWorker = await PaddleOCR.create({
                models: {
                    detectionPath:   '/assets/models/ch_PP-OCRv4_det_infer.onnx',
                    recognitionPath: '/assets/models/ch_PP-OCRv4_rec_infer.onnx',
                    dictionaryPath:  '/assets/models/ppocr_keys_v1.txt',
                },
            });
            ocrSta.textContent = 'Bereit – Foto aufnehmen';
        } catch(e) {
            ocrSta.textContent = 'Fehler: ' + e.message;
            console.error('[OCR]', e);
            return;
        }
    }

    try {
        _ocrStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment', width: {ideal:1920}, height: {ideal:1080} }
        });
        vid.srcObject = _ocrStream;
        vid.setAttribute('playsinline', '');
        vid.setAttribute('autoplay', '');
        vid.muted = true;
        await vid.play().catch(e => console.warn('[OCR] play()', e));
        _ocrRunning = true;
        if (_ocrWorker) ocrSta.textContent = 'Bereit – Foto aufnehmen';
    } catch(e) {
        ocrSta.textContent = 'Kamera-Fehler: ' + e.message;
    }
}

// Berechnet welcher Ausschnitt des nativen Videobilds dem sichtbaren
// Rahmen entspricht (object-fit:cover Mapping)
function ocrGetFrameCrop(vid, frameEl) {
    const container = vid.getBoundingClientRect();
    const frame     = frameEl.getBoundingClientRect();

    const W_v = vid.videoWidth, H_v = vid.videoHeight;
    const W_c = container.width, H_c = container.height;

    // object-fit:cover – Video wird so skaliert dass es den Container füllt
    const scale   = Math.max(W_c / W_v, H_c / H_v);
    const offsetX = (W_v * scale - W_c) / 2; // abgeschnittener Bereich links
    const offsetY = (H_v * scale - H_c) / 2; // abgeschnittener Bereich oben

    // Rahmen-Position relativ zum Container
    const fx = frame.left - container.left;
    const fy = frame.top  - container.top;

    // Zurückrechnen auf native Video-Koordinaten
    return {
        x: Math.max(0, (fx + offsetX) / scale),
        y: Math.max(0, (fy + offsetY) / scale),
        w: Math.min(W_v, frame.width  / scale),
        h: Math.min(H_v, frame.height / scale),
    };
}

// ── Gemeinsame Frame-Analyse: Crop → PaddleOCR → Parser ──────────────────
async function ocrAnalyzeFrame() {
    const vid   = document.getElementById('ocrVideo');
    const frame = document.getElementById('ocrFrame');

    // 1. NUR den Rahmen-Bereich croppen
    const crop = ocrGetFrameCrop(vid, frame);

    // 2. Auf mind. 1200px Breite hochskalieren (~300 DPI Äquivalent)
    const targetW = Math.max(1200, crop.w);
    const scaleUp = targetW / crop.w;
    const canvas  = document.createElement('canvas');
    canvas.width  = Math.round(crop.w * scaleUp);
    canvas.height = Math.round(crop.h * scaleUp);
    const ctx = canvas.getContext('2d');
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(vid, crop.x, crop.y, crop.w, crop.h,
                       0, 0, canvas.width, canvas.height);

    // 3. PaddleOCR: Detection + Recognition
    const dataUrl = canvas.toDataURL('image/png');
    const results = await _ocrWorker.detect(dataUrl);

    // 4. Boxen zu Zeilen clustern (kJ/kcal-Kontext erhalten)
    const items = results
        .filter(r => r.mean > 0.5)
        .map(r => ({
            text: r.text,
            x: r.box ? r.box[0][0] : 0,
            y: r.box ? (r.box[0][1] + r.box[2][1]) / 2 : 0,
            h: r.box ? Math.abs(r.box[2][1] - r.box[0][1]) : 20,
        }))
        .sort((a, b) => a.y - b.y);

    const lines = [];
    for (const item of items) {
        const last = lines[lines.length - 1];
        if (last && Math.abs(item.y - last.y) < Math.max(item.h, last.h) * 0.6) {
            last.parts.push(item);
        } else {
            lines.push({ y: item.y, h: item.h, parts: [item] });
        }
    }
    const text = lines
        .map(l => l.parts.sort((a, b) => a.x - b.x).map(p => p.text).join(' '))
        .join('\n');

    return parseNaehrwerte(text);
}

let _ocrBusy = false; // verhindert parallele detect()-Läufe

// ── Foto-Aufnahme: Analyse mit Overlay-Feedback ──────────────────────────
async function ocrCapture() {
    if (!_ocrRunning || !_ocrWorker || _ocrBusy) return;
    const flash  = document.getElementById('ocrFlash');
    const frame  = document.getElementById('ocrFrame');
    const ocrSta = document.getElementById('ocrStatus');
    const btn    = document.getElementById('btnOcrCapture');

    flash.style.opacity = '0.7';
    setTimeout(() => flash.style.opacity = '0', 120);

    _ocrBusy = true;
    btn.disabled = true;
    ocrSta.textContent = 'Analysiere Bild…';
    frame.style.borderColor = 'var(--quick)';
    scanOverlayShow('Analysiere Nährwerttabelle…', {
        icon: 'bi-body-text', color: 'var(--carb)',
    });

    try {
        const parsed = await ocrAnalyzeFrame();
        frame.style.borderColor = 'var(--accent)';
        scanOverlayHide();
        ocrSta.textContent = '✓ Erkannt – bitte prüfen';
        ocrStop();
        showOcrResult(parsed);
    } catch(e) {
        scanOverlayHide();
        console.error('[OCR] detect', e);
        frame.style.borderColor = 'var(--danger)';
        ocrSta.textContent = '✗ ' + (e.message || e).toString().slice(0, 80);
        setTimeout(() => {
            frame.style.borderColor = 'var(--carb)';
            ocrSta.textContent = 'Bereit – Foto aufnehmen';
        }, 4000);
    } finally {
        _ocrBusy = false;
        btn.disabled = false;
    }
}

function fixOcrNumber(raw) {
    // Häufige OCR-Verwechslungen korrigieren:
    // "9" am Ende → oft "g" (Einheit), also Zeichen davor ist die Zahl
    // "O" → "0", "l" → "1", "S" → "5"
    return raw
        .replace(/O/g, '0')
        .replace(/o(?=\d)/g, '0')   // o vor Zahl → 0
        .replace(/l(?=\d)/g, '1')   // l vor Zahl → 1
        .replace(/,/g, '.');         // Komma → Punkt
}

function extractNumbers(line) {
    // Zeile vorverarbeiten:
    // 1. Bekannte Einheiten entfernen (g, mg, kJ, kcal) die als Zahl erkannt wurden
    // 2. "9" am Zeilenende oder vor Leerzeichen ist wahrscheinlich "g" → entfernen
    let cleaned = line
        .replace(/\b9\s*$/g, '')          // trailing "9" (= "g")
        .replace(/\b9\s+(?=[A-Za-z])/g, '') // "9 " vor Text (= "g ")
        .replace(/([0-9]),([0-9])/g, '$1.$2') // deutsches Komma → Punkt
        .replace(/[^0-9.]/g, ' ');           // alles außer Zahlen/Punkt entfernen
    const matches = cleaned.match(/\d+\.?\d*/g) || [];
    return matches.map(v => parseFloat(v)).filter(v => !isNaN(v) && v > 0);
}

function parseNaehrwerte(text) {
    const result = { kcal: '', eiweiss: '', fett: '', kh: '' };

    // Text vorverarbeiten: "9" nach Zahlen ist fast immer "g"
    // z.B. "12,39" statt "12,3g" – OCR verwechselt g/9 gelegentlich
    const cleanText = text
        .replace(/(\d)9(\s|$)/gm, '$1g$2') // Zahl + 9 am Wortende → Zahl + g
        .replace(/(\d),9(\s|$)/gm, '$1g$2'); // z.B. "3,9 " → "3g "

    // ── kcal: explizites "N kcal"-Muster hat absolute Priorität ──────────
    // Nährwerttabellen schreiben den kcal-Wert immer direkt vor "kcal".
    const kcalMatch = cleanText.match(/(\d+(?:[.,]\d+)?)\s*k\s?cal/i);
    if (kcalMatch) {
        result.kcal = parseFloat(kcalMatch[1].replace(',', '.'));
    } else {
        // Fallback: nur kJ gefunden → in kcal umrechnen (÷ 4,184)
        const kjMatch = cleanText.match(/(\d+(?:[.,]\d+)?)\s*k\s?j/i);
        if (kjMatch) {
            result.kcal = Math.round(parseFloat(kjMatch[1].replace(',', '.')) / 4.184);
        }
    }

    const lines = cleanText.split('\n').map(l => l.trim()).filter(Boolean);

    for (const line of lines) {
        const nums = extractNumbers(line);
        const first = nums.length > 0 ? nums[0] : null;
        const l = line.toLowerCase();

        // kcal wird global über explizite Muster erkannt (siehe unten),
        // nicht mehr zeilenweise – kJ-Werte werden sonst zu leicht verwechselt.
        if (!result.eiweiss && (l.includes('eiwei') || l.includes('protein'))) {
            if (first !== null && first <= 100) result.eiweiss = first;
        }
        if (!result.fett && l.includes('fett') &&
            !l.includes('gesättig') && !l.includes('fettsäure') && !l.includes('trans')) {
            if (first !== null && first <= 100) result.fett = first;
        }
        if (!result.kh && (l.includes('kohlenhydrat') || l.includes('kohlenh') ||
                           l.includes(' kh') || l.includes('zucker') === false && l.includes('kh'))) {
            if (first !== null && first <= 100) result.kh = first;
        }
    }
    return result;
}

function showOcrResult(parsed) {
    document.getElementById('ocrKcal').value    = parsed.kcal    || '';
    document.getElementById('ocrEiweiss').value = parsed.eiweiss || '';
    document.getElementById('ocrFett').value    = parsed.fett    || '';
    document.getElementById('ocrKh').value      = parsed.kh      || '';
    document.getElementById('ocrMenge').value   = '100';
    document.getElementById('ocrName').value    = '';
    document.getElementById('ocrPreview').style.display = 'none';
    document.getElementById('ocrResultModal').style.display = 'block';
    updateOcrPreview();
    setTimeout(() => document.getElementById('ocrName').focus(), 100);
}

function closeOcrResult() {
    document.getElementById('ocrResultModal').style.display = 'none';
}

function updateOcrPreview() {
    const kcal100 = parseFloat(document.getElementById('ocrKcal').value) || 0;
    const menge   = parseFloat(document.getElementById('ocrMenge').value) || 100;
    const prev    = document.getElementById('ocrPreview');
    const total   = Math.round(kcal100 * menge / 100);
    if (kcal100 > 0) { prev.textContent = total + ' kcal'; prev.style.display = 'block'; }
    else prev.style.display = 'none';
}
['ocrKcal','ocrMenge'].forEach(id => {
    document.getElementById(id).addEventListener('input', updateOcrPreview);
});

async function ocrSave() {
    const kcal100 = parseFloat(document.getElementById('ocrKcal').value);
    const menge   = parseFloat(document.getElementById('ocrMenge').value) || 100;
    const name    = document.getElementById('ocrName').value.trim();
    if (!name)    { showToast('Bitte Produktname eingeben'); document.getElementById('ocrName').focus(); return; }
    if (!kcal100) { showToast('Bitte Kalorien eingeben'); return; }

    const btn = document.querySelector('#ocrResultModal .scan-btn');
    btn.disabled = true;

    // Pick-Modus: nur als Zutat zurückgeben, kein Produkt anlegen, kein Tageseintrag
    if (pickMode) {
        returnPickedZutat({
            produkt_id:   null,
            name,
            menge_g:      menge,
            kcal_100g:    kcal100,
            eiweiss_100g: parseFloat(document.getElementById('ocrEiweiss').value) || 0,
            fett_100g:    parseFloat(document.getElementById('ocrFett').value)    || 0,
            kh_100g:      parseFloat(document.getElementById('ocrKh').value)      || 0,
        });
        return;
    }

    try {
        // Produkt anlegen
        const rP = await fetch('/api/produkt_save.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                name,
                kcal_100g:    kcal100,
                eiweiss_100g: parseFloat(document.getElementById('ocrEiweiss').value) || 0,
                fett_100g:    parseFloat(document.getElementById('ocrFett').value)    || 0,
                kh_100g:      parseFloat(document.getElementById('ocrKh').value)      || 0,
                quelle:       'manuell',
            }),
        });
        const dP = await rP.json();
        if (!dP.ok) throw new Error(dP.error);

        // Nur anlegen: Produkt ist gespeichert, Buchung für heute überspringen.
        if (ocrZielCtrl && ocrZielCtrl.get() === 'speichern') {
            showToast('✓ „' + name + '“ angelegt – jederzeit über die Suche eintragbar');
            closeOcrResult();
            return;
        }

        // Eintrag anlegen
        const rE = await fetch('/api/entry.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                produkt_id: dP.id,
                name,
                menge_g: menge,
                kcal:    Math.round(kcal100 * menge / 100 * 10) / 10,
                eiweiss: Math.round((parseFloat(document.getElementById('ocrEiweiss').value)||0) * menge/100 * 10)/10,
                fett:    Math.round((parseFloat(document.getElementById('ocrFett').value)   ||0) * menge/100 * 10)/10,
                kh:      Math.round((parseFloat(document.getElementById('ocrKh').value)     ||0) * menge/100 * 10)/10,
                datum:   new Date().toISOString().split('T')[0],
            }),
        });
        const dE = await rE.json();
        if (dE.ok) {
            showToast('✓ Eingetragen!');
            closeOcrResult();
            setTimeout(() => location.href = '/index.php', 900);
        } else throw new Error(dE.error);
    } catch(e) { showToast('Fehler: ' + e.message); }
    finally { btn.disabled = false; }
}

function ocrStop() {
    if (typeof scanOverlayHide === 'function') scanOverlayHide();
    _ocrRunning = false;
    if (_ocrStream) { _ocrStream.getTracks().forEach(t => t.stop()); _ocrStream = null; }
    document.getElementById('ocrModal').style.display = 'none';
}
window.addEventListener('pagehide', ocrStop);



// Scanner (global, scanner.js): Treffer direkt hier im Produkt-Sheet zeigen
// statt wie auf anderen Seiten nach /log.php?barcode=… weiterzuleiten.
window.onScanProduct = (product) => showProduct(product);

// Übergabe aus dem Scanner einer anderen Seite (/log.php?barcode=…) bzw.
// Einstiege der Quick Actions: Suche (?focus=search), schneller Eintrag
// (?aktion=schnell). Parameter danach entfernen (Neuladen = normale Seite).
(async () => {
    const qs = new URLSearchParams(location.search);
    if (qs.get('focus') === 'search') document.getElementById('searchInput')?.focus();
    if (qs.get('aktion') === 'schnell') openSchnellModal();
    if ((qs.has('focus') || qs.has('aktion')) && !qs.has('barcode')) {
        history.replaceState(null, '', location.pathname + (qs.get('pick') ? '?pick=1' : ''));
    }
    const code = qs.get('barcode');
    if (!code) return;
    history.replaceState(null, '', location.pathname + (qs.get('pick') ? '?pick=1' : ''));
    try {
        const r = await fetch('/api/barcode.php?code=' + encodeURIComponent(code));
        const d = await r.json();
        if (d.ok) showProduct(d.product);
        else { document.getElementById('notFound').classList.remove('d-none'); showToast('Barcode ' + code + ' nicht gefunden'); }
    } catch (e) { showToast('Verbindungsfehler'); }
})();

document.getElementById('btnBarcodeFoto').addEventListener('click', () => {
    document.getElementById('notFound').classList.add('d-none');
    document.getElementById('barcodeFileInput').click();
});
document.getElementById('btnBarcodeEingeben').addEventListener('click', () => {
    openBarcodeManuellModal();
});

function updateManualPreview() {
    const kcal100 = parseFloat(document.getElementById('fKcal').value) || 0;
    const menge   = parseFloat(document.getElementById('fMenge').value) || 0;
    const preview = document.getElementById('kcalPreviewManual');
    if (kcal100 > 0 && menge > 0) {
        preview.textContent = Math.round(kcal100 * menge / 100) + ' kcal';
        preview.style.display = 'block';
    } else { preview.style.display = 'none'; }
}
document.getElementById('fKcal').addEventListener('input', updateManualPreview);
document.getElementById('fMenge').addEventListener('input', updateManualPreview);

document.getElementById('btnSpeichern').addEventListener('click', async () => {
    const kcal100 = parseFloat(document.getElementById('fKcal').value);
    const menge   = parseFloat(document.getElementById('fMenge').value) || 100;
    const name    = document.getElementById('fName').value.trim();
    if (!name)    { showToast('Bitte Produktname eingeben'); document.getElementById('fName').focus(); return; }
    if (!kcal100) { showToast('Bitte Kalorien eingeben'); return; }
    const btn = document.getElementById('btnSpeichern');
    btn.disabled = true;
    try {
        const pRes = await fetch('/api/produkt_save.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                name,
                kcal_100g:    kcal100,
                eiweiss_100g: parseFloat(document.getElementById('fEiweiss').value) || 0,
                fett_100g:    parseFloat(document.getElementById('fFett').value)    || 0,
                kh_100g:      parseFloat(document.getElementById('fKh').value)      || 0,
                portion_g:    parseFloat(document.getElementById('fPortion').value) || null,
            }),
        });
        const pData = await pRes.json();
        const produktId = pData.ok ? (pData.id || null) : null;
        const f = menge / 100;
        const eiweiss100 = parseFloat(document.getElementById('fEiweiss').value) || 0;
        const fett100    = parseFloat(document.getElementById('fFett').value)    || 0;
        const kh100      = parseFloat(document.getElementById('fKh').value)      || 0;

        // Pick-Modus: Produkt bleibt angelegt (wiederverwendbar), aber kein
        // Tageseintrag – stattdessen als Zutat ans Gericht zurückgeben.
        if (pickMode) {
            returnPickedZutat({
                produkt_id: produktId, name, menge_g: menge,
                kcal_100g: kcal100, eiweiss_100g: eiweiss100,
                fett_100g: fett100, kh_100g: kh100,
            });
            return;
        }

        // Nur anlegen: Produkt ist gespeichert, Buchung für heute überspringen
        // (z.B. beim Vorkochen mehrerer Gerichte für die Woche).
        if (manualZielCtrl && manualZielCtrl.get() === 'speichern') {
            showToast('✓ „' + name + '“ angelegt – jederzeit über die Suche eintragbar');
            closeManualModal();
            return;
        }

        const r = await fetch('/api/entry.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                produkt_id: produktId, name, menge_g: menge,
                kcal:    Math.round(kcal100   * f * 10) / 10,
                eiweiss: Math.round(eiweiss100 * f * 10) / 10,
                fett:    Math.round(fett100    * f * 10) / 10,
                kh:      Math.round(kh100      * f * 10) / 10,
                datum:   new Date().toISOString().split('T')[0],
            }),
        });
        const d = await r.json();
        if (d.ok) { showToast('✓ Eingetragen!'); setTimeout(() => location.href = '/index.php', 900); }
        else showToast('Fehler: ' + (d.error || 'Unbekannt'));
    } catch(e) { showToast('Verbindungsfehler'); } finally { btn.disabled = false; }
});

// ── Eintragen ────────────────────────────────────────────────────────────
document.getElementById('mengeInput').addEventListener('input', () => {
    updateKcalPreview();
    syncPfChipsActive();
});

// ── Stepper (+/-) ──────────────────────────────────────────────────────────
function pfStep(delta) {
    const inp = document.getElementById('mengeInput');
    const cur = parseFloat(inp.value) || 0;
    if (window._pfPortionMode && window._pfPortionMode() === 'portion') {
        inp.value = Math.max(0.5, Math.min(50, cur + delta * 0.5));
        inp.dispatchEvent(new Event('input'));
        return;
    }
    // Größere Schritte bei größeren Mengen (schnelleres Einstellen)
    const step = cur >= 500 ? 50 : (cur >= 200 ? 25 : 10);
    inp.value = Math.max(1, Math.min(5000, cur + delta * step));
    inp.dispatchEvent(new Event('input'));
}
document.getElementById('btnMengeMinus').addEventListener('click', () => pfStep(-1));
document.getElementById('btnMengePlus').addEventListener('click', () => pfStep(1));

// ── Schnellwahl-Chips ────────────────────────────────────────────────────
function syncPfChipsActive() {
    const val = parseFloat(document.getElementById('mengeInput').value);
    document.querySelectorAll('#pfQuickChips .pf-chip, #pfQuickChipsPortion .pf-chip').forEach(chip => {
        chip.classList.toggle('active', parseFloat(chip.dataset.val) === val);
    });
}
document.querySelectorAll('#pfQuickChips .pf-chip, #pfQuickChipsPortion .pf-chip').forEach(chip => {
    chip.addEventListener('click', () => {
        document.getElementById('mengeInput').value = chip.dataset.val;
        document.getElementById('mengeInput').dispatchEvent(new Event('input'));
    });
});

document.getElementById('btnEintragen').addEventListener('click', async () => {
    if (!currentProduct) return;
    const btn = document.getElementById('btnEintragen');
    btn.disabled = true;
    if (window._getAndSavePortion) await window._getAndSavePortion(currentProduct.id || null);
    const menge = Math.round(getPfMengeG() * 10) / 10;
    if (menge <= 0) {
        showToast(window._pfPortionMode && window._pfPortionMode() === 'portion'
            ? 'Bitte Portionsgröße angeben' : 'Bitte Menge angeben');
        btn.disabled = false;
        return;
    }

    // Pick-Modus: nicht als Tageseintrag buchen, sondern als Zutat zurückgeben
    if (pickMode) {
        returnPickedZutat({
            produkt_id:   currentProduct.id || null,
            name:         currentProduct.name,
            menge_g:      menge,
            kcal_100g:    currentProduct.kcal_100g    || 0,
            eiweiss_100g: currentProduct.eiweiss_100g || 0,
            fett_100g:    currentProduct.fett_100g    || 0,
            kh_100g:      currentProduct.kh_100g      || 0,
        });
        return;
    }

    const f = menge / 100;
    try {
        const r = await fetch('/api/entry.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                produkt_id: currentProduct.id || null, name: currentProduct.name, menge_g: menge,
                kcal:    Math.round(currentProduct.kcal_100g    * f * 10) / 10,
                eiweiss: Math.round(currentProduct.eiweiss_100g * f * 10) / 10,
                fett:    Math.round(currentProduct.fett_100g    * f * 10) / 10,
                kh:      Math.round(currentProduct.kh_100g      * f * 10) / 10,
                datum:   new Date().toISOString().split('T')[0],
            }),
        });
        if (!r.ok) { const t = await r.text(); showToast('Server-Fehler (' + r.status + ')'); return; }
        const d = await r.json();
        if (d.ok) { showToast('✓ Eingetragen!'); setTimeout(() => location.href = '/index.php', 900); }
        else showToast('Fehler: ' + (d.error || 'Unbekannt'));
    } catch(e) { showToast('Verbindungsfehler'); } finally { btn.disabled = false; }
});

document.getElementById('btnVerwerfen').addEventListener('click', () => {
    currentProduct = null;
    document.getElementById('productFound').classList.remove('visible');
    document.getElementById('notFound').classList.add('d-none');
    if (activeScanMode === 'live') {
        document.getElementById('liveScannerWrap').classList.remove('d-none');
        document.getElementById('liveScanStatus').classList.remove('d-none');
        liveScanLocked = false;
        if (liveScanner) { try { liveScanner.resume(); } catch(_) {} }
        liveSetStatus('<i class="bi bi-camera-video me-1"></i> Barcode in den Rahmen halten…');
    }
});

// Tippen auf Modal-Hintergrund schließt das Modal (wie Verwerfen)
document.getElementById('productFound').addEventListener('click', function(e) {
    if (e.target === this) document.getElementById('btnVerwerfen').click();
});

document.getElementById('btnDeleteProdukt').addEventListener('click', async () => {
    if (!currentProduct?.id) return;
    if (!confirm(`"${currentProduct.name}" aus der Datenbank löschen?`)) return;
    try {
        const r = await fetch('/api/produkt_delete.php', {
            method: 'DELETE',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ id: currentProduct.id }),
        });
        const d = await r.json();
        if (d.ok) {
            showToast('Produkt gelöscht');
            document.getElementById('btnVerwerfen').click();
            // Top-6 neu laden damit gelöschtes Produkt verschwindet
            loadTop5();
        } else {
            showToast('Fehler: ' + (d.error || 'Unbekannt'));
        }
    } catch(e) {
        showToast('Verbindungsfehler');
    }
});


document.getElementById('barcodeFileInput').addEventListener('change', e => {
    if (e.target.files[0]) handleBarcodePhoto(e.target.files[0]);
    e.target.value = '';
});

// ── Barcode manuell Events ───────────────────────────────────────────────
document.getElementById('btnLookupManual').addEventListener('click', async () => {
    const code = document.getElementById('manualBarcodeInput').value.trim();
    if (!code) return;
    closeBarcodeManuellModal();
    const r = await fetch('/api/barcode.php?code=' + encodeURIComponent(code));
    const d = await r.json();
    if (d.ok) showProduct(d.product);
    else { document.getElementById('notFound').classList.remove('d-none'); showToast('Barcode nicht in OpenFoodFacts-DB.'); }
});
document.getElementById('manualBarcodeInput').addEventListener('keyup', e => {
    if (e.key === 'Enter') document.getElementById('btnLookupManual').click();
});

window.addEventListener('pagehide', stopLiveScanner);
window.addEventListener('beforeunload', stopLiveScanner);

// ── Gerichte ─────────────────────────────────────────────────────────────
let _currentGericht = null;



async function loadTopGerichte() {
    const container = document.getElementById('topGerichteChips');
    if (!container) return; // Pick-Modus: Sektion nicht im DOM
    const r = await fetch('/api/gericht.php?sort=nutzung');
    const d = await r.json();
    if (!d.ok || !d.gerichte.length) {
        container.innerHTML = '<span class="pick-empty">Noch keine Gerichte angelegt.</span>';
        document.getElementById('gerichteSectionWrapper').style.display = 'none';
        return;
    }
    const top4 = d.gerichte.slice(0, 4);
    container.innerHTML = top4.map(g => {
        const kcal = g.kcal_gesamt ? Math.round(g.kcal_gesamt / Math.max(1, g.portionen)) : '?';
        return `<button type="button" class="pick-tile pick-tile--row" onclick="openGerichtEintragen(${gerichtArgs(g)})">
                    <span class="tl-dot src-dish"></span>
                    <b>${escHtml(g.name)}</b>
                    <small>${kcal} kcal / Portion</small>
                </button>`;
    }).join('');
}

let _gerichtModus = 'portion';

function openGerichtEintragen(id, name, portionen, kcalGesamt, gewichtGesamt) {
    _currentGericht = { id, portionen, kcalGesamt, gewichtGesamt: parseFloat(gewichtGesamt) || 0 };
    const kcalPro = portionen > 0 ? Math.round(kcalGesamt / portionen) : 0;
    document.getElementById('gerichtEintragenName').textContent = name;
    document.getElementById('gerichtEintragenInfo').textContent =
        'Gericht · ' + portionen + ' Portion' + (portionen !== 1 ? 'en' : '')
        + (_currentGericht.gewichtGesamt > 0 ? ' · ' + Math.round(_currentGericht.gewichtGesamt) + ' g gesamt' : '');
    document.getElementById('geKcalPortion').textContent = kcalPro;
    document.getElementById('geKcalGesamt').textContent  = Math.round(kcalGesamt);
    document.getElementById('gePortionen').textContent   = portionen;
    // Gramm-Modus nur anbieten wenn Gesamtgewicht bekannt
    document.getElementById('geModeGramm').style.display =
        _currentGericht.gewichtGesamt > 0 ? 'block' : 'none';
    setGerichtModus('portion');
    document.getElementById('gerichtEintragenModal').classList.add('visible');
}

function setGerichtModus(modus) {
    _gerichtModus = modus;
    const inp     = document.getElementById('gerichtEintragenPortionen');
    const einheit = document.getElementById('gerichtEintragenEinheit');
    const bP = document.getElementById('geModePortion');
    const bG = document.getElementById('geModeGramm');
    const gramOk = _currentGericht && _currentGericht.gewichtGesamt > 0;

    bP.classList.toggle('active', modus === 'portion');
    bG.classList.toggle('active', modus === 'gramm');
    bG.style.display = gramOk ? '' : 'none';

    document.getElementById('geQuickChipsPortion').style.display = modus === 'portion' ? 'flex' : 'none';
    document.getElementById('geQuickChipsGramm').style.display   = modus === 'gramm'   ? 'flex' : 'none';

    if (modus === 'gramm') {
        einheit.textContent = 'g';
        inp.value = 100; inp.min = 1; inp.step = 1;
    } else {
        einheit.textContent = 'Portion(en)';
        inp.value = 1; inp.min = 0.5; inp.step = 0.5;
    }
    syncGeChipsActive();
    updateGerichtPreview();
}

function updateGerichtPreview() {
    if (!_currentGericht) return;
    const val = parseFloat(document.getElementById('gerichtEintragenPortionen').value) || 0;
    let kcal = 0;
    if (_gerichtModus === 'gramm' && _currentGericht.gewichtGesamt > 0) {
        kcal = _currentGericht.kcalGesamt * val / _currentGericht.gewichtGesamt;
    } else {
        const kcalPro = _currentGericht.portionen > 0
            ? _currentGericht.kcalGesamt / _currentGericht.portionen : 0;
        kcal = kcalPro * val;
    }
    document.getElementById('geKcalPreview').textContent = Math.round(kcal);
    document.getElementById('geBtnKcal').textContent     = Math.round(kcal);
    updateDanach('geAfter', Math.round(kcal));
}
function closeGerichtEintragenModal() {
    document.getElementById('gerichtEintragenModal').classList.remove('visible');
    _currentGericht = null;
}

document.getElementById('gerichtEintragenPortionen').addEventListener('input', () => {
    updateGerichtPreview();
    syncGeChipsActive();
});

// ── Stepper (+/-) ──────────────────────────────────────────────────────────
function geStep(delta) {
    const inp  = document.getElementById('gerichtEintragenPortionen');
    const cur  = parseFloat(inp.value) || 0;
    const step = _gerichtModus === 'gramm' ? (cur >= 200 ? 25 : 10) : 0.5;
    const min  = _gerichtModus === 'gramm' ? 1 : 0.5;
    inp.value  = Math.max(min, cur + delta * step);
    inp.dispatchEvent(new Event('input'));
}
document.getElementById('btnGeMinus').addEventListener('click', () => geStep(-1));
document.getElementById('btnGePlus').addEventListener('click', () => geStep(1));

// ── Schnellwahl-Chips (Portionen und Gramm getrennt) ───────────────────────
function syncGeChipsActive() {
    const val = parseFloat(document.getElementById('gerichtEintragenPortionen').value);
    const wrap = _gerichtModus === 'gramm' ? 'geQuickChipsGramm' : 'geQuickChipsPortion';
    document.querySelectorAll('#' + wrap + ' .pf-chip').forEach(chip => {
        chip.classList.toggle('active', parseFloat(chip.dataset.val) === val);
    });
}
document.querySelectorAll('#geQuickChipsPortion .pf-chip, #geQuickChipsGramm .pf-chip').forEach(chip => {
    chip.addEventListener('click', () => {
        document.getElementById('gerichtEintragenPortionen').value = chip.dataset.val;
        document.getElementById('gerichtEintragenPortionen').dispatchEvent(new Event('input'));
    });
});

document.getElementById('btnGerichtVerwerfen').addEventListener('click', closeGerichtEintragenModal);
document.getElementById('gerichtEintragenModal').addEventListener('click', function(e) {
    if (e.target === this) closeGerichtEintragenModal();
});
document.getElementById('gerichtEintragenModal').addEventListener('click', function(e) {
    if (e.target === this) closeGerichtEintragenModal();
});

document.getElementById('btnGerichtEintragen').addEventListener('click', async () => {
    if (!_currentGericht) return;
    const val   = parseFloat(document.getElementById('gerichtEintragenPortionen').value) || 1;
    const datum = new Date().toISOString().slice(0, 10);
    const payload = { gericht_id: _currentGericht.id, datum };
    if (_gerichtModus === 'gramm') payload.menge_g = val;
    else                           payload.portionen = val;
    const r = await fetch('/api/gericht.php?action=eintragen', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    const d = await r.json();
    closeGerichtEintragenModal();
    if (d.ok) {
        showToast(`Gericht eingetragen (${d.eingetragen} Zutaten)`);
        setTimeout(() => { location.href = '/'; }, 800);
    } else showToast('Fehler: ' + (d.error || 'Unbekannt'));
});

loadTopGerichte();
loadTop5();
</script>

<?php renderFooter('log'); ?>
