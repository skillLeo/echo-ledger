import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { LiveTranslateCard } from "../main/LiveTranslateCard";
import TranslateOverlayApp from "./TranslateOverlayApp";
import { TRANSLATE_FEED, type TranslateFeed, tailText } from "../../lib/translate";
import { callsOf, emitEvent, listenerCount, resetTauriMock, respond } from "../../test/tauri";

const subscribed = () => waitFor(() => expect(listenerCount(TRANSLATE_FEED)).toBeGreaterThan(0));

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const run = <T,>(fn: () => Promise<T>) => fn();

function feed(over: Partial<TranslateFeed> = {}): TranslateFeed {
  return { mode: "game", app: "Valorant", text: "", partial: "", original: "", status: "live", notice: null, locked: true, hidden: false, ...over };
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

  it("says where it works, and offers Move and Stop while it runs", async () => {
    render(<LiveTranslateCard run={run} />);
    expect(screen.getByText(/exclusive full screen hides every overlay/)).toBeInTheDocument();
    expect(screen.getByText(/A browser is heard with all its tabs/)).toBeInTheDocument();

    await subscribed();
    await act(async () => emitEvent(TRANSLATE_FEED, feed()));
    fireEvent.click(await screen.findByRole("button", { name: "Move overlay" }));
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

    await act(async () => emitEvent(TRANSLATE_FEED, feed({ hidden: true })));
    expect(container).toBeEmptyDOMElement();
  });

  it("keeps the newest words when the text is long", () => {
    expect(tailText("short", 10)).toBe("short");
    expect(tailText("one two three four five six", 12)).toBe("five six");
    expect(tailText("alpha beta gamma delta", 11).endsWith("delta")).toBe(true);
  });
});
