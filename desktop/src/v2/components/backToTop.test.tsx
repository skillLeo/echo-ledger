// Back to top (UX-25) as any long list uses it (a project's recordings, Activity, Shared): one component, after the
// list, inside the box that scrolls; the first row is the one its selector names.
import { describe, expect, it } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { BackToTop, List, ListRow } from ".";
import { scrollRootOf } from "./BackToTop";

function page() {
  render(
    <div data-testid="scroller" style={{ overflowY: "auto" }}>
      <div className="el-page el-back-top-room">
        <List label="Things">{Array.from({ length: 30 }, (_, i) => <ListRow key={i} title={`Thing ${i + 1}`} onClick={() => undefined} />)}</List>
        <BackToTop first=".el-row__hit" />
      </div>
    </div>,
  );
  const scroller = screen.getByTestId("scroller");
  Object.defineProperty(scroller, "clientHeight", { configurable: true, value: 400 });
  return scroller;
}

describe("Back to top", () => {
  it("finds the box that scrolls; shows after one screen; a press goes to the top with focus on the first row", () => {
    const scroller = page();
    expect(scrollRootOf(screen.getByRole("list", { name: "Things" }))).toBe(scroller);
    expect(screen.queryByRole("button", { name: "Back to top" })).toBeNull();
    scroller.scrollTop = 401;
    fireEvent.scroll(scroller);
    const button = screen.getByRole("button", { name: "Back to top" });
    expect(button).toHaveAttribute("aria-keyshortcuts", "Home Meta+ArrowUp Control+Home");
    fireEvent.click(button);
    expect(scroller.scrollTop).toBe(0);
    expect(screen.getByRole("button", { name: "Thing 1" })).toHaveFocus();
    expect(screen.queryByRole("button", { name: "Back to top" })).toBeNull();
  });

  it("Home from a row reaches the top; a key in a menu or with Shift is left alone", () => {
    const scroller = page();
    scroller.scrollTop = 900;
    fireEvent.keyDown(screen.getByRole("button", { name: "Thing 20" }), { key: "Home", shiftKey: true });
    expect(scroller.scrollTop).toBe(900);
    fireEvent.keyDown(screen.getByRole("button", { name: "Thing 20" }), { key: "Home" });
    expect(scroller.scrollTop).toBe(0);
  });
});
