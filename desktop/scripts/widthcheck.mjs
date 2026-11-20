// The classic main window at the widths people use it: half of a 1440, 1512 or 1728 screen (from 800 px), 1024, 1280
// and full width, light and dark, English and Urdu (right to left). Each screen is pictured and checked in the page for
// what makes it unusable: text drawn over other text, a scroll area whose content can't be reached, a sticky header
// over a row, a control outside the window, a bar at the bottom over the last row.
// It first proves on a real page that its checks catch each kind of fault, and exits 1 when they don't or when any
// picture has a problem.
//   node scripts/widthcheck.mjs --out=DIR [--only=library,recording] [--engine=webkit|chromium] [--widths=720,800]
import { createServer } from "vite";
import { chromium, webkit } from "playwright";
import { mkdir, writeFile } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const arg = (name) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split("=")[1];
const out = resolve(arg("out") ?? "widthcheck");
const only = arg("only")?.split(",");
const engines = { webkit, chromium };
const engineName = arg("engine") ?? "webkit";
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";

const WIDTHS = (arg("widths") ?? "720,756,800,1024,1280,1728").split(",").map(Number);
const HEIGHT = 900;
const THEMES = (arg("themes") ?? "light,dark").split(",");
const LANGS = (arg("langs") ?? "en,ur").split(",");
/** The sample Library reopens its last recording; on a narrow page that recording takes the list's place (Back). */
const openRecording = async (p) => {
  if (await p.locator(".library.has-open").count()) return;
  await p.locator(".item").first().click();
  await p.waitForTimeout(1200);
};
const showList = async (p) => {
  const back = p.locator(".detail-back");
  if (await back.isVisible()) { await back.click(); await p.waitForTimeout(400); }
  // At rest: every scroller back at its top.
  await p.evaluate(() => document.querySelectorAll("*").forEach((el) => { if (el.scrollTop) el.scrollTop = 0; }));
  await p.waitForTimeout(200);
};
/** [name, fixture screen, steps to reach it] */
const SCREENS = [
  ["library", "library", showList],
  ["recording", "library", openRecording],
  ["recording-scrolled", "library", async (p) => {
    await openRecording(p);
    await p.evaluate(() => document.querySelectorAll(".detail, .content").forEach((el) => el.scrollTo?.(0, el.scrollHeight)));
    await p.waitForTimeout(300);
  }],
  ["projects", "projects", null],
  ["project", "project", null],
  ["activity", "activity", null],
  ["shared", "shared", null],
  ["record", "record", null],
  ["settings", "settings", null],
  ["settings-account", "settings-account", null],
].filter(([name]) => !only || only.includes(name));

/**
 * In the page: the problems a person would see. Words are measured as the text itself (each text node's line boxes; a
 * field's content box), not as their elements, so a word drawn over a select's value is found and a select's own
 * padding is not. Nothing is skipped for aria-hidden: what is hidden from a screen reader is still on screen.
 */
