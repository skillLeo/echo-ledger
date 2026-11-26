import { describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { SplitButton } from "./SplitButton";
import { copyMenuItems } from "./copyMenu";
import { CopyIcon } from "./icons";

const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "..", "styles", "v2.css"), "utf8");

/** A key on a focused button as the browser takes it: Enter and Space press it unless a handler prevented that. */
function press(el: HTMLElement, key: "Enter" | " ") {
  el.focus();
  const down = fireEvent.keyDown(el, { key });
  const up = fireEvent.keyUp(el, { key });
  if (down && up && el instanceof HTMLButtonElement && !el.disabled) fireEvent.click(el);
}

function setup(extra: Partial<Parameters<typeof SplitButton>[0]> = {}) {
  const copyText = vi.fn();
  const withTimestamps = vi.fn();
  const forAi = vi.fn();
  const view = render(
    <SplitButton label="Copy" icon={CopyIcon} onClick={copyText} mainLabel="Copy text" menuLabel="More copy options"
      items={copyMenuItems({ text: copyText, withTimestamps, forAi })} {...extra} />,
  );
  return { copyText, withTimestamps, forAi, view, main: screen.getByRole("button", { name: "Copy text" }), more: screen.getByRole("button", { name: "More copy options" }) };
}

describe("SplitButton", () => {
  it("the main part runs its action and doesn't open the menu", () => {
    const { copyText, main, more } = setup();
    fireEvent.click(main);
    expect(copyText).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole("menu")).toBeNull();
    expect(more).toHaveAttribute("aria-expanded", "false");
  });

  it("the chevron opens the shared menu, named by its label, anchored to the whole button", () => {
    const { more, copyText, view } = setup();
    fireEvent.click(more);
    const menu = screen.getByRole("menu", { name: "More copy options" });
    expect(more).toHaveAttribute("aria-expanded", "true");
    expect(more).toHaveAttribute("aria-controls", menu.id);
    expect(copyText).not.toHaveBeenCalled();
    // The menu floats in its own layer above the page (it is never cut off by a panel), and is placed from the
    // split's own anchor, the whole button, so it lines up with both parts: under it, from its start edge.
    const split = view.container.querySelector(".el-split")!;
    expect(split).toHaveClass("el-menu-anchor");
    expect(split.contains(menu)).toBe(false);
    expect(menu.closest(".el-layer")).not.toBeNull();
    expect(menu).toHaveClass("el-menu--floating");
    expect(screen.getAllByRole("menuitem").map((i) => i.textContent)).toEqual(["Copy text", "Copy with timestamps", "Copy for AI"]);
    fireEvent.click(more);
    expect(screen.queryByRole("menu")).toBeNull();
  });

  it("the menu is placed from the whole split: under it, its start edge under the split's start", () => {
    const { more, view } = setup();
    const split = view.container.querySelector(".el-split")!;
    const rect = (left: number, top: number, width: number, height: number) => ({ left, top, width, height, right: left + width, bottom: top + height, x: left, y: top, toJSON: () => ({}) }) as DOMRect;
    const spy = vi.spyOn(Element.prototype, "getBoundingClientRect").mockImplementation(function (this: Element) {
      if (this === split) return rect(300, 100, 120, 36);
      if (this.matches("[role=menu]")) return rect(0, 0, 224, 120);
      return rect(0, 0, 0, 0);
    });
    try {
      fireEvent.click(more);
      const menu = screen.getByRole("menu", { name: "More copy options" });
      expect(menu.style.left).toBe("300px");
      expect(menu.style.top).toBe("140px");
    } finally {
      spy.mockRestore();
    }
  });

  it("a pick closes the menu, gives focus back to the chevron and runs the action", () => {
    const { more, withTimestamps } = setup();
    fireEvent.click(more);
    fireEvent.click(screen.getByRole("menuitem", { name: "Copy with timestamps" }));
    expect(withTimestamps).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole("menu")).toBeNull();
    expect(more).toHaveFocus();
  });

  it("Enter and Space press each part", () => {
    const { main, more, copyText } = setup();
    press(main, "Enter");
    press(main, " ");
    expect(copyText).toHaveBeenCalledTimes(2);
    expect(screen.queryByRole("menu")).toBeNull();
    press(more, "Enter");
    expect(screen.getByRole("menu")).toBeInTheDocument();
    fireEvent.keyDown(screen.getByRole("menu"), { key: "Escape" });
    expect(screen.queryByRole("menu")).toBeNull();
    expect(more).toHaveFocus();
    press(more, " ");
    expect(screen.getByRole("menu")).toBeInTheDocument();
    expect(copyText).toHaveBeenCalledTimes(2);
  });

  it("ArrowDown and ArrowUp on the chevron open the menu on the first or last item; a press outside closes it", async () => {
    const { more } = setup();
    fireEvent.keyDown(more, { key: "ArrowUp" });
    await act(async () => undefined);
    expect(screen.getByRole("menuitem", { name: "Copy for AI" })).toHaveFocus();
    fireEvent.pointerDown(document.body);
    expect(screen.queryByRole("menu")).toBeNull();
    fireEvent.keyDown(more, { key: "ArrowDown" });
    await act(async () => undefined);
    expect(screen.getByRole("menuitem", { name: "Copy text" })).toHaveFocus();
  });

  it("has the aria the two parts need, both as native buttons", () => {
    const { main, more } = setup();
    for (const part of [main, more]) {
      expect(part.tagName).toBe("BUTTON");
      expect(part).toHaveAttribute("type", "button");
    }
    expect(main).not.toHaveAttribute("aria-haspopup");
    expect(main).toHaveTextContent("Copy");
    expect(more).toHaveAttribute("aria-haspopup", "menu");
    expect(more).toHaveAttribute("aria-expanded", "false");
    expect(more).not.toHaveAttribute("aria-controls");
  });

  it("the main part is named by its words when no mainLabel is given", () => {
    render(<SplitButton label="Copy" onClick={() => undefined} menuLabel="More copy options" items={[]} />);
    expect(screen.getByRole("button", { name: "Copy" })).toBeInTheDocument();
  });

  it("disables both parts", () => {
    const { main, more, copyText } = setup({ disabled: true });
    expect(main).toBeDisabled();
    expect(more).toBeDisabled();
    fireEvent.click(main);
    fireEvent.click(more);
    expect(copyText).not.toHaveBeenCalled();
    expect(screen.queryByRole("menu")).toBeNull();
  });

  it("keeps main then chevron in the document in right to left, and mirrors by logical properties only", () => {
    const { view } = render(
      <div dir="rtl" lang="ur">
        <SplitButton label="کاپی" icon={CopyIcon} onClick={() => undefined} mainLabel="متن کاپی کریں" menuLabel="کاپی کے مزید طریقے" items={copyMenuItems({ text: () => undefined })} />
      </div>,
    ) && { view: document.body };
    const split = view.querySelector(".el-split")!;
    const parts = [...split.querySelectorAll(":scope > button")];
    expect(parts.map((b) => b.getAttribute("aria-label"))).toEqual(["متن کاپی کریں", "کاپی کے مزید طریقے"]);
    // The chevron's side follows the direction: the CSS names start and end, never left and right.
    const block = css.slice(css.indexOf("/* ---- SplitButton"), css.indexOf("/* ----", css.indexOf("/* ---- SplitButton") + 10));
    expect(block).toContain("border-start-start-radius: var(--el-component-button-radius); border-end-start-radius: var(--el-component-button-radius);");
    expect(block).toContain("border-start-start-radius: 0; border-end-start-radius: 0;");
    expect(block).not.toMatch(/\b(left|right)\b/);
    expect(block).not.toMatch(/border-(top|bottom)-(left|right)-radius/);
    expect(block).toContain("min-block-size: calc(var(--el-space-40) + var(--el-space-4))");
    // The chevron isn't a directional glyph: it points down in both directions.
    expect(split.querySelector(".el-split__more .el-icon")).not.toHaveClass("el-icon--directional");
  });
});
