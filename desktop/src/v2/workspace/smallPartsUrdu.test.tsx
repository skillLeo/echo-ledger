// Desktop Urdu, D-264: three small pieces that still spoke English in an Urdu window. A locked feature's tag (PlanBadge,
// on Live, a project's page and Settings: the feature's name and its sentence in Urdu, the plan's name as it is), the
// Live Translate overlay (its own window: Done and the hidden pill in the main window's language, as it keeps it on this
// computer; the keys left to right in their isolates) and a project's screens on its Files tab (ProjectScreens, with
// its thumbnails and its viewer). Classic's screens pass "en" and stay English in an Urdu window; English is unchanged.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { callsOf, emitEvent, flushAsync, listenerCount, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeEntitlements, makeScreen, makeStatus, sampleEntitlements } from "../../test/fixtures";
import type { ClientWorkspace, Entitlements, ProjectScreen, ProjectScreensPage } from "../../lib/ipc";
import { featureWords, resetEntitlementsForTests } from "../../lib/entitlements";
import { forgetPictures } from "../../lib/screens";
import { formatWhen } from "../../lib/format";
import { TRANSLATE_FEED, type TranslateFeed } from "../../lib/translate";
import { BadgeMark, LockedChip, LockedFeatureCard, PlanBadge, useLockedTag } from "../../views/main/Upgrade";
import { ProjectScreens } from "../../views/main/ScreenMoments";
import TranslateOverlayApp from "../../views/translate/TranslateOverlayApp";
import { LiveV2 } from "../live/LiveV2";
import { WorkspaceV2 } from "./WorkspaceV2";
import { FSI, PDI, resetLanguageForTests, setLanguage, stripIsolates, type Lang } from "../../i18n";
import { KEPT_LANGUAGE_KEY, keptWindowLanguage } from "../../i18n/language";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const iso = (value: string | number) => `${FSI}${value}${PDI}`;
const urdu = () => act(() => setLanguage("ur", { root: false }));
const english = () => act(() => setLanguage("en", { root: false }));
const NOW = new Date(2026, 10, 26, 15, 0);
/** Latin letters but the names kept as they are (plans', projects', recordings', keys, the data): what Urdu must not have. */
const latinIn = (words: string, kept: readonly string[]) =>
  /[A-Za-z]/.test([...kept].sort((a, b) => b.length - a.length).reduce((rest, name) => rest.split(name).join(""), stripIsolates(words)));
/** Letters of Urdu's script: what an English screen must not have. */
const urduIn = (words: string) => /[؀-ۿ]/.test(words);
/** Every word a part shows or says: its text, its labels, titles and placeholders. */
function wordsOf(root: Element): string[] {
  const out: string[] = [];
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    const text = node.textContent?.trim();
    if (text) out.push(text);
  }
  for (const element of [root, ...root.querySelectorAll("*")]) {
    for (const name of ["aria-label", "title", "placeholder"]) {
      const value = element.getAttribute(name);
      if (value) out.push(value);
    }
  }
  return out;
}

beforeEach(() => {
  resetTauriMock();
  resetLanguageForTests();
  resetEntitlementsForTests(null);
  localStorage.clear();
});
afterEach(async () => {
  cleanup();
  await flushAsync();
  vi.useRealTimers();
  resetLanguageForTests();
  localStorage.clear();
  document.documentElement.lang = "en";
  document.documentElement.removeAttribute("dir");
});

// ---- 1. A locked feature's tag ------------------------------------------------------------------------------------

/** An answer whose plans name none for Live Translate: the tag says Paid. */
function noPlanNamed(): Entitlements {
  const e = makeEntitlements();
  e.features.live_translate = { ...e.features.live_translate, lowest_plan: null };
  e.plans = e.plans.filter((p) => !p.features.includes("live_translate"));
  return e;
}
/** useLockedTag's tag, drawn. */
function TagOf({ feature, lang }: { feature: string; lang?: Lang }) {
  const tag = useLockedTag(feature, lang);
  return <span data-testid="tag">{tag?.tag}</span>;
}
const live = () => (
  <LiveV2 status={makeStatus({ callsReady: true })} assistOn={false} projects={[]} onRecord={vi.fn()} onVoiceNote={vi.fn()} onCaptions={vi.fn()}
    onSettings={vi.fn()} run={(fn) => fn()} />
);

