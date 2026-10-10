// ─────────────────────────────────────────────────────────────────────────────
// Kalorien Schritte – Ziffernblatt für Pebble
//
// Drei Layouts, beim Build gewählt (layout_hero.h / layout_bento.h / layout_pixel.h).
// „Hero-Balken“ (Standard) – die Heute-Seite der KalorienTracker-App im Kleinformat:
//   Uhrzeit, Datum (zweizeilig rechts: Wochentag / Tag + Monat)
//   NOCH ÜBRIG  1.146 kcal               (orange „Über dem Ziel“)
//   [████ gegessen ████░░░░ Rest ░░//Bonus//]
//   1.240 gegessen                Ziel 2.386
//   SCHRITTE │ AKTIV
// „Bento“ – Kacheln: Uhrzeit oben, darunter Noch übrig │ Schritte,
//   Gegessen │ Aktiv.
// „Pixel“ – Stunden und Minuten als LED-Punktraster untereinander, rechts eine
//   Säule mit den Schritten bis zum Tagesziel, unten Aktivkalorien.
//   Variante „Säulen“: zusätzlich eine Säule für „noch übrig“.
//
// Die Tagesschritte gehen stündlich (zur vollen Stunde) sowie um 23:55 ans
// Handy. Der JavaScript-Teil (src/pkjs/index.js) leitet sie an die
// Kalorien-API weiter: POST /api/activity.php {"schritte": …, "datum": …}.
// Die API ersetzt den Schritte-Eintrag des Tages und antwortet mit der
// Tagesbilanz (noch übrig, gegessen, Ziel, Aktivkalorien gesamt) – die
// Kalorienwerte sind also so aktuell wie der letzte Sync.
//
// Schlägt ein Sync fehl (Handy nicht verbunden, Netz weg), wird alle
// 5 Minuten erneut versucht – bzw. sofort, sobald Bluetooth wieder verbunden
// ist. Bei API-Fehlern (z.B. falscher Key) nicht – dann erst wieder zur
// nächsten vollen Stunde.
//
// Warnsymbole (nur im Warnfall sichtbar): Bluetooth zum Handy getrennt,
// Akku der Uhr unter 15 % (nicht beim Laden).
// ─────────────────────────────────────────────────────────────────────────────
#include <pebble.h>

// ── Farben (Limette ≈ GColorInchworm aus der 64-Farben-Palette) ─────────────
#define COL_BG      GColorBlack
#define COL_TEXT    GColorWhite
#define COL_ACCENT  PBL_IF_COLOR_ELSE(GColorInchworm, GColorWhite)
#define COL_MUTED   PBL_IF_COLOR_ELSE(GColorLightGray, GColorWhite)
#define COL_LINE    PBL_IF_COLOR_ELSE(GColorDarkGray, GColorWhite)
#define COL_TRACK   PBL_IF_COLOR_ELSE(GColorDarkGray, GColorBlack)
#define COL_DANGER  PBL_IF_COLOR_ELSE(GColorSunsetOrange, GColorWhite)

// ── Persistenter Sync-Status ────────────────────────────────────────────────
#define PERSIST_LAST_SYNC  1   // time_t des letzten erfolgreichen Syncs
#define PERSIST_LAST_KCAL  2   // kcal der Schritte laut API
#define PERSIST_REST       3   // noch übrig (kcal) laut API
#define PERSIST_AKTIV      4   // Aktivkalorien des Tages gesamt laut API
#define PERSIST_EATEN      5   // gegessen (kcal) laut API
#define PERSIST_GOAL       6   // Tagesziel inkl. Aktivkalorien laut API
#define PERSIST_STEP_GOAL  7   // Schrittziel aus den Einstellungen des Ziffernblatts

typedef enum { SYNC_NONE, SYNC_SENDING, SYNC_OK, SYNC_ERROR } SyncState;

static Window    *s_window;
static Layer     *s_canvas;

static char       s_time_buf[8];
static char       s_wday_buf[4];
static char       s_date_buf[12];

static int        s_steps      = -1;

static SyncState  s_state      = SYNC_NONE;
static bool       s_pending    = false;   // Sync steht aus → alle 5 min erneut
static time_t     s_last_sync  = 0;
static int        s_last_kcal  = 0;
static bool       s_have_bilanz = false;  // Tagesbilanz vom Server erhalten
static int        s_rest       = 0;
static int        s_aktiv      = 0;
static int        s_eaten      = 0;
static int        s_goal       = 0;
static int        s_step_goal  = 10000;   // Standard, bis die Einstellungen es schicken
static char       s_error[24]  = "";

static bool               s_bt_connected = true;
static BatteryChargeState s_battery;

#define AKKU_WARN_PROZENT 15   // Pebble meldet in 10-%-Schritten → bei 10 % und 0 %

