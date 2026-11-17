// Classic | new design, side by side (docs/PLAN_V2_PARITY.md 1 and 8): each screen as classic shows it and as the new
// design shows it, from the same fixture data (scripts/screens/main.tsx), light and dark, into one picture each:
// docs/v2/parity/desktop/<name>-<theme>.png. Every v2 merge request on the parity work shows the pictures of the
// screens it touches.
//   node src/v2/gallery/sidebyside.mjs [--screen=name] [--out=DIR]
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "./cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..", "..", "..");
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
const steps = (...all) => async (page) => {
  for (const s of all) await s(page);
};

/** [name, what it shows, classic: [path, steps], new design: [path, steps]] */
export const PAIRS = [
  ["appearance", "Settings › Appearance: the design switch both ways",
    [classic("settings"), steps(click("Appearance"))],
    [v2("settings"), steps(click("Appearance"))]],
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
