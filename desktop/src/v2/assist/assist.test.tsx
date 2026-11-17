import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { callsOf, emitEvent, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import type { AssistMessage } from "./adapter";
import { FIXTURE_SESSION, fixtureStream } from "./fixture";
import { EMPTY_CARD, cardReducer, modeNote, parseSuggestion, shown, type CardState } from "./card";
import { AssistCard, CONSENT_LINE, INTERVIEW_NOTE } from "./AssistCard";
import AssistApp from "./AssistApp";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const play = (messages: readonly AssistMessage[], from: CardState = EMPTY_CARD) => messages.reduce(cardReducer, from);
const READY: AssistMessage = { type: "ready", session_id: "as-1", mode: "balanced", requested_mode: "balanced", allowed_modes: ["balanced", "fast"], resumed: false, practice: false, meeting_type: "existing_client" };
const start = (id: string, over: Partial<Extract<AssistMessage, { type: "suggestion_start" }>> = {}): AssistMessage =>
  ({ type: "suggestion_start", suggestion_id: id, turn_id: "t1", kind: "answer", speculative: false, replaces: null, mode: "balanced", model: "haiku", draft: false, ...over });
const token = (id: string, text: string): AssistMessage => ({ type: "token", suggestion_id: id, text });

describe("the card's rules (1.9.0-f)", () => {
  it("streams token by token onto the card", () => {
    const s = play([READY, start("s1"), token("s1", "Offer the "), token("s1", "40% advance.")]);
    expect(shown(s)).toMatchObject({ id: "s1", text: "Offer the 40% advance.", status: "streaming" });
    expect(shown(play([{ type: "suggestion_end", suggestion_id: "s1", status: "done" }], s))!.status).toBe("done");
  });

  it("a refusal after words were shown: the text goes at once, before the fallback's first token", () => {
    const refused = play([READY, start("s1"), token("s1", "I can't help with"), { type: "suggestion_end", suggestion_id: "s1", status: "superseded" }]);
    expect(shown(refused)).toMatchObject({ id: "s1", text: "", status: "superseded" });
    const fallback = play([start("s2", { replaces: "s1" })], refused);
    expect(shown(fallback)).toMatchObject({ id: "s2", text: "" });
    expect(fallback.suggestions.find((x) => x.id === "s1")!.text).toBe("");
    expect(shown(play([token("s2", "Say you will confirm by evening.")], fallback))!.text).toBe("Say you will confirm by evening.");
  });

  it("late tokens of a superseded or cancelled suggestion are dropped", () => {
    const s = play([READY, start("s1"), token("s1", "Draft"), { type: "suggestion_end", suggestion_id: "s1", status: "superseded" }, token("s1", " more")]);
    expect(s.suggestions[0].text).toBe("");
  });

  it("cancelled (they kept talking) puts back what was on the card", () => {
    const s = play([READY, start("s1"), token("s1", "Answer one."), { type: "suggestion_end", suggestion_id: "s1", status: "done" },
      start("s2", { turn_id: "t2", speculative: true }), { type: "suggestion_end", suggestion_id: "s2", status: "cancelled" }]);
    expect(shown(s)!.id).toBe("s1");
  });

  it("a failure ends with an error naming the suggestion: its text goes and the card says so", () => {
    const s = play([READY, start("s1"), token("s1", "Half"),
      { type: "error", code: "provider_error", message: "That suggestion didn't come through.", retry: true, suggestion_id: "s1", in_reply_to: null, fatal: false }]);
    expect(shown(s)).toMatchObject({ text: "", status: "failed" });
    expect(s.error).toEqual({ text: "That suggestion didn't come through.", fatal: false, suggestionId: "s1" });
  });

  it("mode_changed says why, in words; requested says nothing; captions only says captions go on", () => {
    expect(modeNote("fast", "budget")).toBe("Switched to Fast to stay within your assist hours.");
    expect(modeNote("balanced", "requested")).toBeNull();
    expect(modeNote("captions_only", "budget")).toBe("This month's assist hours are used. Assist is paused; captions go on.");
    const s = play([READY, { type: "mode_changed", mode: "fast", reason: "budget", requested: "balanced" }]);
    expect(s.mode).toBe("fast");
    expect(s.requestedMode).toBe("balanced");
  });

  it("keeps the other side's turns for the question line and the last two caption lines", () => {
    const s = play([
      { type: "turn", turn_id: "t1", channel: "other", text: "When can you deliver?", final: false },
      { type: "turn", turn_id: "t1", channel: "other", text: "When can you deliver the order?", final: true },
      { type: "turn", turn_id: "t2", channel: "me", text: "By the 15th.", final: true },
      { type: "turn", turn_id: "t3", channel: "other", text: "And the price?", final: false },
    ]);
    expect(s.turns.t1).toBe("When can you deliver the order?");
    expect(s.captions.map((c) => c.text)).toEqual(["By the 15th.", "And the price?"]);
  });

  it("reads the output contract: first line, up to three bullets, Say next, the language line", () => {
    expect(parseSuggestion("First line here.\n- **One** a\n- Two\n- Three\n- Four\nSay next: Ask about delivery.\nUrdu: وہ ترسیل کا پوچھ رہے ہیں۔")).toEqual({
      first: "First line here.", bullets: ["**One** a", "Two", "Three"], sayNext: "Ask about delivery.", language: { label: "Urdu", text: "وہ ترسیل کا پوچھ رہے ہیں۔" },
    });
  });

  it("the fixture session ends on the upgrade's answer with the draft cleared", () => {
    const s = play(FIXTURE_SESSION);
    expect(shown(s)!.id).toBe("s2");
    expect(s.suggestions.find((x) => x.id === "s1")!.text).toBe("");
    const seen: AssistMessage[] = [];
    fixtureStream((m) => seen.push(m), FIXTURE_SESSION, 0);
    expect(seen).toHaveLength(FIXTURE_SESSION.length);
  });
});

describe("the card", () => {
  const actions = () => ({ onSend: vi.fn(), onMode: vi.fn(), onHint: vi.fn(), onMute: vi.fn(), onHide: vi.fn(), onCopyConsent: vi.fn() });

  it("shows the question small, the first line, bullets, Say next and the language line, and the captions", () => {
    render(<AssistCard state={play(FIXTURE_SESSION)} hint="outline" muted={false} actions={actions()} />);
    const card = screen.getByRole("region", { name: "Live Assist" });
    expect(card.querySelector(".el-assist__question")).toHaveTextContent("Tell me about a time you handled a difficult client.");
    expect(within(card).getByText(/Talk about the Qureshi Textiles payment dispute/)).toBeInTheDocument();
    expect(within(card).getByText("Listened first").tagName).toBe("STRONG");
    expect(within(card).getAllByRole("listitem").length).toBeGreaterThanOrEqual(3);
    expect(within(card).getByText(/Would you like the numbers/)).toBeInTheDocument();
    expect(within(card).getByText(/Woh poochh rahe hain/).closest("p")).toHaveAttribute("dir", "ltr");
    expect(within(screen.getByRole("list", { name: "Captions" })).getAllByRole("listitem").map((l) => l.textContent)).toEqual([
      "Them Tell me about a time you handled a difficult client.", "Me Sure, it was with a textile exporter in Faisalabad.",
    ]);
    expect(within(card).getByRole("note")).toHaveTextContent(INTERVIEW_NOTE);
  });

  it("the in-call controls: help now, regenerate, shorter, formal, Urdu; mode, hints, mute, hide", () => {
    const a = actions();
    render(<AssistCard state={play(FIXTURE_SESSION)} hint="outline" muted={false} actions={a} />);
    for (const name of ["Help me now", "Regenerate", "Shorter", "More formal", "Explain in Urdu"]) fireEvent.click(screen.getByRole("button", { name }));
    expect(a.onSend.mock.calls.map((c) => c[0])).toEqual(["help_now", "regenerate", "shorter", "formal", "translate"]);
    fireEvent.click(screen.getByRole("button", { name: "Mode: Balanced. Change to Fast" }));
    expect(a.onMode).toHaveBeenCalledWith("fast");
    fireEvent.click(screen.getByRole("button", { name: "Hints: Outline. Change to Full answer" }));
    expect(a.onHint).toHaveBeenCalledWith("full");
    fireEvent.click(screen.getByRole("button", { name: "Mute 60 s" }));
    fireEvent.click(screen.getByRole("button", { name: "Hide the card" }));
    expect(a.onMute).toHaveBeenCalled();
    expect(a.onHide).toHaveBeenCalled();
  });

  it("client meetings remind of the consent line, with Copy", () => {
    const a = actions();
    render(<AssistCard state={play([READY])} hint="full" muted={false} actions={a} />);
    expect(screen.getByRole("note")).toHaveTextContent(CONSENT_LINE);
    fireEvent.click(within(screen.getByRole("note")).getByRole("button", { name: "Copy" }));
    expect(a.onCopyConsent).toHaveBeenCalled();
    fireEvent.click(within(screen.getByRole("note")).getByRole("button", { name: "OK" }));
    expect(screen.queryByRole("note")).toBeNull();
  });

  it("captions only: no suggestion controls, the reason said, captions go on", () => {
    const s = play([READY, { type: "mode_changed", mode: "captions_only", reason: "budget" }, { type: "turn", turn_id: "t1", channel: "other", text: "Hello?", final: true }]);
    render(<AssistCard state={s} hint="full" muted={false} actions={actions()} />);
    expect(screen.queryByRole("button", { name: "Help me now" })).toBeNull();
    expect(screen.getByText("This month's assist hours are used. Assist is paused; captions go on.")).toBeInTheDocument();
    expect(screen.getByRole("list", { name: "Captions" })).toHaveTextContent("Hello?");
  });
});

describe("the assist window", () => {
  beforeEach(() => {
    resetTauriMock();
    respond("assist_state", null);
    respond("set_hit_regions", null);
  });

  it("draws nothing unless live_assist and ui_v2.live are both on", async () => {
    respond("ui_flags", { ui_flags: { live_assist: true } });
    render(<AssistApp />);
    await act(flushAsync);
    await act(() => emitEvent("assist:message", READY));
    expect(screen.queryByTestId("assist-card")).toBeNull();
  });

  it("follows the core's messages and sends the person's actions", async () => {
    respond("ui_flags", { ui_flags: { live_assist: true, "ui_v2.live": true } });
    respond("assist_send", null);
    render(<AssistApp />);
    await act(flushAsync);
    for (const m of [READY, start("s1"), token("s1", "Confirm the March date.")]) await act(() => emitEvent("assist:message", m));
    expect(await screen.findByText("Confirm the March date.")).toBeInTheDocument();
    await act(() => emitEvent("assist:message", { type: "suggestion_end", suggestion_id: "s1", status: "superseded" }));
    expect(screen.queryByText("Confirm the March date.")).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Help me now" }));
    await act(flushAsync);
    expect(callsOf("assist_send")).toEqual([{ kind: "help_now" }]);
  });

  it("a window opened mid-session starts from the core's snapshot", async () => {
    respond("ui_flags", { ui_flags: { live_assist: true, "ui_v2.live": true } });
    respond("assist_state", { messages: [READY, start("s1"), token("s1", "Ask for the LC copy.")], hint_level: "keywords", paused: false, muted_until: null });
    render(<AssistApp />);
    await act(flushAsync);
    expect(await screen.findByText("Ask for the LC copy.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /^Hints: Keywords/ })).toBeInTheDocument();
  });
});
