// Screenshots of every desktop screen, light and dark, at 1280 x 800, into docs/design/screens/desktop/. The views run in
// the Vite dev server's fixture mode (scripts/screens/) or the older harness (screenshots/harness.ts, screen moments and
// held recordings), answered by Tauri's IPC mock with sample data. Motion is off (reduced motion), so nothing is caught
// mid-transition.
//   node scripts/screenshots.mjs              every screen
//   node scripts/screenshots.mjs library      only the screens whose name starts with "library"
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(desktop, "..", "docs", "design", "screens", "desktop");

/** Screen name, fixture screen, and what to do before the picture. */
const SCREENS = [
  ["library", "library", async (page) => page.waitForSelector(".segments .segment")],
  ["library-urdu", "library", async (page) => {
    await page.waitForSelector(".segments .segment");
    await page.getByRole("button", { name: "اردو" }).click();
    await page.waitForSelector(".segments[lang=ur]");
  }],
  ["library-find", "library", async (page) => {
    await page.waitForSelector(".segments .segment");
    await page.getByRole("button", { name: "Find in this transcript" }).click();
    await page.getByRole("textbox", { name: "Find in transcript" }).fill("launch");
  }],
  ["library-waiting", "library", async (page) => {
    await page.getByTestId("library-list").getByRole("button", { name: "Review", exact: true }).click();
    await page.waitForSelector(".queue-item.pending");
  }],
  ["library-empty", "library-empty", async (page) => page.waitForSelector(".empty-state")],
  // v1.5: a call a backup engine transcribed.
  ["library-backup", "library-backup", async (page) => page.waitForSelector("[data-testid=backup-engine]")],
  // v1.5: Projects with subfolders, the Library's smart folders, moving, sharing and request links.
  ["projects", "projects", async (page) => page.waitForSelector(".project-tree.on-page")],
  ["project", "project", async (page) => {
    await openAcme(page);
    await page.getByRole("button", { name: "Edit rules" }).click();
    await page.waitForSelector(".rules-list");
  }],
  ["project-notes", "project", async (page) => {
    await openAcme(page);
    await page.getByRole("tab", { name: "Notes", exact: true }).click();
    await page.getByRole("textbox", { name: "Question" }).fill("When do we launch?");
    await page.getByRole("button", { name: "Ask", exact: true }).click();
    await page.waitForSelector(".ask-answer");
  }],
  ["project-files", "project", async (page) => {
    await openAcme(page);
    await page.getByRole("tab", { name: "Files", exact: true }).click();
    await page.waitForSelector(".file-row");
  }],
  ["project-delete", "project", async (page) => {
    await openAcme(page);
    await page.getByRole("button", { name: "Project menu" }).click();
    await page.getByRole("menuitem", { name: "Delete project…" }).click();
    await page.waitForSelector(".sheet");
  }],
  ["library-inbox", "library", async (page) => {
    await page.getByRole("list", { name: "Smart folders" }).getByRole("button", { name: "Inbox" }).click();
    await page.waitForSelector("h1:text('Inbox')");
    await page.waitForSelector(".list .item-title");
  }],
  ["shared", "shared", async (page) => {
    await page.getByRole("list", { name: "Smart folders" }).getByRole("button", { name: "Shared with me" }).click();
    await page.getByRole("button", { name: "Open" }).first().click();
    await page.waitForSelector("[data-testid=shared-banner]");
  }],
  ["share", "share", async (page) => {
    await page.waitForSelector(".segments .segment");
    await page.getByRole("button", { name: "Share", exact: true }).click();
    await page.waitForSelector(".share-sheet .links-list");
  }],
  ["move", "move", async (page) => {
    await page.getByRole("button", { name: "More for Budget review with Acme" }).click();
    await page.getByRole("menuitem", { name: "Move to…" }).click();
    await page.waitForSelector(".pick-list");
  }],
  ["request-files", "request-files", async (page) => {
    await page.getByRole("button", { name: "Request files" }).click();
    await page.waitForSelector(".share-sheet .links-list");
  }],
  ["record", "record", async (page) => page.waitForSelector(".record-btn")],
  // v1.5: signing in, the account and plan, a viewer's Record page, an update ready to install.
  ["sign-in", "sign-in", async (page) => page.waitForSelector("#sign-in-title")],
  ["settings-account", "settings-account", async (page) => {
    await page.waitForSelector(".settings-row");
    await page.getByRole("button", { name: /^Account/ }).click();
    await page.waitForSelector("[data-testid=plan-card]");
  }],
  ["record-viewer", "record-viewer", async (page) => page.waitForSelector("[data-testid=viewer-notice]")],
  ["library-update", "library-update", async (page) => {
    await page.waitForSelector("[data-testid=update-banner]");
    await page.waitForSelector(".segments .segment");
  }],
  ["record-bluetooth", "record-bluetooth", async (page) => page.waitForSelector("[data-testid=bluetooth-note]")],
  ["activity", "activity", async (page) => {
    await page.waitForSelector(".activity-item");
    await page.waitForSelector("[data-testid=skipped-calls]");
  }],
  ["activity-skipped", "activity", async (page) => {
    await page.waitForSelector("[data-testid=skipped-calls]");
    await page.getByTestId("skipped-calls").getByRole("button", { name: "Show" }).click();
    await page.waitForSelector(".skipped-list");
  }],
  ["settings", "settings", async (page) => {
    await page.waitForSelector(".settings-row");
    await page.getByRole("button", { name: "General" }).click();
  }],
  ["settings-calls", "settings", async (page) => {
    await page.waitForSelector(".settings-row");
    await page.getByRole("button", { name: "Calls & meetings" }).click();
  }],
  ["controls", "controls", async (page) => {
    await page.waitForSelector(".rec-pill");
    await page.waitForSelector(".after-pill.saved");
    await page.waitForSelector(".after-pill.chip");
    await page.waitForSelector(".overlay-card .caption-line");
  }],
  ["controls-bluetooth", "controls-bluetooth", async (page) => {
    await page.waitForSelector("[data-testid=bluetooth-notice]");
    await page.waitForSelector(".after-pill.chip");
  }],
  // Phase 1B: what runs on a Mac and on an old Windows, and what a recording says about what it holds.
  ["controls-permission", "controls-permission", async (page) => page.waitForSelector("[data-testid=missing-permission]")],
  ["controls-nothing", "controls-nothing", async (page) => page.waitForSelector("[data-testid=missing-permission]")],
  ["controls-device", "controls-device", async (page) => page.waitForSelector("[data-testid=device-notice]")],
  ["controls-dictation-refused", "controls-dictation-refused", async (page) => page.getByText("Dictation did not start").waitFor()],
  ["record-permission-mac", "record-permission-mac", async (page) => page.waitForSelector("[data-testid=permission-banner]")],
  ["record-windows10", "record-windows10", async (page) => page.waitForSelector("[data-testid=whole-system-note]")],
  ["library-lost-mac", "library-lost-mac", async (page) => {
    await page.waitForSelector("[data-testid=permission-loss]");
    await page.waitForSelector(".segments .segment");
  }],
  ["settings-mac-check", "settings-mac-check", async (page) => {
    await page.getByRole("textbox", { name: "Search settings" }).fill("mac check");
    await page.waitForSelector("[data-testid=check-macos_version]");
  }],
  ["settings-keys-mac", "settings-keys-mac", async (page) => {
    await page.getByRole("textbox", { name: "Search settings" }).fill("in the app");
    await page.waitForSelector("text=⌘W");
  }],
  ["settings-windows10", "settings-windows10", async (page) => {
    await page.getByRole("textbox", { name: "Search settings" }).fill("microphone");
    await page.waitForSelector("[data-testid=whole-system-note]");
  }],
  ["onboarding-mac-microphone", "onboarding-mac", async (page) => {
    await page.getByRole("button", { name: "Continue" }).click();
    await page.waitForSelector("[data-testid=permission-microphone]");
  }],
  ["onboarding-mac-system-audio", "onboarding-mac", async (page) => {
    await page.getByRole("button", { name: "Continue" }).click();
    await page.getByRole("button", { name: "Continue" }).click();
    await page.waitForSelector("[data-testid=permission-system_audio]");
  }],
  ["onboarding-mac-screen", "onboarding-mac", async (page) => {
    for (let i = 0; i < 3; i += 1) await page.getByRole("button", { name: "Continue" }).click();
    await page.waitForSelector("[data-testid=permission-screen]");
  }],
];

