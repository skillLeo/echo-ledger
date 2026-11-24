// The desktop v2 screens the checks open (the gallery's sample screens and the app's own v2 screens in fixture mode,
// scripts/screens/main.tsx), with the steps that reach each one. The bug bash (bugbash.mjs) checks every one; the Gate
// 3 set (shoot.mjs --app) photographs the ones GATE3 names.
export const FLAGS = "ui_v2.ask,ui_v2.shell,ui_v2.library,ui_v2.settings,ui_v2.setup_health,ui_v2.home,ui_v2.live,live_assist,ui_v2.recording_controls,ui_v2.after_call";
export const app = (screen) => `scripts/screens/index.html?screen=${screen}&uiflags=${FLAGS}`;
export const nav = (name) => async (page) => {
  await page.getByRole("navigation", { name: "Main" }).getByRole("button", { name, exact: true }).click();
  await page.waitForTimeout(1200);
};

/** Whether a gallery page was opened in Urdu (its steps then use the Urdu words). */
const urdu = (page) => new URL(page.url()).searchParams.get("lang") === "ur";
/** The sample lists (D14) by their English name, in the page's language. */
const LIST_UR = { Clients: "کلائنٹس", Home: "گھر", Family: "خاندان" };
const listChip = (page, name) => page.getByRole("group", { name: urdu(page) ? "فہرستیں" : "Lists" })
  .getByRole("button", { name: urdu(page) ? LIST_UR[name] : name, exact: true });

/** [name, kind, path (theme added), languages, steps] */
export const CASES = [
  ["g-library", "gallery", "?v2gallery&screen=library", ["en", "ur"]],
  ["g-recording", "gallery", "?v2gallery&screen=recording", ["en", "ur"]],
  ["g-plan", "gallery", "?v2gallery&screen=plan", ["en", "ur"]],
  ["g-settings", "gallery", "?v2gallery&screen=settings", ["en", "ur"]],
  ["g-library-error", "gallery", "?v2gallery&screen=library&state=error", ["en", "ur"]],
  ["g-assist", "gallery", "?v2gallery&screen=assist", ["en"]],
  ["g-kit", "gallery", "?v2gallery&screen=kit", ["en", "ur"]],
  ["g-kit-menus", "gallery", "?v2gallery&screen=kit", ["en", "ur"], async (page) => { await page.locator(".el-sortgroup").first().click(); await page.waitForTimeout(300); }],
  ["g-kit-scrolled", "gallery", "?v2gallery&screen=kit", ["en", "ur"], async (page) => { await page.locator(".g-kit-scroller").evaluate((el) => { el.scrollTop = el.scrollHeight; }); await page.waitForTimeout(300); }],
  ["g-lists", "gallery", "?v2gallery&screen=lists", ["en", "ur"]],
  ["g-lists-clients", "gallery", "?v2gallery&screen=lists", ["en", "ur"], async (page) => { await listChip(page, "Clients").click(); await page.waitForTimeout(300); }],
  ["g-lists-dialog", "gallery", "?v2gallery&screen=lists", ["en", "ur"], async (page) => {
    const ur = urdu(page);
    await page.getByRole("group", { name: ur ? "فہرستیں" : "Lists" }).getByRole("button", { name: ur ? "نئی فہرست" : "New list", exact: true }).click();
    await page.getByRole("textbox", { name: ur ? "فہرست کا نام" : "List name" }).fill(ur ? "سپلائرز" : "Suppliers");
    await page.getByRole("radio", { name: ur ? "نیلا" : "Blue" }).click();
    await page.getByRole("textbox").focus();
    await page.waitForTimeout(300);
  }],
  ["g-lists-addto", "gallery", "?v2gallery&screen=lists", ["en", "ur"], async (page) => { await page.locator('[data-row-id="r6"]').click({ button: "right", position: { x: 260, y: 28 } }); await page.waitForTimeout(300); }],
  ["g-lists-chipmenu", "gallery", "?v2gallery&screen=lists", ["en", "ur"], async (page) => {
    // Right-clicked low on the chip, so the menu opens under it and the chip stays in sight.
    const chip = listChip(page, "Home");
    const box = await chip.boundingBox();
    await chip.click({ button: "right", position: { x: box.width / 3, y: box.height - 2 } });
    await page.waitForTimeout(300);
  }],
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
  ["a-set-snippets", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Voice notes & dictation", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Snippets", exact: true }).click(); await page.waitForTimeout(900); }],
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
  ["a-set-devices", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "Devices", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-about", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "About", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-technical", "app", app("settings"), ["en"], async (page) => { await page.getByRole("button", { name: "About", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: "Technical details", exact: true }).click(); await page.waitForTimeout(900); }],
  ["a-set-mac-check", "app", app("settings-mac"), ["en"], async (page) => { await page.getByRole("button", { name: "Help", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: /^Mac check/ }).first().click(); await page.waitForTimeout(900); }],
  ["a-set-mac-check-report", "app", app("settings-mac-check"), ["en"], async (page) => { await page.getByRole("button", { name: "Help", exact: true }).click(); await page.waitForTimeout(900); await page.getByRole("button", { name: /^Mac check/ }).first().click(); await page.waitForTimeout(900); }],
  ["a-controls", "app", app("controls"), ["en"], async (page) => { await page.getByRole("button", { name: /Show the controls/ }).click(); await page.waitForTimeout(400); }],
];

