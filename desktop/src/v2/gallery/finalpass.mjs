// The desktop v2 final pass: every v2 screen of the app (cases.mjs GATE3) with every v2 flag on, as a person would
// meet it, checked for what breaks.
//   looks:  light and dark in English and light right to left (lang=ur), at 100 % and 200 % text, in a Windows-sized
//           window (1366 x 768) and a small one (720 x 560); each picture gets the bug bash's checks (checks.mjs).
//   clicks: every visible control on the screen (light, English, 1366, 100 %) is pressed once on a fresh page; one that
//           changes nothing on the page and asks nothing of the core is a dead click to look at.
//   keys:   Tab from the top through the screen; focus that sticks, or keeps going round a few controls with no dialog
//           open, is a trap; a dialog that lets focus out of it is one too.
//   node src/v2/gallery/finalpass.mjs --out=DIR [--screen=file-name] [--only=looks|clicks|keys]
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir, writeFile } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { CASES, GATE3 } from "./cases.mjs";
import { audit } from "./checks.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..", "..", "..");
const arg = (name) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split("=")[1];
const out = resolve(arg("out") ?? "finalpass");
const onlyScreen = arg("screen");
const only = arg("only");
/** --part=2/3: the second of three runs side by side (every third screen). */
const part = arg("part")?.split("/").map(Number);
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";

const LOOKS = [["light", "en"], ["dark", "en"], ["light", "ur"]];
const TEXT = [100, 200];
const WINDOWS = [[1366, 768], [720, 560]];

const server = await createServer({ root: desktop, configFile: join(desktop, "vite.config.ts"), server: { port: 1470, strictPort: false }, logLevel: "error" });
await server.listen();
const base = server.resolvedUrls.local[0];
await mkdir(out, { recursive: true });
const browser = await chromium.launch();

/** A fresh page at the screen: [page, problems on the way]. */
async function open([, , path, , step], { theme = "light", lang = "en", text = 100, size = [1366, 768] } = {}) {
  const scale = text / 100;
  const page = await browser.newPage({ viewport: { width: Math.round(size[0] / scale), height: Math.round(size[1] / scale) }, deviceScaleFactor: scale, colorScheme: theme, reducedMotion: "reduce" });
  const problems = [];
  page.on("pageerror", (e) => problems.push(`script error: ${e.message.split("\n")[0]}`));
  await page.goto(`${base}${path}&theme=${theme}${lang === "ur" ? "&lang=ur" : ""}`);
  await page.waitForTimeout(2200);
  try {
    if (step) await step(page);
  } catch (e) {
    problems.push(`step failed: ${String(e.message).split("\n")[0]}`);
  }
  await page.waitForTimeout(300);
  return [page, problems];
}

/** In the page: the visible, enabled controls, each with a way to find it again and its name. */
function listControls() {
  const visible = (el) => {
    const r = el.getBoundingClientRect();
    const s = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && s.visibility !== "hidden" && s.display !== "none" && !el.closest("[aria-hidden=true], .el-visually-hidden")
      // With a modal open only its own controls can be pressed; the page behind it rightly can't.
      && (!modal || modal.contains(el));
  };
  const modal = document.querySelector("[aria-modal=true]");
  const all =[...document.querySelectorAll("button, a[href], [role=button], [role=switch], [role=tab], [role=radio], [role=combobox], [role=menuitem], summary")];
  return all.filter((el) => visible(el) && !el.disabled && el.getAttribute("aria-disabled") !== "true").map((el, index) => ({
    index,
    name: (el.getAttribute("aria-label") || el.textContent || el.getAttribute("title") || "").trim().replace(/\s+/g, " ").slice(0, 70),
    tag: el.tagName.toLowerCase(),
    role: el.getAttribute("role") ?? "",
    // Pressing what is already chosen (the current page, the selected option) rightly changes nothing.
    already: el.getAttribute("aria-current") !== null && el.getAttribute("aria-current") !== "false"
      || el.getAttribute("aria-checked") === "true" || el.getAttribute("aria-selected") === "true"
      || (/Opens Settings$/.test(el.getAttribute("aria-label") || el.textContent || "") && !!document.querySelector("[data-v2=settings], [data-v2=settings-page]")),
  }));
}

/** In the page: starts counting what a click changes (the page's DOM, focus, the core's commands). */
function watch() {
  const w = window;
  w.__changes = 0;
  w.__calls = 0;
  new MutationObserver((m) => { w.__changes += m.length; }).observe(document.body, { subtree: true, childList: true, attributes: true, characterData: true });
  const internals = w.__TAURI_INTERNALS__;
  if (internals && !internals.__watched) {
    const invoke = internals.invoke;
    internals.invoke = (...a) => { w.__calls += 1; return invoke(...a); };
    internals.__watched = true;
  }
  w.__focus = document.activeElement;
  w.__scrolled = false;
  document.addEventListener("scroll", () => { w.__scrolled = true; }, { capture: true });
}

async function looks(entry, c) {
  const found = [];
  for (const [theme, lang] of LOOKS) for (const text of TEXT) for (const size of WINDOWS) {
    const [page, problems] = await open(c, { theme, lang, text, size });
    const file = `${entry[0]}-${theme}-${lang}-t${text}-w${size[0]}.png`;
    await page.screenshot({ path: join(out, file) });
    const issues = [...(await page.evaluate(audit)), ...problems];
    if (issues.length) found.push({ file, issues });
    await page.close();
  }
  return found;
}

