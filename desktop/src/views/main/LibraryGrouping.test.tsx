// P5.1: a group header's box only selects (tri-state), its chevron and name only fold. One click never does both.
import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { GroupHeader } from "./LibraryGrouping";
import { makeItem } from "../../test/fixtures";

const ITEMS = [makeItem("a"), makeItem("b"), makeItem("c")];

function setup(picked: string[] = [], collapsed = false) {
  const onToggle = vi.fn();
  const onPick = vi.fn();
  const view = render(<GroupHeader label="Today" items={ITEMS} picked={new Set(picked)} collapsed={collapsed} onToggle={onToggle} onPick={onPick} />);
  const box = screen.getByRole("checkbox", { name: "Select all in Today" }) as HTMLInputElement;
  const toggle = screen.getByRole("button", { name: /^Today/ });
  return { onToggle, onPick, box, toggle, view };
}

describe("GroupHeader (P5.1)", () => {
  it("the box only selects: a click, its label's target or Space never fold the group", () => {
    const { onToggle, onPick, box, view } = setup();
    fireEvent.click(box);
    expect(onPick).toHaveBeenCalledTimes(1);
    expect(onToggle).not.toHaveBeenCalled();
    // The 28px target around the box is the box's, not the name's.
    fireEvent.click(view.container.querySelector(".group-pick")!);
    expect(onPick).toHaveBeenCalledTimes(2);
    expect(onToggle).not.toHaveBeenCalled();
    fireEvent.keyDown(box, { key: " " });
    expect(onToggle).not.toHaveBeenCalled();
  });

  it("the chevron, the name and the count only fold: they never select", () => {
    const { onToggle, onPick, toggle, view } = setup();
    fireEvent.click(view.container.querySelector(".group-caret")!);
    fireEvent.click(view.container.querySelector(".group-label")!);
    fireEvent.click(view.container.querySelector(".group-count")!);
    expect(onToggle).toHaveBeenCalledTimes(3);
    expect(onPick).not.toHaveBeenCalled();
    expect(toggle).toHaveAttribute("aria-expanded", "true");
  });

  it("shows none, some or all of the group picked", () => {
    const none = setup();
    expect(none.box.checked).toBe(false);
    expect(none.box.indeterminate).toBe(false);
    expect(none.box).toHaveAttribute("aria-checked", "false");
    none.view.unmount();

    const some = setup(["a"]);
    expect(some.box.checked).toBe(false);
    expect(some.box.indeterminate).toBe(true);
    expect(some.box).toHaveAttribute("aria-checked", "mixed");
    some.view.unmount();

    const all = setup(["a", "b", "c"]);
    expect(all.box.checked).toBe(true);
    expect(all.box.indeterminate).toBe(false);
    expect(all.box).toHaveAttribute("aria-checked", "true");
  });

  it("a folded group still selects from its box and says it is folded", () => {
    const { onToggle, onPick, box, toggle } = setup([], true);
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    fireEvent.click(box);
    expect(onPick).toHaveBeenCalledTimes(1);
    expect(onToggle).not.toHaveBeenCalled();
  });
});
