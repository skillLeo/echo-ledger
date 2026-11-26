// The title row's search at the right end (owner's request, Mac 2.0.15): the page's title and count never lose their
// words to the search field. In the screens fixture, a Mac window (740 px tall, the traffic lights drawn), the Library:
//   <prefix>-<width>-<lang>-<theme>.png   at 720, 1024 and 1440, English and Urdu, light and dark,
// saved under docs/design/screens/desktop/title-row/. It also measures, at 720, 900, 1280 and 1440 (and a long project
// name at 1280 and 1024), whether the title is cut, whether its count shows, and the search's form (field or ⌕ button); after
// the change the run fails when a title is cut at 720 px or wider, or a count is not shown; and at 720, 900, 1024 and
// 1440, English and Urdu, when a tab or a toolbar label beside them is cut or wrapped, or the search's placeholder is
// cut; and that each shot reads its way: the search and the toolbar at the right end in English, at the left in Urdu.
//   node scripts/titleRowSearch.mjs before
//   node scripts/titleRowSearch.mjs after
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = process.env.TITLE_ROW_OUT ?? join(desktop, "..", "docs", "design", "screens", "desktop", "title-row");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const prefix = process.argv[2] ?? "after";
const HEIGHT = 740;
const SHOTS = [720, 1024, 1440].flatMap((width) => ["en", "ur"].flatMap((lang) => ["light", "dark"].map((theme) => [width, lang, theme])));
const MEASURED = [720, 900, 1280, 1440];
/** Where no tab or toolbar label may be cut or wrapped, in English and Urdu. */
const LABELS_AT = [720, 900, 1024, 1440];

const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5237, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });

const open = async (width, lang, theme, extra = "") => {
  const page = await browser.newPage({ viewport: { width, height: HEIGHT }, colorScheme: theme, reducedMotion: "reduce" });
  // A busy machine serves the fixture slowly; the waits below are for it to load, not a measure of anything.
  page.setDefaultTimeout(120_000);
  await page.goto(`${base}scripts/screens/index.html?screen=library&theme=${theme}&uiflags=${V2_FLAGS}&platform=macos&lights=1${lang === "ur" ? "&lang=ur" : ""}${extra}`);
  await page.getByRole("button", { name: "Library", exact: true }).last().click();
  await page.locator("button[data-row-id]").first().waitFor();
  // Below 1100 px the panel is a flyout: closed, so the title row shows as the person sees it at rest.
  if (width < 1100 && await page.getByTestId("nav-panel").count()) await page.keyboard.press("Escape");
  return page;
};
const rest = async (page) => {
  await page.mouse.move(0, 0);
  await page.evaluate(() => (document.activeElement instanceof HTMLElement ? document.activeElement.blur() : undefined));
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(400);
};
/** The row as drawn: the title's words whole or cut, the count, the search's form. */
const measure = (page) => page.evaluate(() => {
  const row = document.querySelector('[data-testid="title-row"]');
  const name = row?.querySelector(".el-titlerow__name");
  const count = row?.querySelector(".el-titlerow__count");
  const field = row?.querySelector(".el-titlerow__search");
  const box = (el) => el?.getBoundingClientRect();
  return {
    title: name?.textContent ?? null,
    cut: name ? name.scrollWidth > name.clientWidth + 0.5 : null,
    count: count ? box(count).width > 0 && box(count).right <= box(row).right : false,
    search: row?.querySelector(".el-titlerow__field")
      ? `field ${Math.round(box(field).width)} px, "${row.querySelector(".el-titlerow__field").placeholder}"`
      : field ? "button" : "none",
  };
});

/**
 * Which way the window reads, as drawn: where the search sits in the title row (its end: the right in English, the left
 * when mirrored for Urdu), on the far side of the title, and the Library's row 2 the same way (the first tab on the
 * start side, Filters and Sort & display at the end).
 */
const sides = (page) => page.evaluate(() => {
  const row = document.querySelector('[data-testid="title-row"]');
  const search = row?.querySelector(".el-titlerow__search")?.getBoundingClientRect();
  const title = row?.querySelector(".el-titlerow__start")?.getBoundingClientRect();
  const tabs = [...document.querySelectorAll(".el-lib-tabs__tab")].map((t) => t.getBoundingClientRect());
  const tools = document.querySelector(".el-lib-row2__end")?.getBoundingClientRect();
  const centre = (r) => r.left + r.width / 2;
  const half = innerWidth / 2;
  return {
    dir: document.documentElement.dir || "ltr",
    search: search ? (centre(search) > half ? "right" : "left") : "none",
    searchPastTitle: !!search && !!title && (search.left >= title.right - 0.5 || search.right <= title.left + 0.5),
    tabsFrom: tabs.length > 1 ? (tabs[0].left > tabs[1].left ? "right" : "left") : "none",
    tools: tools ? (centre(tools) > half ? "right" : "left") : "none",
  };
});

/**
 * The labels under the title row, as drawn: each of the Library's tabs (its words wrapped onto two lines, or cut) and
 * the toolbar's buttons beside them (their words cut, or past the window's edge), and the search field's placeholder
 * (wider than the field). A tab scrolled out of the row's view is not cut: the row scrolls.
 */