static const char *WOCHENTAGE[] = { "So", "Mo", "Di", "Mi", "Do", "Fr", "Sa" };
static const char *MONATE[]     = { "Jan", "Feb", "Mär", "Apr", "Mai", "Jun",
                                    "Jul", "Aug", "Sep", "Okt", "Nov", "Dez" };

// ── Hilfen ──────────────────────────────────────────────────────────────────

static int steps_today(void) {
  #if defined(PBL_HEALTH)
    HealthServiceAccessibilityMask mask = health_service_metric_accessible(
        HealthMetricStepCount, time_start_of_today(), time(NULL));
    if (mask & HealthServiceAccessibilityMaskAvailable) {
      return (int)health_service_sum_today(HealthMetricStepCount);
    }
  #endif
  return -1;   // Health nicht verfügbar / nicht erlaubt
}

// Zahl mit Tausenderpunkt: 8432 → „8.432“, -1 → „-“
static void format_num(int n, char *buf, size_t len) {
  if (n < 0)          snprintf(buf, len, "-");
  else if (n >= 1000) snprintf(buf, len, "%d.%03d", n / 1000, n % 1000);
  else                snprintf(buf, len, "%d", n);
}

// Stammt der letzte Sync von heute? (sonst gilt die Tagesbilanz nicht mehr)
static bool synced_today(void) {
  if (s_last_sync <= 0) return false;
  time_t now = time(NULL);
  struct tm last = *localtime(&s_last_sync);
  struct tm *t = localtime(&now);
  return last.tm_yday == t->tm_yday && last.tm_year == t->tm_year;
}

static void update_time(void) {
  time_t now = time(NULL);
  struct tm *t = localtime(&now);
  strftime(s_time_buf, sizeof(s_time_buf), clock_is_24h_style() ? "%H:%M" : "%I:%M", t);
  snprintf(s_wday_buf, sizeof(s_wday_buf), "%s", WOCHENTAGE[t->tm_wday]);
  snprintf(s_date_buf, sizeof(s_date_buf), "%d. %s", t->tm_mday, MONATE[t->tm_mon]);
}

static void update_health(void) {
  s_steps = steps_today();
}

// ── Sync ────────────────────────────────────────────────────────────────────

static void sync_now(void) {
  int steps = steps_today();
  if (steps < 0) {
    s_state = SYNC_ERROR;
    snprintf(s_error, sizeof(s_error), "Health nicht aktiv");
    s_pending = false;
    layer_mark_dirty(s_canvas);
    return;
  }

  char date[12];
  time_t now = time(NULL);
  strftime(date, sizeof(date), "%Y-%m-%d", localtime(&now));

  DictionaryIterator *it;
  if (app_message_outbox_begin(&it) != APP_MSG_OK) {
    s_pending = true;           // z.B. noch eine Nachricht unterwegs → später
    return;
  }
  dict_write_int32(it, MESSAGE_KEY_STEPS, steps);
  dict_write_cstring(it, MESSAGE_KEY_DATE, date);
  s_pending = true;             // bis die Antwort der API da ist
  s_state = SYNC_SENDING;
  layer_mark_dirty(s_canvas);
  app_message_outbox_send();
}

static void inbox_received(DictionaryIterator *it, void *ctx) {
  Tuple *req   = dict_find(it, MESSAGE_KEY_REQUEST_SYNC);
  Tuple *kcal  = dict_find(it, MESSAGE_KEY_RESULT_KCAL);
  Tuple *rest  = dict_find(it, MESSAGE_KEY_RESULT_REST);
  Tuple *aktiv = dict_find(it, MESSAGE_KEY_RESULT_AKTIV);
  Tuple *eaten = dict_find(it, MESSAGE_KEY_RESULT_EATEN);
  Tuple *goal  = dict_find(it, MESSAGE_KEY_RESULT_GOAL);
  Tuple *sgoal = dict_find(it, MESSAGE_KEY_STEP_GOAL);
  Tuple *err   = dict_find(it, MESSAGE_KEY_RESULT_ERR);
  Tuple *rtry  = dict_find(it, MESSAGE_KEY_RESULT_RETRY);

  if (sgoal && sgoal->value->int32 > 0) {   // Schrittziel aus den Einstellungen
    s_step_goal = (int)sgoal->value->int32;
    persist_write_int(PERSIST_STEP_GOAL, s_step_goal);
    layer_mark_dirty(s_canvas);
  }
  if (req) {                    // JS ist bereit oder Einstellungen gespeichert
    sync_now();
    return;
  }
  if (kcal) {
    s_state     = SYNC_OK;
    s_pending   = false;
    s_last_sync = time(NULL);
    s_last_kcal = (int)kcal->value->int32;
    s_error[0]  = '\0';
    persist_write_int(PERSIST_LAST_SYNC, (int32_t)s_last_sync);
    persist_write_int(PERSIST_LAST_KCAL, s_last_kcal);
    s_have_bilanz = rest && aktiv && eaten && goal;
    if (s_have_bilanz) {
      s_rest  = (int)rest->value->int32;
      s_aktiv = (int)aktiv->value->int32;
      s_eaten = (int)eaten->value->int32;
      s_goal  = (int)goal->value->int32;
      persist_write_int(PERSIST_REST, s_rest);
      persist_write_int(PERSIST_AKTIV, s_aktiv);
      persist_write_int(PERSIST_EATEN, s_eaten);
      persist_write_int(PERSIST_GOAL, s_goal);
    } else {                    // ältere API ohne Tagesbilanz
      persist_delete(PERSIST_REST);
    }
  } else if (err) {
    s_state   = SYNC_ERROR;
    s_pending = rtry && rtry->value->int32 == 1;   // nur Netzfehler wiederholen
    snprintf(s_error, sizeof(s_error), "%s", err->value->cstring);
  }
  layer_mark_dirty(s_canvas);
}

