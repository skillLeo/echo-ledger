// D-242: Settings › Captions › Caption style's preview shows the overlay's newest lines: at Lines 2 only the newest two
// (the first turn out of view), at 5 every turn. In the screens fixture (classic), light and dark, and in Urdu (the card on
// the right), saved under docs/design/screens/desktop/d-242/:
//   <prefix>-caption-preview-lines<2|5>-<theme>.png, <prefix>-caption-preview-lines2-ur-<theme>.png
// The run fails, after the change, when Lines 2 shows the first turn or cuts a line, Lines 5 hides one, or the Urdu card
// is not on the right.
//   node scripts/captionPreviewLines.mjs before
//   node scripts/captionPreviewLines.mjs after
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = process.env.CAPTION_OUT ?? join(desktop, "..", "docs", "design", "screens", "desktop", "d-242");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const prefix = process.argv[2] ?? "after";
const server = await createServer({ root: desktop, logLevel: "silent", server: { port: 5252, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
for (const [theme, ur] of [["light", false], ["dark", false], ["light", true]]) {
  const page = await browser.newPage({ viewport: { width: 1200, height: 900 }, colorScheme: theme, reducedMotion: "reduce" });
  await page.goto(`${base}scripts/screens/index.html?screen=settings&theme=${theme}${ur ? "&lang=ur" : ""}`);
  // A busy machine serves the fixture slowly; the waits are for it to load, not a measure of anything.
  const captions = page.locator("button, a, [role=button], [role=link]").filter({ hasText: /^Captions$|^کیپشنز$/ }).first();
  await captions.waitFor({ timeout: 60_000 });
  // The fixture answers no settings writes: here one is merged and handed back, as the core does, so Lines applies.
  await page.evaluate(() => {
    const ipc = window.__TAURI_INTERNALS__;
    const invoke = ipc.invoke.bind(ipc);
    let current = null;
    ipc.invoke = async (cmd, args, options) => {
      if (cmd === "get_settings") return current ?? (current = await invoke(cmd, args, options));
      if (cmd === "update_settings") return (current = { ...(current ?? (await invoke("get_settings", {}, options))), ...args.patch });
      return invoke(cmd, args, options);
    };
  });
  await captions.click();
  const style = page.locator("button, a, [role=button], [role=link]").filter({ hasText: /style|انداز/i }).first();
  await style.waitFor({ timeout: 60_000 });
  await style.click();
  for (const lines of ur ? [2] : [2, 5]) {
    const group = page.locator("[role=radiogroup]").filter({ has: page.getByRole("radio", { name: "5" }) }).first();
    await group.getByRole("radio", { name: String(lines) }).click();
    await page.waitForTimeout(400);
    const card = page.locator(".caption-sample").first();
    const m = await card.evaluate((el, n) => {
      // Before D-242 the card held its turns itself, with no box of lines.
      const holder = el.querySelector(".caption-sample-lines") ?? el;
      const box = holder.getBoundingClientRect();
      const turns = [...holder.children].map((d) => {
        const r = d.getBoundingClientRect();
        return { text: d.textContent, whole: r.top >= box.top - 0.5 && r.bottom <= box.bottom + 0.5, hidden: r.bottom <= box.top + 0.5 };
      });
      const preview = el.parentElement.getBoundingClientRect();
      const c = el.getBoundingClientRect();
      return { lines: n, set: el.style.getPropertyValue("--caption-lines"), boxH: Math.round(box.height), turns, right: Math.abs(preview.right - c.right) < Math.abs(preview.left - c.left) };
    }, lines);
    await card.screenshot({ path: join(out, `${prefix}-caption-preview-lines${lines}${ur ? "-ur" : ""}-${theme}.png`) });
    console.log(theme, ur ? "ur" : "en", JSON.stringify(m));
    const shown = m.turns.filter((t) => !t.hidden);
    const ok = shown.every((t) => t.whole) && m.turns.at(-1).whole && (lines === 2 ? m.turns[0].hidden : m.turns.every((t) => t.whole)) && (!ur || m.right);
    if (!ok) problems.push(`${theme}${ur ? "-ur" : ""}-lines${lines}`);
  }
  await page.close();
}
await browser.close();
await server.close();
if (problems.length) {
  console.log(`the preview doesn't show the overlay's newest lines: ${problems.join(", ")}`);
  process.exitCode = 1;
}
