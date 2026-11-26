// Shared by the Urdu picture scripts.

/**
 * Whether `words` are on the screen, read off the page: a text that holds them, drawn (some size, not hidden), in the
 * window and not scrolled or clipped out of any box it sits in (most of it shown). isVisible() would say yes to a line
 * scrolled out of its list.
 */
export const onScreen = (page, words) => page.evaluate((words) => {
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    if (!node.textContent.includes(words) || !node.parentElement) continue;
    const look = getComputedStyle(node.parentElement);
    if (look.visibility !== "visible" || look.display === "none") continue;
    const range = document.createRange();
    range.selectNodeContents(node);
    const r = range.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) continue;
    let [left, top, right, bottom] = [Math.max(r.left, 0), Math.max(r.top, 0), Math.min(r.right, innerWidth), Math.min(r.bottom, innerHeight)];
    for (let box = node.parentElement; box && box !== document.documentElement; box = box.parentElement) {
      const s = getComputedStyle(box);
      if (s.overflowX === "visible" && s.overflowY === "visible" && s.clipPath === "none") continue;
      const b = box.getBoundingClientRect();
      [left, top, right, bottom] = [Math.max(left, b.left), Math.max(top, b.top), Math.min(right, b.right), Math.min(bottom, b.bottom)];
    }
    if (right - left >= r.width * 0.6 && bottom - top >= r.height * 0.6) return true;
  }
  return false;
}, words);

/**
 * The text a scene shows (D-261): each text drawn inside the first element `root` (a CSS selector) matches, its element
 * shown (not display:none, visibility:hidden, transparent, or a screen-reader-only box), and each empty field's
 * placeholder. Keys (<kbd>) and avatars' initials are left out. A text scrolled out of view counts: it is the scene's.
 */
export const sceneTexts = (page, root) => page.evaluate((root) => {
  const scope = document.querySelector(root);
  if (!scope) return null;
  const shown = (el) => {
    for (let e = el; e && e !== document.documentElement; e = e.parentElement) {
      const s = getComputedStyle(e);
      if (s.display === "none" || s.visibility === "hidden" || Number(s.opacity) < 0.05) return false;
      // A screen-reader-only box: clipped to nothing, or a pixel square with its overflow hidden.
      if (/rect\(0(px)?,? 0(px)?,? 0(px)?,? 0(px)?\)/.test(s.clip) || s.clipPath === "inset(50%)") return false;
      const b = e.getBoundingClientRect();
      if (s.overflow !== "visible" && (b.width <= 1 || b.height <= 1)) return false;
    }
    return true;
  };
  const texts = [];
  const walker = document.createTreeWalker(scope, NodeFilter.SHOW_TEXT);
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    const text = node.textContent.trim();
    const el = node.parentElement;
    if (!text || !el || el.closest("kbd, .el-avatar, script, style") || !shown(el)) continue;
    const range = document.createRange();
    range.selectNodeContents(node);
    const r = range.getBoundingClientRect();
    if (r.width < 1 || r.height < 1) continue;
    texts.push(text);
  }
  for (const field of scope.querySelectorAll("input[placeholder], textarea[placeholder]")) {
    if (!field.value && field.placeholder.trim() && shown(field)) texts.push(field.placeholder.trim());
  }
  return texts;
}, root);

/**
 * The texts of a scene that lose ink (D-261): every text drawn inside the first element `root` matches (labels,
 * paragraphs, lines), in view, that a box it sits in clips (overflow other than visible, a clip-path, paint
 * containment), across or up and down, or that the window clips, or whose ink runs into the border of a card it sits
 * in (a box drawn with a border line, not its own control: a letter drawn over the card's edge reads as cut). Across, the words' boxes as laid out (a line cut with
 * "…" still measures its full width), past a pixel; up and down, the glyphs' ink, which Nastaliq takes well above and
 * below its line, past half a pixel (a kāf's thin top stroke cut that close reads as a lām): measured on a canvas in the
 * text's own font (actualBoundingBox) and placed from its line's box (fontBoundingBox), as urduD255.mjs's fieldClear
 * measures a placeholder (it matches the pixels drawn within a pixel).
 * Not cut: a text scrolled (partly) out of a list that scrolls, or out of the window up or down; and the scene's data
 * (`data`: a name, a title, a file's name, a preview) truncated on purpose, with "…" or a clamp of its lines. Ink
 * that crosses a control's own border (a button, a tab, a menu item) without anything clipping it is a note, as is
 * a control's words reaching past its box. Returns { cut: [{ what, text, why }], notes: [{ what, text, why }] }.
 */