/**
 * The Gate 3 desktop set (shoot.mjs --app): every v2 screen of the app once, as [file name, case above, what it is,
 * the element to scroll into view first]. Each is taken light in English, light right to left (lang=ur) and dark in
 * English, into docs/v2/gate3/desktop/app/, and listed in docs/v2/gate3/desktop/index.md.
 */
export const GATE3 = [
  ["home", "a-home", "Home"],
  ["library", "a-library", "Library"],
  ["recording", "a-recording", "A recording: player and transcript"],
  ["recording-screens", "a-recording-screens", "A recording's screen moments"],
  ["screen-viewer", "a-screen-viewer", "A screen moment, opened"],
  ["trash", "a-trash", "Trash"],
  ["project", "a-workspace", "A project's workspace"],
  ["live", "a-live", "Live"],
  ["live-translate", "a-live-translate", "Live › Live Translate", ".el-translate"],
  ["live-translate-ended", "a-live-translate-ended", "Live › Live Translate, a session that ended on its own", ".el-translate__ended"],
  ["live-assist-prep", "a-prep", "Live Assist: preparing a session"],
  ["recording-controls", "a-controls", "The recording controls window"],
  ["ask", "a-ask", "Ask"],
  ["settings", "a-settings", "Settings"],
  ["setup-health", "a-setup", "Settings › Setup & health (a Mac)"],
  ["help", "a-help", "Help"],
  ["help-guide", "a-help-guide", "Help › a guide"],
  ["whats-new", "a-whats-new", "Help › What's new"],
  ["contact", "a-contact", "Help › Contact support"],
  ["mac-check", "a-set-mac-check-report", "Help › Mac check, with its last report"],
  ["set-voice", "a-set-voice", "Settings › Voice notes & dictation"],
  ["set-replacements", "a-set-replacements", "Settings › Voice notes & dictation › Replacements"],
  ["set-snippets", "a-set-snippets", "Settings › Voice notes & dictation › Snippets"],
  ["set-history", "a-set-history", "Settings › Voice notes & dictation › Recent dictations"],
  ["set-captions", "a-set-captions", "Settings › Captions"],
  ["set-caption-style", "a-set-caption-style", "Settings › Captions › Style"],
  ["set-calls", "a-set-calls", "Settings › Calls & meetings"],
  ["set-call-sites", "a-set-call-sites", "Settings › Calls & meetings › Call sites"],
  ["set-language", "a-set-language", "Settings › Language"],
  ["set-words", "a-set-words", "Settings › Custom words"],
  ["set-key", "a-set-key", "Settings › Transcription key (Free)"],
  ["set-privacy", "a-set-privacy", "Settings › Privacy & data"],
  ["set-consent", "a-set-consent", "Settings › Privacy & data › Recording consent"],
  ["set-lock", "a-set-lock", "Settings › App lock"],
  ["set-security", "a-set-security", "Settings › Sign-in & security"],
  ["set-activity", "a-set-activity", "Settings › Sign-in & security › Sign-in activity"],
  ["set-share", "a-set-share", "Settings › Screen-share privacy"],
  ["set-share-check", "a-set-share-check", "Settings › Screen-share privacy › Check (a Mac)"],
  ["set-notifications", "a-set-notifications", "Settings › Notifications"],
  ["set-mic", "a-set-mic", "Settings › Microphone & audio"],
  ["set-screen", "a-set-screen", "Settings › Screen moments (Pro)"],
  ["set-excluded", "a-set-excluded", "Settings › Screen moments › Never capture these apps"],
  ["set-storage", "a-set-storage", "Settings › Storage"],
  ["set-appearance", "a-set-appearance", "Settings › Appearance"],
  ["set-shortcuts", "a-set-shortcuts", "Settings › Keyboard shortcuts"],
  ["set-profile", "a-set-profile", "Settings › Profile"],
  ["set-plan", "a-set-plan-free", "Settings › Plan & usage (Free, every feature shown)"],
  ["set-plan-pro", "a-set-plan-near", "Settings › Plan & usage (Pro, near its minutes)"],
  ["set-plan-request", "a-set-plan-request", "Settings › Plan & usage (a request waiting for payment)"],
  ["set-devices", "a-set-devices", "Settings › Devices"],
  ["set-about", "a-set-about", "Settings › About"],
  ["set-technical", "a-set-technical", "Settings › About › Technical details"],
];

/**
 * Design-review pictures (shoot.mjs --review=<id>): [file stem, case above, window widths]. Each is taken light and
 * dark, in English and Urdu, at every width given (the width then goes in the name: desktop-lists-800-dark-ur.png),
 * or at 1280 alone when none is given (desktop-lists-dialog-light-en.png), into the review's folder.
 */
export const REVIEWS = {
  D14: {
    dir: "docs/v2/design-review/D14",
    shots: [
      ["desktop-lists", "g-lists", [1280, 800]],
      ["desktop-lists-clients", "g-lists-clients", [1280, 800]],
      ["desktop-lists-dialog", "g-lists-dialog"],
      ["desktop-lists-addto", "g-lists-addto"],
      ["desktop-lists-chipmenu", "g-lists-chipmenu"],
    ],
  },
};