static void outbox_failed(DictionaryIterator *it, AppMessageResult reason, void *ctx) {
  // Handy nicht erreichbar → in 5 Minuten erneut
  s_state   = SYNC_ERROR;
  s_pending = true;
  snprintf(s_error, sizeof(s_error), "Handy nicht verbunden");
  layer_mark_dirty(s_canvas);
}

// ── Zeit & Health-Ereignisse ────────────────────────────────────────────────

static void tick_handler(struct tm *t, TimeUnits changed) {
  update_time();
  update_health();
  bool full_hour  = t->tm_min == 0;
  bool day_close  = t->tm_hour == 23 && t->tm_min == 55;
  bool retry_slot = s_pending && t->tm_min % 5 == 0;
  if (full_hour || day_close || retry_slot) sync_now();
  layer_mark_dirty(s_canvas);
}

#if defined(PBL_HEALTH)
static void health_handler(HealthEventType event, void *ctx) {
  if (event == HealthEventMovementUpdate || event == HealthEventSignificantUpdate) {
    update_health();
    layer_mark_dirty(s_canvas);
  }
}
#endif

static void bt_handler(bool connected) {
  s_bt_connected = connected;
  // Wieder verbunden und ein Sync steht aus → nicht auf den 5-Minuten-Takt warten
  if (connected && s_pending) sync_now();
  layer_mark_dirty(s_canvas);
}

static void battery_handler(BatteryChargeState state) {
  s_battery = state;
  layer_mark_dirty(s_canvas);
}

static bool battery_low(void) {
  return s_battery.charge_percent < AKKU_WARN_PROZENT && !s_battery.is_charging;
}

// ── Zeichnen: Grundbausteine ────────────────────────────────────────────────

static void draw_text(GContext *ctx, const char *text, GFont font, GRect box,
                      GColor color, GTextAlignment align) {
  graphics_context_set_text_color(ctx, color);
  graphics_draw_text(ctx, text, font, box, GTextOverflowModeTrailingEllipsis, align, NULL);
}

static inline int text_width(const char *text, GFont font) {
  return graphics_text_layout_get_content_size(text, font, GRect(0, 0, 300, 100),
             GTextOverflowModeTrailingEllipsis, GTextAlignmentLeft).w;
}

// Warnsymbole als Vektor-Zeichnung (keine Bitmaps → auf allen Plattformen scharf).
// Jeweils links oben an Punkt o, Höhe ICON_H; Rückgabe = Breite.
#define ICON_H(big) ((big) ? 16 : 12)

// Bluetooth-Rune mit Schrägstrich
static int draw_bt_off_icon(GContext *ctx, GPoint o, bool big) {
  int h = ICON_H(big), w = big ? 10 : 8, cx = o.x + w / 2;
  GPoint top = GPoint(cx, o.y), bot = GPoint(cx, o.y + h);
  GPoint ru  = GPoint(o.x + w, o.y + h / 4), rl = GPoint(o.x + w, o.y + 3 * h / 4);
  GPoint lu  = GPoint(o.x, o.y + h / 4),     ll = GPoint(o.x, o.y + 3 * h / 4);
  graphics_context_set_stroke_color(ctx, COL_DANGER);
  graphics_context_set_stroke_width(ctx, big ? 2 : 1);
  graphics_draw_line(ctx, top, bot);
  graphics_draw_line(ctx, lu, rl);
  graphics_draw_line(ctx, rl, bot);
  graphics_draw_line(ctx, ll, ru);
  graphics_draw_line(ctx, ru, top);
  // Schrägstrich über das ganze Symbol
  graphics_draw_line(ctx, GPoint(o.x - 2, o.y + 1), GPoint(o.x + w + 2, o.y + h - 1));
  graphics_context_set_stroke_width(ctx, 1);
  return w;
}

