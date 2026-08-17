<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
$currentUser = requireLogin();

// Persönlichen API-Key laden (und ggf. erstmalig generieren)
$db   = db();
$stmt = $db->prepare("SELECT api_key FROM users WHERE id = ?");
$stmt->bind_param('i', $currentUser['id']);
$stmt->execute();
$row  = $stmt->get_result()->fetch_assoc();
$apiKey = $row['api_key'] ?? null;

// Falls noch kein Key vorhanden (z.B. alter Account vor der Migration),
// jetzt einen generieren und speichern.
if (!$apiKey) {
    $apiKey = bin2hex(random_bytes(32));
    $upd = $db->prepare("UPDATE users SET api_key = ? WHERE id = ?");
    $upd->bind_param('si', $apiKey, $currentUser['id']);
    $upd->execute();
}
renderHeader('API-Dokumentation', 'profil');
?>

<div class="page-header">
    <h1><i class="bi bi-code-slash text-accent me-1"></i> API-Dokumentation</h1>
</div>

<div style="padding:0 1rem 2rem;">

<p style="color:var(--muted);font-size:.88rem;margin-bottom:1.25rem;">
    Beide Endpunkte akzeptieren Anfragen von externen Apps (z.B. Apple Health Shortcuts,
    Home Assistant, Scriptable). Authentifizierung per API-Key.
</p>

<!-- ── Authentifizierung ─────────────────────────────────────── -->
<div class="kt-card" style="margin-bottom:1rem;">
    <div style="font-size:.78rem;color:var(--muted);font-weight:600;text-transform:uppercase;
                letter-spacing:.05em;margin-bottom:.75rem;">Authentifizierung</div>
    <p style="font-size:.88rem;margin-bottom:.75rem;">
        Jede externe Anfrage benötigt den API-Key — entweder als
        <code>Authorization: Bearer &lt;key&gt;</code> Header oder als
        <code>api_key</code> Feld im JSON-Body.
    </p>
    <p style="font-size:.78rem;color:var(--muted);margin-bottom:.5rem;">Dein API-Key:</p>
    <div style="background:var(--surface2);border-radius:10px;padding:.6rem .85rem;
                font-family:monospace;font-size:.82rem;word-break:break-all;
                color:var(--accent);"><?= htmlspecialchars($apiKey) ?></div>
    <p style="font-size:.75rem;color:var(--muted);margin-top:.5rem;">
        Deine user_id: <strong><?= $currentUser['id'] ?></strong>
    </p>
</div>

<!-- ── Basis-URL ─────────────────────────────────────────────── -->
<div class="kt-card" style="margin-bottom:1rem;">
    <div style="font-size:.78rem;color:var(--muted);font-weight:600;text-transform:uppercase;
                letter-spacing:.05em;margin-bottom:.5rem;">Basis-URL</div>
    <code style="font-size:.85rem;color:var(--accent);">https://k.eick-hoff.de/api/</code>
</div>

<!-- ── Gewicht API ───────────────────────────────────────────── -->
<div style="font-size:1rem;font-weight:700;margin:1.5rem 0 .75rem;
            display:flex;align-items:center;gap:.5rem;">
    <i class="bi bi-graph-up text-accent"></i> Gewicht
    <code style="font-size:.75rem;background:var(--surface2);padding:.15rem .5rem;
                 border-radius:6px;color:var(--muted);">weight.php</code>
</div>

