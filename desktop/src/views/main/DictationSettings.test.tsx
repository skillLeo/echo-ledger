import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { DictationCard, withEntry } from "./DictationSettings";
import { callsOf, fail, resetTauriMock, respond } from "../../test/tauri";
import { makeSettings } from "../../test/fixtures";
import type { Settings, SettingsPatch } from "../../lib/ipc";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const writeText = vi.fn((_text: string) => Promise.resolve());

beforeEach(() => {
  resetTauriMock();
  writeText.mockClear();
  Object.defineProperty(navigator, "clipboard", { configurable: true, value: { writeText } });
  respond("dictation_history", []);
});

function renderCard(overrides: Partial<Settings> = {}) {
  const patch = vi.fn((_p: SettingsPatch) => Promise.resolve(true));
  render(<DictationCard settings={makeSettings(overrides)} patch={patch} />);
  return patch;
}

describe("dictation settings (B14)", () => {
  it("adds entries once, ignoring capitals and blanks", () => {
    const list = [{ from: "asap", to: "as soon as possible" }];
    expect(withEntry(list, { from: "ASAP ", to: "x" }, (e) => e.from)).toBeNull();
    expect(withEntry(list, { from: "  ", to: "x" }, (e) => e.from)).toBeNull();
    expect(withEntry(list, { from: "fyi", to: "for your information" }, (e) => e.from)).toHaveLength(2);
  });

  it("switches between press-to-start and hold-to-talk, clean-up, sounds and output", () => {
    const patch = renderCard();
    fireEvent.change(screen.getByRole("combobox", { name: "How the shortcut works" }), { target: { value: "push_to_talk" } });
    fireEvent.change(screen.getByRole("combobox", { name: "Dictation output" }), { target: { value: "clipboard" } });
    fireEvent.click(screen.getByRole("switch", { name: "Clean up the text" }));
    fireEvent.click(screen.getByRole("switch", { name: "Start and stop sounds" }));
    expect(patch.mock.calls.map((c) => c[0])).toEqual([
      { dictationMode: "push_to_talk" },
      { dictationOutput: "clipboard" },
      { dictationCleanup: false },
      { dictationSounds: false },
    ]);
  });

  it("adds and removes replacements", async () => {
    const patch = renderCard({ dictationReplacements: [{ from: "echo ledger", to: "EchoLedger" }] });
    expect(within(screen.getByRole("list", { name: "Replacements" })).getByText("echo ledger → EchoLedger")).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("When I say"), { target: { value: " asap " } });
    fireEvent.change(screen.getByLabelText("Write"), { target: { value: "as soon as possible" } });
    fireEvent.click(screen.getByRole("button", { name: "Add replacement" }));
    await waitFor(() => expect(screen.getByLabelText("When I say")).toHaveValue(""));
    expect(patch).toHaveBeenCalledWith({ dictationReplacements: [{ from: "echo ledger", to: "EchoLedger" }, { from: "asap", to: "as soon as possible" }] });
    fireEvent.click(screen.getByRole("button", { name: "Remove echo ledger" }));
    expect(patch).toHaveBeenLastCalledWith({ dictationReplacements: [] });
  });

  it("adds a snippet only with both a phrase and text", async () => {
    const patch = renderCard();
    const add = screen.getByRole("button", { name: "Add snippet" });
    fireEvent.change(screen.getByLabelText("Phrase"), { target: { value: "insert signature" } });
    expect(add).toBeDisabled();
    fireEvent.change(screen.getByLabelText("Text to insert"), { target: { value: "Best regards,\nSam" } });
    fireEvent.click(add);
    await waitFor(() => expect(patch).toHaveBeenCalledWith({ dictationSnippets: [{ trigger: "insert signature", text: "Best regards,\nSam" }] }));
  });

  it("lists recent dictations, copies one and clears them", async () => {
    respond("dictation_history", [{ at: "2026-10-25T10:00:00Z", text: "Send the invoice." }, { at: "2026-10-25T09:00:00Z", text: "Call Farah." }]);
    renderCard();
    const list = await screen.findByRole("list", { name: "Recent dictations" });
    expect(within(list).getAllByRole("listitem")).toHaveLength(2);
    fireEvent.click(within(list).getAllByRole("button", { name: "Copy" })[1]);
    await waitFor(() => expect(writeText).toHaveBeenCalledWith("Call Farah."));
    expect(await screen.findByRole("button", { name: "Copied" })).toBeInTheDocument();
    respond("dictation_history_clear", undefined);
    fireEvent.click(screen.getByRole("button", { name: "Clear history" }));
    await waitFor(() => expect(screen.getByText("Nothing dictated yet.")).toBeInTheDocument());
    expect(callsOf("dictation_history_clear")).toHaveLength(1);
  });

  it("shows why the history could not be read", async () => {
    fail("dictation_history", "Could not read the history");
    renderCard();
    expect(await screen.findByText("Could not read the history")).toBeInTheDocument();
  });
});
