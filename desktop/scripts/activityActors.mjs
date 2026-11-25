// Contract 2.0.0-aa (UX-18, lead decision 27): Activity items about a person show them in the icon's place for the kinds
// that show a face, their picture (a teammate who sent a recording) or their initials in their colour (an upgrade asked
// for, no picture); a receipt asked for keeps its tile. The new look on a Mac and on Windows, the new look in Urdu (right
// to left), and the classic page, light and dark, at 1280 × 800, in the screens fixture with &actors=1. Each picture is
// docs/design/screens/desktop/activity-actor/<look>-<platform>-<theme>.png. The run fails when a person's row shows no
// avatar, the picture doesn't load, or the receipt's row shows a face.
//   node scripts/activityActors.mjs
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "activity-actor");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5272, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
const looks = [["v2", "macos"], ["v2", "windows"], ["v2-ur", "macos"], ["classic", "macos"]];
try {
  for (const [look, platform] of looks) {
    for (const theme of ["light", "dark"]) {
      const where = `${platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1"}&theme=${theme}`;
      const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
      page.setDefaultTimeout(120_000);
      const flags = look === "v2" ? `&uiflags=${FLAGS}` : look === "v2-ur" ? `&uiflags=${FLAGS},v21_urdu&applang=ur` : "";
      await page.goto(`${base}scripts/screens/index.html?screen=activity&actors=1${flags}${where}`);
      const person = page.getByText("Ayesha Khan sent you Budget review with Acme", { exact: true }).first();
      await person.waitFor();
      await page.waitForTimeout(1200);
      const seen = await page.evaluate((v2) => {
        const rowOf = (text) => [...document.querySelectorAll("li")].find((li) => li.textContent?.includes(text));
        const pic = rowOf("Ayesha Khan sent you")?.querySelector(v2 ? ".el-avatar img" : ".activity-tile.person img");
        const initials = rowOf("Bilal Qureshi asked to upgrade")?.querySelector(v2 ? ".el-avatar" : ".activity-tile.person");
        const receipt = rowOf("Sara Ahmed asked for your receipt")?.querySelector(v2 ? ".el-avatar" : ".activity-tile.person");
        return { picture: !!pic && pic.complete && pic.naturalWidth > 0, initials: initials?.textContent?.trim() ?? "", receiptFace: !!receipt, dir: document.documentElement.dir || "-" };
      }, look !== "classic");
      const name = `${look}-${platform}-${theme}`;
      await page.mouse.move(0, 0);
      await page.screenshot({ path: join(out, `${name}.png`) });
      console.log(`${name}: picture ${seen.picture}, initials "${seen.initials}", receipt face ${seen.receiptFace}, dir ${seen.dir}`);
      if (!seen.picture) problems.push(`${name}: no picture`);
      if (seen.initials !== "BQ") problems.push(`${name}: initials "${seen.initials}"`);
      if (seen.receiptFace) problems.push(`${name}: the receipt's row shows a face`);
      if (look === "v2-ur" && seen.dir !== "rtl") problems.push(`${name}: not right to left`);
      await page.close();
    }
  }
} finally {
  await browser.close();
  await server.close();
}
if (problems.length) {
  console.error(problems.join("\n"));
  process.exit(1);
}
