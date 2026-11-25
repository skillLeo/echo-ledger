// Desktop Urdu, D1b, in the screens fixture (every v2 flag and v21_urdu on, the account's language Urdu: "&applang=ur",
// the uploads of "&uploads=1", 1280 × 800), on a Mac and on Windows, in the light and the dark theme: the Library with
// its filters open, then with filters on (a section's dot and Clear, Custom's two days), the pills row and a pill's
// editor; Save as view over My views; the labels and New label with a library view's name refused; the queue in
// Needs you (the waiting calls, an upload that needs you, another account's) and in All (an import, an upload being
// checked); the import sheet for three dropped files. It checks that the window reads right to left and that each
// scene speaks Urdu, and saves docs/design/screens/desktop/urdu-d1b/<platform>-<step>.png (light) and
// <platform>-<step>-dark.png; the run fails when either check doesn't hold. What the fixture lacks is given in the page:
// two saved views (kept on this computer for the fixture's account, as the views store keeps them) and the dropped files
// (the window's drop event, through the fixture's own IPC mock).
//   node scripts/urduD1b.mjs [--only=macos-light,windows-dark]
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "urdu-d1b");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5271, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
const root = (page) => page.evaluate(() => `${document.documentElement.lang || "-"}/${document.documentElement.dir || "-"}`);

/** Two saved views (My views), kept on this computer for the fixture's account before the page loads. */
const keepViews = (page) => page.addInitScript(() => {
  const view = (id, name, filters) => ({ id, name, spec: { filters, search: null, sort: "newest", group: "date", density: null } });
  const views = [view("v1", "WhatsApp calls", { source: { op: "is", values: ["whatsapp"] } }), view("v2", "Acme this month", { date: { op: "is", values: ["this_month"] } })];
  window.localStorage.setItem("echoledger-my-views:https://ledger.example.test|ayesha@example.test|1", JSON.stringify({ views, version: 1, reorder: false }));
});

