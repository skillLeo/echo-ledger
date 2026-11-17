// The desktop v2 bug bash (Day 5): every v2 screen at 100/130/200 % text, light and dark, English and Urdu (the
// gallery's screens; the app's own v2 screens are English), at 1280 x 800 and at the 720 px compact width. Each page is
// photographed and checked in the DOM for what a person would see as broken: the page scrolling sideways, text cut
// without an ellipsis, something past the window's edge, and controls on top of each other.
//   node src/v2/gallery/bugbash.mjs --out=DIR [--only=gallery|app] [--case=name]
// Text size is the window at 1/scale of its CSS size with that device scale, as when the system text grows.
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir, writeFile } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..", "..", "..");
const arg = (name) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split("=")[1];
const out = resolve(arg("out") ?? join(desktop, "..", "docs", "v2", "bugbash", "desktop-day5"));
const only = arg("only");
const onlyCase = arg("case");
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";

const FLAGS = "ui_v2.ask,ui_v2.shell,ui_v2.library,ui_v2.settings,ui_v2.setup_health,ui_v2.home,ui_v2.live,live_assist,ui_v2.recording_controls,ui_v2.after_call";
const app = (screen) => `scripts/screens/index.html?screen=${screen}&uiflags=${FLAGS}`;
const nav = (name) => async (page) => {
  await page.getByRole("navigation", { name: "Main" }).getByRole("button", { name, exact: true }).click();
  await page.waitForTimeout(1200);
};

