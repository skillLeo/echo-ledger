// The Library row (D12): the indicator slot, the end column as left-to-right isolates in the row's face, the library
// variant's layout rules, and SourceMark with the voice-note mark and the ringed device chip.
import { describe, expect, it } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { List, ListRow } from "./ListRow";
import { SourceMark } from "./SourceMark";
import { LABEL_COLOURS } from "./Label";

const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "..", "styles", "v2.css"), "utf8");
const rule = (selector: string) => {
  const at = css.indexOf(`${selector} {`);
  expect(at, selector).toBeGreaterThan(-1);
  return css.slice(at, css.indexOf("}", at));
};

describe("the indicator slot", () => {
  it("shows unread, pinned, important and label dots in that order, and at most 3 dots", () => {
    const { container } = render(
      <List label="Recordings">
        <ListRow title="Check-in call" meta="Hello, can you understand?" onClick={() => undefined}
          indicators={{
            labels: [
              { name: "Acme", colour: LABEL_COLOURS.blue }, { name: "Invoice", colour: LABEL_COLOURS.amber },
              { name: "Paid", colour: LABEL_COLOURS.green }, { name: "Family", colour: LABEL_COLOURS.pink },
            ],
            important: true, pinned: true, unread: true,
          }} />
      </List>,
    );
    const slot = container.querySelector(".el-row__slot")!;
    // In order: the dot, the pin, the flag, three label dots (the fourth label isn't drawn).
    expect([...slot.children].filter((el) => el.getAttribute("aria-hidden") === "true").map((el) => el.className))
      .toEqual(["el-row__unread", "el-row__ind", "el-row__ind el-row__ind--important", "el-row__dots"]);
    expect(slot.querySelectorAll(".el-row__dots > .el-label-dot")).toHaveLength(3);
    expect(slot.querySelector(".el-row__dots .el-label--blue")).not.toBeNull();
    // The slot sits between the text and the end column, never inside the snippet.
    expect(container.querySelector(".el-row__meta .el-row__slot")).toBeNull();
    expect(slot.previousElementSibling).toHaveClass("el-row__main");
    // The row's button reads them after the snippet.
    expect(screen.getByRole("button", { name: "Check-in call" })).toHaveAccessibleDescription("Hello, can you understand? Unread Pinned Important Acme, Invoice, Paid");
  });

  it("takes no room when empty, and the row's button isn't described by a slot that isn't there", () => {
    const { container } = render(<List label="x"><ListRow title="Plain" onClick={() => undefined} indicators={{ labels: [] }} /></List>);
    expect(container.querySelector(".el-row__slot")).toBeNull();
    expect(screen.getByRole("button", { name: "Plain" })).not.toHaveAttribute("aria-describedby");
  });

  it("speaks the page's language", () => {
    render(<List label="x"><ListRow title="کال" indicators={{ pinned: true, important: true, words: { pinned: "پن شدہ", important: "اہم" } }} /></List>);
    expect(screen.getByText("پن شدہ اہم")).toHaveClass("el-visually-hidden");
  });

  it("draws 16 px glyphs and 8 px dots, 4 apart, the flag in warning", () => {
    expect(rule(".el-row__slot")).toContain("gap: var(--el-space-4)");
    expect(rule(".el-row__ind--important")).toContain("var(--el-role-warning-default)");
    expect(rule(".el-row__unread")).toContain("var(--el-component-status-dot-size)");
    const { container } = render(<ListRow title="x" indicators={{ pinned: true }} />);
    // The small icon size (.el-icon: --el-icon-sm, 16).
    expect(container.querySelector(".el-row__ind > .el-icon")).not.toHaveClass("el-icon--md");
  });
});