function problems() {
  const found = [];
  const vw = document.documentElement.clientWidth;
  const shown = (el) => {
    for (let e = el; e && e !== document.documentElement; e = e.parentElement) {
      const s = getComputedStyle(e);
      if (s.display === "none" || s.visibility === "hidden" || Number(s.opacity) < 0.05) return false;
    }
    const r = el.getBoundingClientRect();
    return r.width > 1 && r.height > 1;
  };
  const name = (el) => `${el.tagName.toLowerCase()}.${[...el.classList].slice(0, 3).join(".")} "${(el.value || el.textContent || "").trim().slice(0, 30)}"`;
  /** The part of a box its scroll areas leave in sight (a word scrolled out of a list is not on screen). */
  const inSight = (el, r) => {
    let { left, top, right, bottom } = r;
    for (let e = el.parentElement; e; e = e.parentElement) {
      if (getComputedStyle(e).overflow === "visible") continue;
      const c = e.getBoundingClientRect();
      left = Math.max(left, c.left); top = Math.max(top, c.top); right = Math.min(right, c.right); bottom = Math.min(bottom, c.bottom);
    }
    left = Math.max(left, 0); top = Math.max(top, 0); right = Math.min(right, innerWidth); bottom = Math.min(bottom, innerHeight);
    return right - left > 1 && bottom - top > 1 ? new DOMRect(left, top, right - left, bottom - top) : null;
  };
  /** Every run of words on screen: its element and its boxes. */
  const words = [];
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let n = walker.nextNode(); n; n = walker.nextNode()) {
    const owner = n.parentElement;
    if (!n.textContent.trim() || !owner || owner.closest("option, script, style, select") || !shown(owner)) continue;
    const range = document.createRange();
    range.selectNodeContents(n);
    const rects = [...range.getClientRects()].map((r) => inSight(owner, r)).filter(Boolean);
    if (rects.length) words.push({ el: owner, rects });
  }
  for (const el of document.querySelectorAll("select, input:not([type=checkbox]):not([type=radio]):not([type=range]), textarea")) {
    const value = el.tagName === "SELECT" ? el.selectedOptions?.[0]?.textContent : el.value || el.placeholder;
    if (!value?.trim() || !shown(el)) continue;
    const r = el.getBoundingClientRect();
    const s = getComputedStyle(el);
    const px = (v) => Number.parseFloat(v) || 0;
    const left = r.left + px(s.borderLeftWidth) + px(s.paddingLeft);
    const right = r.right - px(s.borderRightWidth) - px(s.paddingRight);
    // The value starts at the start side; only its first few characters' width is certain to be drawn.
    const ltr = s.direction !== "rtl";
    const box = new DOMRect(ltr ? left : Math.max(left, right - 24), r.top + 4, Math.min(24, right - left), r.height - 8);
    const seen = box.width > 1 ? inSight(el, box) : null;
    if (seen) words.push({ el, rects: [seen] });
  }
  const cross = (a, b) => Math.min(a.right, b.right) - Math.max(a.left, b.left) > 3 && Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 3;
  const paintedAt = (el, x, y) => {
    const top = document.elementFromPoint(x, y);
    return !!top && (el.contains(top) || top.contains(el) || top === el);
  };
  const barOf = (el) => { for (let e = el; e; e = e.parentElement) if (/(sticky|fixed)/.test(getComputedStyle(e).position)) return e; return null; };
  const opaque = (el) => {
    const m = getComputedStyle(el).backgroundColor.match(/rgba?\(([^)]+)\)/);
    const parts = m ? m[1].split(/[ ,/]+/).filter(Boolean) : [];
    return parts.length === 3 || (parts.length === 4 && Number(parts[3]) >= 0.99);
  };
  // Words on top of words: either one painted where they cross is one too many (the other shows through or is lost).
  // Words that scrolled under a solid bar (a sticky header, the player) are hidden, not overlapped; reaching them is
  // checked by coveredWhenReached. Under a see-through bar they show through: that is an overlap.
  for (let i = 0; i < words.length; i++) for (let j = i + 1; j < words.length; j++) {
    const a = words[i], b = words[j];
    if (a.el === b.el) continue;
    const ba = barOf(a.el), bb = barOf(b.el);
    if (ba !== bb && ((ba && !ba.contains(b.el) && opaque(ba)) || (bb && !bb.contains(a.el) && opaque(bb)))) continue;
    for (const ra of a.rects) for (const rb of b.rects) {
      if (!cross(ra, rb)) continue;
      const x = (Math.max(ra.left, rb.left) + Math.min(ra.right, rb.right)) / 2;
      const y = (Math.max(ra.top, rb.top) + Math.min(ra.bottom, rb.bottom)) / 2;
      if (paintedAt(a.el, x, y) || paintedAt(b.el, x, y)) found.push(`overlap: ${name(a.el)} × ${name(b.el)}`);
    }
  }
  // Controls the window cuts off on the side (nothing scrolls them back).
  for (const el of document.querySelectorAll("button, select, input, a[href]")) {
    if (!shown(el)) continue;
    const r = el.getBoundingClientRect();
    if (r.right > vw + 1 || r.left < -1) {
      let p = el.parentElement, scrolls = false;
      while (p) { if (/(auto|scroll)/.test(getComputedStyle(p).overflowX) && p.scrollWidth > p.clientWidth) { scrolls = true; break; } p = p.parentElement; }
      if (!scrolls) found.push(`off-screen: ${name(el)} ${Math.round(r.left)}..${Math.round(r.right)} of ${vw}`);
    }
  }
  // Content below the window that no scroll area reaches.
  for (const el of document.querySelectorAll("body *")) {
    const s = getComputedStyle(el);
    if (s.overflowY === "hidden" && el.scrollHeight > el.clientHeight + 24 && el.clientHeight > 120) {
      const inner = [...el.querySelectorAll("*")].some((c) => /(auto|scroll)/.test(getComputedStyle(c).overflowY) && c.scrollHeight > c.clientHeight);
      if (!inner) found.push(`clipped: ${name(el)} ${el.clientHeight}/${el.scrollHeight}`);
    }
  }
  if (document.documentElement.scrollWidth > vw + 1) found.push(`page scrolls sideways: ${document.documentElement.scrollWidth} > ${vw}`);
  return [...new Set(found)].slice(0, 30);
}

