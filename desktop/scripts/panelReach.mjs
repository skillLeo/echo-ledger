// UX-02 (PLAN_V2_1_POLISH): at 1024 px every panel item is reached in two clicks or fewer, with v21_panel_flyout on.
// For each place that has a panel: open it from the rail (one click) and press each of its items (the second click; folds
// and ⋯ menus open in place and are not items);
// when an item closes the flyout, the next one opens it again from the rail. The page header's toggle opens it too.
// Then the keyboard: Tab never reaches the page behind the scrim, and Esc closes only the flyout, back to its toggle.
// Fails, with what could not be reached, when any item needs more, is covered or does not take the click.
//   node scripts/panelReach.mjs [width]
import { createServer } from "vite";
import { chromium } from "playwright";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const width = Number(process.argv[2] ?? 1024);
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5236, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
const problems = [];
/** Closes what an item opened (a dialog, a sheet, a menu) as a person would: Esc, else a press on its scrim. */
const settle = async (page) => {
  const open = page.locator('.el-scrim:visible, [role="dialog"]:visible, [role="menu"]:visible, [aria-modal="true"]:visible');
  for (let i = 0; i < 4 && (await open.count()); i++) {
    await page.keyboard.press("Escape");
    await page.waitForTimeout(150);
    const scrim = page.locator(".el-scrim:visible").first();
    if (await scrim.count()) {
      const box = await scrim.boundingBox();
      if (box) await page.mouse.click(box.x + 4, box.y + 4);
      await page.waitForTimeout(150);
    }
  }
};
let reached = 0;
try {
  const page = await browser.newPage({ viewport: { width, height: 800 }, reducedMotion: "reduce" });
  await page.goto(`${base}scripts/screens/index.html?screen=library&theme=light&uiflags=${FLAGS},v21_panel_flyout`);
  await page.getByRole("button", { name: "Library", exact: true }).last().click();
  await page.locator("button[data-row-id]").first().waitFor();
  const rail = page.getByRole("navigation", { name: "Main" });
  const panel = page.getByTestId("nav-panel");
  const places = ["Library", "Projects", "Live", "Ask", "Activity", "Settings"];
  for (const place of places) {
    await settle(page);
    const item = rail.getByRole("button", { name: new RegExp(`^${place}`) }).first();
    // The page header's toggle opens the panel in one click (before anything else on this place).
    await item.click();
    if (!(await panel.waitFor({ timeout: 3000 }).then(() => true, () => false))) { problems.push(`${place}: the rail did not open its panel`); continue; }
    await page.keyboard.press("Escape");
    await panel.waitFor({ state: "detached", timeout: 3000 }).catch(() => undefined);
    const toggle = page.getByRole("button", { name: `Show the ${place} panel` });
    if (await toggle.waitFor({ timeout: 5000 }).then(() => true, () => false)) {
      await toggle.click();
      if (!(await panel.waitFor({ timeout: 3000 }).then(() => true, () => false))) problems.push(`${place}: the page header's toggle did not open the panel`);
    } else problems.push(`${place}: no toggle at the start of the page header`);
    if (!(await panel.count())) await item.click();
    await panel.waitFor({ timeout: 3000 }).catch(() => undefined);
    // The items: what a person picks (folds and ⋯ menus open in place and are not items).
    const ITEMS = ".el-panel__body button:visible:not([aria-expanded]):not([aria-haspopup])";
    const names = await panel.locator(ITEMS).evaluateAll((els) => els.map((b) => (b.getAttribute("aria-label") ?? b.textContent ?? "").trim()));
    if (!names.length) problems.push(`${place}: no items in its panel`);
    for (const [index, name] of names.entries()) {
      let clicks = 0;
      if (!(await panel.count())) {
        await item.click();
        clicks++;
        await panel.waitFor({ timeout: 3000 }).catch(() => undefined);
      }
      const button = panel.locator(ITEMS).nth(index);
      try {
        await button.click({ timeout: 3000 });
        clicks++;
        reached++;
        // A choice closes the flyout a moment later.
        await page.waitForTimeout(250);
      } catch (e) {
        problems.push(`${place} › ${name}: could not be pressed (${e.message.split("\n")[0]})`);
      }
      if (clicks > 2) problems.push(`${place} › ${name}: ${clicks} clicks`);
      // A dialog, sheet or menu it opened closes; the next item starts from the rail again.
      await settle(page);
    }
  }
  // Keyboard: Tab stays out of the page behind the scrim (it is inert; the rail is not), and Esc closes the flyout only,
  // with focus back where it was: an open recording stays open, and the toggle that opened it gets focus again.
  await settle(page);
  const rows = page.locator("button[data-row-id]");
  await rail.getByRole("button", { name: /^Library/ }).first().click();
  await panel.waitFor({ timeout: 3000 }).catch(() => undefined);
  await page.keyboard.press("Escape");
  await panel.waitFor({ state: "detached", timeout: 3000 }).catch(() => undefined);
  // The filters an item opened close with the page's own Esc, now that the flyout is gone.
  for (let i = 0; i < 3 && (await page.locator(".el-filter-scrim:visible").count()); i++) await page.keyboard.press("Escape");
  await rows.first().click();
  await rows.first().waitFor({ state: "detached", timeout: 3000 }).catch(() => problems.push("keyboard: the recording did not open"));
  await page.evaluate(() => document.activeElement?.setAttribute("data-was-focused", ""));
  await page.keyboard.press("Control+Backslash");
  await panel.waitFor({ timeout: 3000 }).catch(() => problems.push("keyboard: Ctrl+\\ did not open the flyout over the recording"));
  for (let i = 0; i < 40; i++) {
    await page.keyboard.press("Tab");
    if (await page.evaluate(() => !!document.activeElement?.closest("main"))) { problems.push(`keyboard: Tab ${i + 1} reached the page behind the scrim`); break; }
  }
  await page.keyboard.press("Escape");
  await panel.waitFor({ state: "detached", timeout: 3000 }).catch(() => problems.push("keyboard: Esc did not close the flyout"));
  if (await rows.count()) problems.push("keyboard: Esc closed the recording behind the flyout");
  await page.keyboard.press("Escape");
  await rows.first().waitFor({ timeout: 3000 }).catch(() => problems.push("keyboard: Esc did not close the recording afterwards"));
  const libToggle = page.getByRole("button", { name: "Show the Library panel" });
  await libToggle.focus();
  await page.keyboard.press("Enter");
  await panel.waitFor({ timeout: 3000 }).catch(() => problems.push("keyboard: the toggle did not open the flyout"));
  await page.keyboard.press("Escape");
  await panel.waitFor({ state: "detached", timeout: 3000 }).catch(() => undefined);
  if (!(await page.evaluate(() => document.activeElement?.getAttribute("aria-label") === "Show the Library panel"))) problems.push("keyboard: focus did not go back to the toggle");
  await page.close();
} finally {
  await browser.close();
  await server.close();
}
console.log(`${reached} panel items reached in two clicks or fewer at ${width} px`);
if (problems.length) {
  console.error(problems.join("\n"));
  process.exit(1);
}
