// UX-08, Activity and its badge (contract 2.0.0-r): the Activity page with three uploads that didn't finish (one of them
// twice), one that went up after all, and the server's one Activity number (4, red: a failure is among them). The short
// set: 1024 and 1280 light, 1280 dark and 1280 Urdu, plus 1280 light with the group opened. Saved under
// docs/design/screens/desktop/ux-08/<after|before>/: <width>-<theme>-<lang>.png and 1280-light-en-open.png.
// Measured (not with --before): one line "3 recordings didn't finish uploading" with Retry all, none of its items on
// their own until it is opened (then all three, the repeated one saying "2 times"), "Uploaded" among the day's items,
// and the rail's Activity badge saying 6 in red (the server's 4 and the 2 calls waiting here). The run fails on any of that.
//   node scripts/activityGroups.mjs [--before]
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const before = process.argv.includes("--before");
const out = process.env.SHOTS_OUT ?? join(desktop, "..", "docs", "design", "screens", "desktop", "ux-08", before ? "before" : "after");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const SET = [[1024, "light", "en"], [1280, "light", "en"], [1280, "dark", "en"], [1280, "light", "ur"]];
const GROUP = "3 recordings didn't finish uploading";

const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5247, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];

async function open(width, theme, lang) {
  const page = await browser.newPage({ viewport: { width, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
  page.setDefaultTimeout(120_000);
  await page.goto(`${base}scripts/screens/index.html?screen=activity&theme=${theme}&uiflags=${FLAGS}&platform=macos&lights=1&grouped=1${lang === "ur" ? "&lang=ur" : ""}`);
  await page.getByRole("navigation", { name: "Main" }).waitFor();
  await page.getByRole("heading", { name: "Needs action" }).waitFor();
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(300);
  return page;
}

try {
  // A first load lets Vite settle its dependencies (it may reload the page once) before anything is measured.
  await (await open(1280, "light", "en")).close();
  for (const [width, theme, lang] of SET) {
    const name = `${width}-${theme}-${lang}`;
    const page = await open(width, theme, lang);
    await page.screenshot({ path: join(out, `${name}.png`) });
    if (!before) {
      const m = await page.evaluate((group) => {
        const rows = [...document.querySelectorAll('[data-v2="activity"] .el-row')].map((r) => r.textContent ?? "");
        const badge = document.querySelector('.el-rail [data-place="activity"] .el-rail__count');
        return {
          line: rows.filter((t) => t.includes(group)).length,
          retryAll: rows.some((t) => t.includes(group) && t.includes("Retry all")),
          alone: rows.filter((t) => t.includes("Upload didn't finish")).length,
          uploaded: rows.some((t) => t.includes("Uploaded")),
          badge: badge ? `${badge.textContent}${badge.classList.contains("el-rail__count--danger") ? " red" : ""}` : "none",
        };
      }, GROUP);
      console.log(`${name}: group line ${m.line}${m.retryAll ? " with Retry all" : ""}; items alone ${m.alone}; Uploaded ${m.uploaded ? "shown" : "MISSING"}; badge ${m.badge}`);
      if (m.line !== 1 || !m.retryAll) problems.push(`${name}: not one "${GROUP}" line with Retry all`);
      if (m.alone) problems.push(`${name}: ${m.alone} of the group's items shown on their own`);
      if (!m.uploaded) problems.push(`${name}: "Uploaded" is missing`);
      // The server's 4, and the fixture's 2 calls waiting on this computer for Transcribe or Delete.
      if (m.badge !== "6 red") problems.push(`${name}: the rail's badge says ${m.badge}, not 6 in red`);
      if (width === 1280 && theme === "light" && lang === "en") {
        await page.getByRole("button", { name: GROUP }).click();
        await page.getByTestId("upload-group").waitFor();
        await page.waitForTimeout(200);
        await page.screenshot({ path: join(out, "1280-light-en-open.png") });
        const inside = await page.getByTestId("upload-group").locator(".el-row").allTextContents();
        console.log(`opened: ${inside.length} items; ${inside.filter((t) => t.includes("2 times")).length} says "2 times"`);
        if (inside.length !== 3) problems.push(`opened: ${inside.length} items, not 3`);
        if (!inside.some((t) => t.includes("WhatsApp call with Omar Khan · 2 times"))) problems.push("opened: the repeated item doesn't say 2 times");
      }
    }
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
