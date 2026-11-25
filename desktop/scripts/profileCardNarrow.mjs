// D-229: the Settings profile card in a narrow window (720 px, the half screen) on Free, with a long email, at 100%
// and zoomed to 150% (UX-07). Below 760 px the card may wrap, but its words were sized from 0 (flex: 1), so it never
// did: the email got its "…" beside Upgrade. In the screens fixture (classic), saved under
// docs/design/screens/desktop/d-229/:
//   <prefix>-settings-free-720-<zoom>-en-<theme>.png
// The run fails, after the change, when the email is cut or Upgrade isn't under the card's words.
//   node scripts/profileCardNarrow.mjs before
//   node scripts/profileCardNarrow.mjs after
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = process.env.PROFILE_OUT ?? join(desktop, "..", "docs", "design", "screens", "desktop", "d-229");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const prefix = process.argv[2] ?? "after";
const EMAIL = "ayesha.khan@northwind.example";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5244, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
for (const zoom of [100, 150]) for (const theme of ["light", "dark"]) {
  // Zoomed as the web view does it: the window's 720 px hold 720 / zoom of the page's pixels, drawn that much bigger.
  const z = zoom / 100;
  const page = await browser.newPage({ viewport: { width: Math.round(720 / z), height: Math.round(800 / z) }, deviceScaleFactor: z, colorScheme: theme, reducedMotion: "reduce" });
  await page.goto(`${base}scripts/screens/index.html?screen=settings&plan=free&theme=${theme}&zoom=${zoom}&email=${encodeURIComponent(EMAIL)}`);
  // A busy machine serves the fixture slowly; the wait is for it to load, not a measure of anything.
  const card = page.getByTestId("profile-card");
  await card.waitFor({ timeout: 60_000 });
  await page.getByText(EMAIL).waitFor({ timeout: 60_000 });
  const m = await card.evaluate((el) => {
    const email = el.querySelector(".profile-email");
    const upgrade = el.querySelector(".profile-upgrade");
    const e = email.getBoundingClientRect();
    const u = upgrade?.getBoundingClientRect();
    return { cut: email.scrollWidth > email.clientWidth + 1, upgradeUnder: u ? u.top >= e.bottom - 1 : null, cardWidth: el.getBoundingClientRect().width };
  });
  await card.screenshot({ path: join(out, `${prefix}-settings-free-720-${zoom}-en-${theme}.png`) });
  console.log(`${zoom}% ${theme}: card ${Math.round(m.cardWidth)} px, email cut ${m.cut}, Upgrade under ${m.upgradeUnder}`);
  if (m.cut) problems.push(`${zoom}% ${theme}: the email is cut`);
  await page.close();
}
await browser.close();
await server.close();
if (prefix === "after" && problems.length) {
  console.error(problems.join("\n"));
  process.exit(1);
}
