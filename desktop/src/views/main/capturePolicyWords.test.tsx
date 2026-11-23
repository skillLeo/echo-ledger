// FIX-01 (PLAN_V2_1_POLISH): Settings says exactly which windows are hidden, honestly per system. Only the live words
// are hidden (Live Assist, captions, translation); the recording controls, the after-call card and the dictation box
// always show. On a Mac the hiding is best effort (ScreenCaptureKit, macOS 15 and later, can ignore it).
import { beforeEach, describe, expect, it, vi } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { act, render, screen } from "@testing-library/react";
import { flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makePrivacyStatus, makeSettings } from "../../test/fixtures";
import { setting } from "../../lib/settingsRegistry";
import { BADGE_HINT } from "../../lib/privacy";
import { HONEST_LINE, SHARE_PRIVACY_INTRO, SHARE_PRIVACY_SHOWN, SharePrivacyPage } from "./PrivacySettings";
import { SharePrivacyPageV2 } from "../../v2/settings/pages/SharePrivacyV2";
import { CAPTURE_HELP } from "./HelpDialog";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

beforeEach(() => resetTauriMock());

describe("the capture policy's words (FIX-01)", () => {
  it("says the live words are hidden and how to show one, and which windows always show", () => {
    expect(SHARE_PRIVACY_INTRO).toBe("Live words (Live Assist, captions, translation) are hidden from screen shares. To show one, use its own window's menu.");
    expect(SHARE_PRIVACY_SHOWN).toBe("The recording controls, the after-call card and the dictation box always show in screen shares and screenshots, like the main window.");
  });

  it("is honest per system: best effort on a Mac, Windows 10 version 2004 or later on Windows", () => {
    expect(HONEST_LINE.macos).toBe("Hidden from screenshots and most screen shares. If you share your entire screen in some apps, it may still show. Share a single window to be safe.");
    expect(HONEST_LINE.windows).toBe("Hidden from screen shares and recordings (Windows 10 version 2004 or later).");
    for (const line of Object.values(HONEST_LINE)) expect(line).not.toMatch(/fully hidden|invisible|always hidden/i);
  });

  it.each([["macos"], ["windows"]] as const)("%s: both designs say it, with that system's line and never the other's", async (platform) => {
    const other = platform === "macos" ? "windows" : "macos";
    for (const make of [
      () => <SharePrivacyPage settings={makeSettings()} patch={async () => true} onCheck={() => undefined} />,
      () => <div className="el-root"><SharePrivacyPageV2 settings={makeSettings()} patch={async () => true} onCheck={() => undefined} /></div>,
    ]) {
      respond("privacy_status", makePrivacyStatus({ platform }));
      const { unmount } = render(make());
      await act(flushAsync);
      const text = document.body.textContent ?? "";
      expect(text).toContain(SHARE_PRIVACY_INTRO);
      expect(text).toContain(HONEST_LINE[platform]);
      expect(text).not.toContain(HONEST_LINE[other]);
      expect(text).toContain(SHARE_PRIVACY_SHOWN);
      // The one switch the setting still has is Live Translate's.
      expect(screen.getByLabelText("Live Translate")).toBeInTheDocument();
      expect(screen.queryByLabelText("Other floating windows")).toBeNull();
      unmount();
    }
  });

  it("names the row for what it hides now, and finds it under the old words too", () => {
    const row = setting("share_privacy.hiding");
    expect(row.title).toBe("Live Translate");
    expect(row.aliases).toEqual(expect.arrayContaining(["Other floating windows", "Hide live words"]));
    expect(row.keywords).not.toEqual(expect.arrayContaining(["recording controls"]));
    expect(setting("share_privacy.demo").title).toBe("Show Live Translate for 10 min");
  });

  it("the badge on the recording controls and the dictation box speaks of the live words, never of that window", () => {
    expect(BADGE_HINT.private).toBe("Private: live words are hidden from screen shares. Check screen-share privacy");
    expect(BADGE_HINT.visible).toBe("Live words may show in screen shares. Check screen-share privacy");
    expect(CAPTURE_HELP).toBe("EchoLedger's windows show in your screenshots; only the live words (Live Assist, captions, translation) are hidden from them. For support, send Copy diagnostics (Settings › Screen-share privacy).");
  });
});

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
