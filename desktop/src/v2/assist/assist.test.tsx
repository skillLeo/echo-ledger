import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { callsOf, emitEvent, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import type { AssistMessage } from "./adapter";
import { FIXTURE_SESSION, fixtureStream } from "./fixture";
import { EMPTY_CARD, cardReducer, errorText, errorWords, isSessionActive, modeNote, offerQuestion, parseSuggestion, sessionActiveWords, shown, type CardState } from "./card";
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
    // The state keeps the code; the card words it when drawn.
    expect(s.error).toEqual({ code: "provider_error", retry: true, fatal: false, suggestionId: "s1" });
    expect(errorText(s.error!)).toBe("Something went wrong with Live Assist.");
  });

  it("mode_changed says why, in words; requested says nothing; captions only says captions go on", () => {
    expect(modeNote("fast", "budget")).toBe("Switched to Fast to stay within your assist hours.");
    expect(modeNote("balanced", "requested")).toBeNull();
    expect(modeNote("captions_only", "budget")).toBe("This month's assist hours are used. Assist is paused; captions go on.");
    const s = play([READY, { type: "mode_changed", from: "balanced", to: "fast", reason: "budget", requested: "balanced" }]);
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
  const actions = () => ({ onSend: vi.fn(), onMode: vi.fn(), onHint: vi.fn(), onMute: vi.fn(), onHide: vi.fn(), onCopyConsent: vi.fn(), onStartOffer: vi.fn(), onDismissOffer: vi.fn(), onEndAndStart: vi.fn(), onKeepRunning: vi.fn() });

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
    const s = play([READY, { type: "mode_changed", from: "fast", to: "captions_only", reason: "budget" }, { type: "turn", turn_id: "t1", channel: "other", text: "Hello?", final: true }]);
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

  it("draws nothing without live_assist; with it, the card shows in classic too (no ui_v2 flag)", async () => {
    respond("ui_flags", { ui_flags: { "ui_v2.live": true } });
    const first = render(<AssistApp />);
    await act(flushAsync);
    await act(() => emitEvent("assist:message", READY));
    await act(() => emitEvent("assist:message", start("s1")));
    expect(screen.queryByTestId("assist-card")).toBeNull();
    first.unmount();
    // Classic: live_assist on, no new-design flag at all.
    respond("ui_flags", { ui_flags: { live_assist: true }, ui_classic: true });
    render(<AssistApp />);
    await act(flushAsync);
    for (const m of [READY, start("s1"), token("s1", "Confirm the March date.")]) await act(() => emitEvent("assist:message", m));
    expect(await screen.findByText("Confirm the March date.")).toBeInTheDocument();
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

describe("contract 1.9.0-n on the card", () => {
  const err = (code: string, retry: boolean, suggestion_id: string | null = null): AssistMessage =>
    ({ type: "error", code, message: "relay text", retry, suggestion_id, in_reply_to: null, fatal: false });

  it("reads the new mode from `to`", () => {
    const s = play([READY, { type: "mode_changed", from: "balanced", to: "fast", reason: "budget" }]);
    expect(s.mode).toBe("fast");
    expect(s.modeChange).toEqual({ mode: "fast", reason: "budget" });
    expect(modeNote(s.modeChange!.mode, s.modeChange!.reason)).toBe("Switched to Fast to stay within your assist hours.");
  });

  it("a skip naming a suggestion ends it, without words", () => {
    const s = play([READY, start("s1"), { type: "skip", suggestion_id: "s1", turn_id: "t1" }]);
    expect(s.suggestions[0]).toMatchObject({ status: "skipped", text: "" });
    expect(shown(s)).toBeNull();
    expect(s.skipped).toBe(true);
    expect(play([token("s1", "late")], s).suggestions[0].text).toBe("");
  });

  it("ready with resumed: true clears a card still streaming; a finished one stays", () => {
    const streaming = play([READY, start("s1"), token("s1", "Half an ans")]);
    const resumed = play([{ ...READY, resumed: true } as AssistMessage], streaming);
    expect(shown(resumed)).toBeNull();
    expect(resumed.suggestions[0]).toMatchObject({ text: "", status: "cancelled" });
    const done = play([READY, start("s1"), token("s1", "Whole."), { type: "suggestion_end", suggestion_id: "s1", status: "done" }, { ...READY, resumed: true } as AssistMessage]);
    expect(shown(done)!.text).toBe("Whole.");
  });

  it("budget_exhausted: off for this call when it won't retry, try again in a moment when it will", () => {
    expect(errorText(play([READY, err("budget_exhausted", false)]).error!)).toBe("Suggestions are off for this call.");
    expect(errorText(play([READY, err("budget_exhausted", true)]).error!)).toBe("Try again in a moment.");
  });

  it("every code gets a plain sentence; bad_message says nothing; never the code itself", () => {
    for (const code of ["provider_unavailable", "provider_timeout", "model_declined", "nothing_to_rewrite", "documents_too_large", "rate_limited"]) {
      const words = errorWords(code, true, "relay text")!;
      expect(words).not.toContain(code);
      expect(words).not.toBe("relay text");
    }
    expect(errorWords("provider_not_configured", false, "Groq key missing")).toBe("Live Assist isn't set up on this server yet.");
    expect(errorWords("something_new", true, "raw relay text")).toBe("Something went wrong with Live Assist.");
    expect(play([READY, err("bad_message", false)]).error).toBeNull();
  });

  it("the core's offer asks for the prepared meeting, with the caller when known", () => {
    const offer: AssistMessage = { type: "offer", prep_id: "pr1", title: "Interview at Mehran Foods", client_ref: "rec-7", caller: "Ayesha Siddiqui" };
    const s = play([offer]);
    expect(s.offer).toEqual({ prepId: "pr1", title: "Interview at Mehran Foods", clientRef: "rec-7", caller: "Ayesha Siddiqui" });
    expect(offerQuestion(s.offer!)).toBe("Start Live Assist for Interview at Mehran Foods with Ayesha Siddiqui?");
    expect(offerQuestion({ title: "March order", caller: null })).toBe("Start Live Assist for March order?");
  });

  it("session_active is recognised from an error message or a rejection", () => {
    // Without the core's own words the state keeps none; the app's are found when the card is drawn.
    const plain = play([READY, err("session_active", false)]).sessionActive!;
    expect(plain).toEqual({ session_id: null, practice: false, text: null, action: null });
    expect(sessionActiveWords(plain)).toEqual({ text: "Another Live Assist session is running.", action: "End it and start this one" });
    const fromCore = play([{ type: "session_active", session_id: "as-0", practice: true, text: "Your practice session is still running.", action: "End practice and start" }]);
    expect(fromCore.sessionActive).toEqual({ session_id: "as-0", practice: true, text: "Your practice session is still running.", action: "End practice and start" });
    expect(sessionActiveWords(fromCore.sessionActive!)).toEqual({ text: "Your practice session is still running.", action: "End practice and start" });
    expect(isSessionActive({ code: "session_active", message: "x" })).toBe(true);
    expect(isSessionActive("Another session: session_active")).toBe(true);
    expect(isSessionActive({ code: "budget_exhausted" })).toBe(false);
  });
});

describe("session_active only after a start from the card", () => {
  it("a session_active error without a start from the card asks nothing", () => {
    const a = { onSend: vi.fn(), onMode: vi.fn(), onHint: vi.fn(), onMute: vi.fn(), onHide: vi.fn(), onCopyConsent: vi.fn(), onStartOffer: vi.fn(), onDismissOffer: vi.fn(), onEndAndStart: vi.fn(), onKeepRunning: vi.fn() };
    const err: AssistMessage = { type: "error", code: "session_active", message: "x", retry: false, suggestion_id: null, in_reply_to: null, fatal: false };
    const { container } = render(<AssistCard state={play([err])} hint="full" muted={false} actions={a} />);
    expect(container).toBeEmptyDOMElement();
    render(<AssistCard state={{ ...play([err]), pendingStart: { prepId: "pr1", clientRef: "rec-7" } }} hint="full" muted={false} actions={a} />);
    expect(screen.getByRole("button", { name: "End it and start this one" })).toBeInTheDocument();
  });
});

describe("the offer and session_active in the assist window", () => {
  beforeEach(() => {
    resetTauriMock();
    respond("assist_state", null);
    respond("set_hit_regions", null);
    respond("ui_flags", { ui_flags: { live_assist: true, "ui_v2.live": true } });
  });
  const offer: AssistMessage = { type: "offer", prep_id: "pr1", title: "Interview at Mehran Foods", client_ref: "rec-7", caller: "Ayesha Siddiqui" };

  it("Start passes the prep and the call's client_ref to assist_start; Not now closes it", async () => {
    respond("assist_start", { session_id: "as-1", auto_saved: true, mode: "balanced", allowed_modes: ["balanced"], seconds_left: 3600, practice: false });
    render(<AssistApp />);
    await act(flushAsync);
    await act(() => emitEvent("assist:message", offer));
    expect(await screen.findByText("Start Live Assist for Interview at Mehran Foods with Ayesha Siddiqui?")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Start" }));
    await act(flushAsync);
    expect(callsOf("assist_start")).toEqual([{ prepId: "pr1", mode: null, practice: false, clientRef: "rec-7" }]);
    await act(() => emitEvent("assist:message", { ...offer, prep_id: "pr2" }));
    fireEvent.click(screen.getByRole("button", { name: "Not now" }));
    expect(screen.queryByText(/Start Live Assist for/)).toBeNull();
  });

  it("409 session_active: the core's words and button; it ends the running session and starts this one in one step", async () => {
    respondWith("assist_start", () => {
      throw { code: "session_active", session_id: "as-0", practice: false, text: "Another Live Assist session is running.", action: "End it and start this one" };
    });
    respond("assist_start_replacing", { session_id: "as-2", auto_saved: true, mode: "balanced", allowed_modes: ["balanced"], seconds_left: 3600, practice: false });
    render(<AssistApp />);
    await act(flushAsync);
    await act(() => emitEvent("assist:message", offer));
    fireEvent.click(await screen.findByRole("button", { name: "Start" }));
    await act(flushAsync);
    expect(screen.getByRole("alert")).toHaveTextContent("Another Live Assist session is running.");
    fireEvent.click(screen.getByRole("button", { name: "End it and start this one" }));
    await act(flushAsync);
    expect(callsOf("assist_start")).toEqual([{ prepId: "pr1", mode: null, practice: false, clientRef: "rec-7" }]);
    expect(callsOf("assist_start_replacing")).toEqual([{ sessionId: "as-0", prepId: "pr1", mode: null, practice: false, clientRef: "rec-7" }]);
  });
});

describe("a replacement hands over the card (both apps)", () => {
  const base = play([READY, start("s1"), token("s1", "Offer the 40% advance."), { type: "suggestion_end", suggestion_id: "s1", status: "done" }]);

  it("the replaced suggestion stays until the replacement's first token", () => {
    const waiting = play([start("s2", { kind: "upgrade", replaces: "s1" })], base);
    expect(shown(waiting)!.id).toBe("s1");
    expect(shown(waiting)!.text).toBe("Offer the 40% advance.");
    const handed = play([token("s2", "Offer 40% in advance, the rest against the bill of lading.")], waiting);
    expect(shown(handed)!.id).toBe("s2");
  });

  it("or until its own superseded end, whichever comes first, and the card is never blank", () => {
    const streaming = play([READY, start("s1"), token("s1", "Draft")]);
    const waiting = play([start("s2", { replaces: "s1" })], streaming);
    expect(shown(waiting)!.text).toBe("Draft");
    const ended = play([{ type: "suggestion_end", suggestion_id: "s1", status: "superseded" }], waiting);
    expect(shown(ended)!.id).toBe("s2");
    expect(shown(ended)!.status).toBe("streaming");
    render(<AssistCard state={ended} hint="full" muted={false} actions={{ onSend: vi.fn(), onMode: vi.fn(), onHint: vi.fn(), onMute: vi.fn(), onHide: vi.fn(), onCopyConsent: vi.fn(), onStartOffer: vi.fn(), onDismissOffer: vi.fn(), onEndAndStart: vi.fn(), onKeepRunning: vi.fn() }} />);
    expect(screen.getByText("Writing a suggestion")).toBeInTheDocument();
  });

  it("a new question's suggestion (replaces: null) takes the card at once; resumed clears it", () => {
    const next = play([start("s3", { turn_id: "t2" })], base);
    expect(shown(next)!.id).toBe("s3");
    expect(shown(play([{ ...READY, resumed: true } as AssistMessage], next))).toBeNull();
  });
});

describe("the shown report", () => {
  beforeEach(() => {
    resetTauriMock();
    respond("assist_state", null);
    respond("set_hit_regions", null);
    respond("assist_shown", null);
    respond("ui_flags", { ui_flags: { live_assist: true, "ui_v2.live": true } });
  });

  it("reports a suggestion once, when its first text is drawn, with the time since its start", async () => {
    render(<AssistApp />);
    await act(flushAsync);
    for (const m of [READY, start("s1")]) await act(() => emitEvent("assist:message", m));
    expect(callsOf("assist_shown")).toHaveLength(0);
    await act(() => emitEvent("assist:message", token("s1", "Ask for ")));
    await act(() => emitEvent("assist:message", token("s1", "the LC copy.")));
    await act(flushAsync);
    const calls = callsOf("assist_shown");
    expect(calls).toHaveLength(1);
    expect(calls[0].suggestionId).toBe("s1");
    expect(typeof calls[0].renderMs).toBe("number");
    expect(calls[0].renderMs as number).toBeGreaterThanOrEqual(0);
  });
});

describe("Keep running", () => {
  beforeEach(() => {
    resetTauriMock();
    respond("assist_state", null);
    respond("set_hit_regions", null);
    respond("assist_keep_running", null);
    respond("assist_card_hide", null);
    respond("ui_flags", { ui_flags: { live_assist: true, "ui_v2.live": true } });
    respondWith("assist_start", () => {
      throw { code: "session_active", session_id: "as-0", practice: false, text: "Another Live Assist session is running.", action: "End it and start this one" };
    });
  });
  const offer: AssistMessage = { type: "offer", prep_id: "pr1", title: "Interview at Mehran Foods", client_ref: "rec-7", caller: null };
  const ask = async () => {
    render(<AssistApp />);
    await act(flushAsync);
    await act(() => emitEvent("assist:message", offer));
    fireEvent.click(await screen.findByRole("button", { name: "Start" }));
    await act(flushAsync);
  };

  it("sits next to End it and start this one and keeps the running session", async () => {
    await ask();
    const buttons = within(screen.getByRole("alert")).getAllByRole("button").map((b) => b.textContent);
    expect(buttons).toEqual(["End it and start this one", "Keep running"]);
    fireEvent.click(screen.getByRole("button", { name: "Keep running" }));
    await act(flushAsync);
    expect(callsOf("assist_keep_running")).toHaveLength(1);
    expect(screen.queryByRole("button", { name: "End it and start this one" })).toBeNull();
  });

  it("closing the card while it asks counts as Keep running", async () => {
    await ask();
    fireEvent.click(screen.getByRole("button", { name: "Hide the card" }));
    await act(flushAsync);
    expect(callsOf("assist_keep_running")).toHaveLength(1);
    expect(callsOf("assist_card_hide")).toHaveLength(1);
  });
});
