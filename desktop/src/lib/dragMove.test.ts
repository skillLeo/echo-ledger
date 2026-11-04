import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { beginRecordingDrag, dragJustEnded, dragLabel, dropTargetAt } from "./dragMove";

/** A sidebar with a project row and the Inbox, and `elementFromPoint` answering with whatever the test points at. */
function sidebar() {
  document.body.innerHTML = `
    <ul><li data-drop-project="c1"><button id="acme">Acme</button></li><li data-drop-project=""><button id="inbox">Inbox</button></li></ul>
    <div id="elsewhere">List</div>`;
  let under: Element | null = null;
  Object.defineProperty(document, "elementFromPoint", { configurable: true, value: vi.fn(() => under) });
  return { pointAt: (id: string | null) => { under = id ? document.getElementById(id) : null; } };
}
const move = (x: number, y: number, buttons = 1) => window.dispatchEvent(new PointerEvent("pointermove", { clientX: x, clientY: y, buttons }));
const up = (x: number, y: number) => window.dispatchEvent(new PointerEvent("pointerup", { clientX: x, clientY: y }));
const press = { clientX: 10, clientY: 10, button: 0 };

beforeEach(() => {
  document.body.className = "";
});
afterEach(() => {
  document.body.innerHTML = "";
});

describe("dragging recordings onto the sidebar", () => {
  it("finds the project or the Inbox under the pointer", () => {
    const { pointAt } = sidebar();
    pointAt("acme");
    expect(dropTargetAt(0, 0)?.projectId).toBe("c1");
    pointAt("inbox");
    expect(dropTargetAt(0, 0)?.projectId).toBeNull();
    pointAt("elsewhere");
    expect(dropTargetAt(0, 0)).toBeNull();
  });

  it("stays a click below the threshold", () => {
    sidebar();
    const onDrop = vi.fn();
    const drag = vi.fn(() => ({ ids: ["a"], label: "Moving" }));
    beginRecordingDrag(press, drag, onDrop);
    move(13, 12);
    up(13, 12);
    expect(drag).not.toHaveBeenCalled();
    expect(onDrop).not.toHaveBeenCalled();
    expect(document.querySelector(".drag-ghost")).toBeNull();
  });

  it("carries a card, marks the target under the pointer and drops onto it", () => {
    const { pointAt } = sidebar();
    const onDrop = vi.fn();
    beginRecordingDrag(press, () => ({ ids: ["a", "b"], label: dragLabel(["One", "Two"]) }), onDrop);
    pointAt("acme");
    move(60, 40);
    expect(document.querySelector(".drag-ghost")).toHaveTextContent("Moving 2 recordings");
    expect(document.body).toHaveClass("dragging-recordings");
    expect(document.querySelector("[data-drop-project='c1']")).toHaveClass("drop-over");
    pointAt("inbox");
    move(60, 80);
    expect(document.querySelector("[data-drop-project='c1']")).not.toHaveClass("drop-over");
    expect(document.querySelector("[data-drop-project='']")).toHaveClass("drop-over");
    up(60, 80);
    expect(onDrop).toHaveBeenCalledWith(["a", "b"], null);
    expect(document.querySelector(".drag-ghost")).toBeNull();
    expect(document.body).not.toHaveClass("dragging-recordings");
    expect(document.querySelector(".drop-over")).toBeNull();
    expect(dragJustEnded()).toBe(true);
    expect(dragJustEnded(Date.now() + 1000)).toBe(false);
  });

  it("drops nothing when released elsewhere or after Escape", () => {
    const { pointAt } = sidebar();
    const onDrop = vi.fn();
    beginRecordingDrag(press, () => ({ ids: ["a"], label: "Moving" }), onDrop);
    pointAt("elsewhere");
    move(80, 80);
    up(80, 80);
    beginRecordingDrag(press, () => ({ ids: ["a"], label: "Moving" }), onDrop);
    pointAt("acme");
    move(80, 80);
    window.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
    expect(document.querySelector(".drag-ghost")).toBeNull();
    up(80, 80);
    expect(onDrop).not.toHaveBeenCalled();
  });

  it("drops nothing after the button was let go where the page did not hear it", () => {
    const { pointAt } = sidebar();
    const onDrop = vi.fn();
    beginRecordingDrag(press, () => ({ ids: ["a"], label: "Moving" }), onDrop);
    pointAt("acme");
    move(80, 80);
    expect(document.querySelector(".drag-ghost")).not.toBeNull();
    // The next move comes with no button pressed: the drag is over, and a later click on a project moves nothing.
    move(90, 90, 0);
    expect(document.querySelector(".drag-ghost")).toBeNull();
    up(90, 90);
    expect(onDrop).not.toHaveBeenCalled();
  });

  it("ignores any button but the main one", () => {
    sidebar();
    const drag = vi.fn(() => ({ ids: ["a"], label: "Moving" }));
    beginRecordingDrag({ ...press, button: 2 }, drag, vi.fn());
    move(80, 80);
    expect(drag).not.toHaveBeenCalled();
  });

  it("names what is moving", () => {
    expect(dragLabel(["Kick-off"])).toBe("Moving “Kick-off”");
    expect(dragLabel([null])).toBe("Moving “recording”");
    expect(dragLabel(["a", "b", "c"])).toBe("Moving 3 recordings");
  });
});
