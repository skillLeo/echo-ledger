// UX-07, zoom like Chrome: the main window at 67% to 200% in windows 1024, 1280 and 1440 px wide (740 tall), English and
// Urdu; the least window (720×560) at 175% and 200% (and Urdu at 200%); and Windows' own title row (v21_win_titlebar)
// at 1280×740 and 720×560, 150% and 200%. As the web view draws it: a page that many CSS pixels narrower, drawn that
// much bigger (deviceScaleFactor), with v21_zoom on and that zoom kept (so the title row has its chip). Saved under
// docs/design/screens/desktop/zoom/: <width>-<zoom>-<lang>.png, 720x560-<zoom>-en.png, win-<window>-<zoom>-en.png, and
// the zoom's own parts in light and dark at 1280 and 125%: ui-avatar-row-<theme>.png (the avatar's Zoom row),
// ui-chip-menu-<theme>.png (the chip and its menu), ui-bubble-<theme>.png, ui-settings-<theme>.png (Settings ›
// Appearance › Zoom), ui-more-<theme>.png (⋯ More's menu at 1280 and 200%), 720x560-200-en-dark.png and
// 720x560-200-en-search[-dark].png (the narrow row, and its search open across it, light and dark), and
// long-<window>-<zoom>-en.png (a view with a long name: the fixture's long label, at 1024 and 1280 and in the least
// window). Measured on each: nothing wider than the window (no sideways scroll), the title row's title whole at every
// width (never cut, never an ellipsis, never under another part; beside the ⌕ button it may wrap or take a line of its
// own), the title row no wider than its room and on screen, every rail item to its last
// (Account) inside the window, Settings and Account shown, no item only partly shown (the places that don't fit whole
// are in ⋯ More, or in the avatar's menu when not even More fits; read from the menu), Activity's badge on Activity, More
// or the avatar, the chip in the row unless at 100%, nothing of the page under the window's buttons. In the narrow row,
// Search opens the field across it (the title stepping aside) with one focus ring, and Esc closes it back to Search. The
// run fails on any of that.
//   node scripts/zoom.mjs [67,100,150,200]
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = process.env.ZOOM_OUT ?? join(desktop, "..", "docs", "design", "screens", "desktop", "zoom");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const ZOOMS = process.argv[2] ? process.argv[2].split(",").map(Number) : [67, 100, 150, 175, 200];
const FLAGS = `${V2_FLAGS},v21_zoom`;
const WIDTHS = [1024, 1280, 1440];
const HEIGHT = 740;
/** Every window measured: 1024, 1280 and 1440 by 740 (Urdu at 150%); the least window, 720×560, at 175% and 200%, where
 * not even More may fit (the places are then in the avatar's menu); and Windows' own title row (v21_win_titlebar, its
 * 48 px top cell), where that comes sooner. */
const RUNS = [];
for (const width of WIDTHS) for (const zoom of ZOOMS) for (const lang of ["en", "ur"]) {
  if (lang === "ur" && zoom !== 150) continue;
  RUNS.push({ width, height: HEIGHT, zoom, lang, platform: "macos", name: `${width}-${zoom}-${lang}` });
}
for (const zoom of [175, 200].filter((z) => ZOOMS.includes(z))) RUNS.push({ width: 720, height: 560, zoom, lang: "en", platform: "macos", name: `720x560-${zoom}-en` });
// The narrow row mirrored (Urdu, right to left), and dark.
if (ZOOMS.includes(200)) RUNS.push({ width: 720, height: 560, zoom: 200, lang: "ur", platform: "macos", name: "720x560-200-ur" });
if (ZOOMS.includes(200)) RUNS.push({ width: 720, height: 560, zoom: 200, lang: "en", theme: "dark", platform: "macos", name: "720x560-200-en-dark" });
// A view with a long name (the review's case: 720 to 1000 CSS px wide, where it used to end in "…").
for (const [width, height, zoom] of [[1024, HEIGHT, 150], [1024, HEIGHT, 200], [1280, HEIGHT, 200], [720, 560, 200]].filter(([, , z]) => ZOOMS.includes(z))) {
  RUNS.push({ width, height, zoom, lang: "en", platform: "macos", long: true, name: `long-${height === HEIGHT ? width : `${width}x${height}`}-${zoom}-en` });
}
for (const [width, height, zoom] of [[1280, 740, 150], [1280, 740, 200], [720, 560, 150], [720, 560, 200]].filter(([, , z]) => ZOOMS.includes(z))) {
  RUNS.push({ width, height, zoom, lang: "en", platform: "windows", name: `win-${height === HEIGHT ? width : `${width}x${height}`}-${zoom}-en` });
}

