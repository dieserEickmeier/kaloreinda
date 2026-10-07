// ─────────────────────────────────────────────────────────────────────────────
// Kalorien Sync – Quick-Launch-App für Pebble
//
// Gedacht für Quick Launch (Uhr: Einstellungen → Quick Launch → Oben/Unten
// halten → „Kalorien Sync“): Ein langer Tastendruck auf dem Ziffernblatt
// öffnet die App, sie schickt sofort die Tagesschritte an die Kalorien-API,
// zeigt kurz das Ergebnis und schließt sich wieder.
//
// Protokoll und Handy-Teil sind dieselben wie beim Ziffernblatt
// (shared/pkjs/index.js): JS meldet sich mit REQUEST_SYNC, die Uhr schickt
// {STEPS, DATE}, das Handy antwortet mit RESULT_KCAL oder RESULT_ERR.
//
// Tasten: Mitte = erneut senden, Zurück = schließen.
// ─────────────────────────────────────────────────────────────────────────────
#include <pebble.h>

#define COL_BG      GColorBlack
#define COL_TEXT    GColorWhite
#define COL_ACCENT  PBL_IF_COLOR_ELSE(GColorInchworm, GColorWhite)
#define COL_MUTED   PBL_IF_COLOR_ELSE(GColorLightGray, GColorWhite)
#define COL_DANGER  PBL_IF_COLOR_ELSE(GColorSunsetOrange, GColorWhite)

#define EXIT_OK_MS       3000    // nach Erfolg automatisch schließen
#define EXIT_ERROR_MS    8000    // nach Fehler etwas länger stehen lassen
#define READY_TIMEOUT_MS 8000    // so lange auf den Handy-Teil warten
#define REPLY_TIMEOUT_MS 25000   // so lange auf die Antwort der API warten

typedef enum { ST_WAITING, ST_SENDING, ST_OK, ST_ERROR } State;

static Window   *s_window;
static Layer    *s_canvas;
static GFont     s_font_steps;
static AppTimer *s_timer = NULL;

static State     s_state = ST_WAITING;
static bool      s_js_ready = false;
static int       s_steps = -1;
static char      s_steps_buf[16];
static char      s_status_buf[40] = "Verbinde...";

// ── Hilfen ──────────────────────────────────────────────────────────────────

static int steps_today(void) {
  #if defined(PBL_HEALTH)
    HealthServiceAccessibilityMask mask = health_service_metric_accessible(
        HealthMetricStepCount, time_start_of_today(), time(NULL));
    if (mask & HealthServiceAccessibilityMaskAvailable) {
      return (int)health_service_sum_today(HealthMetricStepCount);
    }
  #endif
  return -1;
}

static void format_steps(int steps, char *buf, size_t len) {
  if (steps < 0)          snprintf(buf, len, "-");
  else if (steps >= 1000) snprintf(buf, len, "%d.%03d", steps / 1000, steps % 1000);
  else                    snprintf(buf, len, "%d", steps);
}

static void exit_app(void *ctx) {
  s_timer = NULL;
  window_stack_pop_all(true);
}

static void set_timer(uint32_t ms, AppTimerCallback cb) {
  if (s_timer) app_timer_cancel(s_timer);
  s_timer = app_timer_register(ms, cb, NULL);
}

static void show_error(const char *text) {
  s_state = ST_ERROR;
  snprintf(s_status_buf, sizeof(s_status_buf), "%s", text);
  layer_mark_dirty(s_canvas);
  vibes_double_pulse();
  set_timer(EXIT_ERROR_MS, exit_app);
}

static void reply_timeout(void *ctx) {
  s_timer = NULL;
  show_error("Keine Antwort");
}

static void ready_timeout(void *ctx) {
  s_timer = NULL;
  if (!s_js_ready) show_error("Handy nicht verbunden");
}

// ── Sync ────────────────────────────────────────────────────────────────────

static void sync_now(void) {
  s_steps = steps_today();
  format_steps(s_steps, s_steps_buf, sizeof(s_steps_buf));
  if (s_steps < 0) { show_error("Health nicht aktiv"); return; }

  char date[12];
  time_t now = time(NULL);
  strftime(date, sizeof(date), "%Y-%m-%d", localtime(&now));

  DictionaryIterator *it;
  if (app_message_outbox_begin(&it) != APP_MSG_OK) { show_error("Senden fehlgeschlagen"); return; }
  dict_write_int32(it, MESSAGE_KEY_STEPS, s_steps);
  dict_write_cstring(it, MESSAGE_KEY_DATE, date);
  app_message_outbox_send();

  s_state = ST_SENDING;
  snprintf(s_status_buf, sizeof(s_status_buf), "Sende...");
  layer_mark_dirty(s_canvas);
  set_timer(REPLY_TIMEOUT_MS, reply_timeout);
}

static void inbox_received(DictionaryIterator *it, void *ctx) {
  Tuple *req  = dict_find(it, MESSAGE_KEY_REQUEST_SYNC);
  Tuple *kcal = dict_find(it, MESSAGE_KEY_RESULT_KCAL);
  Tuple *err  = dict_find(it, MESSAGE_KEY_RESULT_ERR);

  if (req) {                          // Handy-Teil ist bereit → sofort senden
    s_js_ready = true;
    if (s_state == ST_WAITING) sync_now();
    return;
  }
  if (kcal) {
    s_state = ST_OK;
    snprintf(s_status_buf, sizeof(s_status_buf), "Gesendet · %d kcal", (int)kcal->value->int32);
    layer_mark_dirty(s_canvas);
    vibes_short_pulse();
    set_timer(EXIT_OK_MS, exit_app);
  } else if (err) {
    show_error(err->value->cstring);
  }
}