/**
 * Screens of the older harness (screenshots/harness.ts, v1.5 screen moments and held recordings): name, its query, what
 * to do before the picture, and the viewport when it is not the main window's.
 */
const HARNESS = [
  ["library-screens", "w=main&screens=1", async (page) => {
    await openWeeklySync(page);
    await page.waitForSelector(".screen-thumb");
    await page.evaluate(() => document.querySelector(".screen-thumb")?.scrollIntoView({ block: "center" }));
  }],
  ["library-gallery", "w=main&screens=1", async (page) => {
    await openWeeklySync(page);
    await page.getByRole("button", { name: /^3 screens/ }).click();
    await page.waitForSelector(".screen-gallery .screen-thumb img");
    await page.evaluate(() => document.querySelector(".screen-gallery")?.scrollIntoView({ block: "end" }));
  }],
  ["screen-viewer", "w=main&screens=1", async (page) => {
    await openWeeklySync(page);
    await page.waitForSelector(".screen-thumb img");
    await page.locator(".screen-thumb").nth(1).click();
    await page.waitForSelector(".screen-viewer img");
  }],
  ["project-screens", "w=main&screens=1", async (page) => {
    await page.getByRole("list", { name: "Projects in the sidebar" }).getByRole("button", { name: "Northwind", exact: true }).click();
    await page.getByRole("tab", { name: "Screens", exact: true }).click();
    await page.waitForSelector(".screen-thumb img");
  }],
  ["settings-screens-windows", "w=main&view=settings&screens=1&borderless=0", async (page) => {
    await page.getByRole("textbox", { name: "Search settings" }).fill("screen moments");
    await page.waitForSelector("text=Keep screen moments");
  }],
  ["settings-screens-macos", "w=main&view=settings&screens=1&platform=macos&permission=0", async (page) => {
    await page.getByRole("textbox", { name: "Search settings" }).fill("screen moments");
    await page.waitForSelector("text=Allow Screen Recording");
  }],
  ["library-held", "w=main&held=1", async (page) => page.waitForSelector("text=Waiting for another account")],
  ["controls-screens", "w=pill&screens=1", async (page) => page.waitForSelector(".rec-screens"), { width: 360, height: 92 }],
  // v1.5: the brand's wordmark on the lock and first-run cards.
  ["lock", "w=main&view=lock", async (page) => page.waitForSelector("text=EchoLedger is locked")],
  ["onboarding", "w=main&view=onboarding", async (page) => page.waitForSelector("text=Welcome to EchoLedger")],
];

