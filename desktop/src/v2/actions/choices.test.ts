// A sub-menu's choices (UX-22): a label's name, a project, a format, a sort order. They send the sub-menu's action with
// their value, and checkMenu takes their words only under that sub-menu.
import { describe, expect, it, vi } from "vitest";
import { canRun, checkMenu, choiceItems, menuItems, registerAction, runAction } from "./registry";

describe("a sub-menu's choices", () => {
  it("send the sub-menu's action with their value; checkMenu takes them under it and nowhere else", () => {
    const pick = vi.fn();
    expect(canRun("recording.exportAs")).toBe(false);
    const off = registerAction("recording.exportAs", (ctx) => pick(ctx.value, ctx.targets));
    expect(canRun("recording.exportAs")).toBe(true);
    const ctx = { platform: "windows" as const, targets: ["a"], context: true };
    const formats = choiceItems("recording.exportAs", [{ value: "docx", label: "Word (.docx)" }, { value: "srt", label: "Subtitles (.srt)", checked: true }], ctx);
    expect(formats.map((f) => [f.action, f.choice, f.label, f.checked])).toEqual([["recording.exportAs", "docx", "Word (.docx)", undefined], ["recording.exportAs", "srt", "Subtitles (.srt)", true]]);
    // The one code path: the registry runs the action, with the choice's value.
    formats[1].onPick?.();
    expect(pick).toHaveBeenCalledWith("srt", ["a"]);
    off();
    expect(canRun("recording.exportAs")).toBe(false);
    const [exportAs] = menuItems(["recording.exportAs"], { platform: "windows", targets: ["a"], context: true });
    expect(checkMenu([{ ...exportAs, items: formats }], "windows")).toEqual([]);
    // A choice anywhere else, or under another sub-menu, is not the file's.
    expect(checkMenu(formats, "windows")).toEqual(['"Word (.docx)": a choice outside its sub-menu (recording.exportAs)', '"Subtitles (.srt)": a choice outside its sub-menu (recording.exportAs)']);
    const [labels] = menuItems(["recording.labels"], { platform: "windows", targets: ["a"], context: true });
    expect(checkMenu([{ ...labels, items: formats.slice(0, 1) }], "windows")).toEqual(['"Word (.docx)": a choice outside its sub-menu (recording.exportAs)']);
    // An unknown action can't have choices.
    expect(() => choiceItems("recording.nope", [], ctx)).toThrow("recording.nope is not in actions.json");
  });

  it("one id, several kinds of thing: each runs where its kind is (Rename… of a recording, of a view)", () => {
    const recording = vi.fn();
    const view = vi.fn();
    const offs = [registerAction("file.rename", recording, "recording"), registerAction("file.rename", view, "view")];
    expect([canRun("file.rename", "recording"), canRun("file.rename", "view"), canRun("file.rename", "label"), canRun("file.rename")]).toEqual([true, true, false, false]);
    expect(runAction("file.rename", { platform: "macos", targets: ["r1"], kind: "recording" })).toBe(true);
    expect(runAction("file.rename", { platform: "macos", targets: ["v1"], kind: "view" })).toBe(true);
    expect(runAction("file.rename", { platform: "macos", targets: ["l1"], kind: "label" })).toBe(false);
    expect(recording.mock.calls.map(([c]) => c.targets)).toEqual([["r1"]]);
    expect(view.mock.calls.map(([c]) => c.targets)).toEqual([["v1"]]);
    // One registered without a kind takes any.
    const any = vi.fn();
    offs.push(registerAction("file.rename", any));
    expect(runAction("file.rename", { platform: "macos", targets: ["l1"], kind: "label" })).toBe(true);
    expect(any).toHaveBeenCalledTimes(1);
    offs.forEach((off) => off());
  });
});