<?php
$endpoints = [
    [
        'method' => 'GET',
        'path'   => 'weight.php?limit=30',
        'desc'   => 'Letzte N Gewichtseinträge abrufen',
        'params' => [
            'limit' => 'Anzahl Einträge (1–365, Standard: 30)',
            'datum' => 'Alternativ: einzelnen Tag abrufen (YYYY-MM-DD)',
            'user_id' => 'Pflicht bei API-Key-Auth',
        ],
        'example_req' => null,
        'example_res' => '{"ok":true,"eintraege":[{"id":1,"datum":"2026-06-22","kg":"82.50"}]}',
    ],
    [
        'method' => 'POST',
        'path'   => 'weight.php',
        'desc'   => 'Gewicht eintragen oder überschreiben',
        'params' => [],
        'example_req' => '{"kg": 82.5, "datum": "2026-06-22", "user_id": ' . $currentUser['id'] . ', "api_key": "..."}',
        'example_res' => '{"ok":true,"datum":"2026-06-22","kg":82.5}',
    ],
    [
        'method' => 'DELETE',
        'path'   => 'weight.php',
        'desc'   => 'Eintrag löschen',
        'params' => [],
        'example_req' => '{"id": 42, "user_id": ' . $currentUser['id'] . ', "api_key": "..."}',
        'example_res' => '{"ok":true,"affected":1}',
    ],
];
foreach ($endpoints as $ep): ?>
<div class="kt-card" style="margin-bottom:.75rem;">
    <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
        <span style="background:<?= $ep['method']==='GET' ? '#3b82f6' : ($ep['method']==='POST' ? '#22c55e' : '#ef4444') ?>;
                     color:#fff;font-size:.7rem;font-weight:700;padding:.15rem .45rem;
                     border-radius:5px;"><?= $ep['method'] ?></span>
        <code style="font-size:.8rem;color:var(--text);">/api/<?= $ep['path'] ?></code>
    </div>
    <p style="font-size:.85rem;color:var(--muted);margin-bottom:<?= empty($ep['params']) && !$ep['example_req'] ? '0' : '.75rem' ?>;">
        <?= $ep['desc'] ?>
    </p>
    <?php if (!empty($ep['params'])): ?>
    <div style="font-size:.75rem;color:var(--muted);margin-bottom:.5rem;font-weight:600;">Parameter</div>
    <?php foreach ($ep['params'] as $k => $v): ?>
    <div style="display:flex;gap:.5rem;font-size:.78rem;margin-bottom:.2rem;">
        <code style="color:var(--accent);flex-shrink:0;"><?= $k ?></code>
        <span style="color:var(--muted);"><?= $v ?></span>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($ep['example_req']): ?>
    <div style="font-size:.75rem;color:var(--muted);margin:.6rem 0 .25rem;font-weight:600;">Body (JSON)</div>
    <pre style="background:var(--surface2);border-radius:8px;padding:.6rem .75rem;
                font-size:.72rem;overflow-x:auto;margin:0;"><?= htmlspecialchars($ep['example_req']) ?></pre>
    <?php endif; ?>
    <div style="font-size:.75rem;color:var(--muted);margin:.6rem 0 .25rem;font-weight:600;">Antwort</div>
    <pre style="background:var(--surface2);border-radius:8px;padding:.6rem .75rem;
                font-size:.72rem;overflow-x:auto;margin:0;"><?= htmlspecialchars($ep['example_res']) ?></pre>
</div>
<?php endforeach; ?>

<!-- ── Aktivitäten API ───────────────────────────────────────── -->
<div style="font-size:1rem;font-weight:700;margin:1.5rem 0 .75rem;
            display:flex;align-items:center;gap:.5rem;">
    <i class="bi bi-fire text-accent"></i> Aktivitätskalorien
    <code style="font-size:.75rem;background:var(--surface2);padding:.15rem .5rem;
                 border-radius:6px;color:var(--muted);">activity.php</code>
</div>

