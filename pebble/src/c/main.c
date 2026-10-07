// ─────────────────────────────────────────────────────────────────────────────
// Kalorien Schritte – Ziffernblatt für Pebble
//
// Zeigt Uhrzeit, Datum und die Schritte des Tages und schickt die
// Tagesschritte stündlich (zur vollen Stunde) sowie um 23:55 an das Handy.
// Der JavaScript-Teil (src/pkjs/index.js) leitet sie an die Kalorien-API
// weiter: POST /api/activity.php {"schritte": …, "datum": …}. Die API
// ersetzt den Schritte-Eintrag des Tages – mehrfaches Senden ist unbedenklich.
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
#define COL_DANGER  PBL_IF_COLOR_ELSE(GColorSunsetOrange, GColorWhite)

// ── Persistenter Sync-Status ────────────────────────────────────────────────
#define PERSIST_LAST_SYNC  1   // time_t des letzten erfolgreichen Syncs
#define PERSIST_LAST_KCAL  2   // kcal laut API beim letzten Sync

typedef enum { SYNC_NONE, SYNC_SENDING, SYNC_OK, SYNC_ERROR } SyncState;

static Window    *s_window;
static Layer     *s_canvas;
static GFont      s_font_time;
static GFont      s_font_steps;

static char       s_time_buf[8];
static char       s_date_buf[24];
static char       s_steps_buf[16];
static char       s_status_buf[40];

static SyncState  s_state      = SYNC_NONE;
static bool       s_pending    = false;   // Sync steht aus → alle 5 min erneut
static time_t     s_last_sync  = 0;
static int        s_last_kcal  = 0;
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

// Schritte mit Tausenderpunkt: 8432 → „8.432“
static void format_steps(int steps, char *buf, size_t len) {
  if (steps < 0)        snprintf(buf, len, "-");
  else if (steps >= 1000) snprintf(buf, len, "%d.%03d", steps / 1000, steps % 1000);
  else                  snprintf(buf, len, "%d", steps);
}

static void update_status_text(void) {
  switch (s_state) {
    case SYNC_SENDING:
      snprintf(s_status_buf, sizeof(s_status_buf), "Sende...");
      break;
    case SYNC_ERROR:
      snprintf(s_status_buf, sizeof(s_status_buf), "%s", s_error[0] ? s_error : "Sync fehlgeschlagen");
      break;
    default:
      if (s_last_sync > 0) {
        struct tm *t = localtime(&s_last_sync);
        snprintf(s_status_buf, sizeof(s_status_buf), "%02d:%02d · %d kcal", t->tm_hour, t->tm_min, s_last_kcal);
      } else {
        snprintf(s_status_buf, sizeof(s_status_buf), "Noch nicht gesendet");
      }
  }
}

static void update_time(void) {
  time_t now = time(NULL);
  struct tm *t = localtime(&now);
  strftime(s_time_buf, sizeof(s_time_buf), clock_is_24h_style() ? "%H:%M" : "%I:%M", t);
  snprintf(s_date_buf, sizeof(s_date_buf), "%s, %d. %s",
           WOCHENTAGE[t->tm_wday], t->tm_mday, MONATE[t->tm_mon]);
}

static void update_steps(void) {
  format_steps(steps_today(), s_steps_buf, sizeof(s_steps_buf));
}

// ── Sync ────────────────────────────────────────────────────────────────────

static void sync_now(void) {
  int steps = steps_today();
  if (steps < 0) {
    s_state = SYNC_ERROR;
    snprintf(s_error, sizeof(s_error), "Health nicht aktiv");
    s_pending = false;
    update_status_text();
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
  update_status_text();
  layer_mark_dirty(s_canvas);
  app_message_outbox_send();
}

static void inbox_received(DictionaryIterator *it, void *ctx) {
  Tuple *req  = dict_find(it, MESSAGE_KEY_REQUEST_SYNC);
  Tuple *kcal = dict_find(it, MESSAGE_KEY_RESULT_KCAL);
  Tuple *err  = dict_find(it, MESSAGE_KEY_RESULT_ERR);
  Tuple *rtry = dict_find(it, MESSAGE_KEY_RESULT_RETRY);

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
  } else if (err) {
    s_state   = SYNC_ERROR;
    s_pending = rtry && rtry->value->int32 == 1;   // nur Netzfehler wiederholen
    snprintf(s_error, sizeof(s_error), "%s", err->value->cstring);
  }
  update_status_text();
  layer_mark_dirty(s_canvas);
}

