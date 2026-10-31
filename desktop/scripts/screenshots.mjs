// Screenshots of every desktop screen, light and dark, at 1280 x 800, into docs/design/screens/desktop/. The views run in
// the Vite dev server's fixture mode (scripts/screens/), answered by Tauri's IPC mock with sample data.
//   node scripts/screenshots.mjs              every screen
//   node scripts/screenshots.mjs library      only the screens whose name starts with "library"
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop");

/** Screen name, fixture screen, and what to do before the picture. */
const SCREENS = [
  ["library", "library", async (page) => page.waitForSelector(".segments .segment")],
  ["library-urdu", "library", async (page) => {
    await page.waitForSelector(".segments .segment");
    await page.getByRole("button", { name: "اردو" }).click();
    await page.waitForSelector(".segments[lang=ur]");
  }],
  ["library-find", "library", async (page) => {
    await page.waitForSelector(".segments .segment");
    await page.getByRole("button", { name: "Find in this transcript" }).click();
    await page.getByRole("textbox", { name: "Find in transcript" }).fill("launch");
  }],
  ["library-waiting", "library", async (page) => {
    await page.getByTestId("library-list").getByRole("button", { name: "Review", exact: true }).click();
    await page.waitForSelector(".queue-item.pending");
  }],
  ["library-empty", "library-empty", async (page) => page.waitForSelector(".empty-state")],
  ["clients", "clients", async (page) => page.waitForSelector(".brief-grid")],
  ["client-files", "clients", async (page) => {
    await page.getByRole("tab", { name: "Files" }).click();
    await page.waitForSelector(".file-row");
  }],
  ["client-ask", "clients", async (page) => {
    await page.getByRole("tab", { name: "Ask" }).click();
    await page.getByLabel("Question").fill("When do we launch?");
    await page.getByRole("button", { name: "Ask", exact: true }).click();
    await page.waitForSelector(".ask-answer");
  }],
  ["record", "record", async (page) => page.waitForSelector(".record-btn")],
  ["activity", "activity", async (page) => page.waitForSelector(".activity-item")],
  ["settings", "settings", async (page) => {
    await page.waitForSelector(".settings-row");
    await page.getByRole("button", { name: "General" }).click();
  }],
  ["settings-calls", "settings", async (page) => {
    await page.waitForSelector(".settings-row");
    await page.getByRole("button", { name: "Calls & meetings" }).click();
  }],
  ["controls", "controls", async (page) => {
    await page.waitForSelector(".rec-pill");
    await page.waitForSelector(".review-card");
    await page.waitForSelector(".overlay-card .caption-line");
  }],
];

async function launch() {
  try {
    return await chromium.launch();
  } catch {
    // No Playwright browser downloaded: Microsoft Edge is on every Windows 11 machine.
    return chromium.launch({ channel: "msedge" });
  }
}

const only = process.argv[2];
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5199, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await launch();
await mkdir(out, { recursive: true });
try {
  for (const [name, screen, prepare] of SCREENS) {
    if (only && !name.startsWith(only)) continue;
    for (const theme of ["light", "dark"]) {
      const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
      page.on("pageerror", (e) => console.error(`${name}-${theme}: ${e.message}`));
      await page.goto(`${base}scripts/screens/index.html?screen=${screen}&theme=${theme}`);
      await prepare(page);
      await page.evaluate(() => document.fonts.ready);
      await page.waitForTimeout(400);
      const file = join(out, `desktop-${name}-${theme}.png`);
      await page.screenshot({ path: file });
      console.log(file);
      await page.close();
    }
  }
} finally {
  await browser.close();
  await server.close();
}
