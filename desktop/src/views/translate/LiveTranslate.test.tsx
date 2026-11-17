import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { LiveTranslateCard } from "../main/LiveTranslateCard";
import TranslateOverlayApp from "./TranslateOverlayApp";
import { RUNNING_STATUSES, TRANSLATE_FEED, type TranslateFeed, appsPrompt, endedWords, tailText, translatePhase } from "../../lib/translate";
import translateRs from "../../../src-tauri/src/translate/mod.rs?raw";
import { callsOf, emitEvent, fail, listenerCount, resetTauriMock, respond } from "../../test/tauri";

const subscribed = () => waitFor(() => expect(listenerCount(TRANSLATE_FEED)).toBeGreaterThan(0));

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const run = <T,>(fn: () => Promise<T>) => fn();

function feed(over: Partial<TranslateFeed> = {}): TranslateFeed {
  return { mode: "game", app: "Valorant", text: "", partial: "", original: "", status: "live", notice: null, locked: true, hidden: false, hide_keys: "Ctrl+Shift+H", lock_keys: "Ctrl+Shift+T", ...over };
}

beforeEach(() => {
  resetTauriMock();
  respond("translate_apps", [{ pid: 4242, name: "Valorant" }, { pid: 77, name: "Chrome" }]);
  respond("translate_status", null);
  respond("translate_start", []);
  respond("translate_stop", undefined);
  respond("translate_toggle_lock", undefined);
});

afterEach(() => vi.clearAllMocks());