static void outbox_failed(DictionaryIterator *it, AppMessageResult reason, void *ctx) {
  // Handy nicht erreichbar → in 5 Minuten erneut
  s_state   = SYNC_ERROR;
  s_pending = true;
  snprintf(s_error, sizeof(s_error), "Handy nicht verbunden");
  update_status_text();
  layer_mark_dirty(s_canvas);
}

// ── Zeit & Health-Ereignisse ────────────────────────────────────────────────

static void tick_handler(struct tm *t, TimeUnits changed) {
  update_time();
  update_steps();
  bool full_hour  = t->tm_min == 0;
  bool day_close  = t->tm_hour == 23 && t->tm_min == 55;
  bool retry_slot = s_pending && t->tm_min % 5 == 0;
  if (full_hour || day_close || retry_slot) sync_now();
  layer_mark_dirty(s_canvas);
}

#if defined(PBL_HEALTH)
static void health_handler(HealthEventType event, void *ctx) {
  if (event == HealthEventMovementUpdate || event == HealthEventSignificantUpdate) {
    update_steps();
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

// ── Zeichnen ────────────────────────────────────────────────────────────────

static void draw_text(GContext *ctx, const char *text, GFont font, GRect box,
                      GColor color, GTextAlignment align) {
  graphics_context_set_text_color(ctx, color);
  graphics_draw_text(ctx, text, font, box, GTextOverflowModeTrailingEllipsis, align, NULL);
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

static void canvas_update(Layer *layer, GContext *ctx) {
  GRect b  = layer_get_bounds(layer);
  int   w  = b.size.w;
  int   h  = b.size.h;
  bool  big = w >= 200;
  int   inset = PBL_IF_ROUND_ELSE(w / 8, big ? 14 : 10);
  GTextAlignment align = PBL_IF_ROUND_ELSE(GTextAlignmentCenter, GTextAlignmentLeft);

  graphics_context_set_fill_color(ctx, COL_BG);
  graphics_fill_rect(ctx, b, 0, GCornerNone);

  GFont f_small = fonts_get_system_font(big ? FONT_KEY_GOTHIC_18_BOLD : FONT_KEY_GOTHIC_14_BOLD);
  GFont f_steps = s_font_steps;
  GFont f_label = fonts_get_system_font(big ? FONT_KEY_GOTHIC_18 : FONT_KEY_GOTHIC_14);

  int line_small = big ? 22 : 17;
  int time_h     = big ? 64 : 50;
  int steps_h    = big ? 36 : 30;

  int  icons_w = warn_icons_width(big);
  int  icon_h  = ICON_H(big);
  // Rund: eigene Zeile über dem Datum, nur wenn ein Symbol aktiv ist
  int  icon_row = PBL_IF_ROUND_ELSE(icons_w ? icon_h + 6 : 0, 0);

  // Block vertikal mittig: (Symbole), Datum, Uhrzeit, Schritte, Balken, Status
  int block_h = icon_row + line_small + time_h + 10 + line_small + steps_h + 12 + line_small;
  int y = (h - block_h) / 2 - (big ? 4 : 2);
  GRect col = GRect(inset, 0, w - 2 * inset, 0);

  #if defined(PBL_ROUND)
    if (icons_w) {
      draw_warn_icons(ctx, (w - icons_w) / 2, y, big);
      y += icon_row;
    }
  #endif

  // Datum (eckig: Warnsymbole rechtsbündig in derselben Zeile)
  draw_text(ctx, s_date_buf, f_small, GRect(col.origin.x, y, col.size.w - (icons_w ? icons_w + 6 : 0), line_small + 4), COL_MUTED, align);
  #if !defined(PBL_ROUND)
    if (icons_w) draw_warn_icons(ctx, col.origin.x + col.size.w - icons_w, y + (line_small - icon_h) / 2 + 2, big);
  #endif
  y += line_small;

  // Uhrzeit (Space Grotesk Bold)
  draw_text(ctx, s_time_buf, s_font_time, GRect(col.origin.x - 2, y - (big ? 8 : 6), col.size.w + 4, time_h + 10), COL_TEXT, align);
  y += time_h + 10;

  // „SCHRITTE HEUTE“
  draw_text(ctx, "SCHRITTE HEUTE", f_small, GRect(col.origin.x, y, col.size.w, line_small + 4), COL_MUTED, align);
  y += line_small;

  // Schrittzahl in Limette
  draw_text(ctx, s_steps_buf, f_steps, GRect(col.origin.x, y - 4, col.size.w, steps_h + 6), COL_ACCENT, align);
  y += steps_h;

  // Trennlinie
  graphics_context_set_fill_color(ctx, COL_MUTED);
  int line_w = PBL_IF_ROUND_ELSE(col.size.w / 2, col.size.w);
  graphics_fill_rect(ctx, GRect(PBL_IF_ROUND_ELSE((w - line_w) / 2, col.origin.x), y + 4, line_w, 1), 0, GCornerNone);
  y += 12;

  // Sync-Status: Punkt + Text
  GColor dot = s_state == SYNC_OK ? COL_ACCENT : s_state == SYNC_ERROR ? COL_DANGER : COL_MUTED;
  #if defined(PBL_ROUND)
    GSize ts = graphics_text_layout_get_content_size(s_status_buf, f_label,
                   GRect(0, 0, col.size.w - 14, line_small + 4), GTextOverflowModeTrailingEllipsis, GTextAlignmentLeft);
    int text_x = (w - ts.w + 12) / 2;   // Punkt + Text mittig
  #else
    int text_x = col.origin.x + 12;
  #endif
  graphics_context_set_fill_color(ctx, dot);
  graphics_fill_circle(ctx, GPoint(text_x - 8, y + (big ? 12 : 10)), 3);
  draw_text(ctx, s_status_buf, f_label, GRect(text_x, y, col.size.w - 12, line_small + 4),
            s_state == SYNC_ERROR ? COL_DANGER : COL_MUTED, GTextAlignmentLeft);
}

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

  s_window = window_create();
  // Große Schriften nur auf großen Displays (emery = Pebble Time 2, gabbro) –
  // auf den anderen Plattformen überschreiten sie das Glyph-Größenlimit.
  #if PBL_DISPLAY_WIDTH >= 200
    s_font_time  = fonts_load_custom_font(resource_get_handle(RESOURCE_ID_FONT_TIME_56));
    s_font_steps = fonts_load_custom_font(resource_get_handle(RESOURCE_ID_FONT_NUM_32));
  #else
    s_font_time  = fonts_load_custom_font(resource_get_handle(RESOURCE_ID_FONT_TIME_44));
    s_font_steps = fonts_load_custom_font(resource_get_handle(RESOURCE_ID_FONT_NUM_26));
  #endif

  window_set_background_color(s_window, COL_BG);
  window_set_window_handlers(s_window, (WindowHandlers) {
    .load = window_load, .unload = window_unload,
  });

  s_bt_connected = connection_service_peek_pebble_app_connection();
  s_battery      = battery_state_service_peek();

  update_time();
  update_steps();
  update_status_text();
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
  fonts_unload_custom_font(s_font_time);
  fonts_unload_custom_font(s_font_steps);
  window_destroy(s_window);
}

int main(void) {
  init();
  app_event_loop();
  deinit();
}
