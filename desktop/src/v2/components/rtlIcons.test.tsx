// D-231: in a right-to-left window the glyphs that point a way or read as text mirror like every chevron and arrow
// (sort, sign out, send, Ask's bubble, help's "?", which Urdu writes ؟), wherever they are drawn: a button, a row, a
// menu, the rail, an empty page. The media player's skip back and forward never do. The mirroring itself is v2.css's
// [dir="rtl"] .el-icon--directional > svg { transform: scaleX(-1) }, so a left-to-right window shows them as they are.
import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { Button, EmptyState, Icon, ListRow, NavItem } from "./index";
import * as Icons from "./icons";
import { SortGroupMenu } from "./SortGroupMenu";

const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "..", "styles", "v2.css"), "utf8");
const mirrored = (el: Element | null) => !!el?.closest(".el-icon")?.classList.contains("el-icon--directional");
const iconIn = (el: HTMLElement) => el.querySelector(".el-icon svg");

describe("D-231: way-pointing glyphs mirror right to left", () => {
  it("sort, sign out, send, Ask and help carry the directional class; the media skips and plain glyphs don't", () => {
    const glyphs = { SortIcon: Icons.SortIcon, SignOutIcon: Icons.SignOutIcon, SendIcon: Icons.SendIcon, AskIcon: Icons.AskIcon, HelpIcon: Icons.HelpIcon };
    for (const [name, glyph] of Object.entries(glyphs)) {
      const { container, unmount } = render(<Icon icon={glyph} />);
      expect(container.firstElementChild, name).toHaveClass("el-icon--directional");
      unmount();
    }
    for (const glyph of [Icons.BackIcon, Icons.ForwardIcon, Icons.SearchIcon, Icons.RecordIcon, Icons.PlayIcon]) {
      const { container, unmount } = render(<Icon icon={glyph} />);
      expect(container.firstElementChild).not.toHaveClass("el-icon--directional");
      unmount();
    }
    expect(css).toContain('[dir="rtl"] .el-icon--directional > svg { transform: scaleX(-1); }');
  });

  it("wherever they are drawn: a button, a row, the rail's item, an empty page, the sort menu", () => {
    render(<>
      <Button icon={Icons.SendIcon} onClick={vi.fn()}>Send to WhatsApp…</Button>
      <ul><ListRow compact icon={Icons.SignOutIcon} iconStyle="plain" title="Sign out" onClick={vi.fn()} /></ul>
      <ul><NavItem icon={Icons.HelpIcon} label="Help & shortcuts" onClick={vi.fn()} /></ul>
      <EmptyState icon={Icons.HelpIcon} title="No guides yet" />
      <SortGroupMenu value={{ sort: "newest", group: "none", density: "comfortable" }} onChange={vi.fn()}
        options={{ sort: [{ value: "newest", label: "Newest" }], group: [{ value: "none", label: "None" }], density: [{ value: "comfortable", label: "Comfortable" }] }} />
    </>);
    expect(mirrored(iconIn(screen.getByRole("button", { name: "Send to WhatsApp…" })))).toBe(true);
    expect(mirrored(iconIn(screen.getByRole("button", { name: "Sign out" }).closest("li")!))).toBe(true);
    expect(mirrored(iconIn(screen.getByRole("button", { name: "Help & shortcuts" })))).toBe(true);
    expect(mirrored(iconIn(screen.getByText("No guides yet").closest(".el-empty")! as HTMLElement))).toBe(true);
    const sort = document.querySelector(".el-sortgroup") as HTMLElement;
    expect(mirrored(iconIn(sort))).toBe(true);
  });
});
