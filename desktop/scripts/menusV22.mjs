// UX-22's right-click menus on Windows (and the browser): ours, in Windows 11's style, in the screens fixture, light
// and dark, saved under docs/design/screens/desktop/menus-v22/. A Mac shows the system's own menu instead (a native
// window, which a browser can't draw: those shots are taken in the app).
//   windows-<theme>-one.png      a recording's menu at the pointer
//   windows-<theme>-labels.png   its Labels › open
//   windows-<theme>-many.png     two picked: "2 recordings"
//   windows-<theme>-space.png    the list's empty space
//   node scripts/menusV22.mjs
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "menus-v22");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5235, strictPort: false } });
await server.listen();
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
try {
  for (const theme of ["light", "dark"]) {
    const page = await browser.newPage({ viewport: { width: 1280, height: 1000 }, colorScheme: theme, reducedMotion: "reduce" });
    // Windows: our menu (a Mac would ask for the system's).
    await page.addInitScript(() => Object.defineProperty(navigator, "platform", { value: "Win32", configurable: true }));
    await page.goto(`${server.resolvedUrls.local[0]}scripts/screens/index.html?screen=library&theme=${theme}&uiflags=${V2_FLAGS}`);
    await page.getByRole("button", { name: "Library", exact: true }).last().click();
    const rows = page.locator("button[data-row-id]");
    await rows.first().waitFor();
    await page.waitForTimeout(300);
    const shot = (name) => page.screenshot({ path: join(out, `windows-${theme}-${name}.png`) });
    const box = await rows.nth(2).boundingBox();
    await page.mouse.click(box.x + 300, box.y + 20, { button: "right" });
    await page.getByRole("menu", { name: "Recording actions" }).waitFor();
    await shot("one");
    await page.getByRole("menuitem", { name: "Labels" }).hover();
    await page.waitForTimeout(200);
    await shot("labels");
    await page.keyboard.press("Escape");
    await page.keyboard.press("Escape");
    await rows.nth(2).click({ modifiers: ["Meta"] });
    await rows.nth(3).click({ modifiers: ["Meta"] });
    const many = await rows.nth(3).boundingBox();
    await page.mouse.click(many.x + 300, many.y + 20, { button: "right" });
    await page.getByRole("menu", { name: "Actions for 2 recordings" }).waitFor();
    await shot("many");
    await page.keyboard.press("Escape");
    await page.keyboard.press("Escape");
    await page.mouse.click(900, 960, { button: "right" });
    await page.getByRole("menu", { name: "List actions" }).waitFor();
    await shot("space");
    await page.close();
  }
} finally {
  await browser.close();
  await server.close();
}
