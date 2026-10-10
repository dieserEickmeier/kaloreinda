// ─────────────────────────────────────────────────────────────────────────────
// Layout „Bento“ – wird von main.c eingebunden (Build mit LAYOUT=bento)
//
//   ┌ SA, 10. OKT ──────────── ⚠ ┐
//   │ 14:37                      │
//   ├──────────────┬─────────────┤
//   │ NOCH ÜBRIG   │ SCHRITTE    │   „Noch übrig“ als Limettenkachel,
//   │              │             │   Schritte-Balken bis zum Schrittziel
//   │ 1.146 kcal   │ 8.432 ▬▬▬   │   (orange über dem Ziel)
//   ├──────────────┼─────────────┤
//   │ GEGESSEN     │ AKTIV 14:00 │   rechts oben in „Aktiv“: Sync-Status
//   │ 1.240 ▬▬▬    │ ⚡ 320 kcal  │
//   └──────────────┴─────────────┘
// ─────────────────────────────────────────────────────────────────────────────

#define COL_BORDER   PBL_IF_COLOR_ELSE(GColorDarkGray, GColorWhite)

static GFont  s_font_time;
static GFont  s_font_big;     // Wert „Noch übrig“
static GFont  s_font_val;     // Werte der übrigen Kacheln
static GPath *s_bolt;

// Blitz (Aktivkalorien), wie im Menü der App
static const GPathInfo BOLT_SMALL = { 6, (GPoint[]) { {7, 0}, {1, 7}, {5, 7}, {4, 12}, {10, 5}, {6, 5} } };
static const GPathInfo BOLT_BIG   = { 6, (GPoint[]) { {10, 0}, {1, 10}, {7, 10}, {6, 17}, {14, 7}, {8, 7} } };

static void draw_tile(GContext *ctx, GRect r, bool filled, GColor fill, int radius) {
  if (filled) {
    graphics_context_set_fill_color(ctx, fill);
    graphics_fill_rect(ctx, r, radius, GCornersAll);
  } else {
    graphics_context_set_stroke_color(ctx, COL_BORDER);
    graphics_draw_round_rect(ctx, r, radius);
  }
}

// Wert + kleine Einheit dahinter („1.146 kcal“), linksbündig ab x.
// y = Oberkante der Wert-Box; die Einheit sitzt auf der Grundlinie.
static void draw_value_unit(GContext *ctx, const char *val, GFont f_val, int val_h,
                            const char *unit, GFont f_unit, int x, int y, int max_w, GColor col) {
  draw_text(ctx, val, f_val, GRect(x, y, max_w, val_h + 8), col, GTextAlignmentLeft);
  if (!unit) return;
  int vw = text_width(val, f_val);
  if (vw + 2 + text_width(unit, f_unit) > max_w) return;   // passt nicht → weglassen
  draw_text(ctx, unit, f_unit, GRect(x + vw + 3, y + val_h - 14, max_w - vw - 3, 18), col, GTextAlignmentLeft);
}

// Dünner Fortschrittsbalken (Spur + Füllung) am Kachelboden
static void draw_progress(GContext *ctx, int x, int y, int w, int value, int max, GColor col) {
  int prog = (value <= 0 || max <= 0) ? 0 : (value >= max ? w : w * value / max);
  graphics_context_set_fill_color(ctx, COL_TRACK);
  graphics_fill_rect(ctx, GRect(x, y, w, 3), 1, GCornersAll);
  #if !defined(PBL_COLOR)
    graphics_context_set_stroke_color(ctx, COL_TEXT);    // Schwarzweiß: Umriss statt grauer Spur
    graphics_draw_rect(ctx, GRect(x, y, w, 3));
  #endif
  if (prog > 0) {
    graphics_context_set_fill_color(ctx, col);
    graphics_fill_rect(ctx, GRect(x, y, prog, 3), 1, GCornersAll);
  }
}

