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
    <div class="action-row">
        <button id="btnBarcodeEingeben" class="action-tile" type="button">
            <i class="bi bi-keyboard"></i><span>Barcode<br>eingeben</span>
        </button>
        <button id="btnOcrScan" class="action-tile" type="button">
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

<!-- Barcode unbekannt: Produkt mit diesem Barcode anlegen -->
<div id="notFound" class="d-none kt-card text-center" style="margin:.5rem .85rem 1rem;">
    <i class="bi bi-upc-scan" style="font-size:2rem;display:block;margin-bottom:.5rem;color:var(--muted);"></i>
    <div style="font-weight:700;margin-bottom:.4rem;">Produkt nicht gefunden</div>
    <div class="text-muted" id="notFoundText" style="font-size:.85rem;line-height:1.5;margin-bottom:.9rem;">
        Barcode <b id="notFoundCode" class="num"></b> ist weder in deiner Datenbank noch bei OpenFoodFacts.
        Lege das Produkt einmal an – beim nächsten Scan wird es direkt gefunden.
    </div>
    <button type="button" class="scan-btn mb-2" id="btnNfOcr">
        <i class="bi bi-body-text"></i> Nährwerte scannen
    </button>
    <button type="button" class="scan-btn secondary mb-2" id="btnNfManuell">
        <i class="bi bi-pencil-square"></i> Manuell anlegen
    </button>
    <button type="button" class="btn-link-muted" id="btnNfNochmal">
        <i class="bi bi-arrow-repeat"></i> Nochmal scannen
    </button>
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
· Manuell erfassen – nur für dich sichtbar (mit Barcode angelegt: für alle, die ihn scannen)<br>
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
            <div>
                <h2>Lebensmittel manuell erfassen</h2>
                <p class="sheet-sub barcode-hint" style="display:none;"></p>
            </div>
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

<!-- ── Modal: Nährwerte scannen (OCR) ──────────────────────── -->
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
                position:relative;width:85%;aspect-ratio:3/4;
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
                <!-- Erkennungs-Overlay: gelesene Textstellen + Zeilen der Werte -->
                <div id="ocrBoxen" class="ocr-boxen"></div>
                <!-- Hinweistext im Rahmen -->
                <div style="position:absolute;bottom:.5rem;left:.5rem;right:.5rem;text-align:center;
                            font-size:.7rem;color:rgba(167,139,250,.8);">
                    Nährwerttabelle in den Rahmen halten – wird automatisch erkannt
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
                Kamera wird gestartet…
            </span>
        </div>
        <!-- Live-Fortschritt: welche Werte schon erkannt sind -->
        <div id="ocrChips" class="ocr-chips">
            <span data-feld="kcal">kcal –</span><span data-feld="eiweiss">Eiweiß –</span>
            <span data-feld="fett">Fett –</span><span data-feld="kh">KH –</span>
        </div>
        <div style="display:flex;gap:.75rem;width:100%;max-width:320px;">
            <!-- Ausweg, keine Bestätigung: Übernommen wird automatisch; hier geht
                 es ohne Warten ins Formular (mit dem, was bis jetzt erkannt ist). -->
            <button id="btnOcrCapture" onclick="ocrCapture()"
                    style="flex:1;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);
                           border-radius:14px;color:var(--text);font-size:.95rem;font-weight:600;padding:.85rem;
                           cursor:pointer;-webkit-tap-highlight-color:transparent;">
                <i class="bi bi-pencil"></i> Selbst eintragen
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
                <p class="sheet-sub" id="ocrResultHinweis">
                    Bitte prüfen und ggf. korrigieren
                </p>
                <p class="sheet-sub barcode-hint" style="display:none;"></p>
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

        <!-- Menge + Portionsgröße -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;" class="mb-4">
            <div>
                <label class="form-label">Menge (g)</label>
                <input type="number" id="ocrMenge" class="form-control"
                       placeholder="100" value="100" min="1" step="1" inputmode="decimal">
            </div>
            <div>
                <label class="form-label">Portion (g) <span class="text-muted">optional</span></label>
                <input type="number" id="ocrPortion" class="form-control"
                       placeholder="z.B. 30" min="1" step="0.5" inputmode="decimal">
            </div>
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

<script>
let currentProduct = null;
const USER_ID = <?= (int)$userId ?>;
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
    // Produkte mit Barcode sind für alle sichtbar → nur der Ersteller darf löschen.
    document.getElementById('btnDeleteProdukt').style.display =
        (p.id && p.quelle === 'manuell' && Number(p.ersteller_id) === USER_ID) ? 'block' : 'none';
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

// ── Barcode nachschlagen ─────────────────────────────────────────────────
// Gemeinsam für „Barcode eingeben“ und die Übergabe /log.php?barcode=…
// (der Live-Scanner in scanner.js fragt selbst ab und ruft dann
// onScanProduct / onScanNotFound).
async function lookupBarcode(code) {
    code = String(code || '').replace(/\D/g, '');
    if (code.length < 5) { showToast('Bitte einen gültigen Barcode eingeben'); return; }
    hideNotFound();
    scanOverlayShow('Suche „' + code + '" in Datenbank…', { icon: 'bi-upc-scan', color: 'var(--accent)' });
    try {
        const r = await fetch('/api/barcode.php?code=' + encodeURIComponent(code));
        const d = await r.json();
        if (d.ok) showProduct(d.product);
        else if (d.offline) showToast(d.error);
        else showNotFound(code);
    } catch (e) {
        showToast('Verbindungsfehler – bitte nochmal versuchen');
    } finally {
        scanOverlayHide();
    }
}

// ── Barcode unbekannt → mit diesem Barcode anlegen ───────────────────────
// pendingBarcode wird beim Speichern an produkt_save.php mitgegeben, damit
// der nächste Scan das selbst erfasste Produkt direkt findet.
let pendingBarcode = null;
function showNotFound(code) {
    document.getElementById('productFound').classList.remove('visible');
    document.getElementById('notFoundCode').textContent = code;
    document.getElementById('notFound').dataset.code = code;
    document.getElementById('notFound').classList.remove('d-none');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function hideNotFound() {
    document.getElementById('notFound').classList.add('d-none');
}
function setPendingBarcode(code) {
    pendingBarcode = code || null;
    document.querySelectorAll('.barcode-hint').forEach(el => {
        el.innerHTML = pendingBarcode
            ? '<i class="bi bi-upc"></i> Wird mit Barcode <b class="num">' + escHtml(pendingBarcode) + '</b> gespeichert'
            : '';
        el.style.display = pendingBarcode ? '' : 'none';
    });
}
document.getElementById('btnNfOcr').addEventListener('click', () => {
    setPendingBarcode(document.getElementById('notFound').dataset.code);
    ocrStart();
});
document.getElementById('btnNfManuell').addEventListener('click', () => {
    const code = document.getElementById('notFound').dataset.code;
    openManualModal();
    setPendingBarcode(code);
});
document.getElementById('btnNfNochmal').addEventListener('click', () => {
    hideNotFound();
    zxingStart();
});

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
                datum:   heuteISO(),
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
    setPendingBarcode(null);
    document.getElementById('manualModal').style.display = 'block';
    setTimeout(() => document.getElementById('fKcal').focus(), 100);
}
function closeManualModal() { document.getElementById('manualModal').style.display = 'none'; }
document.getElementById('manualModal').addEventListener('click', function(e) {
    if (e.target === this) closeManualModal();
});
// ── Action Sheet ─────────────────────────────────────────────────────────────
document.getElementById('btnOcrScan').addEventListener('click', () => {
    setPendingBarcode(null);
    ocrStart();
});

