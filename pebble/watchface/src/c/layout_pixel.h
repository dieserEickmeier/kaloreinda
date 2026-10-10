// ─────────────────────────────────────────────────────────────────────────────
// Layout „Pixel“ – wird von main.c eingebunden (Build mit LAYOUT=pixel)
//
//   Sa, 10. Okt                    ▓▓
//   ███ ███                        ▓▓   Stunden weiß, Minuten Limette,
//   █ █   █                        ░░   je 3 × 5 Rasterpunkte
//   ...                            ░░   Säule: Schritte bis zum Ziel
//   320 kcal aktiv                8,4k
//
// Die Ziffern sind gezeichnete Rechtecke, also gilt kein Schrift-Größenlimit.
// ─────────────────────────────────────────────────────────────────────────────

#define SCHRITT_ZIEL 10000
#define SAEULE_SEGMENTE 14

// 3 × 5 Raster je Ziffer, zeilenweise von oben, Bit 14 = links oben
static const uint16_t PIXEL_ZIFFERN[10] = {
  0x7B6F, 0x2C97, 0x73E7, 0x73CF, 0x5BC9, 0x79CF, 0x79EF, 0x7292, 0x7BEF, 0x7BCF,
};

static void draw_pixel_digit(GContext *ctx, int d, int x, int y, int c, GColor col) {
  int dot = c - 2, r = c >= 14 ? 3 : 2;
  for (int row = 0; row < 5; row++) {
    for (int q = 0; q < 3; q++) {
      bool on = PIXEL_ZIFFERN[d] & (1 << (14 - (row * 3 + q)));
      int px = x + q * c, py = y + row * c;
      if (on) {
        graphics_context_set_fill_color(ctx, col);
        graphics_fill_rect(ctx, GRect(px, py, dot, dot), r, GCornersAll);
      } else {
        #if defined(PBL_COLOR)
          // ausgeschaltete LED als kleiner dunkler Punkt
          graphics_context_set_fill_color(ctx, COL_LINE);
          graphics_fill_rect(ctx, GRect(px + dot / 2 - 1, py + dot / 2 - 1, 2, 2), 0, GCornerNone);
        #endif
      }
    }
  }
}

static void canvas_update(Layer *layer, GContext *ctx) {
  GRect b   = layer_get_bounds(layer);
  int   w   = b.size.w, h = b.size.h;
  bool  big = w >= 200;

  graphics_context_set_fill_color(ctx, COL_BG);
  graphics_fill_rect(ctx, b, 0, GCornerNone);

  GFont f_date = fonts_get_system_font(big ? FONT_KEY_GOTHIC_18_BOLD : FONT_KEY_GOTHIC_14_BOLD);
  GFont f_info = fonts_get_system_font(big ? FONT_KEY_GOTHIC_14 : FONT_KEY_GOTHIC_09);
  GFont f_step = fonts_get_system_font(big ? FONT_KEY_GOTHIC_18_BOLD : FONT_KEY_GOTHIC_14_BOLD);

  // Raster (emery 200 × 228 / klein 144 × 168)
  int x      = big ? 8 : 6;
  int c      = big ? 16 : 11;          // Rasterabstand der Punkte
  int y_hh   = big ? 32 : 22;
  int y_mm   = y_hh + 5 * c + (big ? 8 : 5);
  int col_w  = big ? 30 : 22;
  int col_x  = w - x - col_w;
  int col_y  = y_hh, col_h = y_mm + 5 * c - 2 - col_y;
  int y_foot = h - (big ? 26 : 20);

  // ── Datum + Warnsymbole (links neben der Säule) ──
  char buf[32];
  snprintf(buf, sizeof(buf), "%s, %s", s_wday_buf, s_date_buf);
  draw_text(ctx, buf, f_date, GRect(x + 2, big ? 2 : 0, w, 22), COL_MUTED, GTextAlignmentLeft);
  int icons_w = warn_icons_width(big);
  if (icons_w) draw_warn_icons(ctx, col_x - 8 - icons_w, big ? 7 : 3, big);

  // ── Uhrzeit: HH weiß, MM Limette (s_time_buf = „HH:MM“) ──
  int dx = 3 * c + (big ? 6 : 4);
  draw_pixel_digit(ctx, s_time_buf[0] - '0', x,      y_hh, c, COL_TEXT);
  draw_pixel_digit(ctx, s_time_buf[1] - '0', x + dx, y_hh, c, COL_TEXT);
  draw_pixel_digit(ctx, s_time_buf[3] - '0', x,      y_mm, c, COL_ACCENT);
  draw_pixel_digit(ctx, s_time_buf[4] - '0', x + dx, y_mm, c, COL_ACCENT);

  // ── Schritte-Säule, füllt sich von unten ──
  int steps = s_steps < 0 ? 0 : s_steps;
  int on    = (steps * SAEULE_SEGMENTE + SCHRITT_ZIEL / 2) / SCHRITT_ZIEL;
  if (on > SAEULE_SEGMENTE) on = SAEULE_SEGMENTE;
  for (int i = 0; i < SAEULE_SEGMENTE; i++) {
    int y0 = col_y + col_h - (i + 1) * col_h / SAEULE_SEGMENTE;
    int y1 = col_y + col_h - i * col_h / SAEULE_SEGMENTE;
    GRect seg = GRect(col_x, y0 + 1, col_w, y1 - y0 - 3);
    if (i < on) {
      graphics_context_set_fill_color(ctx, COL_ACCENT);
      graphics_fill_rect(ctx, seg, 2, GCornersAll);
    } else {
      #if defined(PBL_COLOR)
        graphics_context_set_fill_color(ctx, COL_TRACK);
        graphics_fill_rect(ctx, seg, 2, GCornersAll);
      #else
        graphics_context_set_stroke_color(ctx, COL_TEXT);
        graphics_draw_rect(ctx, seg);
      #endif
    }
  }

  // ── Fuß: Aktivkalorien (bzw. Sync-Fehler) links, Schritte unter der Säule ──
  if (s_steps < 0)        snprintf(buf, sizeof(buf), "-");
  else if (steps < 1000)  snprintf(buf, sizeof(buf), "%d", steps);
  else                    snprintf(buf, sizeof(buf), "%d,%dk", steps / 1000, steps % 1000 / 100);
  draw_text(ctx, buf, f_step, GRect(col_x - 20, y_foot, col_w + 40, 24), COL_ACCENT, GTextAlignmentCenter);

  int foot_w = col_x - 20 - x - 4;
  if (s_state == SYNC_ERROR && !s_pending) {
    draw_text(ctx, s_error, f_info, GRect(x + 2, y_foot + (big ? 4 : 4), foot_w, 18), COL_DANGER, GTextAlignmentLeft);
  } else if (synced_today()) {
    char num[12];
    format_num(s_have_bilanz ? s_aktiv : s_last_kcal, num, sizeof(num));
    snprintf(buf, sizeof(buf), "%s kcal aktiv%s", num, s_state == SYNC_SENDING ? " ..." : "");
    draw_text(ctx, buf, f_info, GRect(x + 2, y_foot + (big ? 4 : 4), foot_w, 18), COL_MUTED, GTextAlignmentLeft);
  } else {
    draw_text(ctx, "Noch kein Sync heute", f_info, GRect(x + 2, y_foot + 4, foot_w, 18), COL_MUTED, GTextAlignmentLeft);
  }
}

static void layout_init(void) {}
static void layout_deinit(void) {}
