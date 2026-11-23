// The call choice (docs/v2/copy/call-choice.md) on the desktop: the words are the copy deck's, After each call maps
// onto the settings without losing anyone's choice, both designs' Settings say the same, and the hide-from-sharing
// help line says screenshots too.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { AFTER_EACH_CALL_OPTIONS, CALL_WORDS, afterEachCallOf, alwaysOffer, groupHeadline, groupedMixed, visibleRows, afterEachCallPatch, cardMeta, cardName, doneText, groupedAudio, progressStyle } from "./callChoice";
import { makeSettings, makePrivacyStatus } from "../test/fixtures";
import { resetTauriMock, respond } from "../test/tauri";
import { CallsPage } from "../views/main/CaptureSettings";
import { CallsPageV2 } from "../v2/settings/pages/CallsV2";
import { HIDES_SCREENSHOTS_TOO, SharePrivacyPage } from "../views/main/PrivacySettings";
import { SharePrivacyPageV2 } from "../v2/settings/pages/SharePrivacyV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);

const here = dirname(fileURLToPath(import.meta.url));

beforeEach(() => resetTauriMock());

describe("the words", () => {
  it("are the copy deck's English words, key for key", () => {
    const deck = readFileSync(join(here, "..", "..", "..", "docs", "v2", "copy", "call-choice.md"), "utf8");
    const english = new Map([...deck.matchAll(/^\| (\w+) \| ([^|]+) \|/gm)].map((m) => [m[1], m[2].trim()]));
    for (const [key, words] of Object.entries(CALL_WORDS)) expect(words, key).toBe(english.get(key));
    expect(cardMeta(44_000)).toBe(english.get("card_meta")!.replace("%1$s", "0:44"));
    expect(groupedAudio(3)).toBe(english.get("grouped_audio")!.replace("%1$d", "3"));
  });

  it("never say Keep or Skip on their own anywhere in the flow", () => {
    const words = [...Object.values(CALL_WORDS), ...AFTER_EACH_CALL_OPTIONS.flatMap((o) => [o.label, o.hint])];
    for (const w of words) expect(w).not.toMatch(/^(Keep|Skip|Don't keep)$/);
    const flow = ["../v2/controls/AfterCallCard.tsx", "../v2/controls/RecordingPill.tsx", "../views/review/ReviewApp.tsx"].map((f) => readFileSync(join(here, f), "utf8"));
    for (const source of flow) expect(source).not.toMatch(/>\s*(Keep|Skip|Discard|Don't keep)\s*</);
  });

  it("name the call and say what was done", () => {
    expect(cardName({ title: " Budget review ", callerName: "Omar" })).toBe("Budget review");
    expect(cardName({ title: null, callerName: "Muneeb Quraishi" })).toBe("Muneeb Quraishi");
    expect(cardName({ title: null, callerName: " " })).toBe("Call");
    expect([doneText({ kind: "transcribe" }), doneText({ kind: "audio" }), doneText({ kind: "delete" })]).toEqual(["Transcribing…", "Saved as audio", "Deleted"]);
    // "Always do this?" names what Yes sets, in the deck's words.
    expect(alwaysOffer("always_transcribe")).toBe("Always do this? Transcribe every call");
    expect(alwaysOffer("always_audio")).toBe("Always do this? Save audio only");
    // The 8 s line starts where the time is: full at 8 s, half at 4 s, empty when it is over.
    const now = Date.parse("2026-11-22T10:00:00Z");
    expect(progressStyle("2026-11-22T10:00:08Z", now)).toMatchObject({ animationDuration: "8s", "--from": "1" });
    expect(progressStyle("2026-11-22T10:00:04Z", now)).toMatchObject({ animationDuration: "4s", "--from": "0.5" });
    expect(progressStyle("2026-11-22T09:59:00Z", now)).toMatchObject({ animationDuration: "0s", "--from": "0" });
  });
});

describe("After each call", () => {
  it("each value reads from and saves to the settings: Don't record calls is auto-record off, and keeps the choice under it", () => {
    expect(afterEachCallOf({ autoMode: true, afterCall: "ask" })).toBe("ask");
    expect(afterEachCallOf({ autoMode: true, afterCall: "always_transcribe" })).toBe("always_transcribe");
    expect(afterEachCallOf({ autoMode: true, afterCall: "always_audio" })).toBe("always_audio");
    expect(afterEachCallOf({ autoMode: false, afterCall: "always_transcribe" })).toBe("off");
    expect(afterEachCallPatch("off")).toEqual({ autoMode: false });
    expect(afterEachCallPatch("ask")).toEqual({ autoMode: true, afterCall: "ask" });
    expect(afterEachCallPatch("always_transcribe")).toEqual({ autoMode: true, afterCall: "always_transcribe" });
    expect(afterEachCallPatch("always_audio")).toEqual({ autoMode: true, afterCall: "always_audio" });
    // Migration: a person who turned auto-record off before sees Don't record calls; turning it on brings back their choice.
    const before = makeSettings({ autoMode: false, afterCall: "always_audio" });
    expect(afterEachCallOf(before)).toBe("off");
    expect(afterEachCallOf({ ...before, autoMode: true })).toBe("always_audio");
  });

  it("both designs offer the same four choices with the same help lines, and When to ask under Ask me", () => {
    const patch = vi.fn(async () => true);
    for (const page of [
      <CallsPage key="classic" settings={makeSettings({ autoMode: true, afterCall: "ask", askWhen: "start" })} patch={patch} platform="windows" onSites={vi.fn()} consentRead onConsent={vi.fn()} />,
      <div key="v2" className="el-root"><CallsPageV2 settings={makeSettings({ autoMode: true, afterCall: "ask", askWhen: "start" })} patch={patch} platform="windows" onSites={vi.fn()} onConsent={vi.fn()} /></div>,
    ]) {
      const { unmount } = render(page);
      const group = screen.getByRole("group", { name: "After each call" });
      expect(within(group).getAllByRole("radio").map((r) => r.getAttribute("aria-labelledby") && document.getElementById(r.getAttribute("aria-labelledby")!)!.textContent))
        .toEqual(["Ask me", "Transcribe every call", "Save audio only", "Don't record calls"]);
      for (const o of AFTER_EACH_CALL_OPTIONS) expect(within(group).getByRole("radio", { name: o.label })).toHaveAccessibleDescription(o.hint);
      const when = screen.getByRole("group", { name: "When to ask" });
      expect(within(when).getByRole("radio", { name: "At the start of the call" })).toBeChecked();
      fireEvent.click(within(group).getByRole("radio", { name: "Don't record calls" }));
      expect(patch).toHaveBeenLastCalledWith({ autoMode: false });
      unmount();
    }
  });
});

describe("one setting for recording calls", () => {
  it("has no Auto-record calls switch beside After each call; under Don't record calls the apps are greyed out with the reason, in both designs", () => {
    for (const autoMode of [false, true]) {
      for (const page of [
        <CallsPage key="classic" settings={makeSettings({ autoMode, afterCall: "always_audio" })} patch={vi.fn(async () => true)} platform="windows" onSites={vi.fn()} consentRead onConsent={vi.fn()} />,
        <div key="v2" className="el-root"><CallsPageV2 settings={makeSettings({ autoMode, afterCall: "always_audio" })} patch={vi.fn(async () => true)} platform="windows" onSites={vi.fn()} onConsent={vi.fn()} /></div>,
      ]) {
        const { unmount } = render(page);
        expect(screen.queryByRole("switch", { name: "Auto-record calls" })).toBeNull();
        expect(screen.getByRole("radio", { name: autoMode ? "Save audio only" : "Don't record calls" })).toBeChecked();
        expect(screen.getByRole("switch", { name: "Auto-record Zoom" })).toHaveProperty("disabled", !autoMode);
        if (autoMode) expect(screen.queryByTestId("apps-off-reason")).toBeNull();
        else expect(screen.getByTestId("apps-off-reason")).toHaveTextContent("Calls are not recorded. You can still record one from the pill or Record.");
        unmount();
      }
    }
  });
});

describe("Hide EchoLedger from screen sharing", () => {
  it("says in plain words that it also hides EchoLedger from screenshots, in both designs", async () => {
    // Since FIX-01 the row is Live Translate's: the same one-setting rule, said of a hidden window.
    expect(HIDES_SCREENSHOTS_TOO).toBe("Also hidden from your own screenshots");
    respond("privacy_status", makePrivacyStatus());
    const patch = vi.fn(async () => true);
    const classic = render(<SharePrivacyPage settings={makeSettings()} patch={patch} onCheck={() => undefined} />);
    expect(await screen.findByText(HIDES_SCREENSHOTS_TOO)).toBeInTheDocument();
    classic.unmount();
    render(<div className="el-root"><SharePrivacyPageV2 settings={makeSettings()} patch={patch} onCheck={() => undefined} /></div>);
    expect(await screen.findByText(HIDES_SCREENSHOTS_TOO)).toBeInTheDocument();
    expect(patch).not.toHaveBeenCalled();
  });
});

describe("the grouped card's rows", () => {
  it("show every Delete and fold only kept rows, as the core sizes the window for (after_call::visible_rows)", () => {
    const row = (id: string, kind: "delete" | "audio" | "transcribe") => ({ id, kind });
    const five = [row("d1", "delete"), row("a1", "audio"), row("a2", "audio"), row("t1", "transcribe"), row("a3", "audio")];
    expect(visibleRows(five)).toEqual({ shown: [five[0], five[3], five[4]], more: 2 });
    const deletes = [0, 1, 2, 3].map((i) => row(`d${i}`, "delete"));
    expect(visibleRows([...deletes, row("a", "audio")])).toEqual({ shown: deletes, more: 1 });
    expect(visibleRows(five.slice(1, 3)).more).toBe(0);
    expect(groupHeadline([row("a", "audio"), row("b", "audio")])).toBe("2 calls saved as audio");
    expect(groupHeadline([row("a", "audio"), row("d", "delete")])).toBe("2 calls");
    const deck = readFileSync(join(here, "..", "..", "..", "docs", "v2", "copy", "call-choice.md"), "utf8");
    expect(groupedMixed(4)).toBe(/^\| grouped_mixed \| ([^|]+) \|/m.exec(deck)![1].trim().replace("%1$d", "4"));
  });
});