// ── Dropdown-Einträge ─────────────────────────────────────────────────────
document.getElementById('ddSchnell').addEventListener('click', openSchnellModal);
document.getElementById('ddManuell').addEventListener('click', openManualModal);

// ── OCR Nährwerte Scanner (ppu-paddle-ocr + PP-OCRv6 tiny) ───────────────
// Bundle assets/js/ocr/ppu-ocr.js = ppu-paddle-ocr/web + onnxruntime-web
// (nur WASM-Backend), per esbuild als IIFE gebaut (globalThis.PpuOcr). Modelle
// und ORT-WASM liegen lokal – ohne wasmPaths käme die WASM-Datei von jsDelivr.
// Bei einem Update alle Dateien gemeinsam ersetzen und OCR_ASSET_VERSION erhöhen.
const OCR_ASSET_VERSION = 'ppu6.6.1-ort1.30.0-v6tiny-w2';
const OCR_MODEL = {
    detection:            '/assets/models/PP-OCRv6_tiny_det.ort',
    recognition:          '/assets/models/PP-OCRv6_tiny_rec.ort',
    charactersDictionary: '/assets/models/ppocrv6_tiny_dict.txt',
};
let _ocrStream  = null;
let _ocrRunning = false;

// ── Diagnose-Protokoll (nur wenn OCR_LOG in config.php gesetzt) ──────────
// Pro Scan-Sitzung: Gerät/Engine, je Analyse Bildausschnitt (JPEG) + gelesene
// Boxen + Zeilen + Werte + Entscheidung, Ende des Scans und die im Formular
// gespeicherten Werte. Ziel: Fehlerketten an echten Packungen nachvollziehen.
// Senden ist „fire and forget“ und darf den Scan nie stören.
const OCR_LOG = <?= (defined('OCR_LOG') && OCR_LOG) ? 'true' : 'false' ?>;
let _ocrLogSitzung = null, _ocrLogBilder = 0, _ocrLogEnde = false, _ocrLogFormular = false;

function ocrLog(typ, daten, canvas) {
    if (!OCR_LOG || !_ocrLogSitzung) return;
    let bild = null;
    if (canvas && _ocrLogBilder < 80) {
        try {
            const k = Math.min(1, 1200 / canvas.width);
            const c = document.createElement('canvas');
            c.width = Math.round(canvas.width * k); c.height = Math.round(canvas.height * k);
            c.getContext('2d').drawImage(canvas, 0, 0, c.width, c.height);
            bild = c.toDataURL('image/jpeg', 0.85).split(',')[1];
            _ocrLogBilder++;
        } catch (e) { /* Bild optional */ }
    }
    fetch('/api/ocr_log.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sitzung: _ocrLogSitzung, typ, daten, bild }),
    }).catch(() => {});
}
function ocrLogStart(daten) {
    if (!OCR_LOG) return;
    _ocrLogSitzung = new Date().toISOString().slice(0, 19).replace(/[^0-9]/g, '').replace(/^(\d{8})/, '$1-')
                   + '-' + Math.random().toString(36).slice(2, 8);
    _ocrLogBilder = 0; _ocrLogEnde = false; _ocrLogFormular = false;
    ocrLog('start', daten);
}
function ocrLogEnde(grund, werte) {
    if (_ocrLogEnde) return;
    _ocrLogEnde = true;
    ocrLog('ende', { grund, werte });
}

// ── OCR-Engine: bevorzugt im Web Worker (assets/js/ocr/ocr-worker.js), damit
// die Live-Analyse die Seite nicht einfriert. Ohne Worker-/OffscreenCanvas-
// Unterstützung oder wenn der Worker nicht startet: direkt auf der Seite.
const ocrEngine = {
    worker: null, svc: null, offen: new Map(), seq: 0, bereit: null,

    init(onStatus) {
        this.bereit ??= this._init(onStatus).catch(e => { this.bereit = null; throw e; });
        return this.bereit;
    },
    async _init(onStatus) {
        const v = '?v=' + OCR_ASSET_VERSION;
        const model = {
            detection:            OCR_MODEL.detection + v,
            recognition:          OCR_MODEL.recognition + v,
            charactersDictionary: OCR_MODEL.charactersDictionary + v,
        };
        const wasmPaths = {
            mjs:  '/assets/js/ocr/ort-wasm-simd-threaded.mjs' + v,
            wasm: '/assets/js/ocr/ort-wasm-simd-threaded.wasm' + v,
        };
        onStatus('Lade OCR-Engine… (einmalig)');
        if (typeof Worker !== 'undefined' && typeof OffscreenCanvas !== 'undefined') {
            try {
                await this._initWorker(model, wasmPaths);
                return;
            } catch (e) {
                console.warn('[OCR] Worker nicht nutzbar, nutze Haupt-Thread:', e);
                this.worker?.terminate(); this.worker = null;
            }
        }
        if (typeof PpuOcr === 'undefined') {
            await new Promise((resolve, reject) => {
                const s = document.createElement('script');
                s.src = '/assets/js/ocr/ppu-ocr.js' + v;
                s.onload = resolve;
                s.onerror = () => reject(new Error('OCR-Engine nicht ladbar'));
                document.head.appendChild(s);
            });
        }
        // ONNX Runtime: WASM lokal, single-threaded (kein COOP/COEP nötig)
        PpuOcr.ort.env.wasm.wasmPaths  = wasmPaths;
        PpuOcr.ort.env.wasm.numThreads = 1;
        this.svc = new PpuOcr.PaddleOcrService({ model, session: { executionProviders: ['wasm'] } });
        await this.svc.initialize();
    },
    _initWorker(model, wasmPaths) {
        return new Promise((resolve, reject) => {
            const w = new Worker('/assets/js/ocr/ocr-worker.js?v=' + OCR_ASSET_VERSION);
            this.worker = w;
            w.onerror = e => reject(new Error(e.message || 'Worker-Fehler'));
            w.onmessage = e => {
                const m = e.data;
                if (m.typ === 'bereit') { resolve(); return; }
                if (m.typ === 'fehler' && m.id == null) { reject(new Error(m.msg)); return; }
                const p = this.offen.get(m.id);
                if (!p) return;
                this.offen.delete(m.id);
                m.typ === 'ergebnis' ? p.resolve(m.items) : p.reject(new Error(m.msg));
            };
            w.postMessage({ typ: 'init', version: OCR_ASSET_VERSION, model, wasmPaths });
        });
    },
    // Canvas → Textboxen [{text,x,y,w,h}]
    async scan(canvas) {
        if (this.worker) {
            const bitmap = await createImageBitmap(canvas);
            const id = ++this.seq;
            return new Promise((resolve, reject) => {
                this.offen.set(id, { resolve, reject });
                this.worker.postMessage({ typ: 'scan', id, bitmap }, [bitmap]);
            });
        }
        const res = await this.svc.recognize(canvas, { flatten: true, minimumConfidence: 0 });
        return res.results.map(r => ({ text: r.text, x: r.box.x, y: r.box.y, w: r.box.width, h: r.box.height, konf: r.confidence }));
    },
};

