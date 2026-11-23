// With the Library's sections the sidebar's foot stays on screen; in a short window (under SHORT_BELOW) it packs tighter
// so the middle keeps its room: Activity and Settings join the tools as icons, the workspace and the avatar share a
// row. Every button keeps its name (review LF-desktop item 8).
import { afterEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { Shell, SHORT_BELOW } from "./Shell";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const setHeight = (height: number) => {
  Object.defineProperty(window, "innerHeight", { value: height, configurable: true, writable: true });
  window.dispatchEvent(new Event("resize"));
};

function shell(onGo = vi.fn()) {
  render(
    <Shell tab="library" onGo={onGo} status={{ kind: "ready", text: "Ready" }} activity={3} onHelp={() => undefined} onRail={() => undefined}
      me={{ name: "Ayesha Khan", email: "ayesha@example.test", plan: null }}
      workspace={{ name: "Acme team", role: "Owner", others: [], onManage: () => undefined }}
      library={<p>The Library's sections</p>} footStart={<p>Trash</p>}><p /></Shell>,
  );
  return onGo;
}
const root = () => document.querySelector(".el-shell")!;

describe("the sidebar's foot in a short window", () => {
  afterEach(() => setHeight(768));

  it(`under ${SHORT_BELOW} px tall it packs tighter, each button still named; taller, one row each`, () => {
    setHeight(720);
    const onGo = shell();
    expect(root()).toHaveClass("el-shell--short");
    // Activity and Settings sit with the tools; their names (and Activity's count) are still theirs.
    const actions = document.querySelector(".el-shell__actions")!;
    expect(actions.contains(screen.getByRole("button", { name: "Activity, 3" }))).toBe(true);
    expect(actions.contains(screen.getByRole("button", { name: "Settings" }))).toBe(true);
    expect(actions.contains(screen.getByRole("button", { name: "Help & shortcuts" }))).toBe(true);
    // The workspace and the avatar share a row.
    const who = document.querySelector(".el-shell__who")!;
    expect(who.contains(screen.getByRole("button", { name: /Ayesha Khan/ }))).toBe(true);
    expect(who.children).toHaveLength(2);
    fireEvent.click(screen.getByRole("button", { name: "Settings" }));
    expect(onGo).toHaveBeenCalledWith("settings");
    act(() => setHeight(SHORT_BELOW));
    expect(root()).not.toHaveClass("el-shell--short");
  });
});
