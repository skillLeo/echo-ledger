// Settings in two levels (UX-11, DES-05): the panel has the search and the groups; the page is the group's, with a
// titled section per page and no inner list or "‹ Settings". In the screens fixture, a Mac window (800 px tall, the
// traffic lights drawn), saved under docs/design/screens/desktop/ux-11/:
//   <prefix>-recording-<width>-<lang>-<theme>.png   the Recording group at 1024 and 1280, English and Urdu, light and dark
// and at 1280, English, light and dark (<theme>):
//   <prefix>-account-1280-en-<theme>.png            Account & plan
//   <prefix>-startup-windows-1280-en-<theme>.png    Shortcuts & start-up on Windows (its own frame), scrolled to Start-up
//   <prefix>-startup-mac-1280-en-<theme>.png        the same on a Mac, found by the panel's search: Menu bar & Dock
//   <prefix>-about-1280-en-<theme>.png              About & help
//   <prefix>-search-1280-en-<theme>.png             the panel's search: a setting found, its page scrolled to it
//   <prefix>-subpage-1280-en-<theme>.png            Calls & meetings › Call sites, its way back to Recording
// SETTINGS_EXTRAS_ONLY=1 leaves the Recording set out.
// After the change the run fails when a group's page has an inner list, a "‹ Settings" link, a heading other than the
// group's as its title, a section title that isn't 13 px semibold in the secondary colour with 24 px above it, a page
// wider than the window, or a search result that doesn't open its group's page with the setting highlighted.
//   node scripts/settingsTwoLevels.mjs before
//   node scripts/settingsTwoLevels.mjs after
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = process.env.SETTINGS_OUT ?? join(desktop, "..", "docs", "design", "screens", "desktop", "ux-11");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const prefix = process.argv[2] ?? "after";
const after = prefix === "after";
const HEIGHT = 800;

const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5243, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });

const open = async (width, lang, theme, screen = "settings", platform = "macos") => {
  const page = await browser.newPage({ viewport: { width, height: HEIGHT }, colorScheme: theme, reducedMotion: "reduce" });
  // A busy machine serves the fixture slowly; the waits are for it to load, not a measure of anything.
  page.setDefaultTimeout(120_000);
  await page.goto(`${base}scripts/screens/index.html?screen=${screen}&theme=${theme}&uiflags=${V2_FLAGS}&platform=${platform}${platform === "macos" ? "&lights=1" : ""}${lang === "ur" ? "&lang=ur" : ""}`);
  await page.getByRole("navigation", { name: "Main" }).waitFor();
  await page.locator("[data-v2=settings]").first().waitFor();
  return page;
};
/** The panel (a flyout below 1100 px, opened for the pick). */
const panel = async (page) => {
  if (!(await page.getByTestId("nav-panel").isVisible().catch(() => false))) {
    await page.evaluate(() => window.dispatchEvent(new Event("echoledger:show-panel")));
  }
  const p = page.getByTestId("nav-panel");
  await p.waitFor();
  return p;
};
const group = async (page, name) => {
  await (await panel(page)).getByRole("button", { name, exact: true }).click();
  await page.waitForTimeout(500);
  if (await page.getByTestId("nav-panel").isVisible().catch(() => false) && page.viewportSize().width < 1100) await page.keyboard.press("Escape");
};
const rest = async (page) => {
  await page.mouse.move(0, 0);
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(500);
};