async function ocrStart() {
    const modal  = document.getElementById('ocrModal');
    const vid    = document.getElementById('ocrVideo');
    const ocrSta = document.getElementById('ocrStatus');
    modal.style.display = 'flex';
    ocrSta.textContent  = 'Kamera wird gestartet…';
    _ocrBester = {};
    _ocrLogSitzung = null;
    ocrChipsSetzen({});

    // OCR-Engine laden (lazy: ~100 KB Skript, ~14 MB ORT-WASM, ~6 MB
    // Modelle – nur beim ersten Öffnen, danach Browser-Cache)
    try {
        await ocrEngine.init(t => { ocrSta.textContent = t; });
    } catch(e) {
        ocrSta.textContent = 'Fehler: ' + e.message;
        console.error('[OCR]', e);
        return;
    }
    if (modal.style.display === 'none') return;   // während des Ladens geschlossen

    try {
        _ocrStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment', width: {ideal:1920}, height: {ideal:1080} }
        });
        vid.srcObject = _ocrStream;
        vid.setAttribute('playsinline', '');
        vid.setAttribute('autoplay', '');
        vid.muted = true;
        await vid.play().catch(e => console.warn('[OCR] play()', e));
        if (modal.style.display === 'none') { ocrStop(); return; }
        const fr = document.getElementById('ocrFrame').getBoundingClientRect();
        ocrLogStart({
            ua: navigator.userAgent, engine: ocrEngine.worker ? 'worker' : 'haupt-thread',
            version: OCR_ASSET_VERSION, video: [vid.videoWidth, vid.videoHeight],
            fenster: [innerWidth, innerHeight, devicePixelRatio], rahmen: [fr.width, fr.height],
        });
        _ocrRunning = true;
        ocrLiveLoop();
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

    // 3. OCR: Detection + Recognition, flach mit Boxen
    // Konfidenz-Filter hier statt in der Bibliothek: Die bewertet Zeilen
    // stapelweise (mehrere Zeilen teilen sich einen Wert) – mit ihrem Standard
    // 0,5 fielen auf dem iPhone ganze Tabellenhälften weg. 0,2 hält echte
    // Lesungen, wirft Rauschen (≈ 0–0,1) raus.
    const roh    = (await ocrEngine.scan(canvas)).filter(i => i.text.trim());
    const items  = roh.filter(i => i.konf === undefined || i.konf >= OCR_MIN_KONF);
    const zeilen = ocrZeilenBilden(items);
    const werte  = parseNaehrwerte(zeilen.map(z => z.text).join('\n'));
    return { werte, zeilen, items, roh, canvas, w: canvas.width, h: canvas.height };
}

// ── Erkennungs-Overlay ───────────────────────────────────────────────────
// Der analysierte Ausschnitt ist genau der Scan-Rahmen, OCR-Koordinaten
// lassen sich daher als Prozentwerte direkt über den Rahmen legen. Markiert
// wird nur die Nährwerttabelle (dezenter Rahmen) und darin die Zeilen, aus
// denen ein Wert stammt (Farben wie die Chips, app.css) – übriger Text auf der
// Packung bleibt unmarkiert. Beides ist entlang der Schräglage gedreht; ohne
// neue Analyse verblassen die Markierungen (sie hinken ~1 s hinterher).
let _ocrBoxenTimer = null;

// Tabellenzeile – an der Form erkannt, nicht an einzelnen Wörtern (sonst
// zählen Zutatenliste „…, Zucker, Salz“ oder „Hergestellt in … 500 g“ mit):
//   · beginnt (ggf. nach „davon“) mit einem Nährwert-Begriff und hat eine Zahl
//     – auch wenn die Einheit verlesen ist („Salz ∩.85 α“), oder
//   · enthält kJ/kcal oder mindestens zwei Zahlen mit Einheit (Spalten
//     „pro 100 g“ / „pro Portion“).
const OCR_BEGRIFF_ANFANG_RE = /^\W*(?:davon\s+)?(?:durchschnittl\w*\s+)?(?:n[äa]hrwert|brennwert|energie|fett|ges[äa]tt|einfach|mehrfach|kohlenh|zucker|eiwei|protein|salz|natrium|ballast|energy|fat|saturate|carbohydrate|sugar|salt|fibre|fiber)/i;
const ocrIstTabellenZeile = t =>
    (OCR_BEGRIFF_ANFANG_RE.test(t) && /\d/.test(t))
    || /\d\s*k\s?(?:j|cal)\b/i.test(t)
    || (t.match(/\d\s*(?:mg|g)\b/gi) || []).length >= 2;

// Zeilenbereich [von, bis] der Tabelle: zusammenhängende Tabellenzeilen
// (eine fremde Zeile dazwischen erlaubt); bei mehreren Blöcken gewinnt der
// mit den meisten Wertzeilen, dann der längste. null = keine Tabelle.
function ocrTabelleFinden(zeilen, wertZeilen) {
    const ist = zeilen.map(z => ocrIstTabellenZeile(z.text));
    const bloecke = [];
    let von = -1, letzte = -1;
    ist.forEach((t, i) => {
        if (!t) return;
        if (von >= 0 && i - letzte <= 2) { letzte = i; return; }
        if (von >= 0) bloecke.push([von, letzte]);
        von = letzte = i;
    });
    if (von >= 0) bloecke.push([von, letzte]);
    const werte = Object.values(wertZeilen || {});
    const note = ([a, b]) => werte.filter(i => i >= a && i <= b).length * 100 + (b - a);
    return bloecke.sort((x, y) => note(y) - note(x))[0] || null;
}