const labelProblems = (page) => page.evaluate(() => {
  const out = [];
  // The most lines any of an element's texts takes ("Voice notes" on two lines is 2).
  const lines = (el) => {
    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    let most = 0;
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
      if (!node.textContent.trim()) continue;
      const range = document.createRange();
      range.selectNodeContents(node);
      most = Math.max(most, new Set([...range.getClientRects()].filter((r) => r.width > 0).map((r) => Math.round(r.top))).size);
    }
    return most;
  };
  for (const tab of document.querySelectorAll(".el-lib-tabs__tab")) {
    const words = tab.textContent.trim();
    if (lines(tab) > 1) out.push(`tab "${words}" wraps`);
    if (tab.scrollWidth > tab.clientWidth + 0.5) out.push(`tab "${words}" is cut`);
  }
  for (const button of document.querySelectorAll(".el-lib-row2__end button")) {
    const words = button.textContent.trim() || button.getAttribute("aria-label");
    const box = button.getBoundingClientRect();
    if (box.right > innerWidth + 0.5 || box.left < -0.5) out.push(`"${words}" is past the window's edge`);
    if (button.scrollWidth > button.clientWidth + 0.5) out.push(`"${words}" is cut`);
    if (lines(button) > 1) out.push(`"${words}" wraps`);
    if (!button.textContent.trim() && !button.getAttribute("aria-label")) out.push("a toolbar button has no name");
  }
  const field = document.querySelector(".el-titlerow__field");
  if (field?.placeholder) {
    const style = getComputedStyle(field);
    const ctx = document.createElement("canvas").getContext("2d");
    ctx.font = `${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
    if (ctx.measureText(field.placeholder).width > field.clientWidth + 0.5) out.push(`the search's placeholder "${field.placeholder}" is cut`);
  }
  return out;
});

const problems = [];
try {
  for (const [width, lang, theme] of SHOTS) {
    const page = await open(width, lang, theme);
    await rest(page);
    const file = join(out, `${prefix}-${width}-${lang}-${theme}.png`);
    await page.screenshot({ path: file });
    const s = await sides(page);
    // English reads left to right: the search and the toolbar at the right end. Urdu is the mirror.
    const end = lang === "ur" ? "left" : "right";
    const start = lang === "ur" ? "right" : "left";
    const mirrored = s.search === end && s.searchPastTitle && s.tabsFrom === start && s.tools === end;
    console.log(`${file.slice(out.length + 1)}: ${s.dir}, search at the ${s.search} end${s.searchPastTitle ? " past the title" : ", OVER THE TITLE"}, tabs from the ${s.tabsFrom}, Filters and Sort & display at the ${s.tools}${mirrored ? "" : ` (WRONG: the ${end} end expected)`}`);
    if (prefix === "after" && !mirrored) problems.push(`${width} ${lang} ${theme}: not laid out ${lang === "ur" ? "right to left" : "left to right"}`);
    await page.close();
  }
  for (const width of MEASURED) {
    const page = await open(width, "en", "light");
    await rest(page);
    const m = await measure(page);
    console.log(`${width}: "${m.title}" ${m.cut ? "CUT" : "whole"}, count ${m.count ? "shown" : "MISSING"}, search: ${m.search}`);
    if (prefix === "after" && (m.cut || !m.count)) problems.push(`${width}: the title is ${m.cut ? "cut" : "whole"}, its count ${m.count ? "shown" : "missing"}`);
    await page.close();
  }
  // No tab or toolbar label cut or wrapped, in English and Urdu.
  for (const width of LABELS_AT) for (const lang of ["en", "ur"]) {
    const page = await open(width, lang, "light");
    await rest(page);
    const found = await labelProblems(page);
    // How the row made room: the toolbar's labels or its icons only, the tabs whole or scrolling.
    const state = await page.evaluate(() => {
      const labels = [...document.querySelectorAll(".el-lib-row2__end button")].some((b) => b.textContent.trim());
      const tabs = document.querySelector(".el-lib-tabs--scrolls");
      if (!tabs) return `${labels ? "labels" : "icons"}, tabs whole`;
      // Scrolling: how much is out of view, and whether that is only a tab's padding or some of its words.
      const view = tabs.getBoundingClientRect();
      const hidden = [...tabs.querySelectorAll(".el-lib-tabs__tab")].filter((tab) => {
        const range = document.createRange();
        range.selectNodeContents(tab);
        const words = range.getBoundingClientRect();
        return words.left < view.left - 0.5 || words.right > view.right + 0.5;
      }).map((tab) => `"${tab.textContent.trim()}"`);
      const out = tabs.scrollWidth - tabs.clientWidth;
      return `${labels ? "labels" : "icons"}, tabs scroll: ${out} px out of view, ${hidden.length ? `the words of ${hidden.join(", ")} partly out of view until scrolled` : "only a tab's padding, every tab's words in view"}`;
    });
    console.log(`${width} ${lang} (${state}): ${found.length ? found.join("; ") : "every label whole"}`);
    if (prefix === "after") problems.push(...found.map((f) => `${width} ${lang}: ${f}`));
    await page.close();
  }
  // A long name in the title row (a label's, as a long project's would be) at 1280 and 1024.
  for (const width of [1280, 1024]) {
    const page = await open(width, "en", "light", "&longtitle=1");
    // Below 1100 px the label is in the Library's flyout.
    if (width < 1100) await page.locator('nav[data-nav="main"]').getByRole("button", { name: /^Library/ }).click();
    await page.getByRole("button", { name: /Follow up with Qureshi/ }).first().click();
    if (width < 1100 && await page.getByTestId("nav-panel").count()) await page.keyboard.press("Escape");
    await page.waitForTimeout(600);
    await rest(page);
    const m = await measure(page);
    console.log(`${width}, a long name: "${m.title}" ${m.cut ? "CUT" : "whole"}, count ${m.count ? "shown" : "none"}, search: ${m.search}`);
    if (prefix === "after" && m.cut) problems.push(`${width}: the long name is cut`);
    await page.screenshot({ path: join(out, `${prefix}-${width}-en-light-long-name.png`) });
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
