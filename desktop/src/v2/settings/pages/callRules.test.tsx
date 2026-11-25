// Contract 2.0.0-w (Settings › Calls and Call rules): while the person's call_rules flag is on, After each call, When to
// ask and Short calls are the account's, read and written through the core; the Call rules page lists the rules in the
// contract's order (group rules read only, "set on the phone"), adds one for an app or a contact by name, changes and
// removes one, says a contact's Don't record honestly (decision 25), and notes a Source's Don't record that applies
// before the caller is known. With the flag off everything is this computer's own, as before.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { CallRule, CallRules } from "../../../lib/ipc";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../../test/tauri";
import { makeSettings } from "../../../test/fixtures";
import { CallRulesPageV2, CallsPageV2 } from "./CallsV2";
import { CallsPage } from "../../../views/main/CaptureSettings";
import { accountAfterEachCall, cleanName, contactRuleOf, ruleLine, startNotes } from "../../../lib/callRules";

vi.mock("@tauri-apps/api/core", async () => (await import("../../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../../test/tauri")).eventModule);

const rule = (over: Partial<CallRule> & Pick<CallRule, "id" | "kind" | "action">): CallRule => ({ number: null, name: null, group: null, source_app: null, ...over });
const ACCOUNT: CallRules = {
  after_call: "always_audio", ask_when: "after_call", short_calls: "dont_keep", short_call_seconds: 30, version: 12, updated_at: null, declined: [],
  rules: [
    rule({ id: "c1", kind: "contact", name: "Ahmed Khan", action: "dont_record" }),
    rule({ id: "g1", kind: "group", group: "favourites", action: "always_transcribe" }),
    rule({ id: "s1", kind: "source", source_app: "zoom", action: "dont_record" }),
  ],
};
const answer = (over: Partial<CallRules> = {}) => ({ ...ACCOUNT, ...over });
const flush = () => act(flushAsync);

beforeEach(() => {
  resetTauriMock();
  respond("mic_users", []);
  respond("last_not_recorded", null);
});