async function clicks(entry, c) {
  const [first] = await open(c);
  const controls = await first.evaluate(listControls);
  await first.close();
  const dead = [];
  for (const control of controls) {
    if (control.already) continue;
    const [page, problems] = await open(c);
    const now = await page.evaluate(listControls);
    const same = now.find((x) => x.index === control.index && x.name === control.name);
    if (!same) { await page.close(); continue; }
    await page.evaluate(watch);
    let chooser = false;
    page.on("filechooser", () => { chooser = true; });
    const target = await pick(page, control.index);
    try {
      await target.click({ timeout: 3000 });
    } catch (e) {
      dead.push({ control: control.name, why: `can't be pressed: ${String(e.message).split("\n")[0]}` });
      await page.close();
      continue;
    }
    await page.waitForTimeout(500);
    const [changes, calls, focusMoved, popups, scrolled] = await page.evaluate(() => [window.__changes, window.__calls, document.activeElement !== window.__focus, document.querySelectorAll("[role=dialog], [role=alertdialog], [role=menu], [role=listbox]").length, window.__scrolled]);
    if (changes === 0 && calls === 0 && popups === 0 && !scrolled && !chooser) dead.push({ control: control.name, why: `nothing changed (focus ${focusMoved ? "moved" : "stayed"})`, problems });
    await page.close();
  }
  return { count: controls.length, dead };
}
/** The index-th visible enabled control, found the same way listControls counts them. */
async function pick(page, index) {
  const all = await page.$$("button, a[href], [role=button], [role=switch], [role=tab], [role=radio], [role=combobox], [role=menuitem], summary");
  let i = -1;
  for (const h of all) {
    const ok = await h.evaluate((el) => {
      const r = el.getBoundingClientRect();
      const s = getComputedStyle(el);
      const modal = document.querySelector("[aria-modal=true]");
      return r.width > 0 && r.height > 0 && s.visibility !== "hidden" && s.display !== "none" && !el.closest("[aria-hidden=true], .el-visually-hidden") && !el.disabled && el.getAttribute("aria-disabled") !== "true"
        && (!modal || modal.contains(el));
    });
    if (ok && ++i === index) return h;
  }
  throw new Error(`control ${index} not found`);
}

async function keys(entry, c) {
  const [page] = await open(c);
  await page.evaluate(() => { document.activeElement?.blur?.(); window.scrollTo(0, 0); });
  const seen = [];
  for (let n = 0; n < 160; n++) {
    await page.keyboard.press("Tab");
    seen.push(await page.evaluate(() => {
      const el = document.activeElement;
      if (!el || el === document.body) return "(page)";
      const byIds = (el.getAttribute("aria-labelledby") || "").split(/\s+/).map((id) => document.getElementById(id)?.textContent ?? "").join(" ");
      const label = el.id ? document.querySelector(`label[for="${CSS.escape(el.id)}"]`)?.textContent : "";
      const name = (el.getAttribute("aria-label") || byIds.trim() || label || el.textContent || el.getAttribute("placeholder") || el.tagName).trim().replace(/\s+/g, " ").slice(0, 50);
      const modal = !!el.closest("[aria-modal=true]");
      return `${modal ? "[dialog] " : ""}${el.tagName.toLowerCase()} ${name}`;
    }));
  }
  await page.close();
  const traps = [];
  // Stuck: the same element five times running.
  for (let i = 4; i < seen.length; i++) if (seen.slice(i - 4, i + 1).every((x) => x === seen[i]) && seen[i] !== "(page)") { traps.push(`focus sticks on ${seen[i]}`); break; }
  // Round in circles: the last 60 presses visit few distinct places, none of them the sidebar's Home, and no dialog.
  const tail = seen.slice(-60);
  const distinct = new Set(tail);
  const nav = [...distinct].some((x) => /Home/.test(x));
  if (!nav && ![...distinct].some((x) => x.startsWith("[dialog]")) && distinct.size < 40) traps.push(`focus goes round ${distinct.size} places and never back to the sidebar: ${[...distinct].slice(0, 6).join(" | ")}`);
  return { stops: new Set(seen).size, traps };
}

const report = [];
try {
  for (const [n, entry] of GATE3.entries()) {
    if (onlyScreen && entry[0] !== onlyScreen) continue;
    if (part && n % part[1] !== part[0] - 1) continue;
    const c = CASES.find(([name]) => name === entry[1]);
    const row = { screen: entry[0], title: entry[2] };
    if (!only || only === "looks") row.looks = await looks(entry, c);
    if (!only || only === "clicks") row.clicks = await clicks(entry, c);
    if (!only || only === "keys") row.keys = await keys(entry, c);
    report.push(row);
    console.log(entry[0], JSON.stringify({ looks: row.looks?.length, dead: row.clicks?.dead.length, traps: row.keys?.traps }));
  }
} finally {
  await browser.close();
  await server.close();
}
await writeFile(join(out, `report${onlyScreen ? `-${onlyScreen}` : ""}${only ? `-${only}` : ""}${part ? `-part${part[0]}` : ""}.json`), JSON.stringify(report, null, 1));
