// The three levels of navigation (PLAN_NAV_3_LEVELS) in the screens fixture, saved under
// docs/design/screens/desktop/nav-3-levels/: <place>-<width>-<lang>-<theme>.png for the Library at rest, the Library
// with its filters open (they push the list from 1440 px, slide over below), Projects, Settings (a group's page with its
// inner list) and Activity; at 1024 the Library's flyout open. The rail and the panel's feet are checked on screen.
//   node scripts/navLevels.mjs [1280,1440]
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "nav-3-levels");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const widths = process.argv[2] ? process.argv[2].split(",").map(Number) : [1280, 1440, 1024, 1920];
const HEIGHT = 900;

const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5232, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const rest = async (page) => {
  await page.mouse.move(0, 0);
  await page.evaluate(() => (document.activeElement instanceof HTMLElement ? document.activeElement.blur() : undefined));
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(400);
};
const onScreen = async (page) => {
  const problem = await page.evaluate(() => {
    const rail = document.querySelector(".el-rail");
    const last = rail && [...rail.querySelectorAll(".el-rail__item, .el-rail__avatar")].at(-1);
    if (!last) return "no rail";
    const r = last.getBoundingClientRect();
    return r.bottom > innerHeight + 0.5 ? `the rail's foot ends at ${Math.round(r.bottom)} of ${innerHeight}` : "";
  });
  if (problem) throw new Error(problem);
};
try {
  for (const width of widths) for (const lang of ["en", "ur"]) for (const theme of ["light", "dark"]) {
    const page = await browser.newPage({ viewport: { width, height: HEIGHT }, colorScheme: theme, reducedMotion: "reduce" });
    const shoot = async (name) => {
      await rest(page);
      await onScreen(page);
      const file = join(out, `${name}-${width}-${lang}-${theme}.png`);
      await page.screenshot({ path: file });
      console.log(file);
    };
    await page.goto(`${base}scripts/screens/index.html?screen=library&theme=${theme}&uiflags=${V2_FLAGS}${lang === "ur" ? "&lang=ur" : ""}`);
    // The fixture opens a recording: its Back shows the list.
    await page.getByRole("button", { name: "Library", exact: true }).last().click();
    await page.locator("button[data-row-id]").first().waitFor();
    const rail = page.getByRole("navigation", { name: "Main" });
    if (width < 1100) {
      await shoot("library");
      await rail.getByRole("button", { name: /^Library/ }).click();
      await page.getByTestId("nav-panel").waitFor();
      await shoot("library-flyout");
      await page.keyboard.press("Escape");
    } else {
      await shoot("library");
      await page.getByRole("button", { name: /^Filters/ }).click();
      await page.getByTestId("filter-panel").waitFor();
      await page.getByTestId("filter-panel").getByRole("checkbox", { name: /^WhatsApp/ }).first().check();
      await page.waitForTimeout(300);
      await shoot("library-filters");
      await page.getByTestId("filter-panel").getByRole("button", { name: "Close filters" }).click();
    }
    await rail.getByRole("button", { name: /^Projects/ }).click();
    await page.waitForTimeout(300);
    await shoot("projects");
    await rail.getByRole("button", { name: /^Settings/ }).click();
    await page.waitForTimeout(300);
    if (width >= 1100) {
      await page.getByTestId("nav-panel").getByRole("button", { name: "Recording" }).click();
      await page.waitForTimeout(400);
    }
    await shoot("settings");
    await rail.getByRole("button", { name: /^Activity/ }).click();
    await page.waitForTimeout(300);
    await shoot("activity");
    await page.close();
  }
} finally {
  await browser.close();
  await server.close();
}
