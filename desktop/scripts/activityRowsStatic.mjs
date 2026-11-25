// D-066: on the classic Activity page only a notification about a recording is a button. The "New device" row opens
// nothing, so hovering it shows no highlight and no pointer, and it isn't a button. In the screens fixture (classic),
// with the pointer on that row, saved under docs/design/screens/desktop/d-066/:
//   <prefix>-activity-hover-new-device-<theme>.png
// The run fails, after the change, when the row is a button, highlights or shows the pointer.
//   node scripts/activityRowsStatic.mjs before
//   node scripts/activityRowsStatic.mjs after
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = process.env.ACTIVITY_OUT ?? join(desktop, "..", "docs", "design", "screens", "desktop", "d-066");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const prefix = process.argv[2] ?? "after";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5245, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
for (const theme of ["light", "dark"]) {
  const page = await browser.newPage({ viewport: { width: 1100, height: 760 }, colorScheme: theme, reducedMotion: "reduce" });
  await page.goto(`${base}scripts/screens/index.html?screen=activity&theme=${theme}`);
  // A busy machine serves the fixture slowly; the wait is for it to load, not a measure of anything.
  const title = page.getByText("New device", { exact: true });
  await title.waitFor({ timeout: 60_000 });
  const row = title.locator("xpath=ancestor::*[contains(concat(' ', @class, ' '), ' activity-item ')][1]");
  await row.hover();
  await page.waitForTimeout(300);
  const m = await row.evaluate((el) => {
    const s = getComputedStyle(el);
    return { tag: el.tagName.toLowerCase(), background: s.backgroundColor, cursor: s.cursor };
  });
  const recording = await page.getByText("Weekly sync with Northwind").evaluate((el) => el.closest("button") !== null);
  await page.screenshot({ path: join(out, `${prefix}-activity-hover-new-device-${theme}.png`) });
  console.log(theme, JSON.stringify({ ...m, recordingIsButton: recording }));
  if (m.tag === "button" || m.cursor === "pointer" || !/rgba\(0, 0, 0, 0\)|transparent/.test(m.background) || !recording) problems.push(theme);
  await page.close();
}
await browser.close();
await server.close();
if (problems.length) {
  console.log(`row with nowhere to go still acts like a button: ${problems.join(", ")}`);
  process.exitCode = 1;
}
