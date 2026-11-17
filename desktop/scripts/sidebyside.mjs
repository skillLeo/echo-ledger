// Classic | new design, side by side (docs/PLAN_V2_PARITY.md 1 and 8): each screen as classic shows it and as the new
// design shows it, from the same fixture data (scripts/screens/main.tsx), light and dark, into one picture each:
// docs/v2/parity/desktop/<name>-<theme>.png. Every v2 merge request on the parity work shows the pictures of the
// screens it touches.
//   node scripts/sidebyside.mjs [--screen=name] [--out=DIR]
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const arg = (name) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split("=")[1];
const out = resolve(arg("out") ?? join(desktop, "..", "docs", "v2", "parity", "desktop"));
const only = arg("screen");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";

const classic = (screen) => `scripts/screens/index.html?screen=${screen}`;
const v2 = (screen) => `scripts/screens/index.html?screen=${screen}&uiflags=${FLAGS}`;
const click = (name) => async (page) => {
  await page.getByRole("button", { name, exact: true }).first().click();
  await page.waitForTimeout(900);
};
/** Ticks the first `n` recordings in selection mode (classic's list or the new design's rows). */
const pickRows = (n) => async (page) => {
  const rows = page.locator("button[data-row-id], .list .item, [role=listitem] button").filter({ visible: true });
  for (let i = 0; i < n; i++) await rows.nth(i).click();
  await page.waitForTimeout(400);
};
const steps = (...all) => async (page) => {
  for (const s of all) await s(page);
};

/** [name, what it shows, classic: [path, steps], new design: [path, steps]] */
export const PAIRS = [
  ["appearance", "Settings › Appearance: the design switch both ways",
    [classic("settings"), steps(click("Appearance"))],
    [v2("settings"), steps(click("Appearance"))]],
  ["live-assist", "Live Assist: classic's Home (where classic starts things) and the new design's Live tab",
    [`${classic("library")}&uiflags=live_assist`, steps(async (page) => {
      await page.locator(".sidebar").getByRole("button", { name: /^Home/ }).click();
      await page.waitForTimeout(1500);
      await page.getByRole("region", { name: "Live Assist" }).scrollIntoViewIfNeeded();
    })],
    [v2("library"), steps(async (page) => {
      await page.getByRole("navigation", { name: "Main" }).getByRole("button", { name: "Live", exact: true }).click();
      await page.waitForTimeout(1500);
    })]],
  ["selection", "Library selection: classic's bulk bar with More open | the new design's bar (× · count · Select all · Move, Label, Share, Delete · ⋮) with ⋮ › Export ▸ open",
    [classic("library"), steps(click("Select"), pickRows(2), async (page) => {
      await page.locator(".bulk-bar summary", { hasText: "More" }).click();
      await page.waitForTimeout(400);
    })],
    [v2("library"), steps(click("Select"), pickRows(2), async (page) => {
      await page.getByRole("button", { name: "More actions" }).click();
      await page.getByRole("menuitem", { name: "Export" }).hover();
      await page.waitForTimeout(400);
    })]],
  ["row-menu", "A recording's menu: classic's row ⋯ | the new design's row ⋮ (Organise · Export · Danger, the same menu as a right-click)",
    [classic("library"), steps(async (page) => {
      await page.getByRole("button", { name: /^More for / }).first().click({ force: true });
      await page.waitForTimeout(400);
    })],
    [v2("library"), steps(async (page) => {
      await page.getByRole("button", { name: /^More for / }).first().click({ force: true });
      await page.waitForTimeout(400);
    })]],
];

const server = await createServer({ root: desktop, configFile: join(desktop, "vite.config.ts"), server: { port: 1480, strictPort: false }, logLevel: "error" });
await server.listen();
const base = server.resolvedUrls.local[0];
await mkdir(out, { recursive: true });
const browser = await chromium.launch();

async function shoot([path, step], theme) {
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
  await page.goto(`${base}${path}&theme=${theme}`);
  await page.waitForTimeout(2200);
  if (step) await step(page);
  await page.waitForTimeout(300);
  const png = await page.screenshot();
  await page.close();
  return png.toString("base64");
}

try {
  for (const [name, what, before, after] of PAIRS) {
    if (only && name !== only) continue;
    for (const theme of ["light", "dark"]) {
      const [left, right] = [await shoot(before, theme), await shoot(after, theme)];
      const sheet = await browser.newPage({ viewport: { width: 2600, height: 900 }, colorScheme: theme });
      const ink = theme === "dark" ? "#e8e8e6" : "#1b1b19";
      const paper = theme === "dark" ? "#111113" : "#f4f4f2";
      await sheet.setContent(`<body style="margin:0;padding:20px;background:${paper};color:${ink};font:15px system-ui">
        <div style="margin:0 0 10px">${what}</div>
        <div style="display:flex;gap:20px">
          <figure style="margin:0"><figcaption style="margin:0 0 6px;font-weight:600">Classic</figcaption><img style="width:1270px;border:1px solid #888" src="data:image/png;base64,${left}"></figure>
          <figure style="margin:0"><figcaption style="margin:0 0 6px;font-weight:600">New design</figcaption><img style="width:1270px;border:1px solid #888" src="data:image/png;base64,${right}"></figure>
        </div></body>`);
      await sheet.waitForTimeout(200);
      await sheet.screenshot({ path: join(out, `${name}-${theme}.png`), fullPage: true });
      await sheet.close();
      console.log(`${name}-${theme}.png`);
    }
  }
} finally {
  await browser.close();
  await server.close();
}