describe("Live Translate card", () => {
  it("starts the chosen app in the chosen mode, language and speed", async () => {
    render(<LiveTranslateCard run={run} />);
    await screen.findByRole("option", { name: "Valorant" });
    expect(screen.getByRole("button", { name: "Start" })).toBeDisabled();

    fireEvent.change(screen.getByLabelText("App to translate"), { target: { value: "4242" } });
    fireEvent.change(screen.getByLabelText("Translate to"), { target: { value: "ur" } });
    fireEvent.click(screen.getByRole("button", { name: "Start" }));

    await waitFor(() => expect(callsOf("translate_start")).toHaveLength(1));
    expect(callsOf("translate_start")[0]).toEqual({ request: { pid: 4242, name: "Valorant", mode: "game", target: "ur", hints: ["ar", "en"], speed: "fastest" } });
  });

  it("a list that could not be read says so, never that nothing is playing", async () => {
    fail("translate_apps", "Core Audio did not answer");
    render(<LiveTranslateCard run={run} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("The apps playing sound could not be listed: Core Audio did not answer. Press Refresh to try again.");
    expect(screen.getByLabelText("App to translate")).toHaveTextContent("The apps playing sound could not be listed");
    expect(appsPrompt(0, false)).toBe("Nothing is playing sound");
    expect(appsPrompt(2, false)).toBe("Choose an app playing sound");
    expect(appsPrompt(0, true)).toBe("The apps playing sound could not be listed");
  });

  it("says where it works, and offers Move and Stop while it runs", async () => {
    render(<LiveTranslateCard run={run} />);
    expect(screen.getByText(/borderless or windowed games, not exclusive full screen/)).toBeInTheDocument();
    expect(screen.getByText(/A browser is heard with all its tabs/)).toBeInTheDocument();

    await subscribed();
    await act(async () => emitEvent(TRANSLATE_FEED, feed()));
    fireEvent.click(await screen.findByRole("button", { name: "Move Live Translate" }));
    expect(screen.getByText(/Ctrl\+Shift\+T lets you move Live Translate/)).toBeInTheDocument();
    // docs/DESIGN_SYSTEM.md "Screen-share privacy names": the box is Live Translate, never an overlay.
    expect(document.body.textContent ?? "").not.toMatch(/overlay/i);
    fireEvent.click(screen.getByRole("button", { name: "Stop" }));

    await waitFor(() => expect(callsOf("translate_stop")).toHaveLength(1));
    expect(callsOf("translate_toggle_lock")).toHaveLength(1);
  });

  it("names hotkeys another app already holds", async () => {
    respond("translate_start", ["Ctrl+Shift+T"]);
    render(<LiveTranslateCard run={run} />);
    await screen.findByRole("option", { name: "Chrome" });
    fireEvent.change(screen.getByLabelText("App to translate"), { target: { value: "77" } });
    fireEvent.click(screen.getByRole("button", { name: "Start" }));

    expect(await screen.findByText("Another app already uses Ctrl+Shift+T: use the buttons here instead.")).toBeInTheDocument();
  });
});

describe("Live Translate's status: running, ended, stopped", () => {
  it("sorts the feed's statuses, and a status this app doesn't know never counts as running", () => {
    for (const status of ["listening", "live", "quiet", "reconnecting"]) expect(translatePhase(feed({ status }))).toBe("running");
    expect(translatePhase(feed({ status: "ended" }))).toBe("ended");
    expect(translatePhase(feed({ status: "paused_by_core_9" }))).toBe("ended");
    expect(translatePhase(feed({ status: "stopped" }))).toBe("stopped");
    expect(translatePhase(null)).toBe("stopped");
    expect(translatePhase({ running: false } as unknown as TranslateFeed)).toBe("stopped");
    expect(endedWords(feed({ status: "ended", notice: "Valorant closed." }))).toBe("Valorant closed.");
    expect(endedWords(feed({ status: "ended" }))).toBe("Live Translate ended. Valorant closed or stopped playing sound.");
    expect(endedWords(feed({ status: "ended", app: "" }))).toBe("Live Translate ended. The app closed or stopped playing sound.");
  });

  it("knows every status the core sets: running ones as running, stopped and ended as not", () => {
    const set = [...translateRs.matchAll(/status(?: =|:) "([a-z_]+)"\.into\(\)/g)].map((m) => m[1]);
    expect(set.length).toBeGreaterThan(3);
    for (const status of set) expect(RUNNING_STATUSES.has(status) || status === "stopped" || status === "ended", status).toBe(true);
  });

  it("card, running: what it does and Stop", async () => {
    render(<LiveTranslateCard run={run} />);
    await subscribed();
    await act(async () => emitEvent(TRANSLATE_FEED, feed({ status: "live" })));
    expect(screen.getByText("Valorant · translating")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Stop" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Close" })).toBeNull();
    expect(screen.getByLabelText("App to translate")).toBeDisabled();
  });

  it("card, ended: the reason in plain words, Close instead of Stop, nothing that looks running; Close brings the form back", async () => {
    render(<LiveTranslateCard run={run} />);
    await subscribed();
    await act(async () => emitEvent(TRANSLATE_FEED, feed({ status: "ended", notice: "The game closed, so Live Translate stopped." })));
    expect(screen.getByRole("status")).toHaveTextContent("The game closed, so Live Translate stopped.");
    expect(screen.getByTestId("translate-ended")).toHaveTextContent("Valorant · ended");
    expect(screen.queryByRole("button", { name: "Stop" })).toBeNull();
    expect(screen.queryByRole("button", { name: /Move Live Translate|Lock Live Translate/ })).toBeNull();
    expect(screen.queryByText(/translating/)).toBeNull();
    expect(screen.queryByText(/Ctrl\+Shift\+T lets you move/)).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Close" }));
    await waitFor(() => expect(callsOf("translate_stop")).toHaveLength(1));
    expect(screen.getByRole("button", { name: "Start" })).toBeInTheDocument();
    expect(screen.getByLabelText("App to translate")).not.toBeDisabled();
  });

  it("card, ended before it opened (translate_status), and a status it doesn't know: never running", async () => {
    respond("translate_status", feed({ status: "ended" }));
    render(<LiveTranslateCard run={run} />);
    expect(await screen.findByRole("button", { name: "Close" })).toBeInTheDocument();
    expect(screen.getByRole("status")).toHaveTextContent("Live Translate ended. Valorant closed or stopped playing sound.");
    await subscribed();
    await act(async () => emitEvent(TRANSLATE_FEED, feed({ status: "something_new" })));
    expect(screen.queryByRole("button", { name: "Stop" })).toBeNull();
    expect(screen.getByRole("button", { name: "Close" })).toBeInTheDocument();
  });

  it("card, opened on a session that already ended (status() keeps its feed): the reason, Close, and Close stops it", async () => {
    respond("translate_status", feed({ status: "ended", notice: "Valorant stopped playing sound, so Live Translate ended." }));
    render(<LiveTranslateCard run={run} />);
    expect(await screen.findByRole("button", { name: "Close" })).toBeInTheDocument();
    expect(screen.getByRole("status")).toHaveTextContent("Valorant stopped playing sound, so Live Translate ended.");
    expect(screen.getByTestId("translate-ended")).toHaveTextContent("Valorant · ended");
    expect(screen.queryByRole("button", { name: "Stop" })).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Close" }));
    await waitFor(() => expect(callsOf("translate_stop")).toHaveLength(1));
    expect(screen.getByRole("button", { name: "Start" })).toBeInTheDocument();
  });

  it("card, stopped: the form and Start, no status line", async () => {
    render(<LiveTranslateCard run={run} />);
    await subscribed();
    await act(async () => emitEvent(TRANSLATE_FEED, feed({ status: "live" })));
    await act(async () => emitEvent(TRANSLATE_FEED, feed({ status: "stopped" })));
    expect(screen.getByRole("button", { name: "Start" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Stop" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Close" })).toBeNull();
    expect(screen.queryByText(/Valorant ·/)).toBeNull();
  });

  it("the overlay shows a running session only: an ended one, a status it doesn't know and a stopped one leave nothing", async () => {
    const { container } = render(<TranslateOverlayApp />);
    await subscribed();
    await act(async () => emitEvent(TRANSLATE_FEED, feed({ status: "live", text: "Enemy at the red house." })));
    expect(screen.getByText("Enemy at the red house.")).toBeInTheDocument();
    for (const status of ["ended", "something_new", "stopped"]) {
      await act(async () => emitEvent(TRANSLATE_FEED, feed({ status, text: "Enemy at the red house." })));
      expect(container, status).toBeEmptyDOMElement();
    }
  });
});