describe("a locked feature's tag (PlanBadge): the window's language in the new design, classic's English", () => {
  beforeEach(() => {
    respond("get_settings", null);
    respond("live_translate_status", { available: false, reason: "plan" });
  });

  it("on Live in Urdu: the feature's name and its sentence in Urdu, the plan's name as it is", async () => {
    resetEntitlementsForTests(sampleEntitlements("free"));
    await urdu();
    render(live());
    await act(flushAsync);
    const tag = screen.getByRole("button", { name: `${iso("لائیو ترجمہ")}، ${iso("Ultra")} میں` });
    expect(tag).toHaveTextContent(/^Ultra$/);
    expect(tag).toHaveAttribute("title", `${iso("لائیو ترجمہ")}، ${iso("Ultra")} میں ہے`);
    expect(wordsOf(tag).filter((w) => latinIn(w, ["Ultra"]))).toEqual([]);
  });

  it("with no plan named, in Urdu: پیڈ, and the paid plan's sentence; the chip, the mark and a row's tag say it too", async () => {
    resetEntitlementsForTests(noPlanNamed());
    await urdu();
    render(<>
      <PlanBadge feature="live_translate" />
      <LockedChip feature="live_translate" label="لائیو ترجمہ" />
      <BadgeMark feature="live_translate" />
      <TagOf feature="live_translate" />
    </>);
    const [tag, chipTag] = screen.getAllByRole("button", { name: `${iso("لائیو ترجمہ")}، کسی پیڈ پلان میں` });
    expect(tag).toHaveTextContent(/^پیڈ$/);
    expect(tag).toHaveAttribute("title", `${iso("لائیو ترجمہ")}، کسی پیڈ پلان میں ہے`);
    expect(chipTag).toHaveTextContent(/^پیڈ$/);
    expect(document.querySelector(".plan-badge.mark")).toHaveTextContent(/^پیڈ$/);
    expect(screen.getByTestId("tag")).toHaveTextContent(/^پیڈ$/);
    expect(wordsOf(document.body).filter((w) => latinIn(w, []))).toEqual([]);
  });

  it("a feature's name in Urdu where the catalogue has it, the server's and the app's own; a name it doesn't know as written", async () => {
    await urdu();
    const e = sampleEntitlements("free");
    expect(["platform_transcription", "views", "double_check", "screen_moments", "connector", "team", "premium_engines", "live_translate", "ai_notes", "record"]
      .map((f) => featureWords(e, f).name)).toEqual([
      "EchoLedger کی ٹرانسکرپشن", "رومن اردو، انگریزی اور اردو", "دوہری جانچ", "اسکرین کے لمحات", "اے آئی اسسٹنٹ سے پوچھیں", "ٹیم ورک اسپیس",
      "پریمیم انجن", "لائیو ترجمہ", "اے آئی نوٹس", "ریکارڈ اور اپ لوڈ",
    ]);
    // The app's words when the server sends none (an answer without names).
    expect(["platform_transcription", "views", "ai_notes"].map((f) => featureWords(null, f).name))
      .toEqual(["EchoLedger کی ٹرانسکرپشن", "رومن اردو، انگریزی اور اردو ویوز", "کال نوٹس، یادداشت اور پوچھیں"]);
    expect(featureWords(makeEntitlements(), "views").name).toBe("Transcript views");
    // Classic's ("en") in an Urdu window: English.
    expect(featureWords(e, "live_translate", "en")).toEqual({ name: "Live Translate", description: "Live subtitles in your language for games and videos." });
    expect(featureWords(null, "views", "en").name).toBe("Roman Urdu, English and Urdu views");
  });

  it("classic's (\"en\") in an Urdu window: English, as before", async () => {
    resetEntitlementsForTests(noPlanNamed());
    await urdu();
    const { container } = render(<>
      <PlanBadge feature="live_translate" lang="en" />
      <LockedChip feature="live_translate" label="Live Translate" lang="en" />
      <BadgeMark feature="live_translate" lang="en" />
      <TagOf feature="live_translate" lang="en" />
      <LockedFeatureCard feature="live_translate" />
    </>);
    const tags = screen.getAllByRole("button", { name: "Live Translate, in a paid plan" });
    expect(tags).toHaveLength(3);
    for (const tag of tags) {
      expect(tag).toHaveTextContent(/^Paid$/);
      expect(tag).toHaveAttribute("title", "Live Translate is in a paid plan");
    }
    expect(document.querySelector(".plan-badge.mark")).toHaveTextContent(/^Paid$/);
    expect(screen.getByTestId("tag")).toHaveTextContent(/^Paid$/);
    expect(within(screen.getAllByTestId("locked-live_translate")[1]).getByText("Live Translate")).toBeInTheDocument();
    expect(urduIn(wordsOf(container).join(" "))).toBe(false);
    cleanup();
    resetEntitlementsForTests(sampleEntitlements("free"));
    render(<PlanBadge feature="live_translate" lang="en" />);
    expect(screen.getByRole("button", { name: "Live Translate, in Ultra" })).toHaveAttribute("title", "Live Translate is in Ultra");
  });

  it("English is unchanged: the new design's default and classic's say the same", async () => {
    resetEntitlementsForTests(sampleEntitlements("free"));
    await english();
    render(live());
    await act(flushAsync);
    const tag = screen.getByRole("button", { name: "Live Translate, in Ultra" });
    expect(tag).toHaveTextContent(/^Ultra$/);
    expect(tag).toHaveAttribute("title", "Live Translate is in Ultra");
    cleanup();
    resetEntitlementsForTests(noPlanNamed());
    render(<><PlanBadge feature="live_translate" /><BadgeMark feature="live_translate" /><TagOf feature="live_translate" /></>);
    expect(screen.getByRole("button", { name: "Live Translate, in a paid plan" })).toHaveTextContent(/^Paid$/);
    expect(screen.getByRole("button", { name: "Live Translate, in a paid plan" })).toHaveAttribute("title", "Live Translate is in a paid plan");
    expect(document.querySelector(".plan-badge.mark")).toHaveTextContent(/^Paid$/);
    expect(screen.getByTestId("tag")).toHaveTextContent(/^Paid$/);
    expect(featureWords(sampleEntitlements("free"), "views").name).toBe("Roman Urdu, English and Urdu");
  });
});