/**
 * In the page: brings each control and row of the page into view as the keyboard or a link would (scrollIntoView,
 * "nearest") and says which one a sticky or fixed bar then covers (a group header over a row, the player over a line).
 */
function coveredWhenReached() {
  const found = [];
  const sticky = (el) => { for (let e = el; e; e = e.parentElement) if (/(sticky|fixed)/.test(getComputedStyle(e).position)) return e; return null; };
  const name = (el) => `${el.tagName.toLowerCase()}.${[...el.classList].slice(0, 3).join(".")} "${(el.textContent || "").trim().slice(0, 30)}"`;
  const scrollers = [...document.querySelectorAll("*")].filter((el) => /(auto|scroll)/.test(getComputedStyle(el).overflowY));
  const saved = scrollers.map((el) => el.scrollTop);
  const targets = [...document.querySelectorAll(".content button, .content [data-row], .content select, .content input, .content .seg-text")]
    .filter((el) => { const r = el.getBoundingClientRect(); return r.width > 1 && r.height > 1 && !sticky(el); }).slice(0, 120);
  // Reached from below (every scroller at its end first: it lands at the top, under a header) and from above (it lands
  // at the bottom, over a bottom bar).
  for (const [el, from] of targets.flatMap((el) => [[el, "end"], [el, "start"]])) {
    scrollers.forEach((sc) => { sc.scrollTop = from === "end" ? sc.scrollHeight : 0; });
    el.scrollIntoView({ block: "nearest", inline: "nearest" });
    const r = el.getBoundingClientRect();
    for (const y of from === "end" ? [r.top + 3] : [r.bottom - 3]) {
      const top = document.elementFromPoint(r.left + r.width / 2, y);
      if (!top || el.contains(top) || top.contains(el)) continue;
      const bar = sticky(top);
      if (bar && !bar.contains(el) && !bar.matches("[role=dialog], .modal, .sheet, .scrim, .toast, .drawer")) { found.push(`covered when reached: ${name(el)} under ${name(bar)}`); break; }
    }
  }
  scrollers.forEach((el, i) => { el.scrollTop = saved[i]; });
  return [...new Set(found)].slice(0, 20);
}

/** The Library's J / K: each row the cursor lands on must be in sight, under the group header, not behind it. */
async function keyboardRows(page) {
  const found = [];
  if (!(await page.locator(".list .item").first().isVisible().catch(() => false))) return found;
  await page.locator("body").click({ position: { x: 1, y: 1 } }).catch(() => undefined);
  for (let i = 0; i < 12; i++) {
    await page.keyboard.press("j");
    await page.waitForTimeout(60);
    const bad = await page.evaluate(() => {
      const row = document.querySelector(".item.cursor");
      if (!row) return null;
      const r = row.getBoundingClientRect();
      const top = document.elementFromPoint(r.left + r.width / 2, r.top + 4);
      return top && !row.contains(top) && !top.contains(row) ? `J/K: "${row.textContent.trim().slice(0, 30)}" under ${top.className}` : null;
    });
    if (bad) found.push(bad);
  }
  return [...new Set(found)];
}

/**
 * The checks must catch the faults they are for: on a real page, a word drawn over a select's value (the Project chip
 * as it was), a word over a row's title and a row behind a sticky header must each be found. Otherwise the run fails.
 */