/** The page as drawn: its title, inner list, back links, section titles' look, and whether it is wider than the window. */
const measure = (page) => page.evaluate(() => {
  // The page shown (a group's page stays, hidden, under a sub-page opened from it).
  const main = [...document.querySelectorAll("[data-v2=settings-group], [data-v2=settings-page]")].find((el) => el.getClientRects().length > 0) ?? null;
  // Each section's title, and the space above it: from what comes before its section (the page's head, a page's title
  // in a group's page, or the section before) to the title.
  const titles = [...(main?.querySelectorAll(".el-group__title") ?? [])].map((h) => {
    const s = getComputedStyle(h);
    const section = h.closest(".el-group");
    const before = section?.previousElementSibling;
    const above = before ? Math.round(h.getBoundingClientRect().top - before.getBoundingClientRect().bottom) : null;
    return { text: h.textContent.trim(), tag: h.tagName, size: s.fontSize, weight: s.fontWeight, color: s.color, above };
  });
  const probe = document.createElement("span");
  probe.className = "el-secondary";
  document.body.append(probe);
  const secondary = getComputedStyle(probe).color;
  probe.remove();
  return {
    h1: main?.querySelector("h1")?.textContent.trim() ?? null,
    parts: [...(main?.querySelectorAll(".el-settings-part__title") ?? [])].map((h) => h.textContent.trim()),
    // The page's top in view: its title at least 16 px under the title row.
    top: (() => {
      const h1 = main?.querySelector("h1")?.getBoundingClientRect();
      const row = document.querySelector('[data-testid="title-row"]')?.getBoundingClientRect();
      return h1 && row ? Math.round(h1.top - row.bottom) : null;
    })(),
    inner: !!document.querySelector(".el-settings-split__list"),
    back: [...(main?.querySelectorAll(".el-page__back") ?? [])].map((b) => b.textContent.trim()),
    titles,
    secondary,
    wide: document.documentElement.scrollWidth > innerWidth + 0.5,
  };
});
const say = (name, m) => console.log(`${name}: title "${m.h1}"${m.inner ? ", INNER LIST" : ""}${m.back.length ? `, back: ${m.back.join(", ")}` : ""}, ${m.titles.length} section titles${m.wide ? ", WIDER THAN THE WINDOW" : ""}`);

const problems = [];
/** A group's page after the change: its title, no inner list or way back, each section title as DES-05 has it. */
const groupOk = (name, m, title, lang = "en") => {
  if (!after) return;
  if (m.h1 !== title) problems.push(`${name}: the title is "${m.h1}", not "${title}"`);
  if (m.inner) problems.push(`${name}: an inner list`);
  if (m.back.length) problems.push(`${name}: a way back (${m.back.join(", ")})`);
  if (m.wide) problems.push(`${name}: wider than the window`);
  if (m.titles.length + m.parts.length === 0) problems.push(`${name}: no section titles`);
  for (const t of m.titles) {
    if (!/^H[2-4]$/.test(t.tag)) problems.push(`${name}: "${t.text}" is a ${t.tag}, not a heading`);
    // Urdu keeps its own (larger) caption size, as every caption does.
    if ((lang === "en" && t.size !== "13px") || t.weight !== "600" || t.color !== m.secondary) problems.push(`${name}: "${t.text}" is ${t.size} ${t.weight} ${t.color}`);
    if (t.above !== null && t.above !== 24) problems.push(`${name}: "${t.text}" has ${t.above} px above it`);
  }
};