const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5245, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });

/** The Library at that window and zoom, with v21_zoom on and the zoom kept, as the app opens (on a Mac with its window
 * buttons, or with Windows' own title row). */
async function open(width, zoom, lang, theme, { height = HEIGHT, platform = "macos", long = false } = {}) {
  const scale = zoom / 100;
  const page = await browser.newPage({ viewport: { width: Math.round(width / scale), height: Math.round(height / scale) }, deviceScaleFactor: scale, colorScheme: theme, reducedMotion: "reduce" });
  page.setDefaultTimeout(120_000);
  await page.addInitScript((kept) => { try { localStorage.setItem("echoledger-zoom", String(kept)); } catch { /* none */ } }, zoom);
  const where = platform === "windows" ? "&platform=windows&wintitle=1" : "&platform=macos&lights=1";
  await page.goto(`${base}scripts/screens/index.html?screen=library&theme=${theme}&uiflags=${FLAGS}${platform === "windows" ? ",v21_win_titlebar" : ""}${where}&zoom=${zoom}${lang === "ur" ? "&lang=ur" : ""}${long ? "&longtitle=1" : ""}`);
  await page.getByRole("navigation", { name: "Main" }).waitFor();
  // The Library from the rail when it is there (in the least window at 200% it may be in the avatar's menu).
  // (The fixture opens a recording: pressing the Library, already the page, starts it over on its list.)
  const library = page.getByRole("navigation", { name: "Main" }).getByRole("button", { name: "Library", exact: true });
  const rows = page.locator("button[data-row-id]").first();
  if (await library.count()) await library.click();
  if (!(await rows.waitFor({ timeout: 15_000 }).then(() => true, () => false))) {
    if (await library.count()) await library.click();
    else {
      // In More, or (when not even More fits) in the avatar's menu.
      const more = page.getByTestId("rail-more").getByRole("button");
      await ((await more.count()) ? more : page.getByRole("navigation", { name: "Main" }).getByRole("button", { name: /^Account/ })).click();
      await page.getByRole("menu").locator('[role^="menuitem"]', { hasText: "Library" }).first().click();
    }
    await rows.waitFor();
  }
  if (long) {
    // The long view from the Library's panel (a flyout below 1100 CSS px, opened by the Library's own item, or by the
    // Library in More or the avatar's menu).
    const view = page.getByRole("button", { name: /Follow up with Qureshi/ }).first();
    if (!(await view.isVisible().catch(() => false))) {
      if (await library.count()) await library.click();
      else {
        const more = page.getByTestId("rail-more").getByRole("button");
        await ((await more.count()) ? more : page.getByRole("navigation", { name: "Main" }).getByRole("button", { name: /^Account/ })).click();
        await page.getByRole("menu").locator('[role^="menuitem"]', { hasText: "Library" }).first().click();
      }
    }
    await view.click();
    await page.waitForTimeout(400);
  }
  if (await page.getByTestId("nav-panel").count() && await page.evaluate(() => innerWidth < 1100)) await page.keyboard.press("Escape");
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(300);
  return page;
}

