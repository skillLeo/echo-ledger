// A sub-menu's choices (UX-22): a label's name, a project, a format, a sort order. They send the sub-menu's action with
// their value, and checkMenu takes their words only under that sub-menu.
import { describe, expect, it, vi } from "vitest";
import { checkMenu, choiceItems, menuItems } from "./registry";

describe("a sub-menu's choices", () => {
  it("send the sub-menu's action with their value; checkMenu takes them under it and nowhere else", () => {
    const pick = vi.fn();
    const formats = choiceItems("recording.exportAs", [{ value: "docx", label: "Word (.docx)" }, { value: "srt", label: "Subtitles (.srt)", checked: true }], pick);
    expect(formats.map((f) => [f.action, f.choice, f.label, f.checked])).toEqual([["recording.exportAs", "docx", "Word (.docx)", undefined], ["recording.exportAs", "srt", "Subtitles (.srt)", true]]);
    formats[1].onPick?.();
    expect(pick).toHaveBeenCalledWith("srt");
    const [exportAs] = menuItems(["recording.exportAs"], { platform: "windows", targets: ["a"], context: true });
    expect(checkMenu([{ ...exportAs, items: formats }], "windows")).toEqual([]);
    // A choice anywhere else, or under another sub-menu, is not the file's.
    expect(checkMenu(formats, "windows")).toEqual(['"Word (.docx)": a choice outside its sub-menu (recording.exportAs)', '"Subtitles (.srt)": a choice outside its sub-menu (recording.exportAs)']);
    const [labels] = menuItems(["recording.labels"], { platform: "windows", targets: ["a"], context: true });
    expect(checkMenu([{ ...labels, items: formats.slice(0, 1) }], "windows")).toEqual(['"Word (.docx)": a choice outside its sub-menu (recording.exportAs)']);
    // An unknown action can't have choices.
    expect(() => choiceItems("recording.nope", [], pick)).toThrow("recording.nope is not in actions.json");
  });
});