// Batterie-Umriss mit Pol und Füllstand
static int draw_battery_low_icon(GContext *ctx, GPoint o, bool big) {
  int h = big ? 10 : 8, w = big ? 18 : 14;
  int y = o.y + (ICON_H(big) - h) / 2;
  graphics_context_set_stroke_color(ctx, COL_DANGER);
  graphics_context_set_fill_color(ctx, COL_DANGER);
  graphics_draw_rect(ctx, GRect(o.x, y, w, h));
  graphics_fill_rect(ctx, GRect(o.x + w, y + h / 4, 2, h / 2), 0, GCornerNone);   // Pol
  int inner = w - 4;
  int fill  = inner * s_battery.charge_percent / 100;
  graphics_fill_rect(ctx, GRect(o.x + 2, y + 2, fill < 2 ? 2 : fill, h - 4), 0, GCornerNone);
  return w + 2;
}

// Breite aller aktiven Warnsymbole (für die Ausrichtung)
static int warn_icons_width(bool big) {
  int gap = 6, wsum = 0;
  if (!s_bt_connected) wsum += (big ? 10 : 8);
  if (battery_low())   wsum += (wsum ? gap : 0) + (big ? 20 : 16);
  return wsum;
}

// Zeichnet die aktiven Symbole ab x nebeneinander
static void draw_warn_icons(GContext *ctx, int x, int y, bool big) {
  if (!s_bt_connected) x += draw_bt_off_icon(ctx, GPoint(x, y), big) + 6;
  if (battery_low())   draw_battery_low_icon(ctx, GPoint(x, y), big);
}
// ── Layout (beim Build gewählt, siehe wscript: LAYOUT=hero|bento|pixel|saeulen)
//
// Jede Layout-Datei liefert canvas_update(), layout_init() und layout_deinit()
// und nutzt Zustand und Zeichenhilfen von oben.
#if defined(LAYOUT_PIXEL) || defined(LAYOUT_SAEULEN)
  #include "layout_pixel.h"
#elif defined(LAYOUT_BENTO)
  #include "layout_bento.h"
#else
  #include "layout_hero.h"
#endif

// ── Lebenszyklus ────────────────────────────────────────────────────────────

static void window_load(Window *window) {
  Layer *root = window_get_root_layer(window);
  s_canvas = layer_create(layer_get_bounds(root));
  layer_set_update_proc(s_canvas, canvas_update);
  layer_add_child(root, s_canvas);
}

static void window_unload(Window *window) {
  layer_destroy(s_canvas);
}

static void init(void) {
  if (persist_exists(PERSIST_LAST_SYNC)) {
    s_last_sync = (time_t)persist_read_int(PERSIST_LAST_SYNC);
    s_last_kcal = persist_read_int(PERSIST_LAST_KCAL);
    s_state     = SYNC_OK;
  }
  if (persist_exists(PERSIST_REST) && persist_exists(PERSIST_AKTIV) &&
      persist_exists(PERSIST_EATEN) && persist_exists(PERSIST_GOAL)) {
    s_rest  = persist_read_int(PERSIST_REST);
    s_aktiv = persist_read_int(PERSIST_AKTIV);
    s_eaten = persist_read_int(PERSIST_EATEN);
    s_goal  = persist_read_int(PERSIST_GOAL);
    s_have_bilanz = true;
  }
  if (persist_exists(PERSIST_STEP_GOAL)) s_step_goal = persist_read_int(PERSIST_STEP_GOAL);

  s_window = window_create();
  layout_init();

  window_set_background_color(s_window, COL_BG);
  window_set_window_handlers(s_window, (WindowHandlers) {
    .load = window_load, .unload = window_unload,
  });

  s_bt_connected = connection_service_peek_pebble_app_connection();
  s_battery      = battery_state_service_peek();

  update_time();
  update_health();
  window_stack_push(s_window, false);

  tick_timer_service_subscribe(MINUTE_UNIT, tick_handler);
  #if defined(PBL_HEALTH)
    health_service_events_subscribe(health_handler, NULL);
  #endif
  connection_service_subscribe((ConnectionHandlers) {
    .pebble_app_connection_handler = bt_handler,
  });
  battery_state_service_subscribe(battery_handler);

  app_message_register_inbox_received(inbox_received);
  app_message_register_outbox_failed(outbox_failed);
  app_message_open(128, 128);
  // Erster Sync, sobald das Handy „bereit“ meldet (REQUEST_SYNC aus JS)
}

static void deinit(void) {
  tick_timer_service_unsubscribe();
  #if defined(PBL_HEALTH)
    health_service_events_unsubscribe();
  #endif
  connection_service_unsubscribe();
  battery_state_service_unsubscribe();
  layout_deinit();
  window_destroy(s_window);
}

int main(void) {
  init();
  app_event_loop();
  deinit();
}