// Gedrehtes Rechteck als Prozent-Style. Punkte in Bildkoordinaten, winkel
// in rad; die Skalierung Bild→Rahmen ist in x und y gleich, der Winkel bleibt.
function ocrGedrehtesRechteck(ana, punkte, winkel, pad) {
    const cos = Math.cos(winkel), sin = Math.sin(winkel);
    const u = punkte.map(([x, y]) =>  x * cos + y * sin);
    const v = punkte.map(([x, y]) => -x * sin + y * cos);
    const u1 = Math.min(...u) - pad, u2 = Math.max(...u) + pad;
    const v1 = Math.min(...v) - pad, v2 = Math.max(...v) + pad;
    const uc = (u1 + u2) / 2, vc = (v1 + v2) / 2, w = u2 - u1, h = v2 - v1;
    const cx = uc * cos - vc * sin, cy = uc * sin + vc * cos;
    return `left:${(cx - w / 2) / ana.w * 100}%;top:${(cy - h / 2) / ana.h * 100}%;`
         + `width:${w / ana.w * 100}%;height:${h / ana.h * 100}%;transform:rotate(${winkel}rad);`;
}

// Box gehört zum Tabelleninhalt: Bezeichnung („Fett“, „- davon Zucker“) oder
// reiner Zahlenwert („32g“, „2276 kJ/“, „0,25 g“). Zutaten- oder Werbetext,
// der neben der Tabelle in dieselben Zeilen fällt, passt auf keins von beiden.
const OCR_WERT_BOX_RE = /^[\s\-–~<>]*\d[\d.,\s]*(?:m?g|k\s?j|k\s?cal)?\s*\/?\s*$/i;
const ocrIstTabellenBox = t => OCR_BEGRIFF_ANFANG_RE.test(t) || OCR_WERT_BOX_RE.test(t) || ocrIstTabellenZeile(t);

function ocrBoxenZeigen(ana) {
    const el = document.getElementById('ocrBoxen');
    const wertZeilen = ana.werte.zeilen || {};
    const bereich = ocrTabelleFinden(ana.zeilen, wertZeilen);
    let html = '';
    if (bereich) {
        const tabZeilen = ana.zeilen.slice(bereich[0], bereich[1] + 1);
        // Spaltenbereich der Tabelle aus den Tabellen-Boxen; Boxen außerhalb
        // (z.B. Zutatenliste rechts daneben) bleiben unberücksichtigt
        const tabBoxen = tabZeilen.flatMap(z => z.boxen).filter(b => ocrIstTabellenBox(b.text));
        const xMin = tabBoxen.length ? Math.min(...tabBoxen.map(b => b.x)) : -Infinity;
        const xMax = tabBoxen.length ? Math.max(...tabBoxen.map(b => b.x + b.w)) : Infinity;
        // Teil einer Zeile innerhalb der Spalten, entlang ihrer Mittellinie
        const ausschnitt = z => {
            const bs = z.boxen.filter(b => b.x + b.w / 2 >= xMin && b.x + b.w / 2 <= xMax);
            if (!bs.length) return null;
            const m = z.mitte, steig = (m.y2 - m.y1) / (m.x2 - m.x1 || 1);
            const x1 = Math.min(...bs.map(b => b.x)), x2 = Math.max(...bs.map(b => b.x + b.w));
            return { x1, y1: m.y1 + steig * (x1 - m.x1), x2, y2: m.y1 + steig * (x2 - m.x1), h: m.h };
        };
        const kanten = m => [[m.x1, m.y1 - m.h / 2], [m.x2, m.y2 - m.h / 2],
                             [m.x1, m.y1 + m.h / 2], [m.x2, m.y2 + m.h / 2]];
        const ms = tabZeilen.map(ausschnitt).filter(Boolean);
        if (ms.length) {
            const winkel = Math.atan2(ms[0].y2 - ms[0].y1, ms[0].x2 - ms[0].x1);
            const pad = Math.min(...ms.map(m => m.h)) * 0.6;
            html += `<div class="ocr-tabelle" style="${ocrGedrehtesRechteck(ana, ms.flatMap(kanten), winkel, pad)}"></div>`;
        }
        for (const [feld, idx] of Object.entries(wertZeilen)) {
            if (idx < bereich[0] || idx > bereich[1]) continue;
            const m = ausschnitt(ana.zeilen[idx]);
            if (!m) continue;
            const winkel = Math.atan2(m.y2 - m.y1, m.x2 - m.x1);
            html += `<div class="ocr-zeile" data-feld="${feld}" style="${ocrGedrehtesRechteck(ana, kanten(m), winkel, m.h * 0.25)}"></div>`;
        }
    }
    el.classList.remove('alt', 'erkannt');
    el.innerHTML = html;
    clearTimeout(_ocrBoxenTimer);
    _ocrBoxenTimer = setTimeout(() => el.classList.add('alt'), 1600);
}
function ocrBoxenLeeren() {
    clearTimeout(_ocrBoxenTimer);
    const el = document.getElementById('ocrBoxen');
    el.classList.remove('alt', 'erkannt');
    el.innerHTML = '';
}

// ── Live-Erkennung ───────────────────────────────────────────────────────
// Solange der Scanner offen ist, wird laufend ein Bild analysiert (eins nach
// dem anderen, ~1/s). Übernommen wird automatisch, sobald kcal, Eiweiß, Fett
// und KH erkannt sind UND das Ergebnis bestätigt ist (Abstimmung über die
// letzten Analysen, siehe ocrKonsens). Bis dahin zeigt der Scanner je Feld
// den bisher häufigsten Wert an. Der Knopf darunter ist ein Ausweg, keine Bestätigung:
// er öffnet sofort das Formular mit dem bisherigen Stand – geprüft wird dort.
const OCR_FELDER = ['kcal', 'eiweiss', 'fett', 'kh'];
const OCR_LABEL  = { kcal: 'kcal', eiweiss: 'Eiweiß', fett: 'Fett', kh: 'KH' };
let _ocrSession = 0;
let _ocrBester  = {};                   // je Feld der zuletzt erkannte Wert