/** Opens the harness's first recording, the one with screens. */
async function openWeeklySync(page) {
  await page.getByTestId("library-list").getByText("Weekly sync with Northwind").click();
  await page.waitForSelector(".detail-title");
}

/** Opens Acme Holdings from the sidebar's project tree. */
async function openAcme(page) {
  await page.getByRole("list", { name: "Projects in the sidebar" }).getByRole("button", { name: "Acme Holdings", exact: true }).click();
  await page.waitForSelector(".crumbs");
}

async function launch() {
  // Playwright's own Chromium, else the installed Google Chrome (the Mac), else Microsoft Edge (every Windows 11).
  for (const options of [{}, { channel: "chrome" }, { channel: "msedge" }]) {
    try {
      return await chromium.launch(options);
    } catch {
      // Try the next browser.
    }
  }
  throw new Error("No Chromium browser to take the screenshots with");
}

/** Waits for the page's fonts and lets it settle, then takes the picture. */
async function shoot(page, file) {
  // No hover state left from the last click (a row's menu button, a tooltip).
  await page.mouse.move(0, 0);
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(400);
  await page.screenshot({ path: file });
  console.log(file);
}

const only = process.argv[2];
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5199, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await launch();
await mkdir(out, { recursive: true });
try {
  for (const [name, screen, prepare] of SCREENS) {
    if (only && !name.startsWith(only)) continue;
    for (const theme of ["light", "dark"]) {
      const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
      page.on("pageerror", (e) => console.error(`${name}-${theme}: ${e.message}`));
      await page.goto(`${base}scripts/screens/index.html?screen=${screen}&theme=${theme}`);
      await prepare(page);
      await shoot(page, join(out, `desktop-${name}-${theme}.png`));
      await page.close();
    }
  }
  for (const [name, query, prepare, viewport] of HARNESS) {
    if (only && !name.startsWith(only)) continue;
    for (const theme of ["light", "dark"]) {
      const page = await browser.newPage({ viewport: viewport ?? { width: 1280, height: 800 }, colorScheme: theme, reducedMotion: "reduce" });
      page.on("pageerror", (e) => console.error(`${name}-${theme}: ${e.message}`));
      await page.goto(`${base}screenshots/harness.html?${query}&theme=${theme}`);
      await page.waitForSelector("html[data-shot-ready]");
      await prepare(page);
      await shoot(page, join(out, `desktop-${name}-${theme}.png`));
      await page.close();
    }
  }
} finally {
  await browser.close();
  await server.close();
}
