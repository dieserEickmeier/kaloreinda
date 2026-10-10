// ─────────────────────────────────────────────────────────────────────────────
// Layout „Pixel“ – wird von main.c eingebunden (Build mit LAYOUT=pixel/saeulen)
//
//   Sa, 10. Okt                    ▓▓
//   ███ ███                        ▓▓   Stunden weiß, Minuten Limette,
//   █ █   █                        ░░   je 3 × 5 Rasterpunkte
//   ...                            ░░   Säule: Schritte bis zum Ziel
//   320 kcal aktiv                8,4k
//
// Variante „Säulen“ (LAYOUT=saeulen): in der Lücke zwischen Ziffern und
// Schritte-Säule eine zweite Säule für „noch übrig“ – voll am Morgen, leert
// sich mit jedem Eintrag, über dem Ziel komplett orange. Darunter der Wert.
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

// Segment-Säule, füllt sich von unten mit `on` Segmenten
static void draw_column(GContext *ctx, GRect r, int on, GColor col) {
  int col_x = r.origin.x, col_y = r.origin.y, col_w = r.size.w, col_h = r.size.h;
  for (int i = 0; i < SAEULE_SEGMENTE; i++) {
    int y0 = col_y + col_h - (i + 1) * col_h / SAEULE_SEGMENTE;
    int y1 = col_y + col_h - i * col_h / SAEULE_SEGMENTE;
    GRect seg = GRect(col_x, y0 + 1, col_w, y1 - y0 - 3);
    if (i < on) {
      graphics_context_set_fill_color(ctx, col);
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
  #if defined(LAYOUT_SAEULEN)
    int gap    = big ? 14 : 6;           // Abstand der beiden Säulen
    int kcal_x = col_x - gap - col_w;    // Säule „noch übrig“
  #endif

  // ── Datum + Warnsymbole (links neben der Säule) ──
  char buf[32];
  snprintf(buf, sizeof(buf), "%s, %s", s_wday_buf, s_date_buf);
  draw_text(ctx, buf, f_date, GRect(x + 2, big ? 2 : 0, w, 22), COL_MUTED, GTextAlignmentLeft);
  int icons_w = warn_icons_width(big);
  #if defined(LAYOUT_SAEULEN)
    // Warnsymbole direkt hinter dem Datum; die Säulen-Beschriftung entfällt,
    // solange sie ihr im Weg wären
    int icons_x = x + 2 + text_width(buf, f_date) + 6;
    if (icons_w) draw_warn_icons(ctx, icons_x, big ? 7 : 3, big);
    if (!icons_w || icons_x + icons_w + 4 <= kcal_x) {
      GFont f_lab = fonts_get_system_font(big ? FONT_KEY_GOTHIC_14_BOLD : FONT_KEY_GOTHIC_09);
      int   lab_y = col_y - (big ? 18 : 11);
      draw_text(ctx, "KCAL", f_lab, GRect(kcal_x - gap / 2, lab_y, col_w + gap, 16), COL_MUTED, GTextAlignmentCenter);
      draw_text(ctx, "SCHR.", f_lab, GRect(col_x - gap / 2, lab_y, col_w + gap, 16), COL_MUTED, GTextAlignmentCenter);
    }
  #else
    if (icons_w) draw_warn_icons(ctx, col_x - 8 - icons_w, big ? 7 : 3, big);
  #endif

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
  draw_column(ctx, GRect(col_x, col_y, col_w, col_h), on, COL_ACCENT);

  #if defined(LAYOUT_SAEULEN)
    // ── Säule „noch übrig“: leert sich über den Tag, über dem Ziel orange ──
    bool valid = s_have_bilanz && synced_today() && s_goal > 0;
    bool over  = valid && s_rest < 0;
    int  k_on  = !valid ? 0 : over ? SAEULE_SEGMENTE
                        : (s_rest * SAEULE_SEGMENTE + s_goal / 2) / s_goal;
    if (k_on > SAEULE_SEGMENTE) k_on = SAEULE_SEGMENTE;
    draw_column(ctx, GRect(kcal_x, col_y, col_w, col_h), k_on, over ? COL_DANGER : COL_TEXT);
    if (valid) format_num(over ? -s_rest : s_rest, buf, sizeof(buf));
    else       snprintf(buf, sizeof(buf), "-");
    draw_text(ctx, buf, f_step, GRect(kcal_x - gap / 2, y_foot, col_w + gap, 24),
              over ? COL_DANGER : COL_TEXT, GTextAlignmentCenter);
    GRect step_box = GRect(col_x - gap / 2, y_foot, col_w + gap, 24);
    int   foot_w   = kcal_x - gap / 2 - x - 4;
  #else
    GRect step_box = GRect(col_x - 20, y_foot, col_w + 40, 24);
    int   foot_w   = col_x - 20 - x - 4;
  #endif

  // ── Fuß: Aktivkalorien (bzw. Sync-Fehler) links, Schritte unter der Säule ──
  if (s_steps < 0)        snprintf(buf, sizeof(buf), "-");
  else if (steps < 1000)  snprintf(buf, sizeof(buf), "%d", steps);
  else                    snprintf(buf, sizeof(buf), "%d,%dk", steps / 1000, steps % 1000 / 100);
  draw_text(ctx, buf, f_step, step_box, COL_ACCENT, GTextAlignmentCenter);

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