function ocrChipsSetzen(werte) {
    const fmt = v => Number(v).toLocaleString('de-DE', { maximumFractionDigits: 1 });
    let n = 0;
    document.querySelectorAll('#ocrChips [data-feld]').forEach(el => {
        const f = el.dataset.feld, v = werte[f], da = v !== undefined && v !== '';
        if (da) n++;
        el.classList.toggle('ok', da);
        el.textContent = f === 'kcal'
            ? (da ? fmt(v) + ' kcal' : 'kcal –')
            : OCR_LABEL[f] + ' ' + (da ? fmt(v) + ' g' : '–');
    });
    document.getElementById('btnOcrCapture').innerHTML = n
        ? '<i class="bi bi-arrow-right"></i> Mit erkannten Werten weiter'
        : '<i class="bi bi-pencil"></i> Selbst eintragen';
}

// Abstimmung über die letzten Analysen. Jede Lesung eines Feldes zählt als
// Stimme, auch wenn im selben Bild ein anderes Feld fehlte. Bestätigt ist:
//   · eine Kombination aus Kandidaten mit je ≥ 2 Stimmen, deren Werte
//     zueinander passen (4·E + 4·KH + 9·F ≈ kcal) – bei mehreren die mit den
//     meisten Stimmen. Das löst auch Gleichstände wie Eiweiß „49“ vs. „4,9“.
//   · sonst ≥ 3 Stimmen je Feld, doppelt so viele wie der Zweite und ≤ 25 %
//     Abweichung (für Produkte, bei denen
//     die Faustformel nicht passt, z.B. viel Ballaststoffe/Zuckeralkohol).
// werte: je Feld der Favorit (bei Gleichstand der zuletzt gelesene) – für die Chips.
const OCR_FENSTER = 20;
const OCR_MIN_KONF = 0.2;
function ocrKonsens(verlauf) {
    const fenster = verlauf.slice(-OCR_FENSTER);
    const werte = {}, rang = {};
    for (const k of [...OCR_FELDER, 'portion']) {
        const z = new Map();                        // Wert → { n, zuletzt }
        fenster.forEach((p, i) => {
            if (p[k] === '' || p[k] === undefined) return;
            const e = z.get(p[k]) || { n: 0, zuletzt: 0 };
            e.n++; e.zuletzt = i; z.set(p[k], e);
        });
        rang[k] = [...z].sort((a, b) => b[1].n - a[1].n || b[1].zuletzt - a[1].zuletzt);
        if (rang[k].length) werte[k] = rang[k][0][0];
    }

    // Plausible Kombination aus Kandidaten mit ≥ 2 Stimmen (je Feld max. 3)
    const kand = OCR_FELDER.map(k => rang[k].filter(([, e]) => e.n >= 2).slice(0, 3));
    let beste = null;
    if (kand.every(l => l.length)) {
        const kombi = (i, wahl) => {
            if (i === OCR_FELDER.length) {
                const w = Object.fromEntries(OCR_FELDER.map((k, j) => [k, wahl[j][0]]));
                const n = wahl.reduce((s, [, e]) => s + e.n, 0);
                if (ocrPlausibel(w) && (!beste || n > beste.n)) beste = { w, n };
                return;
            }
            for (const c of kand[i]) kombi(i + 1, [...wahl, c]);
        };
        kombi(0, []);
    }
    if (beste) return { werte: { ...werte, ...beste.w }, bestaetigt: true };

    // ohne enge Plausibilität nur bei deutlichem Vorsprung (≥ 3 Stimmen und
    // mindestens doppelt so viele wie der Zweitplatzierte) und grob passend
    // (≤ 25 %: Ballaststoffe/Zuckeralkohole verschieben die Faustformel um
    // höchstens ~20 %; „KH 8“ statt 33 beim Ketchup wären 75 %)
    const klar = OCR_FELDER.every(k => rang[k].length && rang[k][0][1].n >= 3
                                       && rang[k][0][1].n >= 2 * (rang[k][1]?.[1].n ?? 0));
    return { werte, bestaetigt: klar && ocrPlausibel(werte, 0.25) };
}

// Echte Packungen weichen ≤ 4 % ab (Lindt, Nutella, Haribo, Ketchup …); 15 %
// ließ eine verrutschte Lesung durch (Nutella mit „Eiweiß 56,3“: 9 %).
function ocrPlausibel(p, toleranz = 0.08) {
    const berechnet = 4 * p.eiweiss + 4 * p.kh + 9 * p.fett;
    return Math.abs(berechnet - p.kcal) <= Math.max(12, p.kcal * toleranz);
}

async function ocrLiveLoop() {
    const session = ++_ocrSession;
    const vid    = document.getElementById('ocrVideo');
    const ocrSta = document.getElementById('ocrStatus');
    const frame  = document.getElementById('ocrFrame');
    const dot    = document.getElementById('ocrDot');
    const aktiv  = () => _ocrRunning && session === _ocrSession;
    const warte  = ms => new Promise(r => setTimeout(r, ms));
    let bester = _ocrBester = {};
    const verlauf = [];
    const start = Date.now();
    ocrSta.textContent = 'Suche Nährwerte…';
    dot.style.animation = 'zxingPulse 1.4s ease-in-out infinite';

    // Erfolg kurz im Bild zeigen (Markierungen grün), dann ins Formular
    const uebernehmen = async (werte) => {
        frame.style.borderColor = 'var(--accent)';
        document.getElementById('ocrBoxen').classList.add('erkannt');
        ocrSta.textContent = '✓ Erkannt';
        dot.style.animation = '';
        await warte(450);
        if (!aktiv()) return;             // inzwischen geschlossen/„Selbst eintragen“
        ocrLogEnde('automatisch', werte);
        ocrStop();
        showOcrResult(werte, true);
    };

    while (aktiv()) {
        if (document.hidden || vid.readyState < 2 || !vid.videoWidth) { await warte(300); continue; }
        let p, ana, t0 = performance.now();
        try {
            ana = await ocrAnalyzeFrame();
            if (!aktiv()) return;
            p = ana.werte;
            ocrBoxenZeigen(ana);
        } catch (e) {
            console.warn('[OCR] Analyse', e);
            ocrLog('frame', { fehler: String(e.message || e) });
            if (!aktiv()) return;
            ocrSta.textContent = '✗ ' + String(e.message || e).slice(0, 60);
            await warte(1500);
            continue;
        }
        if (!aktiv()) return;

        verlauf.push(p);
        const konsens = ocrKonsens(verlauf);
        bester = _ocrBester = konsens.werte;     // Chips zeigen den Favoriten je Feld
        ocrChipsSetzen(bester);
        const komplett = OCR_FELDER.every(k => p[k] !== '');
        if (OCR_LOG) {
            const r = v => Math.round(v);
            ocrLog('frame', {
                ms: r(performance.now() - t0), w: ana.w, h: ana.h,
                items: ana.items.map(i => [i.text, r(i.x), r(i.y), r(i.w), r(i.h), +(i.konf ?? 1).toFixed(2)]),
                unter05: ana.items.filter(i => i.konf < 0.5).length,
                verworfen: ana.roh.filter(i => !(i.konf >= OCR_MIN_KONF)).map(i => [i.text, +(i.konf ?? 0).toFixed(2)]),
                zeilen: ana.zeilen.map(z => z.text),
                tabelle: ocrTabelleFinden(ana.zeilen, p.zeilen),
                werte: p, konsens, komplett,
                plausibel: komplett ? ocrPlausibel(p) : null,
            }, ana.canvas);
        }
        if (konsens.bestaetigt) {
            await uebernehmen({ kcal: '', eiweiss: '', fett: '', kh: '', portion: '', ...konsens.werte });
            return;
        }

        const n = OCR_FELDER.filter(k => bester[k] !== undefined).length;
        ocrSta.textContent = n === 4 ? 'Prüfe Werte…'
            : (Date.now() - start > 20000 && n < 4)
                ? 'Tipp: näher ran, gerade halten, Spiegelungen vermeiden'
                : n ? `Suche Nährwerte… (${n}/4)` : 'Suche Nährwerte…';
        await warte(200);
    }
}

