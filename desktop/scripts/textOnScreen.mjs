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
 * The labels of a scene that are cut (D-261): the buttons, tabs, headings, labels, menu items and dialog titles inside
 * the first element `root` matches, in the window and in their scroller's view. A label is cut when its words reach
 * past its own box across, or a box they sit in clips them (overflow other than visible, a clip-path, a line cut with
 * "…"), or a control's ink reaches more than 3 px past its box up or down. Across, the words' boxes as laid out; up and
 * down, the glyphs' ink, which Nastaliq takes well above and below its line: measured on a canvas in the text's own
 * font (actualBoundingBox) and placed from its line's box (fontBoundingBox), as urduD255.mjs's fieldClear measures a
 * placeholder (it matches the pixels drawn within a pixel). A control's ink reaching 1 to 3 px past its border (the
 * design system's 40 px button round Nastaliq's tall kāf, D4) is a note, not a cut. A text that is only the scene's
 * data (`data`: names, titles, a file's name) is not a label: a long name cut at its own end is the design.
 * Returns { cut: [{ what, text, why }], notes: [{ what, text, why }] }.
 */
export const cutLabels = (page, root, data = []) => page.evaluate(({ root, data }) => {
  const cut = [];
  const notes = [];
  const scope = document.querySelector(root);
  if (!scope) return { cut: [{ what: "scene", text: root, why: "is not on the page" }], notes };
  const LABELS = 'button, [role="button"], [role="tab"], [role="menuitem"], [role="menuitemradio"], [role="menuitemcheckbox"], h1, h2, h3, h4, h5, h6, label, legend';
  const CONTROLS = 'button, [role="button"], [role="tab"], [role="menuitem"], [role="menuitemradio"], [role="menuitemcheckbox"]';
  const escape = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const names = [...data].sort((a, b) => b.length - a.length).map(escape);
  const dataPattern = names.length ? new RegExp(`(?<!\\p{L})(?:${names.join("|")})(?!\\p{L})`, "gu") : null;
  const strip = (s) => s.replace(/[\u2066-\u2069\u200e\u200f]/g, "");
  const ownWords = (text) => /\p{L}/u.test(dataPattern ? strip(text).replace(dataPattern, "") : strip(text));
  const hidden = (el) => {
    for (let e = el; e && e !== document.documentElement; e = e.parentElement) {
      const s = getComputedStyle(e);
      if (s.display === "none" || s.visibility === "hidden" || Number(s.opacity) < 0.05) return true;
      if (/rect\(0(px)?,? 0(px)?,? 0(px)?,? 0(px)?\)/.test(s.clip) || s.clipPath === "inset(50%)") return true;
      const b = e.getBoundingClientRect();
      if (s.overflow !== "visible" && (b.width <= 1 || b.height <= 1)) return true;
    }
    return false;
  };
  const inside = (a, b) => a.left >= b.left - 1 && a.right <= b.right + 1 && a.top >= b.top - 1 && a.bottom <= b.bottom + 1;
  /** A box's inside, where it clips (its padding box). */
  const clientBox = (el) => {
    const b = el.getBoundingClientRect();
    const left = b.left + el.clientLeft;
    const top = b.top + el.clientTop;
    return { left, top, right: left + (el.clientWidth || b.width), bottom: top + (el.clientHeight || b.height) };
  };
  const clips = (s) => s.overflowX !== "visible" || s.overflowY !== "visible" || s.clipPath !== "none" || /paint|strict|content/.test(s.contain);
  const scrolls = (s) => /(auto|scroll)/.test(`${s.overflowX} ${s.overflowY}`);
  const name = (el) => `${el.tagName.toLowerCase()}${el.classList[0] ? `.${el.classList[0]}` : ""}`;
  const describe = (el) => {
    const kind = el.getAttribute("role") ?? el.tagName.toLowerCase();
    return el.closest('[role="dialog"]') && /^h\d$/.test(kind) ? "dialog title" : kind;
  };
  const ctx = document.createElement("canvas").getContext("2d");
  // One entry per label, its reasons together.
  const entries = new Map([[cut, new Map()], [notes, new Map()]]);
  const say = (list, label, words, why) => {
    const entry = entries.get(list).get(label);
    if (entry) {
      if (!entry.why.split("; ").includes(why)) entry.why += `; ${why}`;
      return;
    }
    const next = { what: describe(label), text: words.slice(0, 80), why };
    entries.get(list).set(label, next);
    list.push(next);
  };
  const view = { left: 0, top: 0, right: innerWidth, bottom: innerHeight };
  for (const label of scope.querySelectorAll(LABELS)) {
    if (hidden(label)) continue;
    const box = label.getBoundingClientRect();
    if (box.width < 2 || box.height < 2 || !inside(box, view)) continue;
    // Scrolled (part) out of its list: not shown, so not cut.
    let away = false;
    for (let e = label.parentElement; e && e !== document.documentElement && !away; e = e.parentElement) {
      if (scrolls(getComputedStyle(e)) && !inside(box, clientBox(e))) away = true;
    }
    if (away) continue;
    const words = strip(label.textContent ?? "").replace(/\s+/g, " ").trim();
    if (!words || !ownWords(words)) continue;
    const control = label.matches(CONTROLS);
    const walker = document.createTreeWalker(label, NodeFilter.SHOW_TEXT);
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
      const text = node.textContent.trim();
      const parent = node.parentElement;
      if (!text || !parent || hidden(parent)) continue;
      const s = getComputedStyle(parent);
      ctx.font = `${s.fontStyle} ${s.fontWeight} ${s.fontSize} ${s.fontFamily}`;
      ctx.direction = s.direction === "rtl" ? "rtl" : "ltr";
      const m = ctx.measureText(text);
      // Where the ink starts under its line box's top and ends over its bottom (negative: past it).
      const under = m.fontBoundingBoxAscent - m.actualBoundingBoxAscent;
      const over = m.fontBoundingBoxDescent - m.actualBoundingBoxDescent;
      const range = document.createRange();
      range.selectNodeContents(node);
      for (const r of range.getClientRects()) {
        if (r.width < 1) continue;
        const ink = { left: r.left, right: r.right, top: r.top + under, bottom: r.bottom - over };
        const across = Math.max(box.left - ink.left, ink.right - box.right);
        if (across > 1) say(cut, label, words, `its words reach ${Math.round(across)} px past its box`);
        if (control) {
          const up = Math.max(box.top - ink.top, ink.bottom - box.bottom);
          if (up > 3) say(cut, label, words, `its ink reaches ${Math.round(up)} px past its box ${box.top - ink.top >= ink.bottom - box.bottom ? "at the top" : "at the bottom"}`);
          else if (up > 1) say(notes, label, words, `its ink reaches ${Math.round(up * 10) / 10} px past its border ${box.top - ink.top >= ink.bottom - box.bottom ? "at the top" : "at the bottom"}`);
        }
        for (let e = parent; e && e !== document.documentElement; e = e.parentElement) {
          const es = getComputedStyle(e);
          if (!clips(es)) continue;
          const c = clientBox(e);
          const clipped = Math.max(c.left - ink.left, ink.right - c.right, c.top - ink.top, ink.bottom - c.bottom);
          if (clipped > 1) {
            say(cut, label, words, `${e === label ? "its own box" : name(e)} clips ${Math.round(clipped)} px of it`);
            break;
          }
        }
      }
    }
    // A line cut with "…" (or simply hidden past its end) inside the label.
    for (const part of [label, ...label.querySelectorAll("*")]) {
      const s = getComputedStyle(part);
      if (!/(hidden|clip)/.test(s.overflowX) || hidden(part) || !ownWords(part.textContent ?? "")) continue;
      if (part.scrollWidth > part.clientWidth + 1) say(cut, label, words, `${part === label ? "it" : name(part)} is cut at its end (${part.scrollWidth} px in ${part.clientWidth})`);
    }
  }
  return { cut, notes };
}, { root, data });
