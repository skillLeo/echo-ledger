// FIX-06 follow-up: the whole Library with the keyboard alone, as a person would use it (the screens fixture, a window
// 1280 × 800, every v2 flag on), in English and Urdu, on a Mac (its window buttons drawn, ⌘) and on Windows (its own
// title row with the caption buttons, v21_win_titlebar; Ctrl). From the window's start, only keys:
//   the rail (Tab into it, ↓ to the Library, Enter), the panel's views, the title row's search (/ too), the tabs, Filters
//   (F too) and Sort & display, the list (↓ ↑ and J K from row to row), a recording (Enter) and back to its row (Esc,
//   then ⌘[ or Alt+←), selection (X, ⌘A or Ctrl+A, the selection bar's actions by Tab, Esc), and every stop with a
//   visible focus ring; on Windows Tab never lands on Minimize, Maximize or Close (the mouse's; Alt+Space has them).
// Each step says how many presses it took; a step that can't be done with keys is listed as unreachable, and the run
// fails on any.
//   npm run check:keyboard            (node scripts/keyboardLibrary.mjs)
import { createServer } from "vite";
import { chromium } from "playwright";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5259, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
const unreachable = [];
const noRing = new Set();
/** Windows' caption buttons: never a Tab stop. */
const CAPTION = /^(Minimize|Maximize|Restore|Restore down|Close)$/;

/** The control with the keyboard: its role and name, and whether a focus ring shows (its own, its field's or its icon's). */
const here = (page) => page.evaluate(() => {
  const el = document.activeElement;
  if (!el || el === document.body) return null;
  const by = (el.getAttribute("aria-labelledby") ?? "").split(/\s+/).filter(Boolean).map((id) => document.getElementById(id)?.textContent ?? "").join(" ");
  const name = (el.getAttribute("aria-label") || by || el.textContent || el.getAttribute("placeholder") || "").trim().replace(/\s+/g, " ");
  const drawn = (node) => {
    if (!node) return false;
    const s = getComputedStyle(node);
    return (s.outlineStyle !== "none" && parseFloat(s.outlineWidth) >= 2) || /\d+px \d+px \d+px [2-9]px/.test(s.boxShadow) || /0px 0px 0px 2px/.test(s.boxShadow);
  };
  const ring = drawn(el) || drawn(el.closest(".el-input, .el-titlerow__search, .el-search")) || drawn(el.querySelector(".el-rail__icon"));
  return { role: el.getAttribute("role") || el.tagName.toLowerCase(), name: name.slice(0, 60), ring, row: el.closest("[data-row-id]")?.getAttribute("data-row-id") ?? null };
});