// „Selbst eintragen“ / „Mit erkannten Werten weiter“: sofort ins Formular,
// auch während die Engine noch lädt; eine laufende Analyse wird verworfen.
function ocrCapture() {
    const werte = { kcal: '', eiweiss: '', fett: '', kh: '', portion: '', ..._ocrBester };
    ocrLogEnde('knopf', werte);
    ocrStop();
    showOcrResult(werte, false);
}

// ── Zeilen aus OCR-Boxen bilden, tolerant gegen schräg gehaltene Etiketten ──
// Schon 3–4° Schräglage reichen, dass bei einer breiten Tabelle der Wert
// rechts auf Höhe der NÄCHSTEN Zeile links landet. Daher zuerst die Steigung
// schätzen: Boxpaare, die weit auseinander in derselben Zeile liegen, haben
// alle dieselbe Steigung; Paare aus Nachbarzeilen streuen. Die häufigste
// Steigung (Histogramm-Spitze) ist die Schräglage. Danach Zeilen entlang
// dieser Steigung clustern und Boxen in Zeilenrichtung sortieren.
// Ergebnis: [{ text, boxen: [{x,y,w,h}], mitte }] in Lesereihenfolge
function ocrZeilenBilden(items) {
    if (!items.length) return [];
    const pts = items.map(i => ({ ...i, cx: i.x + i.w / 2, cy: i.y + i.h / 2 }));
    const minX = Math.min(...pts.map(p => p.x)), maxX = Math.max(...pts.map(p => p.x + p.w));
    const hMed = [...pts.map(p => p.h)].sort((a, b) => a - b)[Math.floor(pts.length / 2)];

    // Steigungs-Histogramm (Bins à 0,005 ≈ 0,3°, Bereich ±0,15 ≈ ±8,5°)
    const BIN = 0.005, MAX = 0.15, bins = new Map();
    for (let i = 0; i < pts.length; i++) for (let j = 0; j < pts.length; j++) {
        const a = pts[i], b = pts[j], dx = b.cx - a.cx;
        if (dx < (maxX - minX) * 0.25) continue;
        const m = (b.cy - a.cy) / dx;
        if (Math.abs(m) > MAX) continue;
        const k = Math.round(m / BIN);
        bins.set(k, (bins.get(k) || 0) + dx);    // weite Paare zählen mehr
    }
    let slope = 0, best = 0;
    for (const [k, wgt] of bins) {
        const w3 = wgt + (bins.get(k - 1) || 0) * 0.5 + (bins.get(k + 1) || 0) * 0.5;
        if (w3 > best) { best = w3; slope = k * BIN; }
    }

    // Entlang der Steigung „geradeziehen“ und zu Zeilen clustern
    for (const p of pts) p.yr = p.cy - slope * (p.cx - minX);
    pts.sort((a, b) => a.yr - b.yr);
    const zeilen = [];
    for (const p of pts) {
        const z = zeilen[zeilen.length - 1];
        if (z && Math.abs(p.yr - z.yr) < hMed * 0.55) { z.teile.push(p); z.yr = (z.yr * (z.teile.length - 1) + p.yr) / z.teile.length; }
        else zeilen.push({ yr: p.yr, teile: [p] });
    }
    // mitte: Mittellinie der Zeile entlang der Schräglage (für das Overlay)
    return zeilen.map(z => {
        const teile = z.teile.sort((a, b) => a.cx - b.cx);
        const x1 = Math.min(...teile.map(p => p.x)), x2 = Math.max(...teile.map(p => p.x + p.w));
        return {
            text:  teile.map(p => p.text).join(' '),
            boxen: teile.map(({ text, x, y, w, h }) => ({ text, x, y, w, h })),
            // Höhe: kleinste Box der Zeile – kurze Wörter sind durch die
            // Schräglage am wenigsten aufgebläht (achsenparallele Boxen)
            mitte: { x1, y1: z.yr + slope * (x1 - minX), x2, y2: z.yr + slope * (x2 - minX),
                     h: Math.min(...teile.map(p => p.h)) },
        };
    });
}

// OCR-Text normalisieren: deutsches Komma → Punkt, O/o/l zwischen bzw. vor
// Ziffern → 0/1. Ziffern selbst bleiben unangetastet.
function normalizeOcrText(text) {
    return text
        // Einheitenspalte „(g)“, „[g]“ – OCR liest das g oft als 9 („(9)“), das
        // wäre sonst die erste Zahl der Zeile
        .replace(/[(\[{]\s*[g9q]\s*[)\]}]/gi, ' ')
        .replace(/(\d)[oO](?=\d|\b)/g, '$10')
        .replace(/\b[oO](?=[.,]?\d)/g, '0')
        .replace(/\bl(?=\d)/g, '1')
        .replace(/(\d)\s?,\s?(\d)/g, '$1.$2');
}

