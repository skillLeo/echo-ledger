// UX-07: a menu's stepping row, "Zoom  [−] 100% [+]" as in Chrome's menu: its buttons step the value and the menu stays
// open; from the keyboard on the row, − and + (and ← →, mirrored right to left) step it; Enter does nothing.
import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/react";
import { Menu } from "./Menu";

function Zoom({ down = vi.fn(), up = vi.fn(), value = "100%", atEnd = false }) {
  return (
    <Menu label="Account" items={[
      { label: "Theme", onPick: () => undefined },
      { label: "Zoom", stepper: { value, down: { label: "Zoom out", onPick: down }, up: { label: "Zoom in", onPick: up, disabled: atEnd } } },
      { label: "Sign out", onPick: () => undefined },
    ]} button={{ variant: "quiet", text: "Account" }} />
  );
}
const open = () => fireEvent.click(screen.getByRole("button", { name: "Account" }));
const menu = () => screen.getByRole("menu", { name: "Account" });
const row = () => within(menu()).getAllByRole("menuitem")[1];

describe("a menu's stepping row (UX-07)", () => {
  it("[−] value [+] at the row's end; the buttons step it and the menu stays open", () => {
    const down = vi.fn();
    const up = vi.fn();
    render(<Zoom down={down} up={up} value="110%" />);
    open();
    expect(row()).toHaveTextContent("Zoom");
    expect(row().querySelector(".el-menu__step-value")).toHaveTextContent("110%");
    const buttons = within(row()).getAllByRole("button");
    expect(buttons.map((b) => b.getAttribute("aria-label"))).toEqual(["Zoom out", "Zoom in"]);
    for (const b of buttons) expect(b).toHaveAttribute("tabindex", "-1");
    fireEvent.click(buttons[1]);
    fireEvent.click(buttons[0]);
    expect(up).toHaveBeenCalledTimes(1);
    expect(down).toHaveBeenCalledTimes(1);
    expect(menu()).toBeInTheDocument();
  });

  it("from the keyboard on the row: + and − (and → ←) step it, Enter leaves the menu open, an end's button does nothing", () => {
    const down = vi.fn();
    const up = vi.fn();
    render(<Zoom down={down} up={up} atEnd />);
    open();
    // Down to the row (the menu opens on its first item).
    for (let i = 0; i < 3 && document.activeElement !== row(); i++) fireEvent.keyDown(menu(), { key: "ArrowDown" });
    expect(row()).toHaveFocus();
    fireEvent.keyDown(menu(), { key: "-" });
    fireEvent.keyDown(menu(), { key: "ArrowLeft" });
    expect(down).toHaveBeenCalledTimes(2);
    fireEvent.keyDown(menu(), { key: "+" });
    fireEvent.keyDown(menu(), { key: "ArrowRight" });
    expect(up).not.toHaveBeenCalled();
    fireEvent.keyDown(menu(), { key: "Enter" });
    expect(menu()).toBeInTheDocument();
  });
});
