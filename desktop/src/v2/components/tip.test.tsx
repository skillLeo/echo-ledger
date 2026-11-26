// DES-01: a tooltip after 500 ms of hover or on keyboard focus, with the name and the shortcut; Esc closes it.
import { afterEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
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

// D-260: Nastaliq rose above the 24 px tooltip (12/16 with 4 px padding) and lost its top. In Urdu a tooltip takes
// urdu.caption and more room on top; scripts/tipNastaliq.mjs measures the drawn ink inside the box. English is as it was.
describe("a tooltip's room for Nastaliq (D-260)", () => {
  const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "..", "styles", "v2.css"), "utf8");
  it("English keeps 12/16 with 4 px above and below", () => {
    const rule = css.match(/\n\.el-tip \{[^}]*\}/)?.[0] ?? "";
    expect(rule).toContain("padding: var(--el-space-4) var(--el-space-8);");
    expect(rule).toContain("font-size: var(--el-desktop-text-small-size); line-height: var(--el-desktop-text-small-line);");
  });
  it("Urdu takes urdu.caption (14/28) and 8 px on top, 4 px below", () => {
    const rule = css.match(/:lang\(ur\) \.el-tip \{[^}]*\}/)?.[0] ?? "";
    expect(rule).toContain("font-size: var(--el-text-urdu-caption-size); line-height: var(--el-text-urdu-caption-line);");
    expect(rule).toContain("padding-block: var(--el-space-8) var(--el-space-4);");
  });
});
