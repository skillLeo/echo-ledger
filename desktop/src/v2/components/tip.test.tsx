// DES-01: a tooltip after 500 ms of hover or on keyboard focus, with the name and the shortcut; Esc closes it.
import { afterEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { TIP_DELAY_MS, useTip } from "./Tip";

function Item() {
  const [tipProps, tip] = useTip("Library ⌘1");
  return <><button type="button" aria-label="Library" {...tipProps}>▤</button>{tip}</>;
}

describe("a tooltip (DES-01)", () => {
  afterEach(() => vi.useRealTimers());

  it("shows after 500 ms of hover, not before, and goes when the pointer leaves", () => {
    vi.useFakeTimers();
    expect(TIP_DELAY_MS).toBe(500);
    render(<Item />);
    const button = screen.getByRole("button", { name: "Library" });
    fireEvent.pointerEnter(button);
    act(() => vi.advanceTimersByTime(499));
    expect(screen.queryByRole("tooltip")).toBeNull();
    act(() => vi.advanceTimersByTime(1));
    expect(screen.getByRole("tooltip").textContent).toBe("Library ⌘1");
    fireEvent.pointerLeave(button);
    expect(screen.queryByRole("tooltip")).toBeNull();
    // Left before the delay: nothing shows.
    fireEvent.pointerEnter(button);
    act(() => vi.advanceTimersByTime(200));
    fireEvent.pointerLeave(button);
    act(() => vi.advanceTimersByTime(600));
    expect(screen.queryByRole("tooltip")).toBeNull();
  });

  it("shows at once on keyboard focus; Esc closes it and leaves focus where it is", () => {
    render(<Item />);
    const button = screen.getByRole("button", { name: "Library" });
    act(() => button.focus());
    fireEvent.focus(button);
    expect(screen.getByRole("tooltip")).toBeInTheDocument();
    fireEvent.keyDown(button, { key: "Escape" });
    expect(screen.queryByRole("tooltip")).toBeNull();
    expect(button).toHaveFocus();
    fireEvent.blur(button);
    expect(screen.queryByRole("tooltip")).toBeNull();
  });

  it("a press hides it", () => {
    vi.useFakeTimers();
    render(<Item />);
    const button = screen.getByRole("button", { name: "Library" });
    fireEvent.pointerEnter(button);
    act(() => vi.advanceTimersByTime(500));
    fireEvent.pointerDown(button);
    expect(screen.queryByRole("tooltip")).toBeNull();
  });
});
