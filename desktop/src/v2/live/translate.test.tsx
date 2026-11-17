import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { TRANSLATE_FEED, type TranslateFeed } from "../../lib/translate";
import { callsOf, emitEvent, flushAsync, listenerCount, resetTauriMock, respond } from "../../test/tauri";
import { collectControls, stale, unmapped, type ControlRole } from "../../test/parity";
import { makeStatus } from "../../test/fixtures";
import { resetEntitlementsForTests } from "../../lib/entitlements";
import { sampleEntitlements } from "../../test/fixtures";
import { LiveTranslateCard } from "../../views/main/LiveTranslateCard";
import { LiveTranslateV2, translateAvailability } from "./LiveTranslateV2";
import { LiveV2 } from "./LiveV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const run = vi.fn(<T,>(fn: () => Promise<T>) => fn());
const feed = (over: Partial<TranslateFeed> = {}): TranslateFeed =>
  ({ mode: "game", app: "Valorant", text: "", partial: "", original: "", status: "live", notice: null, locked: true, hidden: false, ...over });
const subscribed = () => waitFor(() => expect(listenerCount(TRANSLATE_FEED)).toBeGreaterThan(0));

beforeEach(() => {
  resetTauriMock();
  resetEntitlementsForTests(null);
  respond("translate_apps", [{ pid: 4242, name: "Valorant" }, { pid: 77, name: "Chrome" }]);
  respond("translate_status", null);
  respond("translate_start", []);
  respond("translate_stop", undefined);
  respond("translate_toggle_lock", undefined);
});
afterEach(() => vi.clearAllMocks());

/** Picks an option of a v2 select by its label. */
const choose = (label: string, option: string) => {
  fireEvent.click(screen.getByRole("combobox", { name: new RegExp(`^${label}`) }));
  fireEvent.click(screen.getByRole("option", { name: option }));
};

describe("Live Translate v2: running, ended, stopped", () => {
  it("running: the app and what it does as a live status, Move and Stop", async () => {
    render(<LiveTranslateV2 run={run} />);
    await subscribed();
    await act(async () => emitEvent(TRANSLATE_FEED, feed({ status: "reconnecting" })));
    expect(screen.getByText("Valorant · Reconnecting")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Stop" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Close" })).toBeNull();
  });

  it("ended: the reason in plain words, Close instead of Stop, no live status; Close brings the form back", async () => {
    render(<LiveTranslateV2 run={run} />);
    await subscribed();
    await act(async () => emitEvent(TRANSLATE_FEED, feed({ status: "live" })));
    await act(async () => emitEvent(TRANSLATE_FEED, feed({ status: "ended", notice: "Something went wrong with the translation. Start it again." })));
    expect(screen.getByTestId("translate-ended")).toHaveTextContent("Something went wrong with the translation. Start it again.");
    expect(screen.getByText("Valorant · Ended")).toBeInTheDocument();
    expect(screen.queryByText(/Translating/)).toBeNull();
    expect(document.querySelector(".el-status--recording")).toBeNull();
    expect(screen.queryByRole("button", { name: "Stop" })).toBeNull();
    expect(screen.queryByRole("button", { name: /Move Live Translate|Lock Live Translate/ })).toBeNull();
    expect(screen.queryAllByRole("alert")).toHaveLength(0);
    fireEvent.click(screen.getByRole("button", { name: "Close" }));
    await act(flushAsync);
    expect(callsOf("translate_stop")).toHaveLength(1);
    expect(screen.getByRole("button", { name: "Start" })).toBeInTheDocument();
    expect(screen.queryByTestId("translate-ended")).toBeNull();
  });

  it("opened on a session that already ended (status() keeps its feed): the reason, Close, and Close stops it", async () => {
    respond("translate_status", feed({ status: "ended", notice: "Valorant stopped playing sound, so Live Translate ended." }));
    render(<LiveTranslateV2 run={run} />);
    await act(flushAsync);
    expect(screen.getByTestId("translate-ended")).toHaveTextContent("Valorant stopped playing sound, so Live Translate ended.");
    expect(screen.getByText("Valorant · Ended")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Stop" })).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Close" }));
    await act(flushAsync);
    expect(callsOf("translate_stop")).toHaveLength(1);
    expect(screen.getByRole("button", { name: "Start" })).toBeInTheDocument();
  });

  it("a status it doesn't know is never shown as running", async () => {
    respond("translate_status", feed({ status: "warming_up_v9" }));
    render(<LiveTranslateV2 run={run} />);
    await act(flushAsync);
    expect(screen.queryByRole("button", { name: "Stop" })).toBeNull();
    expect(document.querySelector(".el-status--recording")).toBeNull();
    expect(screen.getByRole("button", { name: "Close" })).toBeInTheDocument();
  });

  it("stopped: the form and Start, and no status", async () => {
    render(<LiveTranslateV2 run={run} />);
    await subscribed();
    await act(async () => emitEvent(TRANSLATE_FEED, feed({ status: "live" })));
    await act(async () => emitEvent(TRANSLATE_FEED, feed({ status: "stopped" })));
    expect(screen.getByRole("button", { name: "Start" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Stop" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Close" })).toBeNull();
    expect(screen.queryByText(/Valorant ·/)).toBeNull();
  });
});