static void outbox_failed(DictionaryIterator *it, AppMessageResult reason, void *ctx) {
  show_error("Handy nicht verbunden");
}

// ── Tasten ──────────────────────────────────────────────────────────────────

static void select_click(ClickRecognizerRef rec, void *ctx) {
  if (s_state == ST_SENDING) return;   // läuft schon
  if (!s_js_ready) {                   // Handy noch nicht bereit → weiter warten
    s_state = ST_WAITING;
    snprintf(s_status_buf, sizeof(s_status_buf), "Verbinde...");
    layer_mark_dirty(s_canvas);
    set_timer(READY_TIMEOUT_MS, ready_timeout);
    return;
  }
  sync_now();
}

static void click_config(void *ctx) {
  window_single_click_subscribe(BUTTON_ID_SELECT, select_click);
}

// ── Zeichnen ────────────────────────────────────────────────────────────────

static void draw_text(GContext *ctx, const char *text, GFont font, GRect box, GColor color) {
  graphics_context_set_text_color(ctx, color);
  graphics_draw_text(ctx, text, font, box, GTextOverflowModeTrailingEllipsis, GTextAlignmentCenter, NULL);
}

static void canvas_update(Layer *layer, GContext *ctx) {
  GRect b = layer_get_bounds(layer);
  int w = b.size.w, h = b.size.h;
  bool big = w >= 200;
  int pad = PBL_IF_ROUND_ELSE(w / 8, 8);

  graphics_context_set_fill_color(ctx, COL_BG);
  graphics_fill_rect(ctx, b, 0, GCornerNone);

  GFont f_small = fonts_get_system_font(big ? FONT_KEY_GOTHIC_18_BOLD : FONT_KEY_GOTHIC_14_BOLD);
  GFont f_label = fonts_get_system_font(big ? FONT_KEY_GOTHIC_24_BOLD : FONT_KEY_GOTHIC_18_BOLD);
  int line_small = big ? 22 : 17;
  int steps_h    = big ? 36 : 30;
  int label_h    = big ? 30 : 24;

  int block_h = line_small + 6 + steps_h + 8 + label_h;
  int y = (h - block_h) / 2 - (big ? 6 : 4);
  GRect col = GRect(pad, 0, w - 2 * pad, 0);

  draw_text(ctx, "SCHRITTE HEUTE", f_small, GRect(col.origin.x, y, col.size.w, line_small + 4), COL_MUTED);
  y += line_small + 6;
  draw_text(ctx, s_steps_buf, s_font_steps, GRect(col.origin.x, y - 4, col.size.w, steps_h + 6), COL_ACCENT);
  y += steps_h + 8;

  // Status: Punkt + Text, mittig
  GColor c = s_state == ST_OK ? COL_ACCENT : s_state == ST_ERROR ? COL_DANGER : COL_TEXT;
  GSize ts = graphics_text_layout_get_content_size(s_status_buf, f_label,
                 GRect(0, 0, col.size.w - 16, label_h * 2), GTextOverflowModeWordWrap, GTextAlignmentCenter);
  int tw = ts.w + 14;
  int tx = (w - tw) / 2;
  graphics_context_set_fill_color(ctx, c);
  graphics_fill_circle(ctx, GPoint(tx + 4, y + label_h / 2 + 2), 4);
  graphics_context_set_text_color(ctx, c);
  graphics_draw_text(ctx, s_status_buf, f_label, GRect(tx + 14, y, ts.w + 2, label_h * 2),
                     GTextOverflowModeWordWrap, GTextAlignmentLeft, NULL);

  // Hinweis unten
  if (s_state == ST_ERROR) {
    draw_text(ctx, "Mitte: erneut senden", f_small,
              GRect(col.origin.x, h - line_small - PBL_IF_ROUND_ELSE(22, 8), col.size.w, line_small + 4), COL_MUTED);
  }
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
  #if PBL_DISPLAY_WIDTH >= 200
    s_font_steps = fonts_load_custom_font(resource_get_handle(RESOURCE_ID_FONT_NUM_32));
  #else
    s_font_steps = fonts_load_custom_font(resource_get_handle(RESOURCE_ID_FONT_NUM_26));
  #endif
  s_steps = steps_today();
  format_steps(s_steps, s_steps_buf, sizeof(s_steps_buf));

  s_window = window_create();
  window_set_background_color(s_window, COL_BG);
  window_set_click_config_provider(s_window, click_config);
  window_set_window_handlers(s_window, (WindowHandlers) {
    .load = window_load, .unload = window_unload,
  });
  window_stack_push(s_window, true);

  app_message_register_inbox_received(inbox_received);
  app_message_register_outbox_failed(outbox_failed);
  app_message_open(128, 128);

  if (!connection_service_peek_pebble_app_connection()) {
    show_error("Handy nicht verbunden");
  } else {
    set_timer(READY_TIMEOUT_MS, ready_timeout);   // auf REQUEST_SYNC vom Handy warten
  }
}

static void deinit(void) {
  if (s_timer) app_timer_cancel(s_timer);
  fonts_unload_custom_font(s_font_steps);
  window_destroy(s_window);
}

int main(void) {
  init();
  app_event_loop();
  deinit();
}