async function selfTest(base) {
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  await page.goto(`${base}scripts/screens/index.html?screen=library&theme=light`);
  await page.waitForTimeout(1500);
  const caught = await page.evaluate(({ problems, covered }) => {
    const run = (src) => (0, eval)(`(${src})`)();
    const results = {};
    // 1. A word on a select's value: the select keeps no room for it.
    const label = document.createElement("label");
    label.style.cssText = "position:fixed;left:400px;top:300px;display:inline-flex;z-index:50";
    label.innerHTML = '<span aria-hidden="true" style="position:absolute;left:8px;top:8px">Project</span><select style="padding:0 0 0 2px;height:34px;width:220px"><option>Inbox and more words</option></select>';
    document.body.appendChild(label);
    results.selectWord = run(problems).some((p) => p.startsWith("overlap:"));
    label.remove();
    // 2. A word over a row's title.
    const title = document.querySelector(".item-title");
    const r = title.getBoundingClientRect();
    const over = document.createElement("span");
    over.textContent = "Overlapping words";
    over.style.cssText = `position:fixed;left:${r.left + 4}px;top:${r.top}px;z-index:50;font-size:15px`;
    document.body.appendChild(over);
    results.rowTitle = run(problems).some((p) => p.startsWith("overlap:"));
    over.remove();
    // 3. A sticky header with no scroll padding for it: a row brought into view lands behind it.
    const head = document.querySelector(".group-head");
    const list = head?.closest(".list");
    if (list) {
      list.style.scrollPaddingTop = "0px";
      head.style.height = "120px";
      results.stickyRow = run(covered).length > 0;
      list.style.scrollPaddingTop = "";
      head.style.height = "";
    }
    return results;
  }, { problems: problems.toString(), covered: coveredWhenReached.toString() });
  await page.close();
  const missed = Object.entries(caught).filter(([, ok]) => !ok).map(([k]) => k);
  if (missed.length || Object.keys(caught).length < 3) {
    console.error(`self-test: the checks missed ${missed.join(", ") || "a case"}`);
    return false;
  }
  console.log("self-test: overlap, word on a select and covered row are caught");
  return true;
}

const server = await createServer({ root: desktop, configFile: join(desktop, "vite.config.ts"), server: { port: 1491, strictPort: false }, logLevel: "error" });
await server.listen();
const base = server.resolvedUrls.local[0];
await mkdir(out, { recursive: true });
const report = [];
const browser = await engines[engineName].launch();
let failed = 0;
try {
  if (!(await selfTest(base))) process.exit(1);
  for (const [name, screen, step] of SCREENS) for (const w of WIDTHS) for (const theme of THEMES) for (const lang of LANGS) {
    const page = await browser.newPage({ viewport: { width: w, height: HEIGHT }, colorScheme: theme });
    const errors = [];
    page.on("pageerror", (e) => errors.push(e.message.split("\n")[0]));
    await page.goto(`${base}scripts/screens/index.html?screen=${screen}&theme=${theme}${lang === "ur" ? "&lang=ur" : ""}`);
    await page.waitForTimeout(1500);
    try {
      if (step) await step(page);
    } catch (e) {
      errors.push(`step failed: ${String(e.message).split("\n")[0]}`);
    }
    const found = [...await page.evaluate(problems), ...await page.evaluate(coveredWhenReached), ...(name === "library" ? await keyboardRows(page) : [])];
    if (found.length || errors.length) failed++;
    const file = `${name}-${w}-${theme}-${lang}.png`;
    await page.screenshot({ path: join(out, file) });
    report.push({ screen: name, width: w, theme, lang, problems: found, errors, file });
    console.log(`${name} ${w} ${theme} ${lang}: ${found.length} problems${errors.length ? `, ${errors.length} errors` : ""}`);
    await page.close();
  }
} finally {
  await browser.close();
  await server.close();
}
await writeFile(join(out, "report.json"), `${JSON.stringify(report, null, 1)}\n`);
if (failed) {
  console.error(`${failed} of ${report.length} pictures have problems (report.json)`);
  process.exit(1);
}