describe("Live Translate v2", () => {
  it("starts the chosen app in the chosen mode, language and speed", async () => {
    render(<LiveTranslateV2 run={run} />);
    await act(flushAsync);
    expect(screen.getByRole("button", { name: "Start" })).toBeDisabled();
    choose("App to translate", "Valorant");
    choose("Mode", "Watch (subtitles)");
    choose("Translate to", "Urdu");
    choose("Speed", "Accurate");
    fireEvent.click(screen.getByRole("button", { name: "Start" }));
    await act(flushAsync);
    expect(callsOf("translate_start")).toEqual([{ request: { pid: 4242, name: "Valorant", mode: "watch", target: "ur", hints: [], speed: "accurate" } }]);
  });

  it("says where it works; while it runs: its app and state, Move or Lock, Stop, and the choices locked", async () => {
    render(<LiveTranslateV2 run={run} />);
    expect(screen.getByText(/borderless or windowed games, not exclusive full screen/)).toBeInTheDocument();
    await subscribed();
    await act(async () => emitEvent(TRANSLATE_FEED, feed()));
    expect(screen.getByRole("status")).toHaveTextContent("Valorant · Translating");
    expect(screen.getByRole("combobox", { name: /^App to translate/ })).toBeDisabled();
    expect(screen.getByText(/Ctrl\+Shift\+T lets you move Live Translate/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Move Live Translate" }));
    fireEvent.click(screen.getByRole("button", { name: "Stop" }));
    await act(flushAsync);
    expect(callsOf("translate_toggle_lock")).toHaveLength(1);
    expect(callsOf("translate_stop")).toHaveLength(1);
    await act(async () => emitEvent(TRANSLATE_FEED, feed({ locked: false, notice: "Valorant stopped playing sound." })));
    expect(screen.getByRole("button", { name: "Lock Live Translate" })).toBeInTheDocument();
    expect(screen.getByRole("alert")).toHaveTextContent("Valorant stopped playing sound.");
    // docs/DESIGN_SYSTEM.md "Screen-share privacy names": the box is Live Translate, never an overlay.
    expect(document.body.textContent ?? "").not.toMatch(/overlay/i);
  });

  it("names hotkeys another app already holds", async () => {
    respond("translate_start", ["Ctrl+Shift+T"]);
    render(<LiveTranslateV2 run={run} />);
    await act(flushAsync);
    choose("App to translate", "Chrome");
    fireEvent.click(screen.getByRole("button", { name: "Start" }));
    await act(flushAsync);
    expect(screen.getByRole("alert")).toHaveTextContent("Another app already uses Ctrl+Shift+T: use the buttons here instead.");
  });

  it("nothing playing sound says so; Refresh asks again", async () => {
    respond("translate_apps", []);
    render(<LiveTranslateV2 run={run} />);
    await act(flushAsync);
    expect(screen.getByRole("combobox", { name: /^App to translate/ })).toHaveTextContent("Nothing is playing sound");
    fireEvent.click(screen.getByRole("button", { name: "Refresh" }));
    await act(flushAsync);
    expect(callsOf("translate_apps")).toHaveLength(2);
  });
});

describe("Live Translate in the Live tab follows the plan", () => {
  const props = { onCaptions: vi.fn(), onSettings: vi.fn(), run, assistOn: false, projects: [] };

  it("a plan without it shows it locked with the plan that has it, and never starts it", async () => {
    resetEntitlementsForTests(sampleEntitlements("free"));
    render(<LiveV2 status={makeStatus()} {...props} />);
    await act(flushAsync);
    const row = within(screen.getByRole("list", { name: "During a call" })).getByRole("button", { name: "Live Translate" });
    expect(row).toHaveAccessibleDescription(/Not in your plan/);
    fireEvent.click(row);
    const panel = screen.getByRole("region", { name: "Live Translate" });
    expect(within(panel).getByRole("status")).toHaveTextContent("Not in your plan");
    expect(within(panel).getByRole("button", { name: "Start" })).toBeDisabled();
    expect(within(panel).queryByRole("combobox")).toBeNull();
    expect(callsOf("translate_apps")).toHaveLength(0);
  });

  it("on a Mac it says it isn't there yet, instead of an empty list of apps", async () => {
    render(<LiveV2 status={makeStatus({ platform: "macos" })} {...props} />);
    await act(flushAsync);
    const row = within(screen.getByRole("list", { name: "During a call" })).getByRole("button", { name: "Live Translate" });
    expect(row).toHaveAccessibleDescription(/Live Translate isn't on the Mac yet/);
    fireEvent.click(row);
    expect(within(screen.getByRole("region", { name: "Live Translate" })).getByRole("status")).toHaveTextContent("Live Translate isn't on the Mac yet");
    expect(callsOf("translate_apps")).toHaveLength(0);
  });

  it("the core's answer decides when it gives one", async () => {
    respond("live_translate_status", { available: false, reason: "platform" });
    render(<LiveV2 status={makeStatus({ platform: "windows" })} {...props} />);
    await act(flushAsync);
    expect(within(screen.getByRole("list", { name: "During a call" })).getByRole("button", { name: "Live Translate" })).toHaveAccessibleDescription(/isn't on the Mac yet/);
    expect(translateAvailability(null, "windows", true)).toEqual({ available: true, reason: null });
    expect(translateAvailability(null, "windows", false)).toEqual({ available: false, reason: "plan" });
  });

  it("a server without Live Translate doesn't list it", async () => {
    render(<LiveV2 status={makeStatus({ plan: { ...makeStatus().plan, liveTranslate: false } })} {...props} />);
    await act(flushAsync);
    expect(within(screen.getByRole("list", { name: "During a call" })).queryByRole("button", { name: "Live Translate" })).toBeNull();
  });
});

/** Parity with today's panel (LiveTranslateCard): every control it shows, idle and running, is on the new one. */
describe("parity with today's Live Translate panel", () => {
  type State = "idle" | "running" | "ended";
  const WHERE: [today: string, role: ControlRole, name: string | RegExp, state: State][] = [
    ["combobox: App to translate", "combobox", /^App to translate/, "idle"],
    ["combobox: Mode", "combobox", /^Mode/, "idle"],
    ["combobox: Translate to", "combobox", /^Translate to/, "idle"],
    ["combobox: Speed", "combobox", /^Speed/, "idle"],
    ["button: Refresh", "button", "Refresh", "idle"],
    ["button: Start", "button", "Start", "idle"],
    ["button: Move Live Translate", "button", "Move Live Translate", "running"],
    ["button: Stop", "button", "Stop", "running"],
    ["button: Close", "button", "Close", "ended"],
  ];
  const go = async (state: State) => {
    await subscribed();
    if (state === "running") await act(async () => emitEvent(TRANSLATE_FEED, feed()));
    if (state === "ended") await act(async () => emitEvent(TRANSLATE_FEED, feed({ status: "ended" })));
    await act(flushAsync);
  };

  it("maps every control today's panel has, and nothing it doesn't", async () => {
    const seen = new Set<string>();
    const first = render(<LiveTranslateCard run={run} />);
    await go("idle");
    collectControls(seen);
    await go("running");
    collectControls(seen);
    await go("ended");
    collectControls(seen);
    first.unmount();
    const today = [...seen];
    expect(unmapped(today, WHERE.map(([k]) => k))).toEqual([]);
    expect(stale(today, WHERE.map(([k]) => k))).toEqual([]);
  });

  it.each(WHERE)("finds %s on the new panel", async (_, role, name, state) => {
    render(<LiveTranslateV2 run={run} />);
    await go(state);
    expect(screen.getAllByRole(role, { name }).length).toBeGreaterThan(0);
  });
});