const problems = [];
try {
  // A first load lets Vite settle its dependencies (it may reload the page once) before anything is measured.
  await (await open(1280, 100, "en", "light")).close();
  for (const { width, height, zoom, lang, platform, name, theme = "light", long = false } of RUNS) {
    const page = await open(width, zoom, lang, theme, { height, platform, long });
    const m = await page.evaluate(() => {
      const doc = document.scrollingElement;
      const row = document.querySelector('[data-testid="title-row"]')?.getBoundingClientRect();
      const name = document.querySelector('[data-testid="title-row"] .el-titlerow__name');
      // Every item of the rail to its last (the avatar), not only its box (which the window cuts).
      const items = [...document.querySelectorAll(".el-rail .el-rail__item")];
      const last = items.at(-1)?.getBoundingClientRect();
      const named = items.map((el) => el.getAttribute("aria-label") ?? "");
      // Each item whole: a place inside the places' box (which cuts what overflows it), every item inside the window.
      const box = document.querySelector(".el-rail__places")?.getBoundingClientRect();
      const partly = items.filter((el) => {
        const r = el.getBoundingClientRect();
        const inPlaces = !!el.closest(".el-rail__places") && box;
        const top = inPlaces ? Math.max(box.top, 0) : 0;
        const bottom = inPlaces ? Math.min(box.bottom, innerHeight) : innerHeight;
        return r.top < top - 0.5 || r.bottom > bottom + 0.5;
      }).map((el) => el.getAttribute("aria-label") ?? el.textContent?.trim());
      const inRail = [...document.querySelectorAll(".el-rail__places [data-place]")].map((el) => el.getAttribute("data-place"));
      const more = document.querySelector(".el-rail__more .el-rail__item");
      const badge = document.querySelector('.el-rail [data-place="activity"] .el-rail__count') ? "activity" : more?.querySelector(".el-rail__count") ? "more"
        : document.querySelector(".el-rail__me .el-rail__count") ? "account" : "none";
      const chip = !!document.querySelector('[data-testid="title-row"] .el-zoom-chip');
      const panel = document.querySelector('[data-testid="nav-panel"]');
      // The system's buttons' spot (drawn by the fixture at their native size): nothing of ours that takes a press under it.
      const lights = document.querySelector("[data-traffic-lights]")?.getBoundingClientRect();
      const under = lights ? [...document.querySelectorAll("button, input, a, h1, h2, .el-titlerow__name")].filter((el) => {
        const r = el.getBoundingClientRect();
        return r.width > 0 && r.left < lights.right && r.right > lights.left && r.top < lights.bottom && r.bottom > lights.top;
      }).map((el) => el.getAttribute("aria-label") ?? el.textContent?.trim()) : [];
      // Cut: an ellipsis or a word wider than its box, or the title under another part of the row.
      const nameBox = name?.getBoundingClientRect();
      const covered = !!nameBox && [...document.querySelectorAll('[data-testid="title-row"] button, [data-testid="title-row"] input')].some((el) => {
        const r = el.getBoundingClientRect();
        return r.width > 0 && r.left < nameBox.right - 0.5 && r.right > nameBox.left + 0.5 && r.top < nameBox.bottom - 0.5 && r.bottom > nameBox.top + 0.5;
      });
      const titleRow = document.querySelector('[data-testid="title-row"]');
      return {
        css: innerWidth,
        sideways: doc.scrollWidth > doc.clientWidth + 1,
        cut: name ? name.scrollWidth > name.clientWidth + 0.5 || name.scrollHeight > name.clientHeight + 0.5 || getComputedStyle(name).textOverflow === "ellipsis" || covered : true,
        title: name?.textContent ?? null,
        overflow: !!titleRow && titleRow.scrollWidth > titleRow.clientWidth + 1,
        row: row ? row.right <= innerWidth + 1 && row.left >= -1 : false,
        rail: !!last && last.bottom <= innerHeight + 1 && named.includes("Settings") && named.some((n) => n.startsWith("Account")),
        chip,
        partly,
        inRail,
        more: !!more,
        badge,
        panel: panel ? (panel.classList.contains("el-panel--flyout") ? "flyout" : "docked") : "hidden",
        under,
      };
    });
    await page.screenshot({ path: join(out, `${name}.png`) });
    // The places not in the rail: in More, or (when not even More fits) in the avatar's menu, read from the menu itself.
    const moved = 5 - m.inRail.length;
    let reach = moved === 0;
    let where = "every place shown";
    if (moved > 0) {
      const holder = m.more ? page.getByTestId("rail-more").getByRole("button") : page.getByRole("navigation", { name: "Main" }).getByRole("button", { name: /^Account/ });
      await holder.click();
      const listed = await page.getByRole("menu").locator('[role^="menuitem"] .el-menu__label').allTextContents();
      await page.keyboard.press("Escape");
      const missing = ["Library", "Projects", "Live", "Ask", "Activity"].filter((p) => !m.inRail.includes(p.toLowerCase()) && !listed.includes(p));
      reach = missing.length === 0;
      where = `${moved} in ${m.more ? "More" : "the avatar's menu"}${missing.length ? ` (MISSING: ${missing.join(", ")})` : ""}`;
    }
    if (long && !/Follow up with Qureshi/.test(m.title ?? "")) problems.push(`${name}: the long view isn't the page ("${m.title}")`);
    console.log(`${name}: page ${m.css} CSS px; ${m.sideways ? "SIDEWAYS SCROLL" : "fits"}; title ${long ? `"${m.title}" ` : ""}${m.cut ? "CUT" : "whole"}${m.overflow ? " ROW OVERFLOWS" : ""}; row ${m.row ? "on screen" : "OFF"}; rail ${m.rail ? "to Account" : "CUT"}; ${where}; badge on ${m.badge}; chip ${m.chip ? "shown" : "none"}; panel ${m.panel}${m.under.length ? `; UNDER THE BUTTONS: ${m.under.join(", ")}` : ""}`);
    if (m.sideways) problems.push(`${name}: wider than the window`);
    // The title never loses a word, at any width (lead decision 18; its narrow case wraps it instead).
    if (m.cut) problems.push(`${name}: the title is cut`);
    if (m.overflow) problems.push(`${name}: the title row is wider than its room`);
    if (!m.row) problems.push(`${name}: the title row is off the screen`);
    if (!m.rail) problems.push(`${name}: the rail is cut before Settings and Account`);
    if (m.partly.length) problems.push(`${name}: only partly shown in the rail: ${m.partly.join(", ")}`);
    if (!reach) problems.push(`${name}: a place is neither in the rail, nor in More, nor in the avatar's menu`);
    // The fixture's Activity has items that need the person: its badge shows, on Activity, on More or on the avatar.
    if (m.badge === "none") problems.push(`${name}: Activity's badge is gone`);
    if (m.chip !== (zoom !== 100)) problems.push(`${name}: the chip is ${m.chip ? "shown at 100%" : "missing"}`);
    if (m.under.length) problems.push(`${name}: under the window's buttons: ${m.under.join(", ")}`);
    await page.close();
  }
  // The narrow row's search (lead decision 18's narrow case): Search opens the field across the row, Esc closes it.
  for (const theme of ["light", "dark"]) {
    const shot = `720x560-200-en-search${theme === "dark" ? "-dark" : ""}`;
    const page = await open(720, 200, "en", theme, { height: 560 });
    const titleRow = page.getByTestId("title-row");
    const button = titleRow.getByRole("button", { name: "Search", exact: true });
    const tip = await button.getAttribute("title");
    await button.click();
    const field = titleRow.getByRole("searchbox");
    await field.waitFor();
    const m = await page.evaluate(() => {
      const row = document.querySelector('[data-testid="title-row"]');
      const input = row.querySelector("input");
      const title = row.querySelector(".el-titlerow__title");
      // The row's other parts stay whole beside it: ⊟, ← →, the zoom chip.
      const kept = [...row.querySelectorAll("button")].filter((b) => !row.querySelector(".el-titlerow__search")?.contains(b)).every((b) => {
        const r = b.getBoundingClientRect();
        return r.width > 0 && r.left >= row.getBoundingClientRect().left - 0.5 && r.right <= row.getBoundingClientRect().right + 0.5;
      });
      // Across the row: the box takes the title's place, from the arrows to the row's end part.
      const box = row.querySelector(".el-titlerow__search").getBoundingClientRect();
      const history = row.querySelector(".el-titlerow__history").getBoundingClientRect();
      const endPart = row.querySelector(".el-titlerow__end").getBoundingClientRect();
      const near = (a, b) => Math.min(Math.abs(a.left - b.right), Math.abs(b.left - a.right)) <= 16;
      const across = near(box, history) && near(box, endPart);
      // One focus ring: the box's, never the field's own as well.
      const rings = [row.querySelector(".el-titlerow__search"), input].filter((el) => {
        const style = getComputedStyle(el);
        return (style.outlineStyle !== "none" && parseFloat(style.outlineWidth) > 0) || (style.boxShadow && style.boxShadow !== "none");
      }).length;
      return { focused: document.activeElement === input, width: box.width, across, titleShown: !!title && title.getBoundingClientRect().width > 0,
        overflow: row.scrollWidth > row.clientWidth + 1, kept, rings };
    });
    await page.screenshot({ path: join(out, `${shot}.png`) });
    await page.keyboard.press("Escape");
    const back = await button.evaluate((el) => document.activeElement === el).catch(() => false);
    const titleBack = await titleRow.locator(".el-titlerow__name").isVisible();
    console.log(`${shot}: tooltip "${tip}"; field ${Math.round(m.width)} CSS px${m.across ? " across the row" : " NOT ACROSS"}${m.focused ? ", focused" : ""}; ${m.rings} focus ring${m.rings === 1 ? "" : "s"}; title ${m.titleShown ? "SHOWN" : "aside"}; the row's other parts ${m.kept && !m.overflow ? "whole" : "PUSHED OUT"}; Esc: ${back ? "back to Search" : "FOCUS LOST"}, title ${titleBack ? "back" : "MISSING"}`);
    if (!/^Search \((⌘F|Ctrl\+F)\)$/.test(tip ?? "")) problems.push(`${shot}: the tooltip says "${tip}"`);
    if (!m.focused || !m.across) problems.push(`${shot}: the field didn't open across the row`);
    if (m.rings !== 1) problems.push(`${shot}: ${m.rings} focus rings`);
    if (m.titleShown) problems.push(`${shot}: the title stays beside the open field`);
    if (m.overflow || !m.kept) problems.push(`${shot}: the open field pushes the row's other parts out`);
    if (!back || !titleBack) problems.push(`${shot}: Esc didn't close it back to Search`);
    await page.close();
  }
  // The zoom's own parts, light and dark, at 1280 and 125%.
  for (const theme of ["light", "dark"]) {
    let page = await open(1280, 125, "en", theme);
    await page.getByRole("navigation", { name: "Main" }).getByRole("button", { name: /^Account/ }).click();
    await page.getByRole("menuitem", { name: "Zoom, 125%" }).waitFor();
    await page.screenshot({ path: join(out, `ui-avatar-row-${theme}.png`) });
    await page.keyboard.press("Escape");
    await page.getByTestId("title-row").getByRole("button", { name: "Zoom, 125%" }).click();
    await page.getByRole("menu", { name: "Zoom, 125%" }).waitFor();
    await page.screenshot({ path: join(out, `ui-chip-menu-${theme}.png`) });
    await page.keyboard.press("Escape");
    // The fixture's computer answers as Windows (its app_status), so Ctrl's keys are its own.
    await page.keyboard.press("Control+=");
    await page.locator(".el-zoom-bubble[data-shown]").waitFor();
    await page.screenshot({ path: join(out, `ui-bubble-${theme}.png`) });
    const bubble = await page.locator(".el-zoom-bubble").textContent();
    if (!bubble?.includes("150%")) problems.push(`ui-bubble-${theme}: says ${bubble}`);
    await page.close();
    page = await open(1280, 125, "en", theme);
    await page.getByRole("navigation", { name: "Main" }).getByRole("button", { name: "Settings", exact: true }).click();
    await page.getByRole("button", { name: /^Appearance/ }).first().click();
    await page.getByRole("slider", { name: "Zoom" }).waitFor();
    await page.getByRole("slider", { name: "Zoom" }).scrollIntoViewIfNeeded();
    await page.screenshot({ path: join(out, `ui-settings-${theme}.png`) });
    await page.close();
    // ⋯ More at 200% (1280): the places that don't fit whole, Activity's count with it.
    page = await open(1280, 200, "en", theme);
    await page.getByTestId("rail-more").getByRole("button").click();
    await page.getByRole("menu", { name: "More places" }).waitFor();
    await page.screenshot({ path: join(out, `ui-more-${theme}.png`) });
    console.log(`ui ${theme}: the avatar's row, the chip's menu, the bubble ("${bubble?.trim()}"), Settings › Appearance › Zoom, ⋯ More at 200%`);
    await page.close();
  }
} finally {
  await browser.close();
  await server.close();
}
if (problems.length) {
  console.error(problems.join("\n"));
  process.exit(1);
}