/** [name, kind, path (theme added), languages, steps] */
const CASES = [
  ["g-library", "gallery", "?v2gallery&screen=library", ["en", "ur"]],
  ["g-recording", "gallery", "?v2gallery&screen=recording", ["en", "ur"]],
  ["g-plan", "gallery", "?v2gallery&screen=plan", ["en", "ur"]],
  ["g-settings", "gallery", "?v2gallery&screen=settings", ["en", "ur"]],
  ["g-library-error", "gallery", "?v2gallery&screen=library&state=error", ["en", "ur"]],
  ["g-assist", "gallery", "?v2gallery&screen=assist", ["en"]],
  ["a-library", "app", app("library"), ["en"]],
  ["a-recording", "app", app("library"), ["en"], async (page) => { await page.locator(".el-row__hit").first().click(); await page.waitForTimeout(1200); }],
  ["a-recording-screens", "app", app("library"), ["en"], async (page) => { await page.getByRole("button", { name: "Weekly sync with Northwind", exact: true }).click(); await page.waitForTimeout(1500); }],
  ["a-screen-viewer", "app", app("library"), ["en"], async (page) => { await page.getByRole("button", { name: "Weekly sync with Northwind", exact: true }).click(); await page.waitForTimeout(1500); await page.getByRole("region", { name: /screens?$/ }).getByRole("button").first().click(); await page.waitForTimeout(800); }],
  ["a-trash", "app", app("library"), ["en"], async (page) => { await page.getByRole("button", { name: "Trash", exact: true }).click(); await page.waitForTimeout(1200); }],
  ["a-home", "app", app("library"), ["en"], nav("Home")],
  ["a-live", "app", app("library"), ["en"], nav("Live")],
  ["a-live-translate", "app", app("library"), ["en"], async (page) => { await page.getByRole("navigation", { name: "Main" }).getByRole("button", { name: "Live", exact: true }).click(); await page.waitForTimeout(1200); await page.getByRole("button", { name: "Live Translate", exact: true }).click(); await page.waitForTimeout(800); }],
  ["a-live-translate-ended", "app", `${app("library")}&translate=ended`, ["en"], async (page) => { await page.getByRole("navigation", { name: "Main" }).getByRole("button", { name: "Live", exact: true }).click(); await page.waitForTimeout(1200); await page.getByRole("button", { name: "Live Translate", exact: true }).click(); await page.waitForTimeout(800); }],
  ["a-ask", "app", app("library"), ["en"], nav("Ask")],
  ["a-workspace", "app", app("project"), ["en"], async (page) => {
    await page.getByRole("button", { name: "Acme Holdings", exact: true }).first().click();
    await page.waitForTimeout(1200);
    await page.getByRole("tab", { name: "Memory & instructions" }).click();
    await page.waitForTimeout(800);
  }],
  ["a-workspace-menu", "app", app("project"), ["en"], async (page) => {
    await page.getByRole("button", { name: "Acme Holdings", exact: true }).first().click();
    await page.waitForTimeout(1200);
    await page.getByRole("button", { name: "Project menu" }).click();
    await page.waitForTimeout(300);
  }],
  ["a-prep", "app", app("library"), ["en"], async (page) => { await nav("Live")(page); await page.getByRole("button", { name: "New session" }).click(); await page.waitForTimeout(800); }],
  ["a-settings", "app", app("settings"), ["en"]],
  ["a-settings-page", "app", app("settings"), ["en"], async (page) => { await page.locator(".el-row__hit").first().click(); await page.waitForTimeout(1200); }],
  ["a-setup", "app", app("settings-mac"), ["en"], async (page) => { await page.getByRole("button", { name: "Setup & health" }).click(); await page.waitForTimeout(1500); }],
  ["a-help", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Help", exact: true }).click(); await page.waitForTimeout(1200); }],
  ["a-help-guide", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Help", exact: true }).click(); await page.waitForTimeout(1200); await page.getByRole("button", { name: "After a call", exact: true }).click(); await page.waitForTimeout(800); }],
  ["a-whats-new", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Help", exact: true }).click(); await page.waitForTimeout(1200); await page.getByRole("tab", { name: "What's new" }).click(); await page.waitForTimeout(800); }],
  ["a-contact", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Help", exact: true }).click(); await page.waitForTimeout(1200); await page.getByRole("tab", { name: "Contact support" }).click(); await page.waitForTimeout(500); }],
  ["a-set-voice", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Voice notes & dictation", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-replacements", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Voice notes & dictation", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Replacements", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-history", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Voice notes & dictation", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Recent dictations", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-captions", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Captions", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-caption-style", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Captions", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Style", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-calls", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Calls & meetings", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-call-sites", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Calls & meetings", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Call sites", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-language", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Language", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-words", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Custom words", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-key", "app", `${app("settings")}&plan=free`, ["en"], async (page) => { await page.getByRole("button", { name: "Transcription key", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-privacy", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Privacy & data", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-consent", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Privacy & data", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Recording consent", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-delete", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Privacy & data", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Delete my account", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-lock", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "App lock", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-pin", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "App lock", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "PIN", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-security", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Sign-in & security", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-activity", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Sign-in & security", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Sign-in activity", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-share", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Screen-share privacy", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-share-mac", "app", app("settings-mac"), ["en"], async (page) => { await page.getByRole("button", { name: "Screen-share privacy", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-share-check", "app", app("settings-mac"), ["en"], async (page) => { await page.getByRole("button", { name: "Screen-share privacy", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Check screen-share privacy", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-notifications", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Notifications", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-mic", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Microphone & audio", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-mic-mac", "app", app("settings-mac"), ["en"], async (page) => { await page.getByRole("button", { name: "Microphone & audio", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-screen", "app", `${app("settings")}&plan=pro`, ["en"], async (page) => { await page.getByRole("button", { name: "Screen moments", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-excluded", "app", `${app("settings")}&plan=pro`, ["en"], async (page) => { await page.getByRole("button", { name: "Screen moments", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Never capture these apps", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-storage", "app", `${app("settings")}&plan=pro`, ["en"], async (page) => { await page.getByRole("button", { name: "Storage", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-appearance", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Appearance", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-shortcuts", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Keyboard shortcuts", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-shortcuts-mac", "app", app("settings-mac"), ["en"], async (page) => { await page.getByRole("button", { name: "Keyboard shortcuts", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Change Dictation", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-profile", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Profile", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-plan-free", "app", `${app("settings")}&plan=free`, ["en"], async (page) => { await page.getByRole("button", { name: "Plan & usage", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: /^See every feature/ }).click(); await page.waitForTimeout(500); }],
  ["a-set-plan-pro", "app", `${app("settings")}&plan=pro`, ["en"], async (page) => { await page.getByRole("button", { name: "Plan & usage", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-plan-request", "app", `${app("settings")}&plan=free&request=awaiting_payment`, ["en"], async (page) => { await page.getByRole("button", { name: "Plan & usage", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-plan-near", "app", `${app("settings")}&plan=pro&near=1`, ["en"], async (page) => { await page.getByRole("button", { name: "Plan & usage", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-controls", "app", app("controls"), ["en"], async (page) => { await page.getByRole("button", { name: /Show the controls/ }).click(); await page.waitForTimeout(400); }],
];
const THEMES = ["light", "dark"];
const TEXT = [100, 130, 200];
const WIDTHS = [1280, 720];

/** Runs in the page: what looks broken, in words. */
function audit() {
  const found = [];
  const vw = window.innerWidth;
  const root = document.scrollingElement ?? document.documentElement;
  if (root.scrollWidth > vw + 1) found.push(`page scrolls sideways (${root.scrollWidth} > ${vw})`);
  const label = (el) => {
    const t = (el.getAttribute("aria-label") || el.textContent || "").trim().replace(/\s+/g, " ").slice(0, 60);
    return `${el.tagName.toLowerCase()}.${[...el.classList].slice(0, 2).join(".")} "${t}"`;
  };
  const scrollsX = (el) => {
    for (let p = el.parentElement; p; p = p.parentElement) {
      const o = getComputedStyle(p).overflowX;
      if (o === "auto" || o === "scroll") return true;
    }
    return false;
  };
  const visible = (el) => {
    const r = el.getBoundingClientRect();
    const s = getComputedStyle(el);
    // Hidden for the eye but kept for assistive tools (the rail's names, visually-hidden labels) is not "cut".
    const hiddenForEye = (e) => { for (let p = e; p; p = p.parentElement) if (getComputedStyle(p).clipPath !== "none") return true; return false; };
    return r.width > 0 && r.height > 0 && s.visibility !== "hidden" && s.display !== "none" && !el.closest(".el-visually-hidden") && !hiddenForEye(el);
  };
  // With a modal open, only the modal counts: the page behind it is covered by its scrim.
  const modal = document.querySelector(".el-root [aria-modal=true]");
  const scope = [...(modal ?? document).querySelectorAll(modal ? "*" : ".el-root *")].filter(visible);
  for (const el of scope) {
    const s = getComputedStyle(el);
    const clips = s.overflowX === "hidden" || s.overflowX === "clip";
    if (clips && el.scrollWidth > el.clientWidth + 2 && s.textOverflow !== "ellipsis" && el.textContent.trim() && !el.matches(".el-visually-hidden, .el-progress, .el-dialog, .el-sheet, .el-shell__main, .g-app, .el-shell")) {
      found.push(`cut without an ellipsis: ${label(el)}`);
    }
    const r = el.getBoundingClientRect();
    if ((r.right > vw + 1 || r.left < -1) && !scrollsX(el) && el.children.length === 0 && el.textContent.trim()) found.push(`past the window's edge: ${label(el)}`);
  }
  const controls = scope.filter((el) => el.matches("button, input, select, textarea, [role=switch], [role=combobox]") && !el.matches(".el-row__hit"));
  for (let i = 0; i < controls.length; i++) {
    for (let j = i + 1; j < controls.length; j++) {
      const a = controls[i].getBoundingClientRect();
      const b = controls[j].getBoundingClientRect();
      const w = Math.min(a.right, b.right) - Math.max(a.left, b.left);
      const h = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
      if (w > 2 && h > 2 && !controls[i].contains(controls[j]) && !controls[j].contains(controls[i])) found.push(`controls overlap: ${label(controls[i])} / ${label(controls[j])}`);
    }
  }
  return [...new Set(found)];
}

const server = await createServer({ root: desktop, configFile: join(desktop, "vite.config.ts"), server: { port: 1450, strictPort: false }, logLevel: "error" });
await server.listen();
const base = server.resolvedUrls.local[0];
await mkdir(out, { recursive: true });
const browser = await chromium.launch();
const report = [];
try {
  for (const [name, kind, path, langs, step] of CASES) {
    if ((only && kind !== only) || (onlyCase && name !== onlyCase)) continue;
    for (const lang of langs) for (const theme of THEMES) for (const text of TEXT) for (const width of WIDTHS) {
      const scale = text / 100;
      const page = await browser.newPage({ viewport: { width: Math.round(width / scale), height: Math.round(800 / scale) }, deviceScaleFactor: scale, colorScheme: theme, reducedMotion: "reduce" });
      const errors = [];
      page.on("pageerror", (e) => errors.push(e.message));
      await page.goto(`${base}${path}&theme=${theme}${kind === "gallery" ? `&lang=${lang}` : ""}`);
      await page.waitForTimeout(kind === "app" ? 2200 : 700);
      try {
        if (step) await step(page);
      } catch (e) {
        errors.push(`step failed: ${String(e.message).split("\n")[0]}`);
      }
      const file = `${name}-${lang}-${theme}-t${text}-w${width}.png`;
      await page.screenshot({ path: join(out, file) });
      const issues = [...(await page.evaluate(audit)), ...errors.map((e) => `script error: ${e}`)];
      report.push({ file, name, lang, theme, text, width, issues });
      await page.close();
    }
  }
} finally {
  await browser.close();
  await server.close();
}
await writeFile(join(out, `report${only ? `-${only}` : ""}${onlyCase ? `-${onlyCase}` : ""}.json`), JSON.stringify(report, null, 1));
const bad = report.filter((r) => r.issues.length);
console.log(`${report.length} pages, ${bad.length} with findings`);
for (const r of bad) console.log(r.file, "\n  " + r.issues.join("\n  "));
