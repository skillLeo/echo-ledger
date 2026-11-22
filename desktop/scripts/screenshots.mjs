// Screenshots of every desktop screen, light and dark, at 1440 x 900 (and the narrowest window, 860 x 560, for the main pages), into docs/design/screens/desktop/. The views run in
// the Vite dev server's fixture mode (scripts/screens/) or the older harness (screenshots/harness.ts, screen moments and
// held recordings), answered by Tauri's IPC mock with sample data. Motion is off (reduced motion), so nothing is caught
// mid-transition.
//   node scripts/screenshots.mjs              every screen
//   node scripts/screenshots.mjs library      only the screens whose name starts with "library" (several: "library,plan")
import { createServer } from "vite";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FLAGS as V2_FLAGS } from "../src/v2/gallery/cases.mjs";

const desktop = join(dirname(fileURLToPath(import.meta.url)), "..");
// The sign-in screens show a build with its server built in (v1.6), never a real address: vite.config.ts reads this.
process.env.ECHOLEDGER_DEFAULT_SERVER ??= "https://ledger.example.com";
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
  // v1.6 (P2.7): maintenance, a refused version and an announcement at the top of the window.
  ["library-maintenance", "library-maintenance", async (page) => page.waitForSelector("[data-testid=maintenance]")],
  ["library-refused", "library-refused", async (page) => page.waitForSelector("[data-testid=app-update]")],
  ["library-announcement", "library-announcement", async (page) => page.waitForSelector("[data-testid=announcement]")],
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
  ["record", "record", async (page) => {
    await page.waitForSelector(".record-btn");
    await page.waitForSelector("[data-testid=ready-card] .check-row");
  }],
  // Phase 1C: the Ready check with two things to fix, Settings → Notifications by kind, the Library grouped by project.
  ["record-not-ready", "record-not-ready", async (page) => page.waitForSelector("[data-testid=ready-card].not-ready .check-row")],
  ["library-grouped", "library", async (page) => {
    await page.waitForSelector(".segments .segment");
    await page.getByRole("button", { name: /^View:/ }).click();
    await page.getByRole("group", { name: "Group by" }).getByRole("menuitemradio", { name: /Project$/ }).click();
    await page.keyboard.press("Escape");
    await page.waitForSelector(".group-head");
  }],
  // v1.5: signing in, the account and plan, a viewer's Record page, an update ready to install.
  // v1.6: the email first; a new email's code, then its name and the Terms.
  ["sign-in", "sign-in", async (page) => {
    await page.waitForSelector("#sign-in-title");
    await page.getByRole("button", { name: "Continue with Google" }).waitFor();
  }],
  ["sign-in-code", "sign-in-code", async (page) => {
    await page.getByRole("textbox", { name: "Email" }).fill("new@example.com");
    await page.getByRole("button", { name: "Continue", exact: true }).click();
    await page.waitForSelector("text=Check your email");
  }],
  ["sign-in-profile", "sign-in-profile", async (page) => {
    await page.getByRole("textbox", { name: "Email" }).fill("new@example.com");
    await page.getByRole("button", { name: "Continue", exact: true }).click();
    await page.getByRole("textbox", { name: "Code from the email" }).fill("123456");
    await page.getByRole("button", { name: "Continue", exact: true }).click();
    await page.getByRole("textbox", { name: "Your name" }).fill("Sara Ahmed");
    await page.getByRole("checkbox", { name: "I accept the Terms and Privacy" }).check();
  }],
  // v1.6: a Free account: the badges, the upgrade sheet, Plan & usage and the own Groq key.
  ["record-free", "record-free", async (page) => page.waitForSelector("[data-testid=locked-live_translate] .plan-badge")],
  ["upgrade-free", "record-free", async (page) => {
    await page.locator("[data-testid=locked-live_translate] .plan-badge").click();
    await page.waitForSelector(".plan-radio");
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
  ["controls", "controls", async (page) => {
    await page.waitForSelector(".rec-pill");
    await page.waitForSelector(".call-card");
    await page.waitForSelector(".overlay-card .caption-line");
  }],
  // D-013, D-014, D-040, D-041, D-044, D-052, D-062: no internal, debug or vendor text.
  ...[["", undefined], ["-narrow", { width: 860, height: 560 }]].flatMap(([size, viewport]) => [
    [`record-recording${size}`, "record-recording", async (page) => page.waitForSelector(".record-controls"), viewport],
    [`library-free${size}`, "library&plan=free", async (page) => {
      await page.waitForSelector(".segments .segment");
      await page.waitForSelector("[data-testid=locked-premium_engines]");
    }, viewport],
  ]),
  // D-087: in the window's search a name comes before a keyword: "new" offers New project first (also at 200 % text).
  ...[["", undefined], ["-narrow", { width: 860, height: 560 }], ["-text200", { width: 1100, height: 740, scale: 2 }], ["-narrow-text200", { width: 860, height: 560, scale: 2 }]].map(([size, viewport]) => [`palette-new${size}`, "library&plan=free", async (page) => {
    await page.waitForSelector(".app");
    await page.keyboard.press(process.platform === "darwin" ? "Meta+k" : "Control+k");
    await page.getByRole("dialog", { name: "Command palette" }).getByRole("combobox").fill("new");
    await page.waitForTimeout(600);
  }, viewport]),
  // D-087: what is typed is split as names are, so punctuation typed with a name still finds it.
  ...[["", undefined], ["-narrow", { width: 860, height: 560 }]].map(([size, viewport]) => [`palette-punctuation${size}`, "library&plan=free", async (page) => {
    await page.waitForSelector(".app");
    await page.keyboard.press(process.platform === "darwin" ? "Meta+k" : "Control+k");
    await page.getByRole("dialog", { name: "Command palette" }).getByRole("combobox").fill("calls & meetings");
    await page.waitForTimeout(600);
  }, viewport]),
  // D-087: recordings found later never take the highlight from a row already shown ("call w": the Ready check stays
  // highlighted when "Call with …" recordings come in, though their names start with what was typed).
  ...[["", undefined], ["-narrow", { width: 860, height: 560 }]].map(([size, viewport]) => [`palette-late-recordings${size}`, "library&plan=free&found=1", async (page) => {
    await page.waitForSelector(".app");
    await page.keyboard.press(process.platform === "darwin" ? "Meta+k" : "Control+k");
    await page.getByRole("dialog", { name: "Command palette" }).getByRole("combobox").fill("call w");
    await page.getByRole("group", { name: "Recordings" }).waitFor();
    await page.waitForTimeout(300);
  }, viewport]),
  // D-087: "Go to X" is named by X, so "library" offers Go to Library first (Enter opens it, never Sync).
  ...[["", undefined], ["-narrow", { width: 860, height: 560 }]].map(([size, viewport]) => [`palette-library${size}`, "library&plan=free", async (page) => {
    await page.waitForSelector(".app");
    await page.keyboard.press(process.platform === "darwin" ? "Meta+k" : "Control+k");
    await page.getByRole("dialog", { name: "Command palette" }).getByRole("combobox").fill("library");
    await page.waitForTimeout(600);
  }, viewport]),
  // A Settings row that opens something has a chevron (PIN, Promo code); one that acts reads as an action.
  ...[["", undefined], ["-narrow", { width: 860, height: 560 }]].flatMap(([size, viewport]) => [
    [`settings-app-lock${size}`, "settings", async (page) => { await settingsReady(page); await nav(page, "App lock"); }, viewport],
    [`plan-free-rows${size}`, "settings&plan=free", async (page) => { await settingsReady(page); await nav(page, "Plan & usage"); await page.getByRole("button", { name: /^Promo code/ }).scrollIntoViewIfNeeded(); }, viewport],
  ]),
  // D-014: why a call wasn't recorded, in plain words, in Calls & meetings.
  ...[["", undefined], ["-narrow", { width: 860, height: 560 }]].map(([size, viewport]) => [`settings-calls-why${size}`, "settings", async (page) => {
    await settingsReady(page);
    await nav(page, "Calls & meetings");
    await page.getByTestId("why-not-recorded").waitFor();
    await page.getByTestId("why-not-recorded").scrollIntoViewIfNeeded();
    await page.waitForTimeout(2300);
  }, viewport]),
  // D-130: a failed transcript says why in the app's own words; this month's limit offers no Try again.
  ...[["", undefined], ["-narrow", { width: 860, height: 560 }]].flatMap(([size, viewport]) => [["", "library"], ["-budget", "library&failure=budget_exceeded"]]
    .map(([kind, screen]) => [`library-failed${kind}${size}`, screen, async (page) => {
      await page.waitForSelector(".segments .segment");
      await page.getByTestId("library-list").getByText(/Call with \+92/).click();
      await page.waitForSelector(".detail .notice.error");
    }, viewport])),
  // D-001, D-120: the Calls page's app icons beside their names; a permission allowed before on this Mac, said as a
  // possibility with System Settings first, and Screen Recording switched on while EchoLedger ran (Quit & reopen).
  ...[["", undefined], ["-narrow", { width: 860, height: 560 }]].flatMap(([size, viewport]) => [
    [`settings-calls-apps${size}`, "settings", async (page) => { await settingsReady(page); await nav(page, "Calls & meetings"); await page.waitForSelector(".app-glyph"); }, viewport],
    // "reopen-did-not-apply": EchoLedger was reopened and macOS still has not applied it: switch it off and on instead.
    ...[["allowed-before", "older=1"], ["reopen", "reopen=1"], ["reopen-did-not-apply", "reopen=2"]].map(([state, param]) => [`permissions-${state}-mac${size}`, `library-lost-mac&${param}`, async (page) => {
      await page.waitForSelector("[data-testid=permission-loss]");
      await page.getByTestId("permission-loss").getByRole("button", { name: "Set up" }).click();
      await page.getByRole("dialog", { name: "Set up permissions" }).waitFor();
      if (state.startsWith("reopen")) await page.getByTestId("permission-screen").scrollIntoViewIfNeeded();
    }, viewport]),
    // D-136: no privacy call on this macOS: nothing said that cannot be read, System Settings first.
    [`permissions-untold-mac${size}`, "library-lost-mac&untold=1", async (page) => {
      await page.waitForSelector("[data-testid=permission-loss]");
      await page.getByTestId("permission-loss").getByRole("button", { name: "Set up" }).click();
      await page.getByRole("dialog", { name: "Set up permissions" }).waitFor();
      await page.getByTestId("permission-system_audio").scrollIntoViewIfNeeded();
    }, viewport],
    // The Mac check's Screen Recording step has the same Quit & reopen.
    [`settings-mac-check-reopen${size}`, "settings-mac-check&reopen=1", async (page) => {
      await settingsReady(page);
      await nav(page, "Help");
      await page.getByRole("button", { name: /^Mac check/ }).click();
      await page.waitForSelector("[data-testid=check-screen_permission]");
      await page.getByTestId("check-screen_permission").scrollIntoViewIfNeeded();
    }, viewport],
    // The Ready check and the top bar never say "Microphone allowed" or "Calls: Ready" in that state.
    [`record-allowed-before-mac${size}`, "record&older=1", async (page) => page.waitForSelector("[data-testid=ready-card].not-ready .check-row"), viewport],
  ]),
  // D-023, D-024, D-029, D-031, D-046, D-003: nothing clipped or pushed sideways in the Library.
  ["library-empty-narrow", "library-empty", async (page) => page.waitForSelector(".empty-state"), { width: 860, height: 560 }],
  ...[["-1100", { width: 1100, height: 740 }], ["-1300", { width: 1300, height: 800 }]].map(([size, viewport]) => [`library-long-language${size}`, "library", async (page) => {
    await page.waitForSelector(".segments .segment");
    await page.selectOption("select[aria-label='Language to re-transcribe in']", "");
  }, viewport]),
  // D-132: what a second transcription heard under a line, in the app's own words, never the engine's name.
  ...[["", undefined], ["-narrow", { width: 860, height: 560 }]].map(([size, viewport]) => [`library-second-check${size}`, "library&check=1", async (page) => {
    await page.waitForSelector(".segments .seg-check");
    await page.locator(".segments .seg-check").scrollIntoViewIfNeeded();
  }, viewport]),
  // D-031: an untitled recording is called Recording in the list, on its page's heading and its rename button. In a
  // narrow window the list and the page are one under the other: the row is scrolled into view, and "-page" shows the
  // heading (a screen whose untitled recording is not in view is not taken).
  ...[["", undefined], ["-narrow", { width: 860, height: 560 }], ["-text200", { width: 1100, height: 740, scale: 2 }]].flatMap(([size, viewport]) => [
    [`library-untitled${size}`, "library&untitled=1", async (page) => showUntitled(page, size ? "row" : "both"), viewport],
    ...(size ? [[`library-untitled-page${size}`, "library&untitled=1", async (page) => showUntitled(page, "heading"), viewport]] : []),
  ]),
  // D-121: the Library's row opened its folders in a narrow window; widened until the page's own column shows, the
  // sidebar's copy has closed (the full-size window, the column on the page).
  ["sidebar-library-widened", "library", async (page) => {
    await page.setViewportSize({ width: 960, height: 700 });
    await page.waitForSelector(".app");
    await page.locator(".sidebar").getByRole("button", { name: "Library", exact: true }).click();
    await page.getByRole("list", { name: "Smart folders in the sidebar" }).waitFor();
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.waitForTimeout(300);
    if (await page.getByRole("list", { name: "Smart folders in the sidebar" }).count()) throw new Error("the sidebar's copy is still open");
  }],
  // D-121, D-007, D-090: whole controls, the workspace switcher, Settings' action rows.
  ...[["", undefined], ["-narrow", { width: 860, height: 560 }]].flatMap(([size, viewport]) => [
    [`workspace-menu${size}`, "library&plan=free", async (page) => {
      await page.waitForSelector(".app");
      await page.getByRole("button", { name: /^Workspace:/ }).click();
      await page.waitForSelector(".menu-pop");
    }, viewport],
    [`settings-share-privacy-actions${size}`, "settings-share", async (page) => {
      await settingsReady(page);
      await nav(page, "Screen-share privacy");
      await page.waitForSelector(".set-action");
    }, viewport],
  ]),
  // D-045 … D-122, D-072: the app's own confirm dialog, at full size and at the smallest window.
  ...[["", undefined], ["-narrow", { width: 860, height: 560 }]].flatMap(([size, viewport]) => [
    [`confirm-delete-recording${size}`, "library&plan=free", async (page) => {
      await page.waitForSelector(".detail-title");
      await page.getByRole("button", { name: "Delete", exact: true }).first().click();
      await page.getByRole("alertdialog", { name: "Delete this recording?" }).waitFor();
    }, viewport],
    [`confirm-revoke-link${size}`, "library&plan=free", async (page) => {
      await page.waitForSelector(".app");
      await page.getByRole("navigation", { name: "Main" }).getByRole("button", { name: "Shared", exact: true }).click();
      await page.getByRole("tab", { name: /My links/ }).click();
      await page.getByRole("button", { name: "Revoke", exact: true }).first().click();
      await page.getByRole("alertdialog", { name: "Revoke this link?" }).waitFor();
    }, viewport],
  ]),
  // The call choice: Delete says Deleted with Undo for 5 s (one message at a time).
  ["controls-discarded", "controls-discarded", async (page) => {
    await page.waitForSelector("[data-testid=call-done]");
  }],
  ["controls-discarded-narrow", "controls-discarded", async (page) => {
    await page.waitForSelector("[data-testid=call-done]");
  }, { width: 860, height: 560 }],
  ["controls-bluetooth", "controls-bluetooth", async (page) => {
    await page.waitForSelector("[data-testid=bluetooth-notice]");
    await page.waitForSelector(".call-card");
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
  // v1.6 (P4): the app shell. The Library as a table, a recording in the drawer, the command palette, the top bar's
  // menus, Help & shortcuts, the sidebar as a rail and in its other styles, and Shared.
  ["library-table", "library-table", async (page) => page.waitForSelector(".lib-table .lib-row")],
  ["library-drawer", "library-table", async (page) => {
    await page.getByRole("table", { name: "Recordings" }).getByRole("button", { name: "Kick-off: Contoso website", exact: true }).click();
    await page.waitForSelector(".detail.drawer .segments .segment");
  }],
  // v1.6 (P5.5): Send to WhatsApp from a recording, and the voice saved in Downloads to attach.
  ["whatsapp", "library", async (page) => {
    await page.waitForSelector(".segments .segment");
    await page.locator(".detail").getByRole("button", { name: "Send to WhatsApp" }).click();
    await page.waitForSelector(".whatsapp-sheet");
  }],
  // F6: after Text and voice without the share, the voice saved and on the clipboard (Ctrl+V on Windows, ⌘V on a Mac).
  ...[["whatsapp-voice", "library"], ["whatsapp-voice-mac", "library-whatsapp-mac"]].map(([name, fixture]) => [name, fixture, async (page) => {
    await page.waitForSelector(".segments .segment");
    await page.locator(".detail").getByRole("button", { name: "Send to WhatsApp" }).click();
    await page.getByRole("button", { name: "Text and voice" }).click();
    await page.waitForSelector("[data-testid=whatsapp-paste]");
  }]),
  // F6: a share whose end is not known: nothing claimed, the fallback offered (Windows 10; a Mac share with no answer).
  ...[["whatsapp-opened", "library-whatsapp-opened"], ["whatsapp-opened-mac", "library-whatsapp-opened-mac"]].map(([name, fixture]) => [name, fixture, async (page) => {
    await page.waitForSelector(".segments .segment");
    await page.locator(".detail").getByRole("button", { name: "Send to WhatsApp" }).click();
    await page.getByRole("button", { name: "Text and voice" }).click();
    await page.waitForSelector("[data-testid=whatsapp-opened]");
  }]),
  // v1.6.1 (Notices): a toast that goes somewhere, with × and its timer bar (reduced motion keeps the bar still).
  ["toast", "library", async (page) => {
    await page.waitForSelector(".segments .segment");
    await page.keyboard.press("Control+k");
    await page.locator(".palette-input").fill("sync the library");
    await page.keyboard.press("Enter");
    await page.waitForSelector(".toast .notice-timer");
  }],
  ["palette", "library", async (page) => {
    await page.waitForSelector(".segments .segment");
    await page.keyboard.press("Control+k");
    await page.locator(".palette-input").fill("new");
    await page.waitForSelector(".palette-item");
  }],
  ["create-menu", "library", async (page) => {
    await page.waitForSelector(".segments .segment");
    await page.getByRole("button", { name: "Create" }).click();
    await page.waitForSelector(".shell-pop");
  }],
  ["avatar-menu", "library&plan=free", async (page) => {
    await page.waitForSelector(".segments .segment");
    await page.getByRole("button", { name: /^Account menu:/ }).click();
    await page.waitForSelector(".shell-pop");
  }],
  // D-079, D-133: Help fits the window at every size and text size: the lists scroll, the x and the buttons stay on
  // screen (a screen whose buttons are not inside the sheet is not taken).
  ...[["", undefined], ["-narrow", { width: 860, height: 560 }], ["-narrow-text200", { width: 860, height: 560, scale: 2 }],
    ["-text200", { width: 1100, height: 740, scale: 2 }]].map(([size, viewport]) => [`help${size}`, "library", openHelp, viewport]),
  ["sidebar-rail", "library", async (page) => {
    await page.waitForSelector(".segments .segment");
    await page.getByRole("button", { name: "Collapse the sidebar" }).click();
  }],
  ["sidebar-grey", "library-grey", async (page) => page.waitForSelector(".segments .segment")],
  ["sidebar-dark", "library-darkbar", async (page) => page.waitForSelector(".segments .segment")],
  ["shared-page", "shared", async (page) => {
    await page.locator(".sidebar").getByRole("button", { name: "Shared", exact: true }).click();
    await page.waitForSelector(".page-tabs");
  }],
  // v1.7 Settings: who you are, the sections from one registry, search to a single setting, Plan & usage, and the
  // upgrade flow with the bank details and the receipts. `screen` carries the plan (`&plan=`) and a request's stage.
  ["settings-overview-free", "settings&plan=free", settingsReady],
  ["settings-overview-pro", "settings&plan=pro", settingsReady],
  ["settings-overview-ultra", "settings&plan=ultra", settingsReady],
  ["settings-overview-pro-pending", "settings&plan=pro&request=awaiting_payment", settingsReady],
  ["settings-overview-deleting", "settings&plan=free&deleting=1", settingsReady],
  ["settings-narrow", "settings&plan=free", settingsReady, { width: 860, height: 560 }],
  // The Settings list at the owner's 1000 pt window, the search focused (its ring and the switches whole), a plan with no
  // monthly limit (no empty bar), and the same at 200 % text (the page zoomed twice: half the room in points).
  ["settings-1000", "settings&plan=ultra&unlimited=1", async (page) => { await settingsReady(page); await page.getByRole("textbox", { name: "Search settings" }).focus(); }, { width: 1000, height: 700 }],
  ["settings-1100", "settings&plan=ultra&unlimited=1", async (page) => { await settingsReady(page); await page.getByRole("textbox", { name: "Search settings" }).focus(); }, { width: 1100, height: 740 }],
  ["settings-text200", "settings&plan=ultra&unlimited=1", settingsReady, { width: 1100, height: 740, scale: 2 }],
  ["settings-narrow-text200", "settings&plan=ultra&unlimited=1", settingsReady, { width: 860, height: 560, scale: 2 }],
  ["settings-search-recovered", "settings&plan=free", async (page) => {
    await settingsReady(page);
    await page.getByRole("textbox", { name: "Search settings" }).fill("recovered recording");
    await page.waitForSelector("[data-testid=settings-results]");
  }],
  // One search: the sidebar's opens the window's search, which says where it looked.
  ["search-from-sidebar", "library&plan=free", async (page) => {
    await page.waitForSelector(".segments .segment");
    await page.getByRole("button", { name: /^Search recordings, settings and pages/ }).first().click();
    await page.getByRole("dialog", { name: "Command palette" }).getByRole("combobox").fill("recovered recording");
    await page.waitForTimeout(600);
  }],
  ["search-from-sidebar-narrow", "library&plan=free", async (page) => {
    await page.waitForSelector(".app");
    await page.getByRole("button", { name: /^Search recordings, settings and pages/ }).first().click();
    await page.getByRole("dialog", { name: "Command palette" }).getByRole("combobox").fill("devices");
    await page.waitForTimeout(600);
  }, { width: 860, height: 560 }],
  // Two banners want the place: the more important one shows, in one sentence with its button.
  ["library-banners-mac", "library-banners-mac", async (page) => page.waitForSelector("[data-testid=permission-loss]")],
  ["library-banners-mac-narrow", "library-banners-mac", async (page) => page.waitForSelector("[data-testid=permission-loss]"), { width: 860, height: 560 }],
  ["library-banners-mac-text200", "library-banners-mac", async (page) => page.waitForSelector("[data-testid=permission-loss]"), { width: 1100, height: 740, scale: 2 }],
  // Settings' list and search, the sidebar's Search button and a banner with Learn more open, at the
  // smallest window and at 200 % text.
  ...[["", { width: 860, height: 560 }], ["-text200", { width: 1100, height: 740, scale: 2 }], ["-narrow-text200", { width: 860, height: 560, scale: 2 }]].flatMap(([size, viewport]) => [
    [`review-settings-list${size || "-narrow"}`, "settings&plan=ultra&unlimited=1", settingsReady, viewport],
    [`review-settings-search${size || "-narrow"}`, "settings&plan=free", async (page) => {
      await settingsReady(page);
      await page.getByRole("textbox", { name: "Search settings" }).fill("lock");
      await page.waitForSelector("[data-testid=settings-results]");
    }, viewport],
    [`review-sidebar-search${size || "-narrow"}`, "library&plan=free", async (page) => {
      await page.waitForSelector(".app");
      await page.getByRole("button", { name: /^Search recordings, settings and pages/ }).first().focus();
    }, viewport],
    [`review-banner-learn-more${size || "-narrow"}`, "library-maintenance", async (page) => {
      await page.waitForSelector("[data-testid=maintenance]");
      await page.getByTestId("maintenance").getByRole("button", { name: "Learn more" }).click();
    }, viewport],
  ]),
  // A recovered recording keeps its chip after its transcript renamed it.
  ["library-recovered", "library-recovered", async (page) => page.waitForSelector(".recovered-chip")],
  ["library-recovered-narrow", "library-recovered", async (page) => page.waitForSelector(".recovered-chip"), { width: 860, height: 560 }],
  ["settings-narrow-page", "settings&plan=free", async (page) => { await settingsReady(page); await nav(page, "Notifications"); }, { width: 860, height: 560 }],
  ["plan-free", "settings&plan=free", async (page) => { await settingsReady(page); await nav(page, "Plan & usage"); }],
  ["plan-free-plans", "settings&plan=free", async (page) => {
    await settingsReady(page);
    await nav(page, "Plan & usage");
    await page.locator("#plans-above").scrollIntoViewIfNeeded();
  }],
  ["plan-features", "settings&plan=free", async (page) => {
    await settingsReady(page);
    await nav(page, "Plan & usage");
    await page.getByRole("button", { name: /^See every feature/ }).click();
    await page.locator("[data-testid=all-features]").scrollIntoViewIfNeeded();
  }],
  ["plan-pro-near", "settings&plan=pro&near=1", async (page) => { await settingsReady(page); await nav(page, "Plan & usage"); }],
  ["plan-ultra", "settings&plan=ultra", async (page) => { await settingsReady(page); await nav(page, "Plan & usage"); }],
  ["plan-request-awaiting", "settings&plan=free&request=awaiting_payment", async (page) => { await settingsReady(page); await nav(page, "Plan & usage"); }],
  ["plan-request-needs-receipt", "settings&plan=free&request=needs_receipt", async (page) => { await settingsReady(page); await nav(page, "Plan & usage"); }],
  ["plan-request-declined", "settings&plan=free&request=declined", async (page) => { await settingsReady(page); await nav(page, "Plan & usage"); }],
  ["upgrade-step1", "settings&plan=free", async (page) => {
    await settingsReady(page);
    await page.getByTestId("profile-card").getByRole("button", { name: "Upgrade" }).click();
    await page.waitForSelector(".plan-radio");
  }],
  ["upgrade-step1-promo", "settings&plan=free", async (page) => {
    await settingsReady(page);
    await page.getByTestId("profile-card").getByRole("button", { name: "Upgrade" }).click();
    await page.getByRole("button", { name: /^Promo code/ }).click();
    await page.getByLabel("Code").fill("SAVE50");
    await page.getByRole("button", { name: "Apply" }).click();
    await page.waitForSelector(".promo-applied");
  }],
  ["upgrade-pay", "settings&plan=free&request=awaiting_payment", async (page) => {
    await settingsReady(page);
    await page.getByTestId("state-row").getByRole("button").first().click();
    await page.waitForSelector(".pay-row");
  }],
  ["upgrade-receipt", "settings&plan=free&request=awaiting_payment&round=1", async (page) => {
    await settingsReady(page);
    await page.getByTestId("state-row").getByRole("button").first().click();
    await page.getByRole("button", { name: "Add payment receipt" }).click();
    await page.waitForSelector(".receipt-tile img");
    await page.getByLabel(/Transaction ID/).fill("FT26313ABC7781");
  }],
  ["upgrade-receipt-second-round", "settings&plan=free&request=needs_receipt", async (page) => {
    await settingsReady(page);
    await nav(page, "Plan & usage");
    await page.getByRole("button", { name: "Add receipt" }).click();
    await page.waitForSelector(".receipt-tile img");
  }],
  ["upgrade-status", "settings&plan=free&request=in_review", async (page) => {
    await settingsReady(page);
    await page.getByTestId("state-row").getByRole("button").first().click();
    await page.waitForSelector(".timeline");
  }],
  ["upgrade-status-approved", "settings&plan=pro&request=approved", async (page) => {
    await settingsReady(page);
    await nav(page, "Plan & usage");
    await page.getByTestId("upgrade-request").getByRole("button").first().click();
    await page.waitForSelector(".timeline");
  }],
  ["settings-search", "settings&plan=free", async (page) => {
    await settingsReady(page);
    await page.getByRole("textbox", { name: "Search settings" }).fill("pro");
    await page.waitForSelector(".result-row");
  }],
  ["settings-search-lock", "settings&plan=free", async (page) => {
    await settingsReady(page);
    await page.getByRole("textbox", { name: "Search settings" }).fill("lock after");
    await page.waitForSelector(".result-row");
  }],
  ["settings-search-empty", "settings&plan=free", async (page) => {
    await settingsReady(page);
    await page.getByRole("textbox", { name: "Search settings" }).fill("xyzzy");
    await page.waitForSelector(".set-empty");
  }],
  ["settings-highlight", "settings&plan=free", async (page) => {
    await settingsReady(page);
    await page.getByRole("textbox", { name: "Search settings" }).fill("lock after");
    await page.locator(".result-row").first().click();
    await page.waitForSelector(".is-highlighted");
  }],
  ["palette-settings", "library&plan=free", async (page) => {
    await page.waitForSelector(".segments .segment");
    await page.keyboard.press("Control+k");
    await page.locator(".palette-input").fill("dark");
    await page.waitForSelector(".palette-group-head");
  }],
  ["settings-notifications", "settings", async (page) => { await settingsReady(page); await nav(page, "Notifications"); }],
  ["settings-calls", "settings", async (page) => { await settingsReady(page); await nav(page, "Calls & meetings"); }],
  ["settings-captions-style", "settings", async (page) => {
    await settingsReady(page);
    await nav(page, "Captions");
    await page.getByRole("button", { name: /^Style/ }).click();
    await page.waitForSelector(".set-swatches");
  }],
  ["settings-appearance", "settings", async (page) => { await settingsReady(page); await nav(page, "Appearance"); }],
  ["settings-key-free", "settings&plan=free", async (page) => { await settingsReady(page); await nav(page, "Transcription key"); }],
  ["settings-profile", "settings&plan=free", async (page) => { await settingsReady(page); await nav(page, "Profile"); }],
  ["settings-security", "settings&plan=free", async (page) => { await settingsReady(page); await nav(page, "Sign-in & security"); }],
  ["settings-privacy", "settings&plan=free", async (page) => { await settingsReady(page); await nav(page, "Privacy & data"); }],
  ["settings-about", "settings", async (page) => { await settingsReady(page); await nav(page, "About"); }],
  // v1.8.1: Screen-share privacy and its Share Check, and the badge on the floating windows.
  ["settings-share-privacy", "settings-share", async (page) => { await settingsReady(page); await nav(page, "Screen-share privacy"); await page.waitForSelector("[data-testid=privacy-badge]"); }],
  ["settings-share-privacy-mac", "settings-share-mac", async (page) => {
    await settingsReady(page);
    await nav(page, "Screen-share privacy");
    await page.getByRole("button", { name: /Set up Zoom/ }).click();
    await page.waitForSelector("[data-testid=zoom-steps]");
  }],
  // v1.8.1 audit: the setting Off, Only floating windows, and the 10 minutes running.
  ...["off", "floating", "demo"].map((state) => [`settings-share-privacy-${state}`, `settings-share-${state}`, async (page) => {
    await settingsReady(page);
    await nav(page, "Screen-share privacy");
    await page.waitForSelector("[data-testid=privacy-badge]");
  }]),
  ["settings-share-privacy-bar-mac", "settings-share-bar-mac", async (page) => {
    await settingsReady(page);
    await nav(page, "Screen-share privacy");
    await page.waitForSelector("[data-testid=back-after-share]");
    await page.locator("[data-testid=back-after-share]").scrollIntoViewIfNeeded();
  }],
  ["settings-share-privacy-old-windows", "settings-share-old", async (page) => { await settingsReady(page); await nav(page, "Screen-share privacy"); await page.waitForSelector(".privacy-line.warning"); }],
  ...["pass", "fail", "error"].map((result) => [`share-check-${result}`, `settings-share-${result}-mac`, async (page) => {
    await settingsReady(page);
    await nav(page, "Screen-share privacy");
    await page.locator("#setting-share_privacy\\.check").click();
    await page.getByRole("heading", { level: 1, name: "Check screen-share privacy" }).waitFor();
    await page.getByRole("button", { name: "Check now" }).click();
    await page.waitForSelector("[data-testid=check-result]");
    await page.waitForTimeout(200);
  }]),
  // A Windows result, and the test with the phone on a Mac with its question, answers and Cancel.
  ["share-check-windows-pass", "settings-share-pass", async (page) => {
    await settingsReady(page);
    await nav(page, "Screen-share privacy");
    await page.locator("#setting-share_privacy\\.check").click();
    await page.getByRole("heading", { level: 1, name: "Check screen-share privacy" }).waitFor();
    await page.getByRole("button", { name: "Check now" }).click();
    await page.waitForSelector("[data-testid=check-result]");
    await page.waitForTimeout(200);
  }],
  ["share-check-guided-mac", "settings-share-mac", async (page) => {
    await settingsReady(page);
    await nav(page, "Screen-share privacy");
    await page.locator("#setting-share_privacy\\.check").click();
    await page.getByRole("heading", { level: 1, name: "Check screen-share privacy" }).waitFor();
    await page.getByRole("button", { name: "Test", exact: true }).first().click();
    await page.waitForSelector("[data-testid=guided]");
    await page.locator("[data-testid=guided]").scrollIntoViewIfNeeded();
  }],
  // The test card over the page: where the core puts it for a main window filling a Mac's screen (privacy::check::
  // card_frame finds no room beside it, so the smallest card goes in the top left corner, over the window's title and
  // the top of the page, never over the question and its answers). The card is its own window: drawn here in a frame.
  ["share-check-guided-card-mac", "settings-share-mac", async (page) => {
    await settingsReady(page);
    await nav(page, "Screen-share privacy");
    await page.locator("#setting-share_privacy\\.check").click();
    await page.getByRole("heading", { level: 1, name: "Check screen-share privacy" }).waitFor();
    await page.getByRole("button", { name: "Test", exact: true }).first().click();
    await page.waitForSelector("[data-testid=guided]");
    await page.locator("[data-testid=guided]").scrollIntoViewIfNeeded();
    const theme = new URL(page.url()).searchParams.get("theme");
    // A maximised window starts under the menu bar (33 points): the card's corner is 16 points from the screen's.
    await page.evaluate((src) => {
      const frame = document.createElement("div");
      frame.className = "screens-card-window";
      frame.style.cssText = "position:fixed;left:16px;top:-17px;width:480px;height:270px;border-radius:10px;overflow:hidden;box-shadow:0 18px 50px rgba(0,0,0,.35);z-index:99999";
      const card = document.createElement("iframe");
      card.src = src;
      card.title = "Test card";
      card.style.cssText = "border:0;width:480px;height:270px;display:block";
      frame.appendChild(card);
      document.body.appendChild(frame);
    }, `${base}scripts/screens/index.html?screen=controls-check-card&theme=${theme}`);
    await page.frameLocator(".screens-card-window iframe").locator("[data-testid=check-card]").waitFor();
  }],
  ["settings-captions", "settings", async (page) => { await settingsReady(page); await nav(page, "Captions"); await page.waitForSelector(".caption-sample"); }],
  ["onboarding-private-notes-mac", "onboarding-mac", async (page) => {
    for (let i = 0; i < 8 && !(await page.locator("[data-testid=onboarding-share-check]").count()); i++) {
      await page.getByRole("button", { name: /^(Continue|Skip the test)$/ }).first().click();
    }
    await page.waitForSelector("[data-testid=onboarding-share-check]");
  }],
  ["library-share-notice-mac", "library-share-notice-mac", async (page) => { await page.waitForSelector("[data-testid=main-in-share]"); }],
  ["settings-shortcuts-mac", "settings-keys-mac", async (page) => { await settingsReady(page); await nav(page, "Keyboard shortcuts"); }],
  ["controls-badge-visible", "controls-share-visible", async (page) => {
    await page.waitForSelector("[data-testid=recording-pill]");
    await page.waitForSelector("[data-testid=privacy-badge].visible");
  }],
  ["controls-badge-not-checked", "controls-share-notchecked", async (page) => {
    await page.waitForSelector("[data-testid=recording-pill]");
    await page.waitForSelector("[data-testid=privacy-badge].not_checked");
  }],
  // Windows of their own, at their size: Live Translate (the box, the subtitles) and the test card.
  ["controls-translate-box", "controls-translate-box", async (page) => { await page.waitForSelector(".translate-overlay [data-testid=privacy-badge]"); }, { width: 480, height: 120 }],
  ["controls-translate-subtitles", "controls-translate-subtitles", async (page) => { await page.waitForSelector(".translate-overlay [data-testid=privacy-badge]"); }, { width: 720, height: 90 }],
  ["controls-check-card", "controls-check-card", async (page) => { await page.waitForSelector("[data-testid=check-card]"); }, { width: 640, height: 360 }],
  ["settings-mac-check", "settings-mac-check", async (page) => {
    await settingsReady(page);
    await nav(page, "Help");
    await page.getByRole("button", { name: /^Mac check/ }).click();
    await page.waitForSelector("[data-testid=check-macos_version]");
  }],
  ["settings-microphone-windows10", "settings-windows10", async (page) => {
    await settingsReady(page);
    await nav(page, "Microphone & audio");
    await page.waitForSelector("[data-testid=whole-system-note]");
  }],
  // The narrowest window the app allows (860 x 560): the main pages.
  ["library-narrow", "library", async (page) => page.waitForSelector(".segments .segment"), { width: 860, height: 560 }],
  ["record-narrow", "record", async (page) => page.waitForSelector(".record-btn"), { width: 860, height: 560 }],
  ["projects-narrow", "projects", async (page) => page.waitForSelector(".project-tree.on-page"), { width: 860, height: 560 }],
  ["activity-narrow", "activity", async (page) => page.waitForSelector(".activity-item"), { width: 860, height: 560 }],
  // The v2 Library's header, the only place it says what needs action: its quiet button before Trash, led by an
  // attention dot, always with its words; where the row has no room, Select, then Sync, then Trash fold into More.
  // English and right to left (lang=ur) at 1280 and 760 (the rail), and English at 1200 and 800 with the full sidebar.
  ...[[1280, "en"], [1280, "ur"], [760, "en"], [760, "ur"], [1200, "en"], [800, "en"]].map(([width, lang]) => [`library-needs-top-${width}-${lang}`, `library&uiflags=${V2_FLAGS}${lang === "ur" ? "&lang=ur" : ""}`, async (page) => {
    // The fixture opens a recording: its Back (the last "Library" in the window) shows the list.
    await page.getByRole("button", { name: "Library", exact: true }).last().click();
    await page.locator(".el-page__head .el-page__actions").getByRole("button", { name: /need you$/ }).waitFor();
    // At rest: every scroller back at its top (the click can scroll the page).
    await page.evaluate(() => document.querySelectorAll("*").forEach((el) => { if (el.scrollTop) el.scrollTop = 0; }));
  }, { width, height: 800 }]),
  // The v2 sidebar: the Library's places with their counts as quiet text (no pill), Activity's "needs you" count on its
  // accent pill; and the same sidebar as the icon rail.
  ["v2-sidebar", `library&uiflags=${V2_FLAGS}`, async (page) => {
    await page.getByRole("list", { name: "Library places" }).waitFor();
    await page.locator(".el-nav-item--place .el-nav-item__count").first().waitFor();
  }],
  // A place as the current row (Waiting), and a place under the pointer (Inbox): its quiet count on that row.
  ["v2-sidebar-current", `library&uiflags=${V2_FLAGS}`, async (page) => {
    const places = page.getByRole("list", { name: "Library places" });
    await places.getByRole("button", { name: /^Waiting, / }).click();
    await places.locator(".el-nav-item--place[aria-current=page]").waitFor();
  }],
  ["v2-sidebar-hover", `library&uiflags=${V2_FLAGS}`, async (page) => {
    const places = page.getByRole("list", { name: "Library places" });
    await places.getByRole("button", { name: /^Inbox, / }).hover();
    return { keepPointer: true };
  }],
  // The icon rail: Activity's count (it needs you) is a small accent dot on the bell; place counts stay out.
  ["v2-sidebar-rail", `library&uiflags=${V2_FLAGS}`, async (page) => {
    await page.getByRole("list", { name: "Library places" }).waitFor();
    // The fixture opens a recording: its Back (the last "Library" in the window) shows the list.
    await page.getByRole("button", { name: "Library", exact: true }).last().click();
    await page.getByRole("button", { name: "Collapse sidebar" }).click();
    await page.locator(".el-sidebar[data-rail=on] .el-nav-item__dot").waitFor();
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
    await page.locator(".sidebar").getByRole("button", { name: "Projects", exact: true }).click();
    await page.getByRole("list", { name: "Projects in the column" }).getByRole("button", { name: "Northwind", exact: true }).click();
    await page.getByRole("tab", { name: "Screens", exact: true }).click();
    await page.waitForSelector(".screen-thumb img");
  }],
  ["settings-screens-windows", "w=main&view=settings&screens=1&borderless=0", async (page) => {
    await nav(page, "Screen moments");
    await page.waitForSelector("text=Keep screen moments");
  }],
  ["settings-screens-macos", "w=main&view=settings&screens=1&platform=macos&permission=0", async (page) => {
    await nav(page, "Screen moments");
    await page.waitForSelector("text=Allow Screen Recording");
  }],
  ["library-held", "w=main&held=1", async (page) => page.waitForSelector("text=Waiting for another account")],
  ["controls-screens", "w=pill&screens=1", async (page) => page.waitForSelector(".rec-screens"), { width: 360, height: 92 }],
  // v1.5: the brand's wordmark on the lock and first-run cards.
  ["lock", "w=main&view=lock", async (page) => page.waitForSelector("text=EchoLedger is locked")],
  ["onboarding", "w=main&view=onboarding", async (page) => page.waitForSelector("text=Welcome to EchoLedger")],
];

/** Settings has drawn its list (and read the account). */
async function settingsReady(page) {
  await page.waitForSelector(".settings-root");
  await page.waitForSelector("[data-testid=profile-card], .nav-row");
  await page.waitForTimeout(300);
}

/** Opens a page from Settings' list. */
async function nav(page, name) {
  await page.getByRole("navigation", { name: "Settings" }).getByRole("button", { name, exact: false }).first().click();
  await page.getByRole("heading", { level: 1, name }).waitFor();
}

/** Opens the harness's first recording, the one with screens. */
async function openWeeklySync(page) {
  await page.getByTestId("library-list").getByText("Weekly sync with Northwind").click();
  await page.waitForSelector(".detail-title");
}

/** Opens Acme Holdings from the sidebar's project tree. */
async function openAcme(page) {
  await page.getByRole("list", { name: "Projects in the column" }).getByRole("button", { name: "Acme Holdings", exact: true }).click();
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
/** The untitled recording in view (D-031): its row in the list, its page's heading, or both; throws when it is not. */
async function showUntitled(page, what) {
  await page.waitForSelector(".segments .segment");
  const row = page.getByTestId("library-list").locator(".item-title", { hasText: /^Recording$/ });
  const heading = page.locator(".detail-title", { hasText: /^Recording/ });
  if (what === "row") await row.scrollIntoViewIfNeeded();
  if (what === "heading") await heading.scrollIntoViewIfNeeded();
  await page.waitForTimeout(300);
  const wanted = what === "both" ? [row, heading] : [what === "row" ? row : heading];
  for (const target of wanted) {
    const box = await target.boundingBox();
    const size = page.viewportSize();
    if (!box || box.y < 0 || box.y + box.height > size.height || box.x < 0 || box.x + box.width > size.width) {
      throw new Error("the untitled recording is not in view");
    }
  }
}

/** Opens Help & shortcuts from the sidebar and checks its buttons are on screen, inside the sheet (D-133). */
async function openHelp(page) {
  await page.waitForSelector(".segments .segment");
  await page.locator(".sidebar").getByRole("button", { name: /Help & shortcuts/ }).click();
  await page.waitForSelector(".help-sheet");
  const outside = await page.evaluate(() => {
    const sheet = document.querySelector(".help-sheet").getBoundingClientRect();
    return [...document.querySelectorAll(".help-actions .btn")].filter((b) => {
      const r = b.getBoundingClientRect();
      return r.top < sheet.top || r.bottom > sheet.bottom || r.left < sheet.left || r.right > sheet.right;
    }).map((b) => b.textContent);
  });
  if (outside.length) throw new Error(`Help's buttons are not on screen: ${outside.join(", ")}`);
}

async function shoot(page, file, keepPointer = false) {
  // No hover state left from the last click (a row's menu button, a tooltip), unless the hover is the picture.
  if (!keepPointer) await page.mouse.move(0, 0);
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(400);
  await page.screenshot({ path: file });
  console.log(file);
}

const only = process.argv[2]?.split(",");
const server = await createServer({ root: desktop, logLevel: "error", server: { port: 5199, strictPort: false } });
await server.listen();
const base = server.resolvedUrls.local[0];
const browser = await launch();
await mkdir(out, { recursive: true });
const failed = [];
try {
  for (const [name, screen, prepare, viewport] of SCREENS) {
    if (only && !only.some((o) => name.startsWith(o))) continue;
    for (const theme of ["light", "dark"]) {
      // `scale`: the text that much larger (the page zoomed: the same window holds 1/scale of the points).
      const scale = viewport?.scale ?? 1;
      const size = viewport ? { width: Math.round(viewport.width / scale), height: Math.round(viewport.height / scale) } : { width: 1440, height: 900 };
      const page = await browser.newPage({ viewport: size, deviceScaleFactor: scale, colorScheme: theme, reducedMotion: "reduce" });
      page.on("pageerror", (e) => console.error(`${name}-${theme}: ${e.message}`));
      const [screenName, ...extra] = screen.split("&");
      await page.goto(`${base}scripts/screens/index.html?screen=${screenName}&theme=${theme}${extra.length ? `&${extra.join("&")}` : ""}`);
      try {
        // A screen whose picture is a hover says so (`{ keepPointer: true }`): the pointer stays where it left it.
        const kept = await prepare(page);
        await shoot(page, join(out, `desktop-${name}-${theme}.png`), kept?.keepPointer === true);
      } catch (e) {
        // One screen that cannot be reached says so and the rest are still taken; the run fails at the end.
        failed.push(`${name}-${theme}`);
        console.error(`${name}-${theme}: ${e.message.split("\n")[0]}`);
      }
      await page.close();
    }
  }
  for (const [name, query, prepare, viewport] of HARNESS) {
    if (only && !only.some((o) => name.startsWith(o))) continue;
    for (const theme of ["light", "dark"]) {
      const page = await browser.newPage({ viewport: viewport ?? { width: 1440, height: 900 }, colorScheme: theme, reducedMotion: "reduce" });
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
if (failed.length) {
  console.error(`Not taken: ${failed.join(", ")}`);
  process.exitCode = 1;
}