static void canvas_update(Layer *layer, GContext *ctx) {
  GRect b  = layer_get_bounds(layer);
  int   w  = b.size.w;
  int   h  = b.size.h;
  bool  big = w >= 200;

  graphics_context_set_fill_color(ctx, COL_BG);
  graphics_fill_rect(ctx, b, 0, GCornerNone);

  // Raster: oben die Zeit-Kachel, darunter 2 × 2 Kacheln
  int m   = big ? 5 : 3;          // Außenrand
  int g   = big ? 6 : 4;          // Abstand zwischen Kacheln
  int rad = big ? 12 : 8;
  int pad = big ? 9 : 6;          // Innenabstand
  int th  = big ? 88 : 64;        // Höhe Zeit-Kachel
  int tw  = (w - 2 * m - g) / 2;
  int rh  = (h - 2 * m - th - 2 * g) / 2;

  GFont f_label = fonts_get_system_font(big ? FONT_KEY_GOTHIC_14_BOLD : FONT_KEY_GOTHIC_09);
  GFont f_date  = fonts_get_system_font(big ? FONT_KEY_GOTHIC_18_BOLD : FONT_KEY_GOTHIC_14_BOLD);
  GFont f_unit  = fonts_get_system_font(big ? FONT_KEY_GOTHIC_14_BOLD : FONT_KEY_GOTHIC_09);
  int   label_h = big ? 16 : 11;
  int   big_h   = big ? 26 : 22;  // Höhe der Wertschriften (für Grundlinie der Einheit)
  int   val_h   = big ? 22 : 18;

  char buf[24];

  // ── Zeit-Kachel ──
  GRect top = GRect(m, m, w - 2 * m, th);
  draw_tile(ctx, top, false, COL_BG, rad);
  int icons_w = warn_icons_width(big);
  snprintf(buf, sizeof(buf), "%s, %s", s_wday_buf, s_date_buf);
  draw_text(ctx, buf, f_date,
            GRect(top.origin.x + pad + 2, top.origin.y + (big ? 3 : 1), top.size.w - 2 * pad - icons_w - 6, 22),
            COL_MUTED, GTextAlignmentLeft);
  if (icons_w) {
    draw_warn_icons(ctx, top.origin.x + top.size.w - pad - icons_w, top.origin.y + (big ? 9 : 6), big);
  }
  draw_text(ctx, s_time_buf, s_font_time,
            GRect(top.origin.x + pad - 1, top.origin.y + (big ? 14 : 12), top.size.w - pad, th),
            COL_TEXT, GTextAlignmentLeft);

  int y1 = m + th + g, y2 = y1 + rh + g;
  int x1 = m,          x2 = m + tw + g;
  int inner = tw - 2 * pad;
  int lab_y = big ? 4 : 2;                      // Label-Abstand zur Kachel-Oberkante
  int val_y = rh - val_h - (big ? 17 : 12);     // Wert über dem Fortschrittsbalken
  int bar_y = rh - (big ? 9 : 6);

  bool valid = s_have_bilanz && synced_today();
  bool over  = valid && s_rest < 0;

  // ── Noch übrig (Akzentkachel) ──
  GColor fg = valid ? COL_BG : COL_MUTED;
  draw_tile(ctx, GRect(x1, y1, tw, rh), valid, over ? COL_DANGER : COL_ACCENT, rad);
  draw_text(ctx, over ? "ÜBER ZIEL" : "NOCH ÜBRIG", f_label,
            GRect(x1 + pad, y1 + lab_y, inner, label_h + 4), fg, GTextAlignmentLeft);
  if (valid) format_num(over ? -s_rest : s_rest, buf, sizeof(buf));
  else       snprintf(buf, sizeof(buf), "-");
  draw_value_unit(ctx, buf, s_font_big, big_h, valid ? "kcal" : NULL, f_unit,
                  x1 + pad, y1 + rh - big_h - (big ? 12 : 7), tw - pad - 2, fg);

  // ── Schritte ──
  draw_tile(ctx, GRect(x2, y1, tw, rh), false, COL_BG, rad);
  draw_text(ctx, "SCHRITTE", f_label, GRect(x2 + pad, y1 + lab_y, inner, label_h + 4),
            COL_MUTED, GTextAlignmentLeft);
  format_num(s_steps, buf, sizeof(buf));
  draw_text(ctx, buf, s_font_val, GRect(x2 + pad, y1 + val_y, inner, val_h + 8), COL_ACCENT, GTextAlignmentLeft);
  draw_progress(ctx, x2 + pad, y1 + bar_y, inner, s_steps, s_step_goal, COL_ACCENT);

  // ── Gegessen, Balken bis zum Tagesziel ──
  draw_tile(ctx, GRect(x1, y2, tw, rh), false, COL_BG, rad);
  draw_text(ctx, "GEGESSEN", f_label, GRect(x1 + pad, y2 + lab_y, inner, label_h + 4),
            COL_MUTED, GTextAlignmentLeft);
  if (valid) format_num(s_eaten, buf, sizeof(buf));
  else       snprintf(buf, sizeof(buf), "-");
  draw_text(ctx, buf, s_font_val, GRect(x1 + pad, y2 + val_y, inner, val_h + 8), COL_TEXT, GTextAlignmentLeft);
  draw_progress(ctx, x1 + pad, y2 + bar_y, inner, valid ? s_eaten : 0, s_goal, over ? COL_DANGER : COL_ACCENT);

  // ── Aktiv + Sync-Status ──
  draw_tile(ctx, GRect(x2, y2, tw, rh), false, COL_BG, rad);
  draw_text(ctx, "AKTIV", f_label, GRect(x2 + pad, y2 + lab_y, inner, label_h + 4),
            COL_MUTED, GTextAlignmentLeft);
  // rechts oben: Uhrzeit des letzten Syncs, „…“ beim Senden, „!“ bei Fehler
  char st[8] = "";
  GColor st_col = COL_MUTED;
  if (s_state == SYNC_SENDING)      snprintf(st, sizeof(st), "...");
  else if (s_state == SYNC_ERROR) { snprintf(st, sizeof(st), "!"); st_col = COL_DANGER; }
  else if (synced_today()) {
    struct tm *t = localtime(&s_last_sync);
    snprintf(st, sizeof(st), "%02d:%02d", t->tm_hour, t->tm_min);
  }
  draw_text(ctx, st, s_state == SYNC_ERROR ? f_date : f_label,
            GRect(x2 + pad, y2 + (big ? (s_state == SYNC_ERROR ? 0 : 4) : 2), inner, label_h + 8),
            st_col, GTextAlignmentRight);

  if (s_state == SYNC_ERROR && !s_pending) {
    // dauerhafter Fehler (z.B. API-Key) → Text statt Wert, bis zum nächsten Sync
    graphics_context_set_text_color(ctx, COL_DANGER);
    graphics_draw_text(ctx, s_error, f_label, GRect(x2 + pad, y2 + label_h + (big ? 6 : 3), inner, rh - label_h - 6),
                       GTextOverflowModeWordWrap, GTextAlignmentLeft, NULL);
  } else {
    int bolt_w = big ? 14 : 10, bolt_h = big ? 17 : 12;
    gpath_move_to(s_bolt, GPoint(x2 + pad, y2 + val_y + (val_h - bolt_h) / 2 + (big ? 5 : 4)));
    graphics_context_set_fill_color(ctx, COL_ACCENT);
    gpath_draw_filled(ctx, s_bolt);
    if (synced_today()) format_num(s_have_bilanz ? s_aktiv : s_last_kcal, buf, sizeof(buf));
    else                snprintf(buf, sizeof(buf), "-");
    draw_value_unit(ctx, buf, s_font_val, val_h, synced_today() ? "kcal" : NULL, f_unit,
                    x2 + pad + bolt_w + 4, y2 + val_y + (big ? 5 : 4), tw - pad - bolt_w - 6, COL_TEXT);
  }
}