<?php
$actEndpoints = [
    [
        'method' => 'GET',
        'path'   => 'activity.php?datum=2026-06-22&exclude_workout=1',
        'desc'   => 'Aktivitäten eines Tages abrufen (inkl. Summe)',
        'params' => [
            'datum'           => 'Datum (YYYY-MM-DD, Standard: heute)',
            'exclude_workout' => 'Optional, 1/true: Einträge mit Bezeichnung "Workout" werden weder zurückgegeben noch summiert (Standard: alle Einträge)',
            'user_id'         => 'Pflicht bei API-Key-Auth',
        ],
        'example_req' => null,
        'example_res' => '{"ok":true,"datum":"2026-06-22","exclude_workout":false,"total_kcal":350,"eintraege":[{"id":1,"bezeichnung":"Laufen 5km","kcal":350}]}',
    ],
    [
        'method' => 'POST',
        'path'   => 'activity.php',
        'desc'   => 'Aktivität eintragen',
        'params' => [],
        'example_req' => '{"kcal": 350, "bezeichnung": "Laufen 5km", "datum": "2026-06-22", "user_id": ' . $currentUser['id'] . ', "api_key": "..."}',
        'example_res' => '{"ok":true,"id":1,"datum":"2026-06-22","bezeichnung":"Laufen 5km","kcal":350}',
    ],
    [
        'method' => 'DELETE',
        'path'   => 'activity.php',
        'desc'   => 'Aktivität löschen',
        'params' => [],
        'example_req' => '{"id": 1, "user_id": ' . $currentUser['id'] . ', "api_key": "..."}',
        'example_res' => '{"ok":true,"affected":1}',
    ],
];
foreach ($actEndpoints as $ep): ?>
<div class="kt-card" style="margin-bottom:.75rem;">
    <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
        <span style="background:<?= $ep['method']==='GET' ? '#3b82f6' : ($ep['method']==='POST' ? '#22c55e' : '#ef4444') ?>;
                     color:#fff;font-size:.7rem;font-weight:700;padding:.15rem .45rem;
                     border-radius:5px;"><?= $ep['method'] ?></span>
        <code style="font-size:.8rem;color:var(--text);">/api/<?= $ep['path'] ?></code>
    </div>
    <p style="font-size:.85rem;color:var(--muted);margin-bottom:<?= empty($ep['params']) && !$ep['example_req'] ? '0' : '.75rem' ?>;">
        <?= $ep['desc'] ?>
    </p>
    <?php if (!empty($ep['params'])): ?>
    <div style="font-size:.75rem;color:var(--muted);margin-bottom:.5rem;font-weight:600;">Parameter</div>
    <?php foreach ($ep['params'] as $k => $v): ?>
    <div style="display:flex;gap:.5rem;font-size:.78rem;margin-bottom:.2rem;">
        <code style="color:var(--accent);flex-shrink:0;"><?= $k ?></code>
        <span style="color:var(--muted);"><?= $v ?></span>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($ep['example_req']): ?>
    <div style="font-size:.75rem;color:var(--muted);margin:.6rem 0 .25rem;font-weight:600;">Body (JSON)</div>
    <pre style="background:var(--surface2);border-radius:8px;padding:.6rem .75rem;
                font-size:.72rem;overflow-x:auto;margin:0;"><?= htmlspecialchars($ep['example_req']) ?></pre>
    <?php endif; ?>
    <div style="font-size:.75rem;color:var(--muted);margin:.6rem 0 .25rem;font-weight:600;">Antwort</div>
    <pre style="background:var(--surface2);border-radius:8px;padding:.6rem .75rem;
                font-size:.72rem;overflow-x:auto;margin:0;"><?= htmlspecialchars($ep['example_res']) ?></pre>
</div>
<?php endforeach; ?>

<!-- ── Apple Shortcuts Beispiel ─────────────────────────────── -->
<div style="font-size:1rem;font-weight:700;margin:1.5rem 0 .75rem;
            display:flex;align-items:center;gap:.5rem;">
    <i class="bi bi-apple text-accent"></i> Apple Shortcuts Beispiel
</div>
<div class="kt-card">
    <p style="font-size:.85rem;margin-bottom:.75rem;">
        Shortcut zum Eintragen eines Gewichts aus Apple Health:
    </p>
    <ol style="font-size:.82rem;color:var(--muted);padding-left:1.25rem;margin:0;line-height:2;">
        <li>Aktion: <strong>Gesundheitsmuster</strong> → Körpermasse abrufen (letzter Eintrag)</li>
        <li>Aktion: <strong>URL</strong> → <code>https://k.eick-hoff.de/api/weight.php</code></li>
        <li>Aktion: <strong>Wörterbuch</strong> mit Schlüsseln:
            <code>kg</code> (Messung), <code>user_id</code> (<?= $currentUser['id'] ?>),
            <code>api_key</code> (s.o.)</li>
        <li>Aktion: <strong>Inhalt der URL abrufen</strong> → Methode: POST, Body: Wörterbuch als JSON</li>
    </ol>
</div>

</div>

<?php renderFooter('profil'); ?>
