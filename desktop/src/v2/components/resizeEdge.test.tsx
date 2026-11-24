// UX-03: a panel's edge sets its width: dragged (the width on the panel itself while it moves, the app told once let
// go, closed below the closing width), from the keyboard (8 px, 32 px with Shift, mirrored right to left), and back to
// the usual width with a double click or Enter.
import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { ResizeEdge, clampWidth, dragEnd } from "./ResizeEdge";

function Panel({ width, saved, ...handlers }: { width: number; saved?: number; onWidth?: (w: number) => void; onReset?: () => void; onClose?: () => void }) {
  return (
    <aside data-testid="panel" style={saved ? { ["--el-test-width" as string]: `${saved}px` } : undefined}>
      <ResizeEdge label="Resize the Library panel" side="end" cssVar="--el-test-width" width={width} min={200} max={360} closeBelow={160}
        onWidth={handlers.onWidth ?? (() => undefined)} onReset={handlers.onReset ?? (() => undefined)} onClose={handlers.onClose} />
    </aside>
  );
}
const edge = () => screen.getByRole("separator", { name: "Resize the Library panel" });
const panel = () => screen.getByTestId("panel");

describe("a panel's edge (UX-03)", () => {
  afterEach(() => {
    cleanup();
    document.documentElement.dir = "";
  });

  it("keeps a width in its range, and a drag let go below the closing width closes the panel", () => {
    expect(clampWidth(150, 200, 360)).toBe(200);
    expect(clampWidth(400.4, 200, 360)).toBe(360);
    expect(clampWidth(287.6, 200, 360)).toBe(288);
    expect(dragEnd(159, 200, 360, 160)).toEqual({ close: true });
    expect(dragEnd(160, 200, 360, 160)).toEqual({ width: 200 });
    expect(dragEnd(500, 280, 420, 160)).toEqual({ width: 420 });
    expect(dragEnd(100, 280, 420)).toEqual({ width: 280 }, "no closing width: it never closes");
  });

  it("is a separator with its range and value, focusable, never a drag region of the window", () => {
    render(<Panel width={240} />);
    expect(edge()).toHaveAttribute("aria-orientation", "vertical");
    expect(edge()).toHaveAttribute("aria-valuemin", "200");
    expect(edge()).toHaveAttribute("aria-valuemax", "360");
    expect(edge()).toHaveAttribute("aria-valuenow", "240");
    expect(edge()).toHaveAttribute("tabindex", "0");
    expect(edge()).toHaveAttribute("data-tauri-drag-region", "false");
  });

  it("follows the pointer on the panel itself, tells the app once let go, and leaves the panel's own width to the app", () => {
    const onWidth = vi.fn();
    render(<Panel width={240} onWidth={onWidth} />);
    const capture = vi.fn();
    Object.assign(edge(), { setPointerCapture: capture });
    fireEvent.pointerDown(edge(), { button: 0, clientX: 300, pointerId: 3 });
    expect(capture).toHaveBeenCalledWith(3);
    expect(document.body).toHaveClass("el-resizing");
    fireEvent.pointerMove(edge(), { clientX: 340 });
    expect(panel().style.getPropertyValue("--el-test-width")).toBe("280px");
    expect(edge()).toHaveAttribute("aria-valuenow", "280");
    fireEvent.pointerMove(edge(), { clientX: 900 });
    expect(panel().style.getPropertyValue("--el-test-width")).toBe("360px");
    expect(onWidth).not.toHaveBeenCalled();
    fireEvent.pointerUp(edge(), { clientX: 900 });
    expect(onWidth.mock.calls).toEqual([[360]]);
    expect(document.body).not.toHaveClass("el-resizing");
    // The usual width had no inline value: none is left behind for the app to fight.
    expect(panel().style.getPropertyValue("--el-test-width")).toBe("");
  });

  it("dragged below 160 px the panel fades, and let go it closes, keeping its width", () => {
    const onWidth = vi.fn();
    const onClose = vi.fn();
    render(<Panel width={300} saved={300} onWidth={onWidth} onClose={onClose} />);
    fireEvent.pointerDown(edge(), { button: 0, clientX: 300 });
    fireEvent.pointerMove(edge(), { clientX: 150 });
    expect(panel()).toHaveAttribute("data-closing", "true");
    expect(panel().style.getPropertyValue("--el-test-width")).toBe("200px");
    fireEvent.pointerMove(edge(), { clientX: 250 });
    expect(panel()).not.toHaveAttribute("data-closing");
    fireEvent.pointerMove(edge(), { clientX: 130 });
    fireEvent.pointerUp(edge(), { clientX: 130 });
    expect(onClose).toHaveBeenCalledTimes(1);
    expect(onWidth).not.toHaveBeenCalled();
    expect(panel().style.getPropertyValue("--el-test-width")).toBe("300px");
    expect(panel()).not.toHaveAttribute("data-closing");
  });

  it("a cancelled drag puts everything back and tells nothing", () => {
    const onWidth = vi.fn();
    render(<Panel width={260} saved={260} onWidth={onWidth} />);
    fireEvent.pointerDown(edge(), { button: 0, clientX: 300 });
    fireEvent.pointerMove(edge(), { clientX: 340 });
    fireEvent.pointerCancel(edge());
    expect(panel().style.getPropertyValue("--el-test-width")).toBe("260px");
    expect(edge()).toHaveAttribute("aria-valuenow", "260");
    expect(onWidth).not.toHaveBeenCalled();
    fireEvent.pointerMove(edge(), { clientX: 400 });
    expect(panel().style.getPropertyValue("--el-test-width")).toBe("260px");
  });

  it("a drag that ends with no up or cancel (the capture lost, the window left) is a cancel: nothing of it stays", () => {
    const onWidth = vi.fn();
    for (const end of [() => fireEvent(edge(), new Event("lostpointercapture")), () => fireEvent(window, new Event("blur"))]) {
      render(<Panel width={260} saved={260} onWidth={onWidth} />);
      fireEvent.pointerDown(edge(), { button: 0, clientX: 300 });
      fireEvent.pointerMove(edge(), { clientX: 340 });
      expect(document.body).toHaveClass("el-resizing");
      end();
      expect(document.body).not.toHaveClass("el-resizing");
      expect(panel().style.getPropertyValue("--el-test-width")).toBe("260px");
      fireEvent.pointerMove(edge(), { clientX: 400 });
      fireEvent.pointerUp(edge(), { clientX: 400 });
      expect(panel().style.getPropertyValue("--el-test-width")).toBe("260px", "its listeners are gone");
      cleanup();
    }
    expect(onWidth).not.toHaveBeenCalled();
  });

  it("an edge that goes away mid-drag (another place, a narrower window) ends its drag", () => {
    const onWidth = vi.fn();
    const view = render(<Panel width={260} onWidth={onWidth} />);
    const gone = edge();
    fireEvent.pointerDown(gone, { button: 0, clientX: 300 });
    fireEvent.pointerMove(gone, { clientX: 340 });
    expect(document.body).toHaveClass("el-resizing");
    view.unmount();
    expect(document.body).not.toHaveClass("el-resizing");
    fireEvent.pointerUp(gone, { clientX: 340 });
    fireEvent(window, new Event("blur"));
    expect(onWidth).not.toHaveBeenCalled();
  });

  it("from the keyboard: ←/→ 8 px, Shift 32 px, Home and End the ends, Enter or a double click the usual width", () => {
    const onWidth = vi.fn();
    const onReset = vi.fn();
    render(<Panel width={240} onWidth={onWidth} onReset={onReset} />);
    fireEvent.keyDown(edge(), { key: "ArrowRight" });
    fireEvent.keyDown(edge(), { key: "ArrowLeft", shiftKey: true });
    fireEvent.keyDown(edge(), { key: "Home" });
    fireEvent.keyDown(edge(), { key: "End" });
    expect(onWidth.mock.calls).toEqual([[248], [208], [200], [360]]);
    fireEvent.keyDown(edge(), { key: "Enter" });
    fireEvent.doubleClick(edge());
    expect(onReset).toHaveBeenCalledTimes(2);
  });

  it("right to left the panel's end is on the left: the arrows and the drag mirror", () => {
    document.documentElement.dir = "rtl";
    const onWidth = vi.fn();
    render(<div dir="rtl"><Panel width={240} onWidth={onWidth} /></div>);
    fireEvent.keyDown(edge(), { key: "ArrowLeft" });
    expect(onWidth).toHaveBeenLastCalledWith(248);
    fireEvent.pointerDown(edge(), { button: 0, clientX: 500 });
    fireEvent.pointerMove(edge(), { clientX: 460 });
    expect(panel().style.getPropertyValue("--el-test-width")).toBe("280px");
    fireEvent.pointerUp(edge(), { clientX: 460 });
    expect(onWidth).toHaveBeenLastCalledWith(280);
  });
});
