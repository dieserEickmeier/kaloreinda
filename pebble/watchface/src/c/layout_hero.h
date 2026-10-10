// ─────────────────────────────────────────────────────────────────────────────
// Layout „Hero-Balken“ – wird von main.c eingebunden (Standard-Layout)
// ─────────────────────────────────────────────────────────────────────────────

static GFont s_font_time;
static GFont s_font_big;     // Wert „Noch übrig“
static GFont s_font_val;     // Kennzahlen unten

// ── Zeichnen: Tagesbalken ───────────────────────────────────────────────────
//
// Wie der Balken auf „Heute“: gegessen (Limette) │ Rest (grau) │ Bonus aus
// Bewegung (schraffiert, am Ende des Ziels) │ über dem Ziel (orange).
// Skala = max(Ziel, gegessen), damit eine Überschreitung sichtbar bleibt.

static bool in_pill(int px, int py, GRect r) {
  int rad = r.size.h / 2;
  int cy  = r.origin.y + rad;
  int cx  = px < r.origin.x + rad ? r.origin.x + rad
          : px > r.origin.x + r.size.w - 1 - rad ? r.origin.x + r.size.w - 1 - rad : px;
  int dx = px - cx, dy = py - cy;
  return dx * dx + dy * dy <= rad * rad;
}

static void draw_day_bar(GContext *ctx, GRect r, bool valid) {
  int rad = r.size.h / 2;
  graphics_context_set_fill_color(ctx, COL_TRACK);
  graphics_fill_rect(ctx, r, rad, GCornersAll);
  #if !defined(PBL_COLOR)
    graphics_context_set_stroke_color(ctx, COL_TEXT);   // Schwarzweiß: Umriss statt grauer Spur
    graphics_draw_round_rect(ctx, r, rad);
  #endif
  if (!valid || s_goal <= 0) return;

  int w     = r.size.w;
  int tot   = s_goal > s_eaten ? s_goal : s_eaten;
  int x_goal = w * s_goal / tot;
  int x_eat  = w * (s_eaten < s_goal ? (s_eaten > 0 ? s_eaten : 0) : s_goal) / tot;
  int x_bon  = x_goal - w * (s_aktiv > 0 ? (s_aktiv < s_goal ? s_aktiv : s_goal) : 0) / tot;

  // Bonus schraffiert (Pixelmuster, an den runden Enden beschnitten)
  graphics_context_set_stroke_color(ctx, COL_ACCENT);
  for (int x = x_bon; x < x_goal; x++) {
    for (int y = 0; y < r.size.h; y++) {
      int px = r.origin.x + x, py = r.origin.y + y;
      if ((x + y) % 6 < 3 && in_pill(px, py, r)) graphics_draw_pixel(ctx, GPoint(px, py));
    }
  }
  // gegessen
  if (x_eat > 0) {
    graphics_context_set_fill_color(ctx, COL_ACCENT);
    graphics_fill_rect(ctx, GRect(r.origin.x, r.origin.y, x_eat < r.size.h ? r.size.h : x_eat, r.size.h), rad,
                       x_eat >= w ? GCornersAll : GCornersLeft);
  }
  // über dem Ziel
  if (s_eaten > s_goal) {
    graphics_context_set_fill_color(ctx, COL_DANGER);
    graphics_fill_rect(ctx, GRect(r.origin.x + x_goal, r.origin.y, w - x_goal, r.size.h), rad, GCornersRight);
    graphics_fill_rect(ctx, GRect(r.origin.x + x_goal, r.origin.y, rad, r.size.h), 0, GCornerNone);
  }
}

// ── Zeichnen: Ziffernblatt ──────────────────────────────────────────────────