static void layout_init(void) {
  // Große Schriften für die Uhrzeit auf allen Plattformen, die Kachelwerte
  // nur auf emery – sonst überschreiten sie das Glyph-Größenlimit.
  #if PBL_DISPLAY_WIDTH >= 200
    s_font_time = fonts_load_custom_font(resource_get_handle(RESOURCE_ID_FONT_TIME_56));
    s_font_big  = fonts_load_custom_font(resource_get_handle(RESOURCE_ID_FONT_NUM_26));
    s_font_val  = fonts_load_custom_font(resource_get_handle(RESOURCE_ID_FONT_NUM_22));
    s_bolt      = gpath_create(&BOLT_BIG);
  #else
    s_font_time = fonts_load_custom_font(resource_get_handle(RESOURCE_ID_FONT_TIME_44));
    s_font_big  = fonts_get_system_font(FONT_KEY_GOTHIC_24_BOLD);
    s_font_val  = fonts_get_system_font(FONT_KEY_GOTHIC_18_BOLD);
    s_bolt      = gpath_create(&BOLT_SMALL);
  #endif
}

static void layout_deinit(void) {
  gpath_destroy(s_bolt);
  fonts_unload_custom_font(s_font_time);
  #if PBL_DISPLAY_WIDTH >= 200
    fonts_unload_custom_font(s_font_big);
    fonts_unload_custom_font(s_font_val);
  #endif
}
