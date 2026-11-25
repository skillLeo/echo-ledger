// Desktop Urdu, the shared fixes (fix/D-urdu-shared): the pieces every Urdu screen uses (scripts/screens/urduShared.tsx),
// on a Mac (with its window buttons) and on Windows, light and dark, 1024 × 640: the rows, dates, page head and toast,
// and a Sheet. Each picture is docs/design/screens/desktop/urdu-shared/<set>/<platform>-<scene>-<theme>.png, <set>
// "before" (v1.1 as it was) or "after". The run fails when the window isn't right to left.
//   node scripts/urduShared.mjs --set=before|after
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const set = process.argv.find((a) => a.startsWith("--set="))?.split("=")[1];
if (set !== "before" && set !== "after") throw new Error("--set=before or --set=after");
const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "urdu-shared", set);
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5267, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
try {
  for (const platform of ["macos", "windows"]) {
    for (const theme of ["light", "dark"]) {
      for (const scene of ["lines", "sheet"]) {
        const page = await browser.newPage({ viewport: { width: 1024, height: 640 }, reducedMotion: "reduce" });
        await page.goto(`${base}scripts/screens/urduShared.html?platform=${platform}&theme=${theme}&scene=${scene}`);
        await page.locator('[data-ready="true"]').waitFor();
        await page.waitForTimeout(800);
        const name = `${platform}-${scene}-${theme}`;
        await page.screenshot({ path: join(out, `${name}.png`) });
        const at = await page.evaluate(() => `${document.documentElement.lang}/${document.documentElement.dir}`);
        console.log(`${set} ${name}: <html> ${at}`);
        if (at !== "ur/rtl") problems.push(`${name}: ${at}`);
        await page.close();
      }
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