export const cutTexts = (page, root, data = []) => page.evaluate(({ root, data }) => {
  const cut = [];
  const notes = [];
  const scope = document.querySelector(root);
  if (!scope) return { cut: [{ what: "scene", text: root, why: "is not on the page" }], notes };
  const CONTROLS = 'button, [role="button"], [role="tab"], [role="menuitem"], [role="menuitemradio"], [role="menuitemcheckbox"], [role="option"]';
  const escape = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const names = [...data].sort((a, b) => b.length - a.length).map(escape);
  const dataPattern = names.length ? new RegExp(`(?<!\\p{L})(?:${names.join("|")})(?!\\p{L})`, "gu") : null;
  const strip = (s) => s.replace(/[\u2066-\u2069\u200e\u200f]/g, "");
  /** Whether a text is only the scene's data (no word of the app's own). */
  const dataOnly = (text) => !/\p{L}/u.test(dataPattern ? strip(text).replace(dataPattern, "") : strip(text));
  const styles = new Map();
  const style = (el) => {
    if (!styles.has(el)) styles.set(el, getComputedStyle(el));
    return styles.get(el);
  };
  const hiddenMemo = new Map();
  const hidden = (el) => {
    if (!el || el === document.documentElement) return false;
    if (hiddenMemo.has(el)) return hiddenMemo.get(el);
    const s = style(el);
    const b = el.getBoundingClientRect();
    const own = s.display === "none" || s.visibility === "hidden" || Number(s.opacity) < 0.05
      || /rect\(0(px)?,? 0(px)?,? 0(px)?,? 0(px)?\)/.test(s.clip) || s.clipPath === "inset(50%)"
      || (s.overflow !== "visible" && (b.width <= 1 || b.height <= 1));
    const result = own || hidden(el.parentElement);
    hiddenMemo.set(el, result);
    return result;
  };
  /** A box's inside, where it clips (its padding box). */
  const clientBox = (el) => {
    const b = el.getBoundingClientRect();
    const left = b.left + el.clientLeft;
    const top = b.top + el.clientTop;
    return { left, top, right: left + (el.clientWidth || b.width), bottom: top + (el.clientHeight || b.height) };
  };
  const painted = (s) => s.clipPath !== "none" || /paint|strict|content/.test(s.contain);
  const clipsX = (s) => s.overflowX !== "visible" || painted(s);
  const clipsY = (s) => s.overflowY !== "visible" || painted(s);
  const scrollsX = (el, s) => /(auto|scroll)/.test(s.overflowX) && el.scrollWidth > el.clientWidth + 1;
  const scrollsY = (el, s) => /(auto|scroll)/.test(s.overflowY) && el.scrollHeight > el.clientHeight + 1;
  /** A side of a box drawn with a border line. */
  const edge = (s, side) => Number.parseFloat(s[`border${side}Width`]) >= 0.5 && s[`border${side}Style`] !== "none" && s[`border${side}Style`] !== "hidden"
    && !/^(transparent|rgba\(\d+, \d+, \d+, 0\))$/.test(s[`border${side}Color`]);
  const edged = (s) => ["Left", "Right", "Top", "Bottom"].some((side) => edge(s, side));
  const name = (el) => `${el.tagName.toLowerCase()}${el.classList[0] ? `.${el.classList[0]}` : ""}`;
  const describe = (el) => {
    const control = el.closest(CONTROLS);
    if (control && scope.contains(control)) return control.getAttribute("role") ?? control.tagName.toLowerCase();
    const heading = el.closest('h1, h2, h3, h4, h5, h6, label, legend');
    if (heading) return heading.closest('[role="dialog"]') && /^H\d$/.test(heading.tagName) ? "dialog title" : heading.tagName.toLowerCase();
    return name(el);
  };
  const ctx = document.createElement("canvas").getContext("2d");
  const segmenter = new Intl.Segmenter(undefined, { granularity: "grapheme" });
  const entries = new Map([[cut, new Map()], [notes, new Map()]]);
  const say = (list, key, what, text, why) => {
    const entry = entries.get(list).get(key);
    if (entry) {
      // The same reason on another line: the larger number stands.
      const shape = (w) => w.replace(/\d+(\.\d+)?/g, "#");
      const reasons = entry.why.split("; ");
      const same = reasons.findIndex((w) => shape(w) === shape(why));
      const size = (w) => Number.parseFloat(w.match(/\d+(\.\d+)?/)?.[0] ?? "0");
      if (same < 0) reasons.push(why);
      else if (size(why) > size(reasons[same])) reasons[same] = why;
      entry.why = reasons.join("; ");
      return;
    }
    const next = { what, text: strip(text).replace(/\s+/g, " ").trim().slice(0, 80), why };
    entries.get(list).set(key, next);
    list.push(next);
  };
  const docScrolls = document.scrollingElement && document.scrollingElement.scrollHeight > innerHeight + 1;
  const walker = document.createTreeWalker(scope, NodeFilter.SHOW_TEXT);
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    const text = node.textContent;
    const parent = node.parentElement;
    if (!text.trim() || !parent || hidden(parent)) continue;
    const s = style(parent);
    ctx.font = `${s.fontStyle} ${s.fontWeight} ${s.fontSize} ${s.fontFamily}`;
    ctx.direction = s.direction === "rtl" ? "rtl" : "ltr";
    ctx.textAlign = "left";
    const control = parent.closest(CONTROLS);
    const own = control && scope.contains(control) ? control.getBoundingClientRect() : null;
    const what = describe(parent);
    // The text's lines as laid out: each letter's box (a grapheme at a time), grouped by the line it sits on.
    const range = document.createRange();
    const lines = new Map();
    for (const { segment, index } of segmenter.segment(text)) {
      if (!/\S/.test(segment)) continue;
      range.setStart(node, index);
      range.setEnd(node, index + segment.length);
      const c = range.getClientRects()[0];
      if (!c || (c.width === 0 && c.height === 0)) continue;
      const key = Math.round(c.top);
      const line = lines.get(key);
      if (!line) lines.set(key, { start: index, end: index + segment.length, left: c.left, right: c.right, top: c.top, bottom: c.bottom });
      else Object.assign(line, { start: Math.min(line.start, index), end: Math.max(line.end, index + segment.length), left: Math.min(line.left, c.left),
        right: Math.max(line.right, c.right), top: Math.min(line.top, c.top), bottom: Math.max(line.bottom, c.bottom) });
    }
    for (const line of lines.values()) {
      const r = line;
      if (r.right - r.left < 1 || r.bottom - r.top < 1) continue;
      // The line's ink: up and down from its line box (where it starts under the top, ends over the bottom); across,
      // past its first and last letters' boxes where a stroke reaches beyond them (Nastaliq's first letter does).
      const m = ctx.measureText(text.slice(line.start, line.end));
      const under = m.fontBoundingBoxAscent - m.actualBoundingBoxAscent;
      const over = m.fontBoundingBoxDescent - m.actualBoundingBoxDescent;
      const ink = { left: r.left - Math.max(0, m.actualBoundingBoxLeft), right: r.right + Math.max(0, m.actualBoundingBoxRight - m.width), top: r.top + under, bottom: r.bottom - over };
      let scrolled = false;
      let lost = null;
      let fixedAbove = false;
      for (let e = parent; e && e !== document.documentElement && !scrolled && !fixedAbove; e = e.parentElement) {
        const es = style(e);
        // A box fixed to the window (a dialog) isn't clipped by the boxes it sits in, only by its own and the window:
        // the walk stops after it.
        fixedAbove = es.position === "fixed";
        // A card (a box with a border) the text sits in, not its own control: ink running into its border is cut by it.
        if (!lost && !(control && control.contains(e)) && edged(es)) {
          const b = clientBox(e);
          const into = Math.max(
            edge(es, "Left") ? (b.left - ink.left) - 1 : 0, edge(es, "Right") ? (ink.right - b.right) - 1 : 0,
            edge(es, "Top") ? (b.top - ink.top) - 0.5 : 0, edge(es, "Bottom") ? (ink.bottom - b.bottom) - 0.5 : 0);
          if (into > 0) {
            const side = b.left - ink.left > 1 || ink.right - b.right > 1 ? "across" : b.top - ink.top > ink.bottom - b.bottom ? "at the top" : "at the bottom";
            const px = Math.round(Math.max(b.left - ink.left, ink.right - b.right, b.top - ink.top, ink.bottom - b.bottom) * 10) / 10;
            lost = { box: e, px, truncated: false, side, border: true };
          }
        }
        if (!clipsX(es) && !clipsY(es)) continue;
        const b = clientBox(e);
        const sx = scrollsX(e, es);
        const sy = scrollsY(e, es);
        // Scrolled (partly) out of a list that scrolls that way: not shown now, so not cut.
        if ((sy && (r.top < b.top - 1 || r.bottom > b.bottom + 1)) || (sx && (r.left < b.left - 1 || r.right > b.right + 1))) {
          scrolled = true;
          break;
        }
        if (lost) continue;
        const across = clipsX(es) && !sx ? Math.max(b.left - ink.left, ink.right - b.right) : 0;
        // Up and down in a list that scrolls, only at its ends (the first line's ink at its top, the last's at its end).
        const top = clipsY(es) && (!sy || e.scrollTop <= 0) ? b.top - ink.top : 0;
        const bottom = clipsY(es) && (!sy || e.scrollTop + e.clientHeight >= e.scrollHeight - 1) ? ink.bottom - b.bottom : 0;
        // Across, past a pixel (layout rounds); up and down, past half of one: a kāf's thin top stroke is that close.
        const upDown = Math.max(top, bottom);
        if (across > 1 || upDown > 0.5) {
          // Cut on purpose: words laid out past the box and ended with "…", or lines clamped (not a letter's ink past it).
          const laidAcross = Math.max(b.left - r.left, r.right - b.right) > 1;
          const laidDown = Math.max(b.top - r.top, r.bottom - b.bottom) > 0.5;
          const truncated = (laidAcross && es.textOverflow === "ellipsis") || (laidDown && es.webkitLineClamp !== "none");
          const px = Math.round(Math.max(across, upDown) * 10) / 10;
          lost = { box: e, px, truncated, side: across >= upDown ? "across" : top >= bottom ? "at the top" : "at the bottom" };
        }
      }
      if (scrolled) continue;
      // The window: up and down a page that scrolls is scrolling; across, words past its edge are lost.
      if (!lost) {
        const across = Math.max(-ink.left, ink.right - innerWidth);
        const down = docScrolls ? 0 : Math.max(-ink.top, ink.bottom - innerHeight);
        if (across > 1 || down > 0.5) lost = { box: null, px: Math.round(Math.max(across, down) * 10) / 10, truncated: false, side: across >= down ? "across" : "up or down" };
      }
      if (lost) {
        if (lost.truncated && dataOnly(text)) continue;
        const by = lost.box ? name(lost.box) : "the window";
        say(cut, node, what, text, lost.truncated ? `cut with “…” by ${by}` : lost.border ? `runs ${lost.px} px into the border of ${by} (${lost.side})`
          : `${by} clips ${lost.px} px of it (${lost.side})`);
        continue;
      }
      // Ink past a control's own border with nothing clipping it: a note for its size, not a cut.
      if (own) {
        const past = Math.max(own.left - ink.left, ink.right - own.right, own.top - ink.top, ink.bottom - own.bottom);
        if (past > 1) {
          const where = own.top - ink.top >= past - 0.01 ? "at the top" : ink.bottom - own.bottom >= past - 0.01 ? "at the bottom" : "across";
          say(notes, control, describe(parent), control.textContent ?? text, `its ink reaches ${Math.round(past * 10) / 10} px past its border ${where}, unclipped`);
        }
      }
    }
  }
  return { cut, notes };
}, { root, data });