try {
  for (const width of process.env.SETTINGS_EXTRAS_ONLY ? [] : [1024, 1280]) for (const lang of ["en", "ur"]) for (const theme of ["light", "dark"]) {
    const name = `${prefix}-recording-${width}-${lang}-${theme}`;
    const page = await open(width, lang, theme);
    await group(page, "Recording");
    await rest(page);
    await page.screenshot({ path: join(out, `${name}.png`) });
    const m = await measure(page);
    say(name, m);
    groupOk(name, m, "Recording", lang);
    if (after && (m.top === null || m.top < 16)) problems.push(`${name}: the page's title is ${m.top} px under the title row`);
    if (after) {
      const dir = await page.evaluate(() => getComputedStyle(document.querySelector("[data-v2=settings-group]") ?? document.body).direction);
      if (dir !== (lang === "ur" ? "rtl" : "ltr")) problems.push(`${name}: drawn ${dir}`);
    }
    await page.close();
  }
  for (const theme of ["light", "dark"]) {
    for (const [shot, title] of [["account", "Account & plan"], ["about", "About & help"]]) {
      const name = `${prefix}-${shot}-1280-en-${theme}`;
      const page = await open(1280, "en", theme);
      await group(page, title);
      await rest(page);
      await page.screenshot({ path: join(out, `${name}.png`) });
      const m = await measure(page);
      say(name, m);
      groupOk(name, m, title);
      if (after && shot === "about" && !(await page.getByRole("button", { name: /^Help/ }).count())) problems.push(`${name}: no way to Help`);
      await page.close();
    }
    {
      // Windows, in its own frame: Shortcuts & start-up scrolled to Start-up (Start when I sign in, what ✕ does).
      const name = `${prefix}-startup-windows-1280-en-${theme}`;
      const page = await open(1280, "en", theme, "settings", "windows");
      await group(page, "Shortcuts & start-up");
      await page.evaluate(() => document.getElementById("settings-part-startup")?.scrollIntoView({ block: "start" }));
      await rest(page);
      await page.screenshot({ path: join(out, `${name}.png`) });
      const m = await measure(page);
      say(name, m);
      groupOk(name, m, "Shortcuts & start-up");
      if (after) {
        for (const [role, label] of [["switch", "Start when I sign in"], ["combobox", /^When I close the window/]]) {
          const control = page.getByRole(role, { name: label });
          if (!(await control.count()) || !(await control.first().isVisible()) || !(await control.first().evaluate((el) => {
            const r = el.getBoundingClientRect();
            return r.top >= 0 && r.bottom <= innerHeight;
          }))) problems.push(`${name}: "${label}" isn't in view`);
        }
        if (await page.getByRole("switch", { name: "Show EchoLedger in the menu bar" }).count()) problems.push(`${name}: the Mac's menu bar switch on Windows`);
      }
      await page.close();
    }
    {
      // A Mac: Start-up with the menu bar's and the Dock's switches, found by the panel's search.
      const name = `${prefix}-startup-mac-1280-en-${theme}`;
      const page = await open(1280, "en", theme, "settings-mac");
      const p = await panel(page);
      await p.getByRole("searchbox", { name: "Search settings" }).fill("menu bar");
      await p.getByRole("button", { name: "Show EchoLedger in the menu bar" }).click();
      await page.waitForTimeout(1200);
      await page.mouse.move(0, 0);
      await page.screenshot({ path: join(out, `${name}.png`) });
      const m = await measure(page);
      say(name, m);
      if (after) {
        groupOk(name, m, "Shortcuts & start-up");
        for (const sw of ["Start when I sign in", "Show EchoLedger in the menu bar", "Show timer in the menu bar", "Show count on the app icon"]) {
          if (!(await page.getByRole("switch", { name: sw }).count())) problems.push(`${name}: no "${sw}" switch`);
        }
        const lit = await page.evaluate(() => document.querySelector(".is-highlighted")?.id ?? null);
        if (lit !== "setting-app.menu_bar_icon") problems.push(`${name}: highlighted ${lit}`);
      }
      await page.close();
    }
    {
      const name = `${prefix}-search-1280-en-${theme}`;
      const page = await open(1280, "en", theme);
      const p = await panel(page);
      await p.getByRole("searchbox", { name: "Search settings" }).fill("skip short calls");
      await p.getByRole("button", { name: "Skip calls shorter than" }).click();
      // Long enough for the sections above to read and the mic list to poll once: the row must still be in view.
      await page.waitForTimeout(3000);
      await page.mouse.move(0, 0);
      await page.screenshot({ path: join(out, `${name}.png`) });
      const m = await measure(page);
      say(name, m);
      if (after) {
        groupOk(name, m, "Recording");
        const hit = await page.evaluate(() => {
          const el = document.querySelector(".is-highlighted");
          const r = el?.getBoundingClientRect();
          return { id: el?.id ?? null, inView: !!r && r.top >= 0 && r.bottom <= innerHeight, focused: !!el && document.activeElement === el };
        });
        if (hit.id !== "setting-calls.skip_short") problems.push(`${name}: highlighted ${hit.id}`);
        if (!hit.inView) problems.push(`${name}: the highlighted row slid out of view`);
        if (!hit.focused) problems.push(`${name}: the highlighted row hasn't the keyboard`);
      }
      await page.close();
    }
    {
      const name = `${prefix}-subpage-1280-en-${theme}`;
      const page = await open(1280, "en", theme);
      await group(page, "Recording");
      await page.getByRole("button", { name: /^Call sites/ }).click();
      await page.waitForTimeout(600);
      await rest(page);
      await page.screenshot({ path: join(out, `${name}.png`) });
      const m = await measure(page);
      say(name, m);
      if (after) {
        if (m.h1 !== "Call sites") problems.push(`${name}: the title is "${m.h1}"`);
        if (m.back.join() !== "Recording") problems.push(`${name}: back is "${m.back.join()}", not "Recording"`);
        await page.keyboard.press("Escape");
        await page.waitForTimeout(600);
        const back = await measure(page);
        if (back.h1 !== "Recording") problems.push(`${name}: Esc went to "${back.h1}"`);
      }
      await page.close();
    }
  }
} finally {
  await browser.close();
  await server.close();
}
if (problems.length) {
  console.error(problems.join("\n"));
  process.exit(1);
}
