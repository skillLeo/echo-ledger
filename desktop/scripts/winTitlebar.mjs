// Windows' own title row (UX-20, v21_win_titlebar) in the screens fixture, saved under
// docs/design/screens/desktop/win-titlebar/:
//   <width>-<lang>-<theme>.png      the Library at 1024, 1280 and 1440, English and Urdu, light and dark, with the row
//   off-1280-en-light.png           the flag off: Windows' own title bar outside the page, the page as before
// Measured on each: the row 48 px from the window's top; Minimize, Maximize and Close 46 × 48 at the row's end edge
// (the right in English, mirrored to the left in Urdu), flush with the window's edge, nothing of the page under them;
// the app's mark in the rail's top cell; the title whole. The flag off has none of it, and its row is as before (52 px).
// The run fails on any of that. What only Windows can show (Snap Layouts, resizing, Alt+Space, the system's move loop,
// DPI) is the device batch's.
//   node scripts/winTitlebar.mjs
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = process.env.WIN_TITLEBAR_OUT ?? join(desktop, "..", "docs", "design", "screens", "desktop", "win-titlebar");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const HEIGHT = 740;

const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5241, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });

const open = async ({ width, lang = "en", theme = "light", on = true }) => {
  const page = await browser.newPage({ viewport: { width, height: HEIGHT }, colorScheme: theme, reducedMotion: "reduce" });
  page.setDefaultTimeout(120_000);
  await page.goto(`${base}scripts/screens/index.html?screen=library&theme=${theme}&uiflags=${V2_FLAGS}&platform=windows${on ? "&wintitle=1" : ""}${lang === "ur" ? "&lang=ur" : ""}`);
  await page.getByRole("navigation", { name: "Main" }).getByRole("button", { name: "Library", exact: true }).click();
  await page.locator("button[data-row-id]").first().waitFor();
  if (width < 1100 && await page.getByTestId("nav-panel").count()) await page.keyboard.press("Escape");
  await page.mouse.move(width / 2, HEIGHT / 2);
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(400);
  return page;
};

/** The row as drawn. */
const measure = (page) => page.evaluate(() => {
  const row = document.querySelector('[data-testid="title-row"]');
  const r = row.getBoundingClientRect();
  const buttons = [...document.querySelectorAll(".el-winbutton")].map((b) => {
    const box = b.getBoundingClientRect();
    // What is drawn at the button's centre: the button itself (or its icon), never the page over it.
    const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);
    return { name: b.getAttribute("aria-label"), left: box.left, right: box.right, top: box.top, width: Math.round(box.width), height: Math.round(box.height), own: !!hit && b.contains(hit) };
  });
  const mark = document.querySelector(".el-rail__top .el-rail__mark");
  const name = row.querySelector(".el-titlerow__name");
  return {
    row: { top: Math.round(r.top), height: Math.round(r.height) },
    buttons,
    mark: mark ? Math.round(mark.getBoundingClientRect().width) : 0,
    cut: name ? name.scrollWidth > name.clientWidth + 0.5 : null,
    dir: document.documentElement.dir || "ltr",
  };
});

const problems = [];
try {
  for (const width of [1024, 1280, 1440]) for (const lang of ["en", "ur"]) for (const theme of ["light", "dark"]) {
    const name = `${width}-${lang}-${theme}`;
    const page = await open({ width, lang, theme });
    await page.screenshot({ path: join(out, `${name}.png`) });
    const m = await measure(page);
    const names = m.buttons.map((b) => b.name);
    const rtl = m.dir === "rtl";
    // In English Close is the last at the right edge; in Urdu the row mirrors, Close at the left edge.
    const edge = rtl ? Math.round(Math.min(...m.buttons.map((b) => b.left))) : Math.round(width - Math.max(...m.buttons.map((b) => b.right)));
    // As drawn, left to right: Minimize, Maximize, Close; mirrored in Urdu, Close at the left edge.
    const order = [...m.buttons].sort((a, b) => a.left - b.left).map((b) => b.name);
    const expected = rtl ? ["Close", "Maximize", "Minimize"] : ["Minimize", "Maximize", "Close"];
    console.log(`${name}: row ${m.row.height} px at ${m.row.top}; buttons ${m.buttons.map((b) => `${b.name} ${b.width}×${b.height}`).join(", ")}, ${edge} px from the ${rtl ? "left" : "right"} edge; mark ${m.mark} px; title ${m.cut ? "CUT" : "whole"}`);
    if (m.row.top !== 0 || m.row.height !== 48) problems.push(`${name}: the row is ${m.row.height} px at ${m.row.top}`);
    if (JSON.stringify(names) !== JSON.stringify(["Minimize", "Maximize", "Close"])) problems.push(`${name}: buttons ${names.join(", ")}`);
    if (m.buttons.some((b) => b.width !== 46 || b.height !== 48 || Math.round(b.top) !== 0)) problems.push(`${name}: a button is not 46 × 48 at the top`);
    if (m.buttons.some((b) => !b.own)) problems.push(`${name}: something of the page lies over a button`);
    if (edge !== 0) problems.push(`${name}: the buttons end ${edge} px from the window's edge`);
    if (JSON.stringify(order) !== JSON.stringify(expected)) problems.push(`${name}: drawn ${order.join(", ")}`);
    if (m.mark !== 20) problems.push(`${name}: the mark is ${m.mark} px`);
    if (m.cut) problems.push(`${name}: the title is cut`);
    await page.close();
  }
  {
    const page = await open({ width: 1280, on: false });
    await page.screenshot({ path: join(out, "off-1280-en-light.png") });
    const m = await measure(page);
    console.log(`off-1280-en-light: row ${m.row.height} px at ${m.row.top}; buttons ${m.buttons.length}; mark ${m.mark} px`);
    if (m.buttons.length || m.mark || m.row.height !== 52) problems.push("the flag off is not the page as before");
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
