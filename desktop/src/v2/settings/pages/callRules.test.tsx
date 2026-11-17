// Call rules in Settings › Calls & meetings (docs/PLAN_CALL_RULES.md 2): a rule per app and per person, the same words
// everywhere, and what is sent to the core.
import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/react";
import { CallRulesV2 } from "./CallsV2";
import { makeSettings } from "../../../test/fixtures";
import { CHOICE_WORDS, contactKey, contactLabel, withContactRule, withSourceRule } from "../../../lib/callRules";
import { setting } from "../../../lib/settingsRegistry";

/** A Select's name is its label then its value. */
const box = (label: string) => screen.getByRole("combobox", { name: new RegExp(`^${label}`) });
const choose = (label: string, option: string) => {
  fireEvent.click(box(label));
  fireEvent.click(screen.getByRole("option", { name: option }));
};

describe("call rules", () => {
  it("say the four choices with the same words as After a call", () => {
    expect(Object.values(CHOICE_WORDS)).toEqual(["Transcribe", "Keep audio", "Don't record", "Ask me"]);
    const after = setting("calls.after").options!.map((o) => o.label);
    expect(after).toEqual(["Ask me", "Keep audio", "Transcribe", "Don't record"]);
    const short = setting("calls.skip_short").options!;
    expect(short.find((o) => o.default)?.label).toBe("Don't keep under 30 s");
  });

  it("set a rule per app, and Same as the default removes it", () => {
    const patch = vi.fn().mockResolvedValue(undefined);
    render(<CallRulesV2 settings={makeSettings({ sourceRules: { zoom: "transcribe" } })} patch={patch} />);
    expect(box("Rule for Zoom")).toHaveTextContent("Transcribe");
    choose("Rule for WhatsApp desktop", "Don't record");
    expect(patch).toHaveBeenLastCalledWith({ sourceRules: { zoom: "transcribe", whatsapp: "dont_record" } });
    choose("Rule for Zoom", "Same as the default");
    expect(patch).toHaveBeenLastCalledWith({ sourceRules: {} });
  });

  it("add, change and remove a rule for a person", () => {
    const patch = vi.fn().mockResolvedValue(undefined);
    const { rerender } = render(<CallRulesV2 settings={makeSettings()} patch={patch} />);
    expect(screen.getByText("No rules for people yet")).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("Name"), { target: { value: "  Ahmed   Khan " } });
    fireEvent.click(screen.getByRole("button", { name: "Add rule" }));
    expect(patch).toHaveBeenLastCalledWith({ contactRules: { "ahmed khan": "dont_record" } });
    rerender(<CallRulesV2 settings={makeSettings({ contactRules: { "ahmed khan": "dont_record" } })} patch={patch} />);
    const rows = screen.getByRole("list", { name: "Rules for people" });
    expect(within(rows).getByText("Ahmed Khan")).toBeInTheDocument();
    choose("Rule for Ahmed Khan", "Keep audio only");
    expect(patch).toHaveBeenLastCalledWith({ contactRules: { "ahmed khan": "keep_audio" } });
    fireEvent.click(screen.getByRole("button", { name: "Remove the rule for Ahmed Khan" }));
    expect(patch).toHaveBeenLastCalledWith({ contactRules: {} });
  });

  it("compare names as the core does and treat Ask me as no rule", () => {
    expect(contactKey("  Sara   ALI ")).toBe("sara ali");
    expect(contactLabel("sara ali")).toBe("Sara Ali");
    expect(withContactRule({ "sara ali": "transcribe" }, "Sara Ali", "ask")).toEqual({});
    expect(withSourceRule({ meet: "ask" }, "meet", "")).toEqual({});
    expect(withSourceRule({}, "meet", "ask")).toEqual({ meet: "ask" }, "a source may ask against another default");
  });
});
