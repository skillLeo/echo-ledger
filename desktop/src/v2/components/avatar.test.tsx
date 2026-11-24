// DES-08 (PLAN_V2_1_POLISH): one avatar everywhere. No photo: the initials on a solid circle in one of the tokens' 8
// colours (the server's avatar_color, else one worked out from who it is), never grey or white, never a bare letter.
// A photo fills the circle; a photo that fails shows the initials. Every avatar has a 1 px inner ring.
import { describe, expect, it } from "vitest";
import { fireEvent, render } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { AVATAR_COLOURS, Avatar, avatarColour } from "./layout";
import { initials } from "../settings/initials";

const css = readFileSync(join(__dirname, "../styles/v2.css"), "utf8");

describe("the avatar (DES-08)", () => {
  it("initials: the first letters of the first and last name, one letter for one word, else the email's", () => {
    expect(initials("Saif Hassam", "s@x.com")).toBe("SH");
    expect(initials("Saif", "s@x.com")).toBe("S");
    expect(initials("", "zara@x.com")).toBe("Z");
  });

  it("without a photo: the initials on one of the 8 colours, the server's when it gives one", () => {
    expect(AVATAR_COLOURS).toBe(8);
    const { container, rerender } = render(<Avatar initials="SH" color={5} />);
    const circle = container.querySelector(".el-avatar")!;
    expect(circle.textContent).toBe("SH");
    expect(circle.getAttribute("data-colour")).toBe("5");
    // No colour from the server: one from who it is, the same every time and always one of the 8.
    rerender(<Avatar initials="SH" seed="saif@example.com" />);
    const first = container.querySelector(".el-avatar")!.getAttribute("data-colour");
    rerender(<Avatar initials="SH" seed="saif@example.com" />);
    expect(container.querySelector(".el-avatar")!.getAttribute("data-colour")).toBe(first);
    for (const seed of ["a", "bb", "ccc", "Ayesha Siddiqui", "عائشہ", ""]) expect(avatarColour(null, seed)).toBeGreaterThanOrEqual(0);
    expect(new Set(["a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k", "l"].map((s) => avatarColour(undefined, s))).size).toBeGreaterThan(4);
    // Out of range from the server: worked out instead.
    expect(avatarColour(9, "x")).toBe(avatarColour(null, "x"));
    expect(avatarColour(-1, "x")).toBe(avatarColour(null, "x"));
  });

  it("each colour is a token, with white initials, and there is no grey circle left", () => {
    for (let n = 0; n < AVATAR_COLOURS; n++) {
      expect(css).toContain(`.el-avatar[data-colour="${n}"] { background: var(--el-role-avatar-${n}); }`);
    }
    expect(css).toMatch(/\.el-avatar \{[^}]*color: var\(--el-role-avatar-text\)/);
    expect(css).not.toMatch(/\.el-avatar \{[^}]*background: var\(--el-role-bg-subtle\)/);
    expect(css).not.toContain(".el-sidebar .el-avatar { background: var(--el-role-border-default); }");
    // The inner ring, over a photo too.
    expect(css).toMatch(/\.el-avatar::after \{[^}]*box-shadow: inset 0 0 0 var\(--el-desktop-avatar-ring\) var\(--el-role-avatar-ring\)/);
  });

  it("a photo fills the circle; one that fails to load shows the initials, never a broken image", () => {
    const { container } = render(<Avatar initials="SH" photo="data:image/png;base64,AAAA" color={1} />);
    const img = container.querySelector(".el-avatar img")!;
    expect(img).toBeInTheDocument();
    expect(img.getAttribute("alt")).toBe("");
    expect(container.querySelector(".el-avatar")!.textContent).toBe("");
    fireEvent.error(img);
    expect(container.querySelector(".el-avatar img")).toBeNull();
    expect(container.querySelector(".el-avatar")!.textContent).toBe("SH");
  });

  it("comes in the plan's sizes: rail 28, lists 32, the account menu 40, the profile 96", () => {
    for (const size of ["rail", "list", "menu", "profile"] as const) {
      const { container, unmount } = render(<Avatar initials="SH" size={size} />);
      expect(container.querySelector(".el-avatar")).toHaveClass(`el-avatar--${size}`);
      unmount();
    }
    expect(css).toContain(".el-avatar--rail { inline-size: var(--el-desktop-avatar-rail); block-size: var(--el-desktop-avatar-rail);");
    expect(css).toContain(".el-avatar--menu { inline-size: var(--el-desktop-avatar-menu); block-size: var(--el-desktop-avatar-menu);");
    expect(css).toContain(".el-avatar--profile { inline-size: var(--el-desktop-avatar-profile); block-size: var(--el-desktop-avatar-profile);");
  });

  it("is hidden from assistive tools: the name is said by what holds it", () => {
    const { container } = render(<Avatar initials="SH" />);
    expect(container.querySelector(".el-avatar")).toHaveAttribute("aria-hidden", "true");
  });
});