describe("the library variant", () => {
  const row = (extra: Record<string, unknown> = {}) => render(
    <List label="Recordings">
      <ListRow variant="library" leading={<SourceMark app="whatsapp" device="desktop" label="WhatsApp, on a computer" />} title="Check-in call: is everything fine there"
        meta="Hello, can you understand?" endTop="15 Nov" endBottom={<span className="el-num">1:19</span>} onClick={() => undefined} {...extra} />
      <ListRow variant="library" leading={<SourceMark app="voice_note" device="phone" label="Voice note, on a phone" />} title="Unclear recording"
        meta="No speech" endTop="5:09 am" endBottom="0:02" onClick={() => undefined} />
    </List>,
  ).container;

  it("puts the mark in its own cell, makes the title strong, and keeps every other row as it was", () => {
    const c = row();
    const first = c.querySelector(".el-row")!;
    expect(first).toHaveClass("el-row--library");
    expect(first.querySelector(":scope > .el-row__lead > .el-source")).not.toBeNull();
    expect(first.querySelector(".el-row__title")).toHaveClass("el-row__title--strong");
    expect(first.querySelector(".el-row__title")).toHaveAttribute("dir", "auto");
    expect(first.querySelector(".el-row__meta-text")).toHaveAttribute("dir", "auto");
    const plain = render(<ListRow title="Settings row" endTop="9:00 am" leading={<span className="x" />} />).container.querySelector(".el-row")!;
    expect(plain).not.toHaveClass("el-row--library");
    expect(plain.querySelector(".el-row__lead")).toBeNull();
    expect(plain.querySelector(".el-row__end-top")).toHaveAttribute("dir", "auto");
  });

  it("makes the date and the length left-to-right isolates on the end side", () => {
    const c = row();
    const top = c.querySelector(".el-row__end-top")!;
    const bottom = c.querySelector(".el-row__end-bottom")!;
    // The isolate is inside the cell, so the cell keeps the row's direction for its spacing and alignment.
    expect(top.firstElementChild).toHaveAttribute("dir", "ltr");
    expect(bottom.firstElementChild).toHaveAttribute("dir", "ltr");
    expect(top).not.toHaveAttribute("dir");
    expect(top).toHaveTextContent("15 Nov");
    expect(bottom).toHaveTextContent("1:19");
    expect(screen.getByRole("button", { name: "Check-in call: is everything fine there" })).toHaveAccessibleDescription("Hello, can you understand? 15 Nov 1:19");
  });

  it("lays out at 72 (56 compact) with 12 block padding, title.s over a body snippet, and caption end lines in the row's face", () => {
    const base = rule(".el-row--library");
    expect(base).toContain("min-block-size: calc(var(--el-space-64) + var(--el-space-8))");
    expect(base).toContain("padding-block: var(--el-space-12)");
    expect(base).toContain("margin-inline: var(--el-space-8)");
    expect(base).toContain("border-radius: var(--el-radius-md)");
    expect(rule(".el-row--library.el-row--compact")).toContain("min-block-size: calc(var(--el-space-48) + var(--el-space-8))");
    expect(rule(".el-row--library .el-row__title")).toContain("font-size: var(--el-text-title-s-size); line-height: var(--el-text-title-s-line)");
    expect(rule(".el-row--library .el-row__meta")).toContain("font-size: var(--el-text-body-size)");
    expect(css).toContain(".el-row--library .el-row__end .el-num { font: inherit; }");
    expect(css).toMatch(/\.el-row--library \.el-row__end-top, \.el-row--library \.el-row__end-bottom \{[^}]*font-variant-numeric: tabular-nums;/);
    // One line each, cut with an ellipsis on the element that has the text's direction.
    expect(rule(".el-row__title")).toContain("white-space: nowrap");
    expect(rule(".el-row__meta-text")).toContain("text-overflow: ellipsis");
  });

  it("draws a hairline between rows from the text's start, none after the last, and hover, focus and selection on the 8 radius", () => {
    const divider = rule(".el-row--library + .el-row--library::before");
    expect(divider).toContain("inset-inline-start: calc(var(--row-pad) + var(--el-space-40) + var(--el-space-16))");
    expect(divider).toContain("border-block-start: var(--el-border-hairline) solid var(--el-role-border-default)");
    expect(css).not.toMatch(/\.el-row--library::after/);
    expect(css).toContain(".el-row--library.el-row--interactive:hover, .el-row--library:has(> .el-row__hit:focus-visible) { background: var(--el-role-bg-subtle);");
    expect(css).toContain(".el-row--library.el-row--selected { background: var(--el-role-accent-soft);");
    const c = row({ selected: true });
    expect(c.querySelector(".el-row")).toHaveClass("el-row--selected");
  });

  it("uses logical properties only", () => {
    const block = css.slice(css.indexOf("/* The indicator slot (any row, D12)"), css.indexOf("/* ---- Chip and FilterBar"));
    expect(block.length).toBeGreaterThan(100);
    expect(block).not.toMatch(/(?<![\w-])(left|right|top|bottom|width|height|margin-(left|right)|padding-(left|right))\s*:/);
  });
});

describe("SourceMark", () => {
  it("draws a known app's logo, the voice note in the accent with the audio lines, and the neutral glyph for the rest", () => {
    render(<>
      <SourceMark app="whatsapp" device="phone" label="WhatsApp, on a phone" />
      <SourceMark app="voice_note" device="phone" label="Voice note, on a phone" />
      <SourceMark app="discord" device="web" label="Discord, on the web" />
    </>);
    const wa = screen.getByRole("img", { name: "WhatsApp, on a phone" });
    expect(wa.querySelector("svg.source-logo")).not.toBeNull();
    expect(wa).toHaveClass("el-source--logo", "el-source--md");
    const voice = screen.getByRole("img", { name: "Voice note, on a phone" });
    expect(voice).toHaveClass("el-source--voice");
    expect(voice.querySelector("svg.source-logo")).toBeNull();
    expect(voice.querySelector(":scope > .el-icon.el-icon--md > svg")).not.toBeNull();
    expect(rule(".el-source--mark.el-source--voice")).toContain("background: var(--el-role-accent-soft); color: var(--el-role-accent-text)");
    const other = screen.getByRole("img", { name: "Discord, on the web" });
    expect(other).toHaveClass("el-source--neutral");
    expect(rule(".el-source--mark.el-source--neutral")).toContain("color: var(--el-role-text-secondary)");
  });

  it("puts the device on a ringed 16 px chip, the phone as its outline, and none on the small mark", () => {
    const { container } = render(<>
      <SourceMark app="zoom" device="phone" label="a" />
      <SourceMark app="zoom" device="desktop" label="b" />
      <SourceMark app="zoom" label="c" />
      <SourceMark app="zoom" device="web" size="sm" label="d" />
    </>);
    const [phone, desktop, none, small] = [...container.querySelectorAll(".el-source")];
    expect(phone.querySelector(".el-source__device svg rect")).not.toBeNull(); // the phone outline, not a handset
    expect(desktop.querySelector(".el-source__device svg")).not.toBeNull();
    expect(phone.querySelector(".el-source__device")!.innerHTML).not.toBe(desktop.querySelector(".el-source__device")!.innerHTML);
    expect(none.querySelector(".el-source__device")).toBeNull();
    expect(small.querySelector(".el-source__device")).toBeNull();
    expect(small.querySelector("svg.source-logo")).toHaveAttribute("width", "24");
    expect(rule(".el-source--mark > .el-source__device")).toContain("0 0 0 var(--el-space-2) var(--row-bg, var(--el-role-bg-canvas))");
    expect(rule(".el-source__device")).toContain("inline-size: var(--el-space-16)");
    expect(rule(".el-source__device")).toContain("inset-inline-end: calc(-1 * var(--el-space-2))");
  });
});