// ---- 2. The Live Translate overlay --------------------------------------------------------------------------------

function feed(over: Partial<TranslateFeed> = {}): TranslateFeed {
  return { mode: "game", app: "Valorant", text: "", partial: "", original: "", status: "live", notice: null, locked: false, hidden: false, hide_keys: "Ctrl+Shift+H", lock_keys: "Ctrl+Shift+T", ...over };
}
const keep = (kept: { on: boolean; account: Lang | null }) => localStorage.setItem(KEPT_LANGUAGE_KEY, JSON.stringify(kept));
/** The main window keeps another language: other windows hear it as a storage event. */
const keptElsewhere = async (kept: { on: boolean; account: Lang | null }) => {
  keep(kept);
  await act(async () => {
    window.dispatchEvent(new StorageEvent("storage", { key: KEPT_LANGUAGE_KEY }));
  });
};
const overlay = async () => {
  respond("translate_status", null);
  const view = render(<TranslateOverlayApp />);
  await waitFor(() => expect(listenerCount(TRANSLATE_FEED)).toBeGreaterThan(0));
  return view;
};

describe("the Live Translate overlay: the main window's language, as it keeps it on this computer", () => {
  it("in Urdu: Done (ہو گیا) and the hidden pill, the keys left to right in their isolates; the window's <html> follows", async () => {
    keep({ on: true, account: "ur" });
    const { container } = await overlay();
    await waitFor(() => expect(document.documentElement.lang).toBe("ur"));
    expect(document.documentElement.dir).toBe("rtl");
    await emitEvent(TRANSLATE_FEED, feed());
    const done = screen.getByRole("button", { name: `ہو گیا (${iso("Ctrl+Shift+T")})` });
    expect(done).toHaveAttribute("dir", "rtl");
    expect(container.querySelector(".translate-dots")).toHaveAttribute("aria-label", "سن رہا ہے");
    expect(wordsOf(container).filter((w) => latinIn(w, ["Ctrl+Shift+T"]))).toEqual([]);
    fireEvent.click(done);
    expect(callsOf("translate_toggle_lock")).toHaveLength(1);

    await emitEvent(TRANSLATE_FEED, feed({ hidden: true, hide_keys: "⌘⇧H" }));
    const pill = screen.getByRole("status");
    expect(pill.textContent).toBe(`لائیو ترجمہ سن رہا ہے · ${iso("⌘⇧H")} سے دکھائیں`);
    expect(pill).toHaveAttribute("dir", "rtl");
    // What the translation says, and the core's notice, are shown as they come.
    await emitEvent(TRANSLATE_FEED, feed({ text: "Enemy at the red house.", notice: null, lock_keys: "⌘⇧T" }));
    expect(screen.getByText("Enemy at the red house.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: `ہو گیا (${iso("⌘⇧T")})` })).toBeInTheDocument();
  });

  it("follows the main window at once: another language kept, or Urdu turned off, and back", async () => {
    keep({ on: true, account: "ur" });
    await overlay();
    await emitEvent(TRANSLATE_FEED, feed());
    await waitFor(() => expect(screen.getByRole("button", { name: `ہو گیا (${iso("Ctrl+Shift+T")})` })).toBeInTheDocument());
    await keptElsewhere({ on: true, account: "en" });
    await waitFor(() => expect(screen.getByRole("button", { name: "Done (Ctrl+Shift+T)" })).toBeInTheDocument());
    expect(document.documentElement.lang).toBe("en");
    await keptElsewhere({ on: true, account: "ur" });
    await waitFor(() => expect(screen.getByRole("button", { name: `ہو گیا (${iso("Ctrl+Shift+T")})` })).toBeInTheDocument());
    // The new design (or Urdu) turned off in the main window: English, whatever the account's language.
    await keptElsewhere({ on: false, account: "ur" });
    await waitFor(() => expect(screen.getByRole("button", { name: "Done (Ctrl+Shift+T)" })).toBeInTheDocument());
  });

  it("English when nothing is kept, or Urdu is off: as before", async () => {
    expect([
      keptWindowLanguage({ on: false, account: null }), keptWindowLanguage({ on: false, account: "ur" }), keptWindowLanguage({ on: true, account: null }),
      keptWindowLanguage({ on: true, account: "en" }), keptWindowLanguage({ on: true, account: "ur" }),
    ]).toEqual(["en", "en", "en", "en", "ur"]);
    const { container } = await overlay();
    await emitEvent(TRANSLATE_FEED, feed());
    expect(screen.getByRole("button", { name: "Done (Ctrl+Shift+T)" })).toBeInTheDocument();
    expect(container.querySelector(".translate-dots")).toHaveAttribute("aria-label", "Listening");
    await emitEvent(TRANSLATE_FEED, feed({ hidden: true }));
    expect(screen.getByRole("status").textContent).toBe("Live Translate is listening · Ctrl+Shift+H to show");
    expect(document.documentElement.lang).toBe("en");
  });
});

// ---- 3. A project's screens -----------------------------------------------------------------------------------------

const shot = (index: number, session: string, title: string | null, at_ms: number, extra: Partial<ProjectScreen> = {}): ProjectScreen => ({
  ...makeScreen(index, at_ms), id: `${session}-${index}`, session_id: session, session_title: title, session_started_at: "2026-11-24T10:00:00Z", ...extra,
});
const FIRST: ProjectScreensPage = {
  data: [shot(1, "s1", "Kick-off", 60_000, { description: "The checkout page" }), shot(2, "s1", "Kick-off", 872_000), shot(1, "s3", null, 5_000)],
  next_cursor: "c-2",
};
const PROJECT = { id: "c1", name: "Qureshi Textiles", screens: true };
/** The names and the data as they are: the project's, the recording's, what a screen shows, the times. */
const KEPT = ["Qureshi Textiles", "Kick-off", "The checkout page"];
const screens = (props: { lang?: Lang; first?: ProjectScreensPage; client?: typeof PROJECT | { id: string; name: string; screens?: boolean } } = {}) => (
  <ProjectScreens client={props.client ?? PROJECT} first={props.first ?? FIRST} notify={vi.fn()} onOpenRecording={vi.fn()} onClientChanged={vi.fn()} lang={props.lang} />
);
const thumbs = () => within(screen.getByTestId("project-screens")).getAllByRole("listitem").map((item) => item.querySelector("button")!);
const openViewer = async () => {
  fireEvent.click(thumbs()[1]);
  await act(flushAsync);
  return screen.getByRole("dialog");
};

describe("a project's screens (ProjectScreens): the window's language on the new project page, classic's English", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(NOW);
    forgetPictures();
    respondWith("screen_image", ({ url }) => `data:image/webp;base64,${btoa(String(url))}`);
    respondWith("client_screens", () => FIRST);
  });

  it("on the new project page's Files tab, in Urdu: the switch, the screens, Show more and the viewer; no Latin but the names", async () => {
    const WS: ClientWorkspace = { client: { ...PROJECT, ai_notes: true }, ai: { configured: true, spent_usd: 0, budget_usd: 5, client_spent_usd: 0 }, brief: null, memory: [], notes: [], files: [] };
    respond("library_list", []);
    respond("client_workspace", { data: WS });
    respond("client_rules", { data: [] });
    respond("search_library", { data: [], next_page: null });
    await urdu();
    render(<WorkspaceV2 project={PROJECT} projects={[PROJECT]} parent={null} folders={[]} menu={[]} onShare={vi.fn()} onSend={vi.fn()} onRequest={vi.fn()}
      onBack={vi.fn()} onProjects={vi.fn()} onOpenProject={vi.fn()} onOpenRecording={vi.fn()} onLibrary={vi.fn()} onMoveRecordings={vi.fn()} />);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("tab", { name: "فائلیں" }));
    await act(flushAsync);
    await act(flushAsync);
    const part = screen.getByRole("region", { name: "اس پروجیکٹ کے اسکرین کے لمحات" });
    expect(within(part).getByRole("switch", { name: "اسکرین کے لمحات محفوظ کریں" })).toHaveAttribute("aria-checked", "true");
    const hint = document.getElementById("screens-keep-c1-hint")!;
    expect(hint.textContent).toBe(`${iso("Qureshi Textiles")} کی میٹنگز کے دوران اسکرین پر جو کچھ تھا، کمپیوٹر ایپ اسے محفوظ رکھتی ہے۔`);
    expect(hint).toHaveAttribute("dir", "rtl");
    const grid = screen.getByTestId("project-screens");
    expect(within(grid).getByRole("button", { name: `اسکرین ${iso(1)} · ${iso("1:00")}: ${iso("The checkout page")}` })).toBeInTheDocument();
    expect(within(grid).getByText(`اسکرین ${iso(2)} · ${iso("14:32")}`)).toBeInTheDocument();
    // A recording with no title is named in the window's words.
    expect(within(grid).getByRole("region", { name: "ریکارڈنگ" })).toBeInTheDocument();
    expect(within(grid).getByRole("region", { name: "Kick-off" })).toBeInTheDocument();
    expect(within(grid).getByRole("button", { name: "مزید دکھائیں" })).toBeInTheDocument();
    const dates = [formatWhen(FIRST.data[0].session_started_at!)];
    expect([...wordsOf(part), ...wordsOf(grid)].filter((w) => latinIn(w, [...KEPT, ...dates]))).toEqual([]);

    const viewer = await openViewer();
    expect(viewer).toHaveAccessibleName(`اسکرین ${iso(2)} · ${iso("14:32")}`);
    expect(within(viewer).getByText(`${iso(3)} میں سے ${iso(2)}`)).toBeInTheDocument();
    // The recording's title in its isolate and when (the date keeps its no-break spaces).
    const sub = viewer.querySelector(".screen-viewer-title .small")!;
    expect(sub.textContent).toBe(`${iso("Kick-off")} · ${dates[0]}`);
    expect(sub).toHaveAttribute("dir", "rtl");
    for (const name of ["بند کریں", "پچھلی اسکرین", "اگلی اسکرین", "یہاں سے چلائیں"]) expect(within(viewer).getByRole("button", { name })).toBeInTheDocument();
    expect(wordsOf(viewer).filter((w) => latinIn(w, [...KEPT, ...dates]))).toEqual([]);
  });

  it("in Urdu with nothing kept yet, and with the switch off", async () => {
    await urdu();
    render(screens({ first: { data: [], next_cursor: null }, client: { ...PROJECT, screens: false } }));
    const empty = screen.getByTestId("screens-empty");
    expect(within(empty).getByText("ابھی اسکرین کے کوئی لمحات نہیں")).toBeInTheDocument();
    const line = empty.querySelector("p")!;
    expect(line.textContent).toBe(`جب ${iso("Qureshi Textiles")} کی کوئی میٹنگ کمپیوٹر ایپ پر ریکارڈ ہو تو شیئر کی گئی اسکرین پر جو کچھ تھا وہ یہاں نظر آتا ہے، ٹرانسکرپٹ میں اپنے لمحے کے ساتھ۔`);
    expect(line).toHaveAttribute("dir", "rtl");
    expect(document.getElementById("screens-keep-c1-hint")!.textContent).toBe(`${iso("Qureshi Textiles")} میں رکھی گئی میٹنگز کی اسکرین سے کچھ محفوظ نہیں ہوتا۔`);
    expect(wordsOf(document.body).filter((w) => latinIn(w, KEPT))).toEqual([]);
  });

  it("classic's (\"en\") in an Urdu window: English, as before (its dates follow the window, as they did)", async () => {
    await urdu();
    const { container } = render(screens({ lang: "en" }));
    await act(flushAsync);
    expect(screen.getByRole("switch", { name: "Keep screen moments" })).toBeInTheDocument();
    expect(screen.getByRole("region", { name: "Screen moments of this project" })).toBeInTheDocument();
    expect(document.getElementById("screens-keep-c1-hint")!.textContent).toBe("The desktop keeps what was on screen during Qureshi Textiles's meetings.");
    expect(screen.getByRole("button", { name: "Screen 1 · 1:00: The checkout page" })).toBeInTheDocument();
    expect(screen.getByRole("region", { name: "Recording" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Show more" })).toBeInTheDocument();
    const dates = [formatWhen(FIRST.data[0].session_started_at!)];
    const words = (root: Element) => wordsOf(root).map((w) => dates.reduce((rest, d) => rest.split(d).join(""), w));
    expect(urduIn(words(container).join(" "))).toBe(false);
    const viewer = await openViewer();
    expect(viewer).toHaveAccessibleName("Screen 2 · 14:32");
    expect(within(viewer).getByText("2 of 3")).toBeInTheDocument();
    for (const name of ["Close", "Previous screen", "Next screen", "Play from here"]) expect(within(viewer).getByRole("button", { name })).toBeInTheDocument();
    expect(urduIn(words(viewer).join(" "))).toBe(false);
  });

  it("English is unchanged: the new page's default says what classic's does", async () => {
    await english();
    const { container } = render(screens());
    await act(flushAsync);
    expect(document.getElementById("screens-keep-c1-hint")!.textContent).toBe("The desktop keeps what was on screen during Qureshi Textiles's meetings.");
    expect(screen.getByRole("button", { name: "Screen 1 · 1:00: The checkout page" })).toHaveAttribute("title", "The checkout page");
    expect(screen.getByText("Screen 2 · 14:32")).toBeInTheDocument();
    expect(urduIn(wordsOf(container).join(" "))).toBe(false);
    const viewer = await openViewer();
    expect(within(viewer).getByText(/^Kick-off · /)).toBeInTheDocument();
    expect(within(viewer).getByText("2 of 3")).toBeInTheDocument();
    cleanup();
    render(screens({ first: { data: [], next_cursor: null } }));
    expect(screen.getByText("No screen moments yet")).toBeInTheDocument();
    expect(screen.getByText("When a meeting of Qureshi Textiles is recorded on the desktop, what was on the shared screen shows here, next to its moment in the transcript.")).toBeInTheDocument();
  });
});
