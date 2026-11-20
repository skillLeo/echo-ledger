// The classic main window at the widths people use it: half of a 1440, 1512 or 1728 screen (from 800 px), 1024, 1280
// and full width, light and dark, English and Urdu (right to left). Each screen is pictured and checked in the page for
// what makes it unusable: text drawn over other text, a scroll area whose content can't be reached, a sticky header
// over a row, a control outside the window, a bar at the bottom over the last row.
//   node scripts/widthcheck.mjs --out=DIR [--only=library,recording] [--engine=webkit|chromium]
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

const WIDTHS = (arg("widths") ?? "800,1024,1280,1728").split(",").map(Number);
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

/** In the page: the problems a person would see. */
function problems() {
  const found = [];
  const vw = document.documentElement.clientWidth;
  const visible = (el) => {
    const r = el.getBoundingClientRect();
    const s = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && s.visibility !== "hidden" && s.display !== "none" && Number(s.opacity) > 0.05;
  };
  const name = (el) => `${el.tagName.toLowerCase()}.${[...el.classList].slice(0, 3).join(".")} "${(el.textContent ?? "").trim().slice(0, 30)}"`;
  // Text on top of text: leaf elements with words whose boxes cross, neither inside the other.
  const leaves = [...document.querySelectorAll("label, span, p, h1, h2, h3, div, button, a, select, option, input, .badge")]
    .filter((el) => visible(el) && ((el.matches("input, select") && (el.value ?? "") !== "") || ([...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim()))))
    .filter((el) => !el.closest("[aria-hidden=true], .sr-only, .visually-hidden, option"))
    .slice(0, 1500);
  const boxes = leaves.map((el) => ({ el, r: el.getBoundingClientRect() }));
  for (let i = 0; i < boxes.length; i++) for (let j = i + 1; j < boxes.length; j++) {
    const a = boxes[i], b = boxes[j];
    if (a.el.contains(b.el) || b.el.contains(a.el)) continue;
    const x = Math.min(a.r.right, b.r.right) - Math.max(a.r.left, b.r.left);
    const y = Math.min(a.r.bottom, b.r.bottom) - Math.max(a.r.top, b.r.top);
    if (x > 4 && y > 4) {
      // Only when both are painted where they cross (one scrolled under a sticky bar isn't seen).
      const cx = (Math.max(a.r.left, b.r.left) + Math.min(a.r.right, b.r.right)) / 2;
      const cy = (Math.max(a.r.top, b.r.top) + Math.min(a.r.bottom, b.r.bottom)) / 2;
      const top = document.elementFromPoint(cx, cy);
      const seen = (el) => top && (el.contains(top) || top.contains(el));
      if (seen(a.el) && seen(b.el)) found.push(`overlap: ${name(a.el)} × ${name(b.el)}`);
    }
  }
  // Words or controls under a bar that sticks (a sticky header on a row, the player on the recording's head): at the
  // top of a page nothing may be covered.
  for (const { el, r } of boxes) {
    if (r.top < 0 || r.bottom > innerHeight || r.left < 0 || r.right > innerWidth) continue;
    const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    if (!top || el.contains(top) || top.contains(el)) continue;
    let bar = top;
    while (bar && !/(sticky|fixed)/.test(getComputedStyle(bar).position)) bar = bar.parentElement;
    if (!bar || bar.contains(el) || bar.matches("[role=dialog], .modal, .sheet, .scrim, .toast, .drawer")) continue;
    // What scrolls under a bar is fine while scrolling can bring it out: a top bar only at the top of its scroller,
    // a bottom bar only at its end, is a fault.
    let scroller = bar.parentElement;
    while (scroller && !(/(auto|scroll)/.test(getComputedStyle(scroller).overflowY) && scroller.scrollHeight > scroller.clientHeight)) scroller = scroller.parentElement;
    const atBottomBar = getComputedStyle(bar).bottom !== "auto" && getComputedStyle(bar).top === "auto";
    const stuck = !scroller ? true : atBottomBar ? scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - 2 : scroller.scrollTop <= 0;
    if (stuck) found.push(`covered: ${name(el)} under ${name(bar)}`);
  }
  // Controls the window cuts off on the side (nothing scrolls them back).
  for (const el of document.querySelectorAll("button, select, input, a[href]")) {
    if (!visible(el)) continue;
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
    if (s.overflowY === "hidden" && el.scrollHeight > el.clientHeight + 24 && el.clientHeight > 120 && !el.closest("[aria-hidden=true]")) {
      const inner = [...el.querySelectorAll("*")].some((c) => /(auto|scroll)/.test(getComputedStyle(c).overflowY) && c.scrollHeight > c.clientHeight);
      if (!inner) found.push(`clipped: ${name(el)} ${el.clientHeight}/${el.scrollHeight}`);
    }
  }
  // Horizontal page scroll.
  if (document.documentElement.scrollWidth > vw + 1) found.push(`page scrolls sideways: ${document.documentElement.scrollWidth} > ${vw}`);
  return [...new Set(found)].slice(0, 30);
}

const server = await createServer({ root: desktop, configFile: join(desktop, "vite.config.ts"), server: { port: 1491, strictPort: false }, logLevel: "error" });
await server.listen();
const base = server.resolvedUrls.local[0];
await mkdir(out, { recursive: true });
const report = [];
const browser = await engines[engineName].launch();
try {
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
    const found = await page.evaluate(problems);
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
await writeFile(join(out, "report.json"), JSON.stringify(report, null, 1));