static void canvas_update(Layer *layer, GContext *ctx) {
  GRect b  = layer_get_bounds(layer);
  int   w  = b.size.w;
  bool  big = w >= 200;

  graphics_context_set_fill_color(ctx, COL_BG);
  graphics_fill_rect(ctx, b, 0, GCornerNone);

  int x  = big ? 10 : 6;          // Rand links/rechts
  int cw = w - 2 * x;             // Inhaltsbreite

  GFont f_small = fonts_get_system_font(big ? FONT_KEY_GOTHIC_14_BOLD : FONT_KEY_GOTHIC_09);
  GFont f_date  = fonts_get_system_font(big ? FONT_KEY_GOTHIC_18_BOLD : FONT_KEY_GOTHIC_14);
  GFont f_info  = fonts_get_system_font(big ? FONT_KEY_GOTHIC_14 : FONT_KEY_GOTHIC_09);
  GFont f_unit  = fonts_get_system_font(big ? FONT_KEY_GOTHIC_18_BOLD : FONT_KEY_GOTHIC_14_BOLD);

  // Layout-Raster (emery 200 × 228 / klein 144 × 168)
  int y_time  = big ? 0   : 0;
  int y_label = big ? 54  : 38;
  int y_big   = big ? 66  : 48;
  int big_h   = big ? 44  : 28;   // Höhe der Wertschrift
  int y_bar   = big ? 126 : 86;
  int bar_h   = big ? 12  : 8;
  int y_info  = big ? 141 : 96;
  int y_line  = big ? 172 : 118;
  int y_stat  = big ? 178 : 122;
  int stat_h  = big ? 20  : 18;

  char buf[24];
  bool valid = s_have_bilanz && synced_today();
  bool over  = valid && s_rest < 0;

  // ── Uhrzeit, Datum, Warnsymbole ──
  draw_text(ctx, s_time_buf, s_font_time, GRect(x - 2, y_time, cw, big ? 48 : 36), COL_TEXT, GTextAlignmentLeft);
  // Datum zweizeilig rechtsbündig, damit es nicht an die Uhrzeit stößt;
  // Warnsymbole links neben dem (kurzen) Wochentag
  int line_h = big ? 20 : 15;
  draw_text(ctx, s_wday_buf, f_date, GRect(x, big ? 2 : 0, cw, 22), COL_MUTED, GTextAlignmentRight);
  draw_text(ctx, s_date_buf, f_date, GRect(x, (big ? 2 : 0) + line_h, cw, 22), COL_MUTED, GTextAlignmentRight);
  int icons_w = warn_icons_width(big);
  if (icons_w) {
    int wd_w = text_width(s_wday_buf, f_date);
    draw_warn_icons(ctx, x + cw - wd_w - 6 - icons_w, big ? 6 : 3, big);
  }

  // ── Noch übrig + Sync-Status rechts daneben ──
  draw_text(ctx, over ? "ÜBER DEM ZIEL" : "NOCH ÜBRIG", f_small, GRect(x, y_label, cw, 18),
            over ? COL_DANGER : COL_MUTED, GTextAlignmentLeft);
  char st[8] = "";
  GColor st_col = COL_MUTED;
  if (s_state == SYNC_SENDING)      snprintf(st, sizeof(st), "...");
  else if (s_state == SYNC_ERROR) { snprintf(st, sizeof(st), "!"); st_col = COL_DANGER; }
  else if (synced_today()) {
    struct tm *t = localtime(&s_last_sync);
    snprintf(st, sizeof(st), "%02d:%02d", t->tm_hour, t->tm_min);
  }
  draw_text(ctx, st, s_state == SYNC_ERROR ? f_date : f_small,
            GRect(x, y_label - (s_state == SYNC_ERROR ? 4 : 0), cw, 22), st_col, GTextAlignmentRight);

  if (valid) format_num(over ? -s_rest : s_rest, buf, sizeof(buf));
  else       snprintf(buf, sizeof(buf), "-");
  GColor big_col = over ? COL_DANGER : (valid ? COL_TEXT : COL_MUTED);
  draw_text(ctx, buf, s_font_big, GRect(x - 1, y_big, cw, big_h + 12), big_col, GTextAlignmentLeft);
  if (valid) {
    int vw = text_width(buf, s_font_big);
    draw_text(ctx, "kcal", f_unit, GRect(x + vw + 5, y_big + big_h - (big ? 16 : 14), cw - vw - 5, 22),
              COL_MUTED, GTextAlignmentLeft);
  }

  // ── Tagesbalken + Legende (bzw. dauerhafter Sync-Fehler) ──
  draw_day_bar(ctx, GRect(x, y_bar, cw, bar_h), valid);
  if (s_state == SYNC_ERROR && !s_pending) {
    draw_text(ctx, s_error, f_small, GRect(x, y_info, cw, 18), COL_DANGER, GTextAlignmentLeft);
  } else if (valid) {
    char num[12];
    format_num(s_eaten, num, sizeof(num));
    snprintf(buf, sizeof(buf), "%s gegessen", num);
    draw_text(ctx, buf, f_info, GRect(x, y_info, cw, 18), COL_MUTED, GTextAlignmentLeft);
    format_num(s_goal, num, sizeof(num));
    snprintf(buf, sizeof(buf), "Ziel %s", num);
    draw_text(ctx, buf, f_info, GRect(x, y_info, cw, 18), COL_MUTED, GTextAlignmentRight);
  } else {
    draw_text(ctx, "Noch kein Sync heute", f_info, GRect(x, y_info, cw, 18), COL_MUTED, GTextAlignmentLeft);
  }

  // ── Kennzahlen: Schritte │ Aktiv ──
  graphics_context_set_fill_color(ctx, COL_LINE);
  graphics_fill_rect(ctx, GRect(x, y_line, cw, 1), 0, GCornerNone);
  int half = cw / 2;
  graphics_fill_rect(ctx, GRect(x + half - 3, y_line + 8, 1, big ? 40 : 30), 0, GCornerNone);
  draw_text(ctx, "SCHRITTE", f_small, GRect(x, y_stat, half - 6, 16), COL_MUTED, GTextAlignmentLeft);
  draw_text(ctx, "AKTIV", f_small, GRect(x + half + 3, y_stat, half - 3, 16), COL_MUTED, GTextAlignmentLeft);
  int vy = y_stat + (big ? 16 : 11);
  format_num(s_steps, buf, sizeof(buf));
  draw_text(ctx, buf, s_font_val, GRect(x, vy, half - 6, stat_h + 8), COL_ACCENT, GTextAlignmentLeft);
  if (synced_today()) format_num(s_have_bilanz ? s_aktiv : s_last_kcal, buf, sizeof(buf));
  else                snprintf(buf, sizeof(buf), "-");
  draw_text(ctx, buf, s_font_val, GRect(x + half + 3, vy, half - 3, stat_h + 8), COL_TEXT, GTextAlignmentLeft);
}

static void layout_init(void) {
  // Große Schriften nur auf emery (Pebble Time 2) – auf den kleineren
  // Displays überschreiten sie das Glyph-Größenlimit; dort Systemschriften.
  #if PBL_DISPLAY_WIDTH >= 200
    s_font_time = fonts_load_custom_font(resource_get_handle(RESOURCE_ID_FONT_TIME_40));
    s_font_big  = fonts_load_custom_font(resource_get_handle(RESOURCE_ID_FONT_NUM_44));
    s_font_val  = fonts_load_custom_font(resource_get_handle(RESOURCE_ID_FONT_NUM_20));
  #else
    s_font_time = fonts_get_system_font(FONT_KEY_LECO_26_BOLD_NUMBERS_AM_PM);
    s_font_big  = fonts_get_system_font(FONT_KEY_GOTHIC_28_BOLD);
    s_font_val  = fonts_get_system_font(FONT_KEY_GOTHIC_18_BOLD);
  #endif
}

static void layout_deinit(void) {
  #if PBL_DISPLAY_WIDTH >= 200
    fonts_unload_custom_font(s_font_time);
    fonts_unload_custom_font(s_font_big);
    fonts_unload_custom_font(s_font_val);
  #endif
}
