// ─────────────────────────────────────────────────────────────────────────────
// Kalorien Schritte – Handy-Teil (PebbleKit JS), gemeinsam für das
// Ziffernblatt (watchface/) und die Quick-Launch-App (sync-app/).
//
// Empfängt {STEPS, DATE} von der Uhr und schickt sie an die API:
//   POST <API-Adresse>  {"schritte": STEPS, "datum": DATE, "api_key": …}
// Antwort an die Uhr: RESULT_KCAL (Erfolg) oder RESULT_ERR + RESULT_RETRY
// (1 = Netzfehler, Uhr versucht es in 5 Minuten erneut).
//
// API-Adresse und Key bleiben auf dem Handy (localStorage) – sie werden
// nicht an die Uhr übertragen. Jede Pebble-App hat ihren eigenen Speicher,
// der Key wird also je App einmal eingetragen.
// ─────────────────────────────────────────────────────────────────────────────
var STORE_KEY = 'kalorien-schritte-settings';
var DEFAULT_URL = 'https://k.eick-hoff.de/api/activity.php';

function loadSettings() {
  try { return JSON.parse(localStorage.getItem(STORE_KEY)) || {}; }
  catch (e) { return {}; }
}

function saveSettings(s) {
  localStorage.setItem(STORE_KEY, JSON.stringify(s));
}

function sendToWatch(msg) {
  Pebble.sendAppMessage(msg, function () {}, function (e) {
    console.log('Nachricht an Uhr fehlgeschlagen: ' + JSON.stringify(e));
  });
}

function reportError(text, retry) {
  console.log('Sync-Fehler: ' + text);
  sendToWatch({ RESULT_ERR: text.substring(0, 22), RESULT_RETRY: retry ? 1 : 0 });
}

function syncSteps(steps, datum) {
  var s = loadSettings();
  var url = s.apiUrl || DEFAULT_URL;
  if (!s.apiKey) {
    reportError('API-Key fehlt', false);
    return;
  }

  var xhr = new XMLHttpRequest();
  xhr.open('POST', url, true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.setRequestHeader('X-Api-Key', s.apiKey);
  xhr.timeout = 20000;
  xhr.onload = function () {
    var res = null;
    try { res = JSON.parse(xhr.responseText); } catch (e) { /* keine JSON-Antwort */ }
    if (xhr.status === 200 && res && res.ok) {
      console.log('Gesendet: ' + steps + ' Schritte → ' + res.kcal + ' kcal');
      sendToWatch({ RESULT_KCAL: res.kcal | 0 });
    } else if (xhr.status === 401) {
      reportError('API-Key ungültig', false);
    } else if (xhr.status >= 500 || xhr.status === 0) {
      reportError('Server-Fehler ' + xhr.status, true);
    } else {
      reportError((res && res.error) || ('Fehler ' + xhr.status), false);
    }
  };
  xhr.onerror   = function () { reportError('Kein Netz', true); };
  xhr.ontimeout = function () { reportError('Zeitüberschreitung', true); };
  // api_key zusätzlich im Body – funktioniert auch, wenn ein Proxy den
  // Header nicht durchreicht
  xhr.send(JSON.stringify({ schritte: steps, datum: datum, api_key: s.apiKey }));
}

// JS bereit → Uhr soll sofort einen Sync anstoßen
Pebble.addEventListener('ready', function () {
  sendToWatch({ REQUEST_SYNC: 1 });
});

Pebble.addEventListener('appmessage', function (e) {
  var p = e.payload;
  if (typeof p.STEPS !== 'undefined' && p.DATE) {
    syncSteps(p.STEPS, p.DATE);
  }
});

// ── Einstellungsseite ────────────────────────────────────────────────────────
// Eigene kleine HTML-Seite als data:-URL (kein Clay – das unterstützt die
// neuen Plattformen nicht). Speichern schließt die Seite über
// pebblejs://close#<JSON>, das Ergebnis kommt in 'webviewclosed' an.
function esc(v) {
  return String(v || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

function configPage(s) {
  return '<!doctype html><html lang="de"><head><meta charset="utf-8">' +
    '<meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<title>Kalorien Schritte</title><style>' +
    'body{margin:0;background:#09090b;color:#f4f4f5;font:16px -apple-system,system-ui,sans-serif;padding:24px 18px}' +
    'h1{font-size:28px;margin:0 0 6px;letter-spacing:-.02em}h1 span{color:#d4f53c}' +
    'p{color:#a1a1aa;font-size:14px;line-height:1.45;margin:0 0 22px}' +
    'label{display:block;font-size:13px;color:#a1a1aa;margin:16px 0 6px}' +
    'input{width:100%;box-sizing:border-box;background:#1e1e23;border:1px solid #26262c;border-radius:14px;' +
    'color:#f4f4f5;font-size:16px;padding:13px 14px}input:focus{outline:none;border-color:#d4f53c}' +
    'small{display:block;color:#71717a;font-size:12px;margin-top:6px}' +
    'button{width:100%;margin-top:28px;background:#d4f53c;color:#1a2000;border:none;border-radius:16px;' +
    'font-size:17px;font-weight:700;padding:15px}' +
    '</style></head><body>' +
    '<h1>Kalorien <span>Schritte</span></h1>' +
    '<p>Schickt deine Tagesschritte an die KalorienTracker-API – das Ziffernblatt stündlich ' +
    'und um 23:55, die Sync-App sofort beim Öffnen. Die API rechnet daraus Aktivitätskalorien ' +
    'und ersetzt den Schritte-Eintrag des Tages.</p>' +
    '<label for="u">API-Adresse</label>' +
    '<input id="u" type="url" autocapitalize="off" autocorrect="off" value="' + esc(s.apiUrl || DEFAULT_URL) + '">' +
    '<label for="k">API-Key</label>' +
    '<input id="k" type="text" autocapitalize="off" autocorrect="off" spellcheck="false" placeholder="64 Zeichen" value="' + esc(s.apiKey) + '">' +
    '<small>Zu finden in der App unter Profil → Einstellungen → API-Dokumentation.</small>' +
    '<button id="b">Speichern &amp; jetzt senden</button>' +
    '<script>document.getElementById("b").onclick=function(){' +
    'var r={apiUrl:document.getElementById("u").value.trim(),apiKey:document.getElementById("k").value.trim()};' +
    'location.href="pebblejs://close#"+encodeURIComponent(JSON.stringify(r));};</script>' +
    '</body></html>';
}

Pebble.addEventListener('showConfiguration', function () {
  Pebble.openURL('data:text/html;charset=utf-8,' + encodeURIComponent(configPage(loadSettings())));
});

Pebble.addEventListener('webviewclosed', function (e) {
  if (!e || !e.response) return;          // abgebrochen
  var r;
  try { r = JSON.parse(decodeURIComponent(e.response)); } catch (err) { return; }
  saveSettings({ apiUrl: (r.apiUrl || '').trim() || DEFAULT_URL, apiKey: (r.apiKey || '').trim() });
  sendToWatch({ REQUEST_SYNC: 1 });
});