try {
  const only = process.argv.find((a) => a.startsWith("--only="))?.split("=")[1]?.split(",");
  for (const [platform, theme] of [["macos", "light"], ["macos", "dark"], ["windows", "light"], ["windows", "dark"]]) {
    if (only && !only.includes(`${platform}-${theme}`)) continue;
    const where = `${platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1"}&theme=${theme}`;
    const label = `${platform} (${theme})`;
    const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, reducedMotion: "reduce" });
    page.setDefaultTimeout(120_000);
    await keepViews(page);
    await page.goto(`${base}scripts/screens/index.html?screen=library&uiflags=${FLAGS},v21_urdu${where}&applang=ur&uploads=1`);
    await page.getByRole("navigation", { name: "Main" }).waitFor();
    await page.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 30_000 }).catch(() => undefined);
    await page.waitForTimeout(1000);

    /** Saves the window, after checking its direction and that `words` (Urdu) are on it. */
    const shot = async (step, words) => {
      await page.mouse.move(0, 0);
      await page.waitForTimeout(500);
      await page.screenshot({ path: join(out, `${platform}-${step}${theme === "dark" ? "-dark" : ""}.png`) });
      const at = await root(page);
      const speaks = await page.getByText(words, { exact: false }).first().isVisible().catch(() => false);
      console.log(`${label}: ${step}; <html> ${at}; ${speaks ? "in Urdu" : "NOT in Urdu"}`);
      if (at !== "ur/rtl") problems.push(`${label}: ${step} is ${at}, not ur/rtl`);
      if (!speaks) problems.push(`${label}: ${step} doesn't show “${words}”`);
    };
    // The Library panel's views and its labels, by their own (Urdu) names.
    const views = page.getByRole("list", { name: "لائبریری کے ویوز" });
    const labels = page.getByRole("region", { name: "لیبلز" });
    const panel = () => page.getByTestId("filter-panel");
    const pills = () => page.getByRole("group", { name: "لگے ہوئے فلٹرز" });

    // The Library with its filters: every section and its options with their counts.
    await views.getByRole("button", { name: /^تمام ریکارڈنگز/ }).click();
    await page.waitForTimeout(600);
    await page.locator('[data-testid="title-row"] button, .el-lib-row2 button').filter({ hasText: /^فلٹرز/ }).first().click();
    await panel().waitFor();
    await shot("filters", "کسی بھی وقت");

    // Filters on: Source WhatsApp, and Custom with its first day; a section's dot and Clear.
    await panel().getByRole("region", { name: "ذریعہ" }).getByRole("checkbox", { name: /^WhatsApp \d/ }).click();
    await panel().getByRole("region", { name: "تاریخ" }).getByRole("radio", { name: /^اپنی تاریخیں/ }).click();
    await panel().getByLabel("سے").fill("2026-11-01");
    await page.waitForTimeout(500);
    await shot("filters-on", "صاف کریں");

    // The pills row, then a pill's editor (is / is not and its choices).
    await panel().getByRole("button", { name: "فلٹرز بند کریں" }).click();
    await pills().waitFor();
    await shot("pills", "ویو کے طور پر محفوظ کریں");
    await pills().locator(".el-fpill__edit").first().click();
    await page.getByRole("radiogroup").filter({ hasText: "نہیں ہے" }).first().waitFor();
    await shot("pill-editor", "نہیں ہے");
    await page.keyboard.press("Escape");

    // Save as view over My views, a name already in use typed: the reason under the field.
    await pills().getByRole("button", { name: "ویو کے طور پر محفوظ کریں" }).click();
    const save = page.getByRole("dialog", { name: "ویو کے طور پر محفوظ کریں" });
    await save.waitFor();
    await save.getByRole("textbox").fill("whatsapp calls");
    await shot("save-view", "نام کا ویو ہے");
    await page.keyboard.press("Escape");
    await pills().getByRole("button", { name: "سب صاف کریں" }).click();
    await page.waitForTimeout(400);

    // The labels, and New label with a library view's name refused.
    await labels.getByRole("button", { name: "نیا لیبل" }).first().click();
    const newLabel = page.getByRole("dialog", { name: "نیا لیبل" });
    await newLabel.waitFor();
    await newLabel.getByLabel("لیبل کا نام").fill("اہم");
    await shot("new-label", "لائبریری کے ویوز میں سے ایک ہے");
    await page.keyboard.press("Escape");

    // The queue: Needs you (the waiting calls listed, an upload that needs you, another account's), then All (an import
    // and an upload being checked).
    await views.getByRole("button", { name: /^آپ کی توجہ چاہیے/ }).click();
    await page.getByTestId("library-queue").waitFor();
    await page.waitForTimeout(600);
    await shot("queue-needs", "سب ٹرانسکرائب کریں");
    await views.getByRole("button", { name: /^تمام ریکارڈنگز/ }).click();
    await page.getByTestId("library-queue").waitFor();
    await page.waitForTimeout(600);
    await shot("queue-progress", "اپ لوڈ کی جانچ ہو رہی ہے…");

    // The import sheet: three files dropped on the window, one it can't take.
    await page.evaluate(() => window.__TAURI_INTERNALS__.invoke("plugin:event|emit", { event: "tauri://drag-drop", payload: {
      paths: ["/Users/me/Downloads/PTT-20261030-WA0003.opus", "/Users/me/Movies/Call_with_Acme_Oct.mp4", "/Users/me/Desktop/readme.txt"],
    } }));
    await page.getByRole("list", { name: "امپورٹ کی جانے والی فائلیں" }).waitFor();
    await page.waitForTimeout(400);
    await shot("import", "فائلیں امپورٹ کریں");
    await page.close();
  }
} finally {
  await browser.close();
  await server.close();
}
if (problems.length) {
  console.error(`\n${problems.join("\n")}`);
  process.exit(1);
}