describe("Settings › Calls with the account's call rules", () => {
  it("with the flag off (the core's null): this computer's own settings, and no Call rules row", async () => {
    respond("call_rules_get", null);
    const patch = vi.fn().mockResolvedValue(undefined);
    render(<CallsPageV2 settings={makeSettings({ autoMode: true, afterCall: "always_transcribe", skipCallsShorterThanS: 10 })} patch={patch} platform="windows" onSites={vi.fn()} onConsent={vi.fn()} onRules={vi.fn()} />);
    await flush();
    expect(screen.getByRole("radio", { name: /Transcribe every call/ })).toBeChecked();
    expect(screen.queryByRole("button", { name: /Call rules/ })).toBeNull();
    fireEvent.click(screen.getByRole("radio", { name: /Save audio only/ }));
    expect(patch).toHaveBeenCalledWith({ autoMode: true, afterCall: "always_audio" });
    expect(callsOf("call_rules_settings_set")).toEqual([]);
  });

  it("with the flag on: the account's After each call, When to ask and Short calls, each change sent to the account", async () => {
    respond("call_rules_get", ACCOUNT);
    respondWith("call_rules_settings_set", (args) => answer((args as { settings: Partial<CallRules> }).settings));
    const patch = vi.fn().mockResolvedValue(undefined);
    const onRules = vi.fn();
    render(<CallsPageV2 settings={makeSettings({ autoMode: true, afterCall: "ask", skipCallsShorterThanS: 10 })} patch={patch} platform="windows" onSites={vi.fn()} onConsent={vi.fn()} onRules={onRules} />);
    await flush();
    expect(screen.getByRole("radio", { name: /Save audio only/ })).toBeChecked();
    fireEvent.click(screen.getByRole("radio", { name: /Don't record calls/ }));
    await flush();
    expect(callsOf("call_rules_settings_set")).toEqual([{ settings: { after_call: "dont_record" } }]);
    expect(patch).not.toHaveBeenCalled();
    // Short calls: the account's switch at the server's 30 seconds, not this computer's seconds.
    expect(screen.queryByRole("combobox", { name: /Skip calls shorter than/ })).toBeNull();
    fireEvent.click(screen.getByRole("switch", { name: "Don't keep short calls" }));
    await flush();
    expect(callsOf("call_rules_settings_set").at(-1)).toEqual({ settings: { short_calls: "keep" } });
    fireEvent.click(screen.getByRole("button", { name: /Call rules/ }));
    expect(onRules).toHaveBeenCalled();
    // The core says the rules changed (another device): the page follows.
    await act(async () => { await emitEvent("call_rules:changed", answer({ after_call: "always_transcribe" })); });
    expect(screen.getByRole("radio", { name: /Transcribe every call/ })).toBeChecked();
    // And the flag turned off: this computer's own again.
    await act(async () => { await emitEvent("call_rules:changed", null); });
    expect(screen.getByRole("radio", { name: /Ask me/ })).toBeChecked();
  });

  it("a change that can't be saved says why, in the core's words", async () => {
    respond("call_rules_get", ACCOUNT);
    fail("call_rules_settings_set", "This can't be saved now: EchoLedger can't reach the server. Try again when you're online.");
    render(<CallsPageV2 settings={makeSettings()} patch={vi.fn()} platform="windows" onSites={vi.fn()} onConsent={vi.fn()} onRules={vi.fn()} />);
    await flush();
    fireEvent.click(screen.getByRole("radio", { name: /Ask me/ }));
    await flush();
    expect(screen.getByRole("alert")).toHaveTextContent("can't reach the server");
  });

  it("the classic page follows the account the same way", async () => {
    respond("call_rules_get", ACCOUNT);
    respondWith("call_rules_settings_set", (args) => answer((args as { settings: Partial<CallRules> }).settings));
    const patch = vi.fn().mockResolvedValue(undefined);
    render(<CallsPage settings={makeSettings({ afterCall: "ask" })} patch={patch} platform="windows" onSites={vi.fn()} consentRead onConsent={vi.fn()} onRules={vi.fn()} />);
    await flush();
    expect(screen.getByRole("radio", { name: /Save audio only/ })).toBeChecked();
    fireEvent.click(screen.getByRole("radio", { name: /Ask me/ }));
    await flush();
    expect(callsOf("call_rules_settings_set")).toEqual([{ settings: { after_call: "ask" } }]);
    expect(screen.getByRole("button", { name: /Call rules/ })).toHaveTextContent("3 rules");
  });
});

describe("Settings › Calls › Call rules", () => {
  it("lists the rules in the contract's order; a group rule is read only; a contact's Don't record says what it does here", async () => {
    // With a rule that records a contact, Zoom's Don't record (decided before the caller is known) is noted.
    respond("call_rules_get", answer({ rules: [rule({ id: "c0", kind: "contact", name: "Sara Ahmed", action: "always_transcribe" }), ...ACCOUNT.rules] }));
    render(<CallRulesPageV2 />);
    await flush();
    const rows = within(screen.getByRole("list", { name: "Call rules" })).getAllByRole("listitem");
    expect(rows.map((r) => r.querySelector(".el-row__title")?.textContent)).toEqual(["Sara Ahmed", "Ahmed Khan", "Favourites", "Zoom"]);
    expect(rows[1]).toHaveTextContent("Stops and deletes the recording as soon as Ahmed Khan is known");
    expect(rows[1]).not.toHaveTextContent(/nothing is recorded|not recorded/i);
    expect(rows[2]).toHaveTextContent("Set on the phone");
    expect(within(rows[2]).queryByRole("button")).toBeNull();
    expect(within(rows[2]).queryByRole("combobox")).toBeNull();
    // Zoom's Don't record comes before the caller is known, and there is a rule for a contact: the page says so.
    expect(screen.getByText("On this computer, Zoom's Don't record applies before the caller is known.")).toBeInTheDocument();
  });

  it("adds a rule for an app and for a contact by name, changes and removes one", async () => {
    respond("call_rules_get", ACCOUNT);
    respondWith("call_rule_save", (args) => answer({ rules: [...ACCOUNT.rules, rule({ id: "n1", ...(args as { rule: Partial<CallRule> }).rule } as CallRule)] }));
    respondWith("call_rule_update", (args) => answer({ rules: ACCOUNT.rules.map((r) => (r.id === (args as { id: string }).id ? { ...r, ...(args as { patch: Partial<CallRule> }).patch } : r)) }));
    respondWith("call_rule_delete", (args) => answer({ rules: ACCOUNT.rules.filter((r) => r.id !== (args as { id: string }).id) }));
    render(<CallRulesPageV2 />);
    await flush();
    fireEvent.click(screen.getAllByRole("button", { name: "Add rule" })[0]);
    await flush();
    expect(callsOf("call_rule_save")[0]).toEqual({ rule: { kind: "source", source_app: "zoom", action: "dont_record" } });
    // A contact by name: its Don't record says so before it is added.
    fireEvent.change(screen.getByLabelText("Name"), { target: { value: "  Sara Ahmed " } });
    // The add forms' choices (the rows' say "Choice for …"): the app's, then the contact's.
    const contactChoice = screen.getAllByRole("combobox", { name: /^Choice (?!for )/ })[1];
    fireEvent.click(contactChoice);
    fireEvent.click(screen.getByRole("option", { name: "Don't record" }));
    expect(screen.getByText("Stops and deletes the recording as soon as Sara Ahmed is known")).toBeInTheDocument();
    fireEvent.click(screen.getAllByRole("button", { name: "Add rule" })[1]);
    await flush();
    expect(callsOf("call_rule_save")[1]).toEqual({ rule: { kind: "contact", name: "Sara Ahmed", action: "dont_record" } });
    expect(screen.getByLabelText("Name")).toHaveValue("");
    // Change Zoom's, then remove Ahmed's.
    fireEvent.click(screen.getByRole("combobox", { name: /^Choice for Zoom/ }));
    fireEvent.click(screen.getByRole("option", { name: "Transcribe every call" }));
    await flush();
    expect(callsOf("call_rule_update")).toEqual([{ id: "s1", patch: { action: "always_transcribe" } }]);
    fireEvent.click(screen.getByRole("button", { name: "Remove the rule for Ahmed Khan" }));
    await flush();
    expect(callsOf("call_rule_delete")).toEqual([{ id: "c1" }]);
  });

  it("500 rules: the core's words, and nothing is added", async () => {
    respond("call_rules_get", ACCOUNT);
    fail("call_rule_save", "You have 500 call rules, the most there can be. Remove one first.");
    render(<CallRulesPageV2 />);
    await flush();
    fireEvent.click(screen.getAllByRole("button", { name: "Add rule" })[0]);
    await flush();
    expect(screen.getByRole("alert")).toHaveTextContent("You have 500 call rules");
    expect(within(screen.getByRole("list", { name: "Call rules" })).getAllByRole("listitem")).toHaveLength(3);
  });

  it("a contact's name is sent and matched as the server keeps it: one space, 120 characters, lower case", () => {
    expect(cleanName("  Sara \t  Ahmed  ")).toBe("Sara Ahmed");
    expect(Array.from(cleanName("ع".repeat(130))).length).toBe(120);
    const rules = { ...ACCOUNT, rules: [rule({ id: "c1", kind: "contact", name: "Ahmed  Khan", action: "always_audio" }),
      rule({ id: "c2", kind: "contact", name: "Omar", number: "+923001234567", action: "dont_record" })] };
    expect(contactRuleOf(rules, " ahmed khan")?.id).toBe("c1");
    expect(contactRuleOf(rules, "Omar"), "a rule with a number never matches by name").toBeUndefined();
  });

  it("the account's Short calls says automatically recorded calls, at the server's seconds", async () => {
    respond("call_rules_get", answer({ short_call_seconds: 45 }));
    render(<CallsPageV2 settings={makeSettings()} patch={vi.fn()} platform="windows" onSites={vi.fn()} onConsent={vi.fn()} onRules={vi.fn()} />);
    await flush();
    expect(screen.getByText(/^Automatically recorded calls shorter than 45 seconds, and missed calls, aren't kept\./)).toBeInTheDocument();
  });

  it("the words: After each call from the account, never chosen is Ask me; the start notes only with a contact rule", () => {
    expect(accountAfterEachCall({ after_call: null })).toBe("ask");
    expect(accountAfterEachCall({ after_call: "dont_record" })).toBe("off");
    expect(ruleLine(rule({ id: "s", kind: "source", source_app: "zoom", action: "dont_record" }))).toBe("Don't record");
    expect(startNotes({ ...ACCOUNT, rules: [rule({ id: "s1", kind: "source", source_app: "zoom", action: "dont_record" })] })).toEqual([]);
    expect(startNotes({ ...ACCOUNT, after_call: "dont_record", rules: [rule({ id: "c", kind: "contact", name: "A", action: "always_audio" })] }))
      .toEqual(["On this computer, Don't record calls applies before the caller is known."]);
  });
});
