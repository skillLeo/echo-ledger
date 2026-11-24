// FIX-01 (PLAN_V2_1_POLISH): Settings says exactly which windows are hidden, honestly per system. Only the live words
// are hidden (Live Assist, captions, translation); the recording controls, the after-call card and the dictation box
// always show. On a Mac the hiding is best effort (ScreenCaptureKit, macOS 15 and later, can ignore it).
import { beforeEach, describe, expect, it, vi } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { act, render, screen, within } from "@testing-library/react";
import { flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makePrivacyStatus, makeSettings } from "../../test/fixtures";
import { setting } from "../../lib/settingsRegistry";
import { BADGE_HINT } from "../../lib/privacy";
import { HONEST_LINE, LAST_CHECK_SENTENCE, SHARE_PRIVACY_LEAD, SharePrivacyPage } from "./PrivacySettings";
import { SharePrivacyPageV2 } from "../../v2/settings/pages/SharePrivacyV2";
import { CAPTURE_HELP } from "./HelpDialog";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

beforeEach(() => resetTauriMock());

describe("the capture policy's words (FIX-01)", () => {
  it("says what the page is about in one line, and how far hiding goes on each system", () => {
    expect(SHARE_PRIVACY_LEAD).toBe("What others see when you share your screen.");
    expect(HONEST_LINE.macos).toBe("Hidden from screenshots and most screen shares. If you share your entire screen in some apps, it may still show. Share a single window to be safe.");
    expect(HONEST_LINE.windows).toBe("Hidden from screen shares and recordings (Windows 10 version 2004 or later).");
    for (const line of Object.values(HONEST_LINE)) expect(line).not.toMatch(/fully hidden|invisible|always hidden/i);
  });

  it.each([["macos"], ["windows"]] as const)("%s, new design: one lead line, every window's state on its row, one footnote", async (platform) => {
    const other = platform === "macos" ? "windows" : "macos";
    respond("privacy_status", makePrivacyStatus({ platform, badge: "private" }));
    render(<div className="el-root"><SharePrivacyPageV2 settings={makeSettings()} patch={async () => true} onCheck={() => undefined} /></div>);
    await act(flushAsync);
    const page = screen.getByTestId("share-privacy");
    // The top: the lead line alone (no stacked sentences, no last check: that is the Check row's value).
    const top = page.querySelector(".el-share-top")!;
    expect(top.textContent).toBe(SHARE_PRIVACY_LEAD);
    for (const sentence of Object.values(LAST_CHECK_SENTENCE)) expect(page.textContent).not.toContain(sentence);
    expect(within(row(page, "share_privacy.check")).getByText("Hidden, today")).toBeInTheDocument();
    // Every row says its state on the right, and what to do in its subtitle.
    expect(row(page, "share_privacy.main")).toHaveTextContent("Shown in screen shares");
    const always = row(page, "share_privacy.always");
    expect(always).toHaveTextContent("Recording controls");
    expect(always).toHaveTextContent("And the after-call card");
    expect(always).toHaveTextContent("Always shown");
    // Decision 8: the dictation box is shown, and says why.
    const dictation = row(page, "share_privacy.dictation");
    expect(dictation).toHaveTextContent("Dictation box");
    expect(dictation).toHaveTextContent("Always shown");
    expect(dictation).toHaveTextContent("It shows your own words as you type them");
    for (const [anchor, title, subtitle] of [["share_privacy.card", "Live Assist card", "Show it from the card's own menu"], ["share_privacy.captions", "Captions", "Show them from the captions' own menu"]]) {
      const r = row(page, anchor);
      expect(r).toHaveTextContent(title);
      expect(r).toHaveTextContent("Hidden");
      expect(r).toHaveTextContent(subtitle);
    }
    const translate = row(page, "share_privacy.hiding");
    expect(within(translate).getByLabelText("Live Translate")).toBeInTheDocument();
    expect(translate).toHaveTextContent("Also hidden from your own screenshots");
    // One footnote under the windows: the main window's line and this system's, never the other's.
    const footnote = screen.getByTestId("share-privacy-footnote");
    expect(footnote.textContent).toBe(`The main window shows in screen shares unless you hide it for a while. ${HONEST_LINE[platform]}`);
    expect(page.textContent).not.toContain(HONEST_LINE[other]);
    expect(page.textContent?.split(HONEST_LINE[platform]).length).toBe(2);
    // English in a right-to-left window keeps its own direction (its full stop at the end), aligned like the page.
    expect(top.querySelector("p > bdi")).not.toBeNull();
    expect(footnote.querySelector("bdi")).not.toBeNull();
  });

  it("Live Translate shown: its row says so, never that it is hidden", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "macos", hiding: "off" }));
    render(<div className="el-root"><SharePrivacyPageV2 settings={makeSettings({ hideFromSharing: "off" })} patch={async () => true} onCheck={() => undefined} /></div>);
    await act(flushAsync);
    const translate = row(screen.getByTestId("share-privacy"), "share_privacy.hiding");
    expect(translate).toHaveTextContent("Shows in your screen shares and screenshots");
    expect(translate).not.toHaveTextContent("Also hidden");
  });

  it("today's design says the same: the lead line, the always-shown windows, one footnote", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "windows" }));
    render(<SharePrivacyPage settings={makeSettings()} patch={async () => true} onCheck={() => undefined} />);
    await act(flushAsync);
    const text = screen.getByTestId("share-privacy").textContent ?? "";
    expect(text).toContain(SHARE_PRIVACY_LEAD);
    expect(text).toContain("Recording controls");
    expect(text).toContain("Always shown · and the after-call card");
    expect(text).toContain("Always shown · it shows your own words as you type them");
    expect(screen.getByTestId("share-privacy-footnote").textContent).toBe(`The main window shows in screen shares unless you hide it for a while. ${HONEST_LINE.windows}`);
    expect(screen.getByLabelText("Live Translate")).toBeInTheDocument();
    expect(screen.queryByLabelText("Other floating windows")).toBeNull();
  });

  it("names the row for what it hides now, and finds it under the old words too", () => {
    const hiding = setting("share_privacy.hiding");
    expect(hiding.title).toBe("Live Translate");
    expect(hiding.description).toBe("Also hidden from your own screenshots");
    expect(hiding.aliases).toEqual(expect.arrayContaining(["Other floating windows", "Hide live words"]));
    expect(hiding.keywords).not.toEqual(expect.arrayContaining(["recording controls"]));
    expect(setting("share_privacy.always").aliases).toEqual(expect.arrayContaining(["Recording controls & after-call card", "After-call card"]));
    expect(setting("share_privacy.dictation").title).toBe("Dictation box");
    expect(setting("share_privacy.demo").title).toBe("Show Live Translate for 10 min");
  });

  it("the badge on the recording controls and the dictation box speaks of the live words, never of that window", () => {
    expect(BADGE_HINT.private).toBe("Private: live words are hidden from screen shares. Check screen-share privacy");
    expect(BADGE_HINT.visible).toBe("Live words may show in screen shares. Check screen-share privacy");
    expect(CAPTURE_HELP).toBe("EchoLedger's windows show in your screenshots; only the live words (Live Assist, captions, translation) are hidden from them. For support, send Copy diagnostics (Settings › Screen-share privacy).");
  });
});

/** The row of the page with that anchor. */
const row = (page: HTMLElement, anchor: string) => {
  const found = page.querySelector<HTMLElement>(`[data-setting="${anchor}"]`);
  if (!found) throw new Error(`no row ${anchor}`);
  return found;
};

describe("one capture policy (FIX-01)", () => {
  it("no page sets a window's capture flag: only the core's privacy service does (src-tauri/src/privacy)", () => {
    const root = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
    const files = (dir: string): string[] => readdirSync(dir).flatMap((f) => {
      const path = join(dir, f);
      return statSync(path).isDirectory() ? files(path) : [path];
    });
    const sources = files(root).filter((f) => /\.(tsx?|mjs|js)$/.test(f) && !/\.test\.tsx?$/.test(f));
    expect(sources.length).toBeGreaterThan(100);
    const setters = /setContentProtected|set_content_protected|content_protected|setSharingType|SetWindowDisplayAffinity/;
    expect(sources.filter((f) => setters.test(readFileSync(f, "utf8")))).toEqual([]);
  });
});