// Erster Nährwert einer Zeile (g pro 100 g). OCR liest das „g“ der Einheit
// gelegentlich als „9“ („2.39“ statt „2.3g“). Eine 9 am Ende wird aber NUR
// entfernt, wenn danach keine Einheit steht UND der Wert sonst unplausibel
// wäre (zwei Nachkommastellen bzw. > 100 g) – „19 g“ oder „2.9 g“ bleiben so.
function extractNaehrwert(line) {
    const re = /(\d+(?:\.\d+)?)(\s*(?:mg|g|kcal|kj)\b)?/gi;
    let m;
    while ((m = re.exec(line)) !== null) {
        let raw = m[1];
        const hatEinheit = !!m[2];
        if (/^mg/i.test((m[2] || '').trim())) continue;   // mg-Angaben überspringen
        // Nur bei zwei Nachkommastellen („12.39“ → 12.3). Ganze Zahlen > 100
        // („539“) sind fast immer ein Wert aus einer verrutschten Zeile, z.B.
        // kcal – gekürzt würde daraus ein falscher Nährwert (53).
        if (!hatEinheit && raw.length > 1 && raw.endsWith('9') && /\.\d9$/.test(raw)) {
            raw = raw.slice(0, -1).replace(/\.$/, '');
        }
        const v = parseFloat(raw);
        // Bezugsgröße „pro 100 g“ in derselben Zeile ist kein Nährwert
        if (v === 100 && /(?:pro|je|per)\s*$/i.test(line.slice(0, m.index))) continue;
        if (!isNaN(v) && v <= 100) return v;
    }
    return null;
}

// Editierabstand (Levenshtein) – für verlesene Bezeichnungen wie „Eieiß“,
// „Kohientydrate“ oder „Fetl“.
function editAbstand(a, b) {
    let prev = Array.from({ length: b.length + 1 }, (_, j) => j);
    for (let i = 1; i <= a.length; i++) {
        const cur = [i];
        for (let j = 1; j <= b.length; j++) {
            cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
        }
        prev = cur;
    }
    return prev[b.length];
}
// Erstes Wort der Zeile (nur Buchstaben) ähnelt einem der Begriffe? Der
// Anfangsbuchstabe muss stimmen – so kann z.B. „Salz“ nie als „Fett“ gelten.
function zeileBeginntMit(l, begriffe, maxFehler) {
    const wort = (l.match(/\p{L}+/u) || [''])[0];
    // lange Wörter vertragen mehr Lesefehler („Kohienyratle“ → 4 von 13):
    // bis zu einem Drittel der Länge
    return wort.length >= 3 && begriffe.some(b => wort[0] === b[0]
        && editAbstand(wort, b) <= Math.max(maxFehler, Math.round(b.length / 3)));
}

function parseNaehrwerte(text) {
    // zeilen: Feld → Index der Zeile, aus der der Wert stammt (für das Overlay)
    const result = { kcal: '', eiweiss: '', fett: '', kh: '', portion: '', zeilen: {} };
    const clean = normalizeOcrText(text);
    const lines = clean.split('\n').map(l => l.trim()).filter(Boolean);

    // ── kcal: explizites „N kcal“ hat Priorität, dann „kJ/kcal 1046/249“,
    // zuletzt nur kJ → umrechnen (÷ 4,184).
    // Steht ein kJ-Wert da, muss der kcal-Wert dazu passen (± 5 %) – sonst
    // stammt er z.B. aus der Portionsspalte oder ist verlesen, dann gilt kJ.
    // „249 kcal“ – nur innerhalb einer Zeile (über einen Umbruch hinweg würde
    // „…336/ 4⏎kcal 539“ zu „4 kcal“). Dazu Einheitenspalte VOR der Zahl am
    // Zeilenanfang („kcal) 539“, „(kJ 2252/“), wie bei Nutella.
    const kcalWerte  = [...clean.matchAll(/(\d+(?:\.\d+)?)[ \t]*k[ \t]?cal/gi),
                        ...clean.matchAll(/^\W*k[ \t]?cal\W{0,3}(\d+(?:\.\d+)?)/gim)]
                       .map(m => parseFloat(m[1])).filter(v => v <= 900);
    const kombiMatch = clean.match(/kj\s*\/\s*k\s?cal\D{0,15}(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)/i);
    const kjMatch    = clean.match(/(\d+(?:\.\d+)?)[ \t]*k[ \t]?j/i)
                    || clean.match(/^\W*(?:\p{L}+\W+)?k[ \t]?j\W{0,3}(\d{3,4}(?:\.\d+)?)/imu);
    const ausKj      = kjMatch ? parseFloat(kjMatch[1]) / 4.184 : null;
    const passt      = v => ausKj === null || Math.abs(v - ausKj) <= Math.max(3, ausKj * 0.05);
    // Widersprechen sich kcal und kJ, ist einer verlesen (z.B. „2042 kJ“ statt
    // 1042, oder kcal aus der Portionsspalte). Entschieden wird nach den Makros
    // (unten, sobald sie bekannt sind); ohne Makros gilt der kJ-Wert.
    let kcalStrittig = null;
    // „kJ/kcal 2252/539“: nur wenn das Paar zusammenpasst (sonst z.B. „336/ 4“
    // aus der Portionsspalte oder ein verlesener Wert)
    const kombiOk = kombiMatch && Math.abs(parseFloat(kombiMatch[1]) / 4.184 - parseFloat(kombiMatch[2]))
                                  <= Math.max(3, parseFloat(kombiMatch[2]) * 0.05);
    if (kombiOk && passt(parseFloat(kombiMatch[2]))) {
        result.kcal = parseFloat(kombiMatch[2]);
    } else if (kcalWerte.some(passt)) {
        result.kcal = kcalWerte.find(passt);
    } else if (ausKj !== null && ausKj <= 900) {
        result.kcal = Math.round(ausKj);
        if (kcalWerte.length) kcalStrittig = [result.kcal, ...kcalWerte];
    } else if (kcalWerte.length) {
        result.kcal = kcalWerte[0];
    }
    if (result.kcal !== '') {
        const mitZahl = lines.findIndex(l => [...l.matchAll(/(\d+(?:\.\d+)?)\s*k\s?cal/gi)]
            .some(m => parseFloat(m[1]) === result.kcal));
        const i = mitZahl >= 0 ? mitZahl : lines.findIndex(l => /k\s?j|k\s?cal/i.test(l));
        if (i >= 0) result.zeilen.kcal = i;
    }

    // ── Portionsgröße: „pro Portion (30 g)“, „1 Portion = 30 g“, „pro 30 g“
    // „port\w{0,3}n“ deckt auch verlesene Formen wie „Porton“ ab
    const portionMatch = clean.match(/(?:port\w{0,3}n|serving|stück)\D{0,20}?(\d+(?:\.\d+)?)\s*(?:g|ml)\b/i)
                      || clean.match(/(?:pro|je)\s+(\d+(?:\.\d+)?)\s*(?:g|ml)\b/i);
    if (portionMatch && parseFloat(portionMatch[1]) !== 100) {
        result.portion = parseFloat(portionMatch[1]);
    }

    lines.forEach((line, i) => {
        const l = line.toLowerCase();
        if (result.eiweiss === '' && (l.includes('eiwei') || l.includes('protein')
                                || zeileBeginntMit(l, ['eiweiß', 'eiweiss'], 2))) {
            const v = extractNaehrwert(line); if (v !== null) { result.eiweiss = v; result.zeilen.eiweiss = i; }
        }
        if (result.fett === '' && (l.includes('fett') || /\bfat\b/.test(l) || zeileBeginntMit(l, ['fett'], 2))
            && !/^\W*davon\b/.test(l) && !/ges[äa]t+ig|fetts[äa]ure|saturate|trans/.test(l)) {
            const v = extractNaehrwert(line); if (v !== null) { result.fett = v; result.zeilen.fett = i; }
        }
        if (result.kh === '' && (l.includes('kohlenh') || l.includes('carbohydrate') || /\bkh\b/.test(l)
                           || zeileBeginntMit(l, ['kohlenhydrate'], 3))) {
            const v = extractNaehrwert(line); if (v !== null) { result.kh = v; result.zeilen.kh = i; }
        }
    });

    if (kcalStrittig && result.eiweiss !== '' && result.fett !== '' && result.kh !== '') {
        const ausMakros = 4 * result.eiweiss + 4 * result.kh + 9 * result.fett;
        result.kcal = kcalStrittig.reduce((a, b) => Math.abs(b - ausMakros) < Math.abs(a - ausMakros) ? b : a);
    }
    return result;
}

