// D-256: a right-click menu opens on the same side as its sub-menus. In English, to the pointer's right; right to left
// (Urdu), to the pointer's left, and moved inside the window at its edge. The screens fixture on Windows (our own menu),
// light and dark, with a dot where the pointer pressed, saved under docs/design/screens/desktop/menu-rtl/.
//   <lang>-<theme>-row.png    a recording's menu at the pointer
//   <lang>-<theme>-sub.png    its Labels › open
//   ur-<theme>-edge.png       pressed near the window's left edge: the menu stays inside it
//   node scripts/menuRtl.mjs
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "menu-rtl");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5236, strictPort: false } });
await server.listen();
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
// The pointer, drawn for the shot only.
const dot = (page, x, y) => page.evaluate(([x, y]) => {
  document.getElementById("shot-pointer")?.remove();
  const d = document.createElement("div");
  d.id = "shot-pointer";
  Object.assign(d.style, { position: "fixed", left: `${x - 5}px`, top: `${y - 5}px`, width: "10px", height: "10px", borderRadius: "50%",
    background: "#e11d48", boxShadow: "0 0 0 2px #fff", zIndex: 2147483647, pointerEvents: "none" });
  document.body.appendChild(d);
}, [x, y]);
try {
  for (const lang of ["en", "ur"]) {
    for (const theme of ["light", "dark"]) {
      const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
      await page.addInitScript(() => Object.defineProperty(navigator, "platform", { value: "Win32", configurable: true }));
      await page.goto(`${server.resolvedUrls.local[0]}scripts/screens/index.html?screen=library&theme=${theme}&uiflags=${V2_FLAGS}${lang === "ur" ? "&lang=ur" : ""}`);
      await page.getByRole("button", { name: "Library", exact: true }).last().click();
      const rows = page.locator("button[data-row-id]");
      await rows.first().waitFor();
      await page.waitForTimeout(300);
      const shot = (name) => page.screenshot({ path: join(out, `${lang}-${theme}-${name}.png`) });
      const box = await rows.nth(2).boundingBox();
      const x = Math.round(box.x + box.width / 2);
      const y = Math.round(box.y + 20);
      await page.mouse.click(x, y, { button: "right" });
      await page.getByRole("menu", { name: "Recording actions" }).waitFor();
      await dot(page, x, y);
      await shot("row");
      await page.getByRole("menuitem", { name: "Labels" }).hover();
      await page.waitForTimeout(200);
      await shot("sub");
      await page.keyboard.press("Escape");
      await page.keyboard.press("Escape");
      if (lang === "ur") {
        const edge = await rows.nth(4).boundingBox();
        const ex = Math.round(edge.x + 40);
        const ey = Math.round(edge.y + 20);
        await page.mouse.click(ex, ey, { button: "right" });
        await page.getByRole("menu", { name: "Recording actions" }).waitFor();
        await dot(page, ex, ey);
        await shot("edge");
      }
      await page.close();
    }
  }
} finally {
  await browser.close();
  await server.close();
}
