// Desktop Urdu, D4, in the screens fixture (every v2 flag and v21_urdu on, the account's language Urdu: "&applang=ur",
// 1280 × 800), on a Mac and on Windows, in the light and the dark theme: the recording page with its transcript playing,
// its bookmarks and its screens; the Copy menu; the recording's ⋮ and the Move dialog; a failed
// transcript with Try again, and with the line for a missing Groq key; the Transcribe dialog with its minutes. It checks
// that the window reads right to left and that each scene speaks Urdu, and saves
// docs/design/screens/desktop/urdu-d4/<platform>-<step>.png (light) and <platform>-<step>-dark.png; the run fails when
// either check doesn't hold. The fixture's answers it lacks (a bookmark on the call with screens, the Transcribe
// estimate) are given in the page, around the fixture's own IPC mock.
//   node scripts/urduD4.mjs
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop", "urdu-d4");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5269, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await chromium.launch();
await mkdir(out, { recursive: true });
const problems = [];
const root = (page) => page.evaluate(() => `${document.documentElement.lang || "-"}/${document.documentElement.dir || "-"}`);

/** The answers the fixture doesn't give, around its IPC mock: a bookmark on the call with screens, the minutes to use. */
const extraAnswers = (page) => page.evaluate(() => {
  const internals = window.__TAURI_INTERNALS__;
  const invoke = internals.invoke.bind(internals);
  internals.invoke = async (cmd, args, options) => {
    if (cmd === "sessions_transcribe_estimate") return { minutes: 7, minutes_left: 120, items: [] };
    const answer = await invoke(cmd, args, options);
    if (cmd === "session_detail" && args?.id === "r1") return { ...answer, markers: [{ id: "mk-r1", at_ms: 95_000, tag: "Billing", note: "The Pay button" }] };
    return answer;
  };
});

try {
  for (const [platform, theme] of [["macos", "light"], ["macos", "dark"], ["windows", "light"], ["windows", "dark"]]) {
    const where = `${platform === "windows" ? "&platform=windows" : "&platform=macos&lights=1"}&theme=${theme}`;
    const label = `${platform} (${theme})`;
    /** The window on the fixture's Library (the Kick-off call open), the account's language Urdu. */
    const open = async (extra = "") => {
      const opened = await browser.newPage({ viewport: { width: 1280, height: 800 }, reducedMotion: "reduce" });
      opened.setDefaultTimeout(120_000);
      await opened.goto(`${base}scripts/screens/index.html?screen=library&uiflags=${FLAGS},v21_urdu${where}&applang=ur${extra}`);
      await opened.getByRole("navigation", { name: "Main" }).waitFor();
      await opened.waitForFunction(() => document.documentElement.dir === "rtl", null, { timeout: 30_000 }).catch(() => undefined);
      await opened.waitForTimeout(1000);
      await extraAnswers(opened);
      return opened;
    };
    /** From the open recording back to the Library, and into the recording called `title`. */
    const openRecording = async (page, title) => {
      await page.locator(".el-page__back").first().click();
      await page.getByRole("button", { name: title }).first().click();
      await page.locator(".el-page__titles").first().waitFor();
      await page.waitForTimeout(800);
    };
    let page = await open();
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

    // The call with screens, a bookmark and its transcript, playing: its head and player, then its lines from the second.
    await openRecording(page, /^Weekly sync with Northwind/);
    await page.getByRole("button", { name: "چلائیں", exact: true }).click();
    await page.waitForTimeout(800);
    await page.evaluate(() => document.querySelectorAll("*").forEach((el) => { if (el.scrollTop > 0) el.scrollTop = 0; }));
    await shot("recording", "خاموشی چھوڑ دیں");
    await page.getByRole("list", { name: "ٹرانسکرپٹ" }).locator(".el-segment__play").first().click();
    await page.waitForTimeout(1500);
    await shot("recording-transcript", "اسکرین");

    // The Copy menu, from Copy's chevron.
    await page.getByRole("button", { name: "کاپی کے مزید طریقے" }).click();
    await page.getByRole("menu").first().waitFor();
    await shot("copy-menu", "وقت کے ساتھ کاپی کریں");
    await page.keyboard.press("Escape");

    // The recording's ⋮ (the Library's one menu with this page's parts), then the Move dialog. Its choices' list is left
    // closed: open, it is cut by the dialog's body, in English as in Urdu (the shared Select in a Dialog, not D4's).
    await page.getByRole("button", { name: "ریکارڈنگ کے اختیارات" }).click();
    await page.getByRole("menu", { name: "ریکارڈنگ کے اختیارات" }).waitFor();
    await shot("organise-menu", "زبان بدلیں اور دوبارہ ٹرانسکرائب کریں…");
    await page.getByRole("menuitem", { name: "پروجیکٹ میں منتقل کریں…" }).click();
    const move = page.getByRole("dialog", { name: "پروجیکٹ میں منتقل کریں" });
    await move.waitFor();
    await page.waitForTimeout(400);
    await shot("move-dialog", "کہاں منتقل کریں");
    await page.keyboard.press("Escape");
    await page.waitForTimeout(400);

    // The failed call: why, in the app's words, and Try again; then its ⋮'s Transcribe… with the minutes.
    await openRecording(page, /^\+92 300 1234567/);
    await shot("failed", "دوبارہ کوشش کریں");
    await page.getByRole("button", { name: "ریکارڈنگ کے اختیارات" }).click();
    await page.getByRole("menuitem", { name: "ٹرانسکرائب کریں…", exact: true }).click();
    await page.getByRole("dialog", { name: "یہ ریکارڈنگ ٹرانسکرائب کریں؟" }).waitFor();
    await page.waitForTimeout(600);
    await shot("transcribe-dialog", "اس مہینے کے باقی");
    await page.close();

    // The same call when the plan transcribes with the person's own key and none is added yet.
    page = await open("&failure=needs_transcription_key");
    await openRecording(page, /^\+92 300 1234567/);
    await shot("failed-key", "اپنی Groq کلید شامل کریں");
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