async function walk(lang, platform) {
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, reducedMotion: "reduce" });
  page.setDefaultTimeout(120_000);
  const mod = platform === "windows" ? "Control" : "Meta";
  const where = platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1";
  const flags = platform === "windows" ? `${V2_FLAGS},v21_win_titlebar` : V2_FLAGS;
  await page.goto(`${base}scripts/screens/index.html?screen=library&uiflags=${flags}${where}${lang === "ur" ? "&lang=ur" : ""}`);
  await page.getByRole("navigation", { name: "Main" }).waitFor();
  await page.waitForTimeout(1500);
  const log = [];
  const tag = `${lang} ${platform}`;
  const say = (text) => log.push(`${tag}: ${text}`);
  /** Tab (or Shift+Tab) until the control matches, at most `limit` presses. */
  const tabTo = async (test, label, { back = false, limit = 60 } = {}) => {
    for (let i = 1; i <= limit; i++) {
      await page.keyboard.press(back ? "Shift+Tab" : "Tab");
      const at = await here(page);
      if (at && !at.ring) noRing.add(`${tag}: ${at.role} "${at.name}"`);
      if (at && CAPTION.test(at.name)) unreachable.push(`${tag}: Tab landed on the caption button ${at.name}`);
      if (at && test(at)) {
        say(`${label}: ${i} ${back ? "Shift+Tab" : "Tab"}`);
        return at;
      }
    }
    unreachable.push(`${tag}: ${label} (not reached in ${limit} presses)`);
    say(`${label}: NOT REACHED`);
    return null;
  };
  const expectAt = async (test, label) => {
    const at = await here(page);
    if (at && test(at)) say(`${label}: yes${at.ring ? "" : " (NO RING)"}`);
    else {
      unreachable.push(`${tag}: ${label} (focus on ${at ? `${at.role} "${at.name}"` : "nothing"})`);
      say(`${label}: NO (focus on ${at ? `${at.role} "${at.name}"` : "nothing"})`);
    }
    if (at && !at.ring) noRing.add(`${tag}: ${at.role} "${at.name}"`);
    return at;
  };
  const press = async (key, wait = 300) => {
    await page.keyboard.press(key);
    await page.waitForTimeout(wait);
  };

  // The rail: one stop; ↓ to the Library, Enter.
  await page.locator("body").focus();
  await tabTo((a) => !!a.name && (a.name.startsWith("Library") || a.name.startsWith("Record")), "the rail");
  for (let i = 0; i < 6; i++) {
    const at = await here(page);
    if (at?.name.startsWith("Library")) break;
    await press("ArrowDown", 100);
  }
  await expectAt((a) => a.name.startsWith("Library"), "↓ to the Library in the rail");
  await press("Enter", 800);
  // The panel's views.
  await tabTo((a) => /^Needs you/.test(a.name), "the panel's Needs you view");
  await press("Enter", 800);
  await tabTo((a) => /^All recordings/.test(a.name), "back to All recordings", { back: true });
  await press("Enter", 800);
  // The title row's search, then the tabs, Filters and Sort & display.
  await tabTo((a) => a.role === "input" || /searchbox|search/.test(a.role) || /^Search recordings/.test(a.name), "the title row's search");
  await page.keyboard.type("invoice");
  await page.waitForTimeout(800);
  const typedIn = await page.evaluate(() => {
    const el = document.activeElement;
    return { tag: el?.tagName, label: el?.getAttribute("aria-label"), value: el?.value, rows: document.querySelectorAll("[data-row-id]").length };
  });
  say(`typed into ${typedIn.tag} "${typedIn.label}" = "${typedIn.value}" (${typedIn.rows} rows)`);
  const found = await page.locator("[data-row-id]").count();
  say(`typed "invoice": ${found} rows`);
  if (!found) unreachable.push(`${tag}: the title row's search found nothing for "invoice"`);
  // Esc empties it (a second Esc leaves it).
  await press("Escape", 500);
  await tabTo((a) => a.role === "tab" && /^All/.test(a.name), "the tabs (All)");
  await press("ArrowRight", 300);
  const tab = await here(page);
  say(`→ on the tabs: ${tab?.name}`);
  // Back to All (Home), chosen.
  await press("Home", 300);
  await press("Enter", 600);
  const chosen = await here(page);
  if (!/^All/.test(chosen?.name ?? "")) unreachable.push(`${tag}: Home on the tabs didn't go back to All (${chosen?.name})`);
  await tabTo((a) => /^Filters/.test(a.name), "Filters");
  await press("Enter", 800);
  const panel = await page.getByTestId("filter-panel").isVisible().catch(() => false);
  say(`Filters open: ${panel ? "yes" : "NO"}`);
  if (!panel) unreachable.push(`${tag}: Filters didn't open with Enter`);
  await tabTo((a) => a.role === "checkbox" || a.role === "input", "a filter's choice", { limit: 15 });
  await press("Space", 600);
  await press("Escape", 600);
  const closed = !(await page.getByTestId("filter-panel").isVisible().catch(() => false));
  say(`Esc closes Filters: ${closed ? "yes" : "NO"}`);
  if (!closed) unreachable.push(`${tag}: Esc didn't close Filters`);
  // Clear what the filter left (the bar's Clear all), else F toggles the panel off and on.
  const clear = page.getByRole("button", { name: /^Clear all/ });
  if (await clear.count()) {
    await tabTo((a) => /^Clear all/.test(a.name), "Clear all filters", { limit: 30, back: true });
    await press("Enter", 600);
  }
  await tabTo((a) => /^Sort & display|^Sort/.test(a.name), "Sort & display");
  await press("Enter", 500);
  const menu = await page.getByRole("menu").or(page.getByRole("dialog")).first().isVisible().catch(() => false);
  say(`Sort & display opens: ${menu ? "yes" : "NO"}`);
  if (!menu) unreachable.push(`${tag}: Sort & display didn't open with Enter`);
  await press("Escape", 400);
  // The list: Tab into it, then ↓ ↑ (and J K) from row to row.
  const first = await tabTo((a) => !!a.row, "the first row");
  await press("ArrowDown", 200);
  const second = await here(page);
  say(`↓ in the list: ${second?.row && second.row !== first?.row ? "next row" : "DIDN'T MOVE"}`);
  if (!second?.row || second.row === first?.row) unreachable.push(`${tag}: ↓ doesn't move from row to row`);
  await press("ArrowUp", 200);
  const back = await here(page);
  if (back?.row !== first?.row) unreachable.push(`${tag}: ↑ doesn't go back a row`);
  await press("j", 200);
  const j = await here(page);
  if (!j?.row || j.row === first?.row) unreachable.push(`${tag}: J doesn't move from row to row`);
  await press("k", 200);
  // From the list, / and ⌘F go to the search (no Tabs back up).
  await press("/", 300);
  const slash = await here(page);
  say(`/ from the list: ${slash?.name}`);
  if (!/^Search recordings/.test(slash?.name ?? "")) unreachable.push(`${tag}: / from the list doesn't reach the search (${slash?.name})`);
  await press("Escape", 300);
  await page.locator("button[data-row-id]").first().focus();
  await page.keyboard.press(`${mod}+f`);
  await page.waitForTimeout(300);
  const find = await here(page);
  say(`${mod === "Meta" ? "⌘F" : "Ctrl+F"} from the list: ${find?.name}`);
  if (!/^Search recordings/.test(find?.name ?? "")) unreachable.push(`${tag}: ${mod === "Meta" ? "⌘F" : "Ctrl+F"} from the list doesn't reach the search (${find?.name})`);
  await press("Escape", 300);
  // Back to the list by Tab (the title row's search, the tabs, Filters, Sort & display, then the rows).
  await tabTo((a) => !!a.row, "the list again from the search");
  // A recording, and back to its row.
  const opened = await here(page);
  await press("Enter", 1500);
  const page2 = await page.locator(".el-recording__title").first().isVisible().catch(() => false);
  say(`Enter opens the recording: ${page2 ? "yes" : "NO"}`);
  if (!page2) unreachable.push(`${tag}: Enter doesn't open a recording`);
  await tabTo((a) => a.role === "button" && /^Play|^Pause/.test(a.name), "the recording's Play", { limit: 40 });
  await press("Escape", 1200);
  const after = await here(page);
  say(`Esc back to the list, on its row: ${after?.row === opened?.row ? "yes" : `NO (${after ? after.name : "nothing"})`}`);
  if (after?.row !== opened?.row) unreachable.push(`${tag}: Esc from a recording doesn't come back to its row`);
  // Again, and back with the platform's Back (⌘[ on a Mac, Alt+← on Windows) instead of Esc.
  const backKey = platform === "windows" ? "Alt+ArrowLeft" : "Meta+BracketLeft";
  await press("Enter", 1500);
  await tabTo((a) => a.role === "button" && /^Play|^Pause/.test(a.name), "the recording's Play (again)", { limit: 40 });
  await press(backKey, 1200);
  const walkedBack = await here(page);
  say(`${platform === "windows" ? "Alt+←" : "⌘["} back to the list, on its row: ${walkedBack?.row === opened?.row ? "yes" : `NO (${walkedBack ? walkedBack.name : "nothing"})`}`);
  if (walkedBack?.row !== opened?.row) unreachable.push(`${tag}: ${platform === "windows" ? "Alt+←" : "⌘["} from a recording doesn't come back to its row`);
  // Selection: X picks the row (and starts choosing), ↓ X another, the bar's actions by Tab, ⌘A, Esc.
  await press("x", 400);
  await press("ArrowDown", 200);
  await press("x", 400);
  const picked = await page.locator('button[data-row-id][aria-pressed="true"]').count();
  say(`X picks: ${picked} picked`);
  if (picked < 2) unreachable.push(`${tag}: X doesn't pick rows (${picked})`);
  await tabTo((a) => /^Delete|^Move|^Label|^Share|^More actions/.test(a.name), "the selection bar's actions", { back: true, limit: 40 });
  await page.keyboard.press(`${mod}+a`);
  await page.waitForTimeout(400);
  const all = await page.locator('button[data-row-id][aria-pressed="true"]').count();
  say(`${mod === "Meta" ? "⌘A" : "Ctrl+A"} picks all: ${all}`);
  if (all < 3) unreachable.push(`${tag}: ${mod === "Meta" ? "⌘A" : "Ctrl+A"} doesn't pick every row (${all})`);
  await press("Escape", 500);
  const none = await page.locator('button[data-row-id][aria-pressed="true"]').count();
  say(`Esc ends the selection: ${none === 0 ? "yes" : `NO (${none})`}`);
  if (none) unreachable.push(`${tag}: Esc doesn't end the selection`);
  await page.close();
  return log;
}

try {
  for (const platform of ["macos", "windows"]) for (const lang of ["en", "ur"]) console.log((await walk(lang, platform)).join("\n"));
} finally {
  await browser.close();
  await server.close();
}
if (noRing.size) console.log(`\nStops with no visible ring:\n${[...noRing].join("\n")}`);
if (unreachable.length) {
  console.error(`\nUnreachable with the keyboard:\n${unreachable.join("\n")}`);
  process.exit(1);
}
console.log("\nEverything reached with the keyboard.");