// automatisch = vom Live-Scan bestätigt; sonst über den Knopf mit Teilstand
function showOcrResult(parsed, automatisch = false) {
    const n = OCR_FELDER.filter(k => parsed[k] !== '' && parsed[k] != null).length;
    document.getElementById('ocrResultHinweis').textContent =
        automatisch ? 'Automatisch erkannt – bitte mit der Packung vergleichen'
        : n === 4   ? 'Erkannt, aber noch nicht bestätigt – bitte genau prüfen'
        : n         ? n + ' von 4 Werten erkannt – bitte prüfen und ergänzen'
        :             'Bitte die Werte von der Packung eintragen';
    const wert = v => (v === '' || v == null) ? '' : v;   // 0 g ist ein gültiger Wert
    document.getElementById('ocrKcal').value    = wert(parsed.kcal);
    document.getElementById('ocrEiweiss').value = wert(parsed.eiweiss);
    document.getElementById('ocrFett').value    = wert(parsed.fett);
    document.getElementById('ocrKh').value      = wert(parsed.kh);
    document.getElementById('ocrMenge').value   = '100';
    document.getElementById('ocrPortion').value = parsed.portion || '';
    document.getElementById('ocrName').value    = '';
    document.getElementById('ocrPreview').style.display = 'none';
    document.getElementById('ocrResultModal').style.display = 'block';
    updateOcrPreview();
    setTimeout(() => document.getElementById('ocrName').focus(), 100);
}

function closeOcrResult() {
    if (!_ocrLogFormular) { _ocrLogFormular = true; ocrLog('verworfen', null); }
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

    // Protokoll: was tatsächlich gespeichert wurde (Vergleich mit der Erkennung)
    if (!_ocrLogFormular) {
        _ocrLogFormular = true;
        ocrLog('gespeichert', Object.fromEntries(['ocrName', 'ocrKcal', 'ocrEiweiss', 'ocrFett', 'ocrKh', 'ocrPortion', 'ocrMenge']
            .map(id => [id.slice(3).toLowerCase(), document.getElementById(id).value])));
    }

    const btn = document.getElementById('btnOcrSave');
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
                portion_g:    parseFloat(document.getElementById('ocrPortion').value) || null,
                barcode:      pendingBarcode,
            }),
        });
        const dP = await rP.json();
        if (!dP.ok) throw new Error(dP.error);
        if (pendingBarcode) { hideNotFound(); setPendingBarcode(null); }

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
                datum:   heuteISO(),
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
    if (_ocrRunning) ocrLogEnde('geschlossen', { ..._ocrBester });
    if (typeof scanOverlayHide === 'function') scanOverlayHide();
    _ocrRunning = false;
    _ocrSession++;
    ocrBoxenLeeren();
    document.getElementById('ocrDot').style.animation = '';
    document.getElementById('ocrFrame').style.borderColor = 'var(--carb)';
    if (_ocrStream) { _ocrStream.getTracks().forEach(t => t.stop()); _ocrStream = null; }
    document.getElementById('ocrModal').style.display = 'none';
}
window.addEventListener('pagehide', ocrStop);



// Scanner (global, scanner.js): Treffer direkt hier im Produkt-Sheet zeigen
// statt wie auf anderen Seiten nach /log.php?barcode=… weiterzuleiten.
window.onScanProduct  = (product) => showProduct(product);
window.onScanNotFound = (code) => showNotFound(code);

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
    // lookupBarcode braucht das Such-Overlay aus scanner.js (defer) – das ist
    // erst nach dem Parsen da, dieses Inline-Skript läuft aber schon vorher.
    if (document.readyState === 'loading') {
        await new Promise(r => document.addEventListener('DOMContentLoaded', r, { once: true }));
    }
    lookupBarcode(code);
})();

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
                barcode:      pendingBarcode,
            }),
        });
        const pData = await pRes.json();
        if (!pData.ok && pendingBarcode) { showToast('Fehler: ' + (pData.error || 'Unbekannt')); return; }
        const produktId = pData.ok ? (pData.id || null) : null;
        if (pData.ok && pendingBarcode) { hideNotFound(); setPendingBarcode(null); }
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
                datum:   heuteISO(),
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
                datum:   heuteISO(),
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
    hideNotFound();
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
        if (d.ok && d.deleted) {
            showToast('Produkt gelöscht');
            document.getElementById('btnVerwerfen').click();
            // Top-6 neu laden damit gelöschtes Produkt verschwindet
            loadTop5();
        } else {
            showToast('Fehler: ' + (d.error || 'Produkt konnte nicht gelöscht werden'));
        }
    } catch(e) {
        showToast('Verbindungsfehler');
    }
});

// ── Barcode manuell Events ───────────────────────────────────────────────
document.getElementById('btnLookupManual').addEventListener('click', () => {
    const code = document.getElementById('manualBarcodeInput').value.replace(/\D/g, '');
    if (code.length < 5) { showToast('Bitte einen gültigen Barcode eingeben'); return; }
    closeBarcodeManuellModal();
    lookupBarcode(code);
});
document.getElementById('manualBarcodeInput').addEventListener('keyup', e => {
    if (e.key === 'Enter') document.getElementById('btnLookupManual').click();
});


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
    const datum = heuteISO();
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