describe("Live Translate overlay", () => {
  it("shows the settled text, the changing words apart, and the original line", async () => {
    render(<TranslateOverlayApp />);
    await subscribed();
    await act(async () => emitEvent(TRANSLATE_FEED, feed({ text: "Enemy at the red house.", partial: "Go left", original: "العدو عند البيت الأحمر" })));

    expect(screen.getByText("Enemy at the red house.")).toBeInTheDocument();
    expect(screen.getByText("Go left")).toHaveClass("translate-partial");
    expect(screen.getByText("العدو عند البيت الأحمر")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Done/ })).toBeNull();
  });

  it("shows Done while it can be moved, a notice instead of text, and nothing when hidden", async () => {
    const { container } = render(<TranslateOverlayApp />);
    await subscribed();
    await act(async () => emitEvent(TRANSLATE_FEED, feed({ locked: false, notice: "No connection — retrying" })));
    expect(screen.getByText("No connection — retrying")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Done (Ctrl+Shift+T)" }));
    expect(callsOf("translate_toggle_lock")).toHaveLength(1);

    await act(async () => emitEvent(TRANSLATE_FEED, feed({ hidden: true, hide_keys: "Ctrl+Shift+H" })));
    // Hidden, it still listens: never nothing on screen.
    expect(container).not.toBeEmptyDOMElement();
    expect(screen.getByRole("status")).toHaveTextContent("Live Translate is listening · Ctrl+Shift+H to show");
    expect(screen.queryByText("No connection — retrying")).toBeNull();
    await act(async () => emitEvent(TRANSLATE_FEED, feed({ hidden: true, hide_keys: "⌘⇧H" })));
    expect(screen.getByRole("status")).toHaveTextContent("Live Translate is listening · ⌘⇧H to show");
    await act(async () => emitEvent(TRANSLATE_FEED, feed({ hidden: false, locked: false, lock_keys: "⌘⇧T" })));
    expect(screen.getByRole("button", { name: "Done (⌘⇧T)" })).toBeInTheDocument();
    // Ended on its own, even while hidden: never the listening pill; the overlay leaves the reason to the panel.
    await act(async () => emitEvent(TRANSLATE_FEED, feed({ status: "ended", hidden: true, notice: "This app stopped playing sound. Play something in it, then start Live Translate again." })));
    expect(screen.queryByText(/is listening/)).toBeNull();
    expect(container).toBeEmptyDOMElement();
    await act(async () => emitEvent(TRANSLATE_FEED, feed({ status: "stopped" })));
    expect(container).toBeEmptyDOMElement();
  });

  it("keeps the newest words when the text is long", () => {
    expect(tailText("short", 10)).toBe("short");
    expect(tailText("one two three four five six", 12)).toBe("five six");
    expect(tailText("alpha beta gamma delta", 11).endsWith("delta")).toBe(true);
  });
});
