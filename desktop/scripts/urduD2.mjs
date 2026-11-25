// Desktop Urdu, D2, in the screens fixture (every v2 flag and v21_urdu on, the account's language Urdu: "&applang=ur",
// 1280 × 800), on a Mac and on Windows, in the light and the dark theme: Shared with me's list, My links and Request
// links (lib/sharing.ts's words), a recording a teammate sent open read-only, the Request files sheet, and what the
// new design opens with the sharing words: a recording's Share sheet and a project's filing rules. It checks
// that the window reads right to left and that the page speaks Urdu, and saves
// docs/design/screens/desktop/urdu-d2/<platform>-<step>.png (light) and <platform>-<step>-dark.png; the run fails when
// either check doesn't hold.
//   node scripts/urduD2.mjs
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "urdu-d2");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5266, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
const root = (page) => page.evaluate(() => `${document.documentElement.lang || "-"}/${document.documentElement.dir || "-"}`);

try {
  for (const [platform, theme] of [["macos", "light"], ["macos", "dark"], ["windows", "light"], ["windows", "dark"]]) {
    const where = `${platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1"}&theme=${theme}`;
    const label = `${platform} (${theme})`;
    /** The window on a screen of the fixture, the account's language Urdu. */
    const open = async (screen) => {
      const opened = await browser.newPage({ viewport: { width: 1280, height: 800 }, reducedMotion: "reduce" });
      opened.setDefaultTimeout(120_000);
      await opened.goto(`${base}scripts/screens/index.html?screen=${screen}&uiflags=${FLAGS},v21_urdu${where}&applang=ur`);
      await opened.getByRole("navigation", { name: "Main" }).waitFor();
      await opened.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 15_000 }).catch(() => undefined);
      await opened.waitForTimeout(1000);
      return opened;
    };
    let page = await open("library");
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

    // Shared › With me, from the Library panel's Shared (in the account's language since D1; in English before it).
    await page.getByRole("button", { name: /^(شیئر شدہ|Shared)$/ }).first().click();
    await page.locator("[data-v2=shared]").waitFor();
    await page.waitForTimeout(800);
    await shot("shared-with-me", "کی بھیجی ہوئی ریکارڈنگ");

    // My links and Request links: lib/sharing.ts's words.
    await page.getByRole("tab", { name: /میرے لنکس/ }).click();
    await page.getByRole("list", { name: "میرے لنکس" }).waitFor();
    await shot("shared-my-links", "بار دیکھا گیا");
    await page.getByRole("tab", { name: /درخواست کے لنکس/ }).click();
    await page.getByRole("list", { name: "درخواست کے لنکس" }).waitFor();
    await shot("shared-request-links", "فائلیں موصول ہوئیں");

    // The recording a teammate sent, open read-only.
    await page.getByRole("tab", { name: /میرے ساتھ/ }).click();
    await page.getByRole("list", { name: "میرے ساتھ شیئر شدہ" }).getByRole("button", { name: "Call with Omar", exact: true }).click();
    await page.locator("[data-v2=shared-reader]").waitFor();
    await page.getByRole("toolbar", { name: "تلاش اور کاپی" }).waitFor();
    await shot("shared-reader", "صرف پڑھنے کے لیے");

    // The Request files sheet, from New request link.
    // Back to Shared: the reader's own Back (the Library panel's Shared is also named شیئر شدہ since D1).
    await page.locator("[data-v2=shared-reader]").getByRole("button", { name: "شیئر شدہ" }).click();
    await page.locator("[data-v2=shared]").waitFor();
    await page.getByRole("button", { name: "نیا درخواست کا لنک" }).click();
    await page.getByRole("dialog", { name: "فائلیں منگوائیں" }).waitFor();
    await page.waitForTimeout(400);
    await shot("request-sheet", "لوگوں کو نظر آنے والا عنوان");
    await page.close();

    // A recording's Share sheet, from the Library's recording page (its own words aren't in the account's language yet).
    page = await open("library");
    await page.getByRole("button", { name: "Share", exact: true }).first().click();
    await page.getByRole("dialog", { name: /شیئر کریں$/ }).waitFor();
    await page.waitForTimeout(400);
    await shot("share-sheet", "کون کھول سکتا ہے");
    await page.close();

    // A project's filing rules (Projects › Acme Holdings, under its recordings).
    page = await open("projects");
    await page.getByRole("button", { name: /^Acme Holdings/ }).first().click();
    const rules = page.getByRole("region", { name: "رکھنے کے قواعد" });
    await rules.waitFor();
    await rules.scrollIntoViewIfNeeded();
    await shot("project-rules", "رکھنے کے قواعد");
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
