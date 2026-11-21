// The owner's half-screen window (2.0.2): with a recording open on a narrow page the recording takes the list's place,
// with Back; titles keep their own direction; the player's height is the scroll padding of what scrolls behind it; and
// the stylesheet rules behind each fix (jsdom can't lay out, so scripts/widthcheck.mjs pictures the real window).
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { LibraryView } from "./LibraryView";
import { PlayerDock } from "./TranscriptTools";
import { flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";
import { COLUMN_CONTENT } from "../../lib/shell";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "../../styles.css"), "utf8");
let rules: CSSStyleRule[] = [];
beforeAll(() => {
  const style = document.createElement("style");
  style.textContent = css;
  document.head.appendChild(style);
  rules = Array.from((style.sheet as CSSStyleSheet).cssRules).filter((r): r is CSSStyleRule => r instanceof CSSStyleRule);
});
/** The value the last rule naming this selector gives a property. */
const prop = (selector: string, name: string) =>
  rules.filter((r) => r.selectorText.split(",").map((s) => s.trim()).includes(selector)).map((r) => r.style.getPropertyValue(name).trim()).filter(Boolean).pop() ?? "";

const ITEMS = [
  makeItem("a", { title: "Zoom with Acme", transcript: { final_status: "completed", final_transcript_id: "tr-a", live_available: false, preview: null } }),
  makeItem("u", { title: "ادائیگی کا منصوبہ اور قرض کی واپسی", transcript: { final_status: "completed", final_transcript_id: "tr-a", live_available: false, preview: null } }),
  makeItem("m", { title: "Payment plan میں loan کی واپسی", transcript: { final_status: "completed", final_transcript_id: "tr-a", live_available: false, preview: null } }),
];

beforeEach(() => {
  resetTauriMock();
  window.localStorage.clear();
  respond("library_list", ITEMS);
  respond("library_refresh", ITEMS);
  respond("transcript_detail", makeTranscript({ id: "tr-a" }));
  respond("session_detail", {});
  respond("playback_url", "https://audio.example.test/rec.wav");
  respond("pending_list", []);
  respond("upload_list", []);
  respond("list_clients", { data: [] });
});
afterEach(async () => {
  await flushAsync();
});

async function renderLibrary() {
  render(<LibraryView notify={vi.fn()} />);
  await flushAsync();
  return within(screen.getByTestId("library-list"));
}

/** The Library inside the window's frame: `.app.narrow` as MainApp sets it on a page under 798 px. */
async function renderNarrow(narrow = true) {
  render(<div className={`app ${narrow ? "narrow" : ""}`}><main className="content page-library"><LibraryView notify={vi.fn()} /></main></div>);
  await flushAsync();
  return within(screen.getByTestId("library-list"));
}
const shownNow = (el: Element | null) => !!el && getComputedStyle(el).display !== "none";
const open = async (list: ReturnType<typeof within>, title: string) => {
  fireEvent.click(list.getByText(title).closest("button")!);
  await flushAsync();
};

describe("a recording on a narrow page (the half-screen window)", () => {
  it("takes the list's place, with Back focused; Back brings the list back with focus on its row", async () => {
    const list = await renderNarrow();
    expect(shownNow(screen.getByTestId("library-list"))).toBe(true);
    // Nothing open: the list is the page, no empty "Select a recording" box under it.
    expect(shownNow(document.querySelector(".detail"))).toBe(false);
    await open(list, "Zoom with Acme");
    expect(shownNow(screen.getByTestId("library-list"))).toBe(false);
    expect(shownNow(document.querySelector(".library-toolbar"))).toBe(false);
    const back = screen.getByRole("button", { name: "Back" });
    expect(back).toHaveFocus();
    fireEvent.click(back);
    await flushAsync();
    expect(shownNow(screen.getByTestId("library-list"))).toBe(true);
    expect(document.querySelector('[data-row="a"]')).toHaveFocus();
  });

  it("Esc goes back to the list the same way, and J / K do nothing while the list is out of sight", async () => {
    const list = await renderNarrow();
    await open(list, "Zoom with Acme");
    fireEvent.keyDown(document.body, { key: "j" });
    fireEvent.keyDown(document.body, { key: "k" });
    await flushAsync();
    expect(document.querySelector(".item.cursor")).toBeNull();
    expect(screen.getByRole("button", { name: "Back" })).toHaveFocus();
    fireEvent.keyDown(document.body, { key: "Escape" });
    await flushAsync();
    expect(shownNow(screen.getByTestId("library-list"))).toBe(true);
    expect(document.querySelector('[data-row="a"]')).toHaveFocus();
    // With the list back, J moves again.
    fireEvent.keyDown(document.body, { key: "j" });
    expect(document.querySelector(".item.cursor")).not.toBeNull();
  });

  it("in Table view Esc returns focus to the row's title (a table row itself can't take focus)", async () => {
    window.localStorage.setItem("echoledger-library-layout", "table");
    const list = await renderNarrow();
    fireEvent.click(list.getByRole("button", { name: "Zoom with Acme" }));
    await flushAsync();
    fireEvent.keyDown(document.body, { key: "Escape" });
    await flushAsync();
    expect(document.querySelector('[data-row="a"] .lib-title')).toHaveFocus();
  });

  it("in Shared with me Esc goes back to the list too, with focus on the card's Open", async () => {
    respond("shared_with_me", { data: [{ id: "01JDSHARE1", kind: "session", from: { name: "Imran" }, note: null, title: "Call with Omar", sent_at: null, saved_at: null, client: null,
      session: makeItem("other-1", { title: "Call with Omar" }) }] });
    render(<div className="app narrow"><main className="content page-library"><LibraryView notify={vi.fn()} smartFolder="shared" /></main></div>);
    await flushAsync();
    fireEvent.click(screen.getByRole("button", { name: "Open" }));
    await flushAsync();
    expect(shownNow(screen.getByTestId("library-list"))).toBe(false);
    expect(screen.getByRole("button", { name: "Back" })).toHaveFocus();
    fireEvent.keyDown(document.body, { key: "Escape" });
    await flushAsync();
    expect(shownNow(screen.getByTestId("library-list"))).toBe(true);
    const openButton = screen.getByRole("button", { name: "Open" });
    expect(openButton).toHaveFocus();
    expect(openButton).toHaveAttribute("aria-pressed", "false");
  });

  it("keeps Back in a bar that sticks under the top bar, with the title once the header has scrolled under it (D-165)", async () => {
    const list = await renderNarrow();
    await open(list, "Zoom with Acme");
    const bar = document.querySelector(".item-bar")!;
    expect(within(bar as HTMLElement).getByRole("button", { name: "Back" })).toBeInTheDocument();
    const title = bar.querySelector(".item-bar-title")!;
    expect(title).toHaveTextContent("Zoom with Acme");
    expect(title).toHaveAttribute("dir", "auto");
    expect(bar).not.toHaveClass("titled");
    // The header's title scrolls up past the bar's bottom edge: the bar shows the title.
    vi.spyOn(bar, "getBoundingClientRect").mockReturnValue({ top: 0, bottom: 48, height: 48 } as DOMRect);
    vi.spyOn(document.querySelector(".detail .detail-title")!, "getBoundingClientRect").mockReturnValue({ top: -20, bottom: 10, height: 30 } as DOMRect);
    fireEvent.scroll(document.querySelector(".content")!);
    await flushAsync();
    expect(bar).toHaveClass("titled");
    expect(prop(".app.narrow .library.has-open .item-bar", "position")).toBe("sticky");
    expect(prop(".app.narrow .library.has-open .item-bar", "background")).toBe("var(--surface)");
  });

  it("gives the bar's height to the page's scroll padding and stacks the find bar under it", async () => {
    const rect = vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockReturnValue({ height: 44.2 } as DOMRect);
    const list = await renderNarrow();
    await open(list, "Zoom with Acme");
    expect(document.documentElement.style.getPropertyValue("--item-bar-h")).toBe("45px");
    expect(prop(".app.narrow .content.page-library", "scroll-padding-block-start")).toContain("var(--item-bar-h");
    expect(prop(".app.narrow .detail .find-bar", "top")).toContain("var(--item-bar-h");
    rect.mockRestore();
  });

  it("pulls Back to the start edge in either direction (right in Urdu), not always to the left", () => {
    expect(prop(".app.narrow .library.has-open .detail-back", "margin-inline-start")).toBe("calc(-1 * var(--space-2))");
    expect(prop(".app.narrow .library.has-open .detail-back", "margin-left")).toBe("");
  });

  it("on a wide page keeps the list beside the recording, with no Back and focus left where it was", async () => {
    const list = await renderNarrow(false);
    await open(list, "Zoom with Acme");
    expect(shownNow(screen.getByTestId("library-list"))).toBe(true);
    expect(screen.queryByRole("button", { name: "Back" })).toBeNull();
  });

  it("fits the top bar in a half-screen window, and the page scrolls under sticky headers at its edge", () => {
    expect(prop(".app.narrow .topbar", "gap")).toBe("var(--space-1)");
    expect(prop(".app.narrow .library:not(.as-table) .group-head", "top")).toBe("calc(-1 * var(--space-5))");
  });

  it("leaves the nested column out until the list and a readable recording fit beside it (1280 px with the sidebar)", () => {
    // 1280 − 240 (sidebar) = 1040 px of page: the list and the recording, no third column squeezing the recording.
    expect(1280 - 240).toBeLessThan(COLUMN_CONTENT);
    expect(1440 - 240).toBeGreaterThanOrEqual(COLUMN_CONTENT);
  });
});

describe("titles keep their own direction", () => {
  it("gives every row title dir=auto, so an Urdu title is cut at its own end and a mixed one reads from its first word", async () => {
    const list = await renderLibrary();
    for (const title of ["Zoom with Acme", "ادائیگی کا منصوبہ اور قرض کی واپسی", "Payment plan میں loan کی واپسی"]) {
      expect(list.getByText(title)).toHaveAttribute("dir", "auto");
    }
    // Lined up with the row's start (the window's direction), cut at the title's own end (plaintext).
    const urdu = list.getByText("ادائیگی کا منصوبہ اور قرض کی واپسی");
    expect(getComputedStyle(urdu).textAlign).toBe("left");
    expect(getComputedStyle(urdu).unicodeBidi).toBe("plaintext");
    expect(getComputedStyle(urdu).textOverflow).toBe("ellipsis");
    document.documentElement.dir = "rtl";
    expect(getComputedStyle(urdu).textAlign).toBe("right");
    document.documentElement.dir = "";
  });
});

describe("selects with their word on them (Project, Language)", () => {
  it("draws the select itself, so WebKit keeps the room for the word and the value never sits under it", () => {
    expect(prop(".chip-select > select", "appearance")).toBe("none");
    expect(prop(".chip-select > select", "-webkit-appearance")).toBe("none");
    expect(prop(".head-chip .chip-select > select", "padding-inline-start")).toContain("var(--chip-key-w");
    // The hover colour must not drop the drawn arrow (a `background` shorthand would).
    expect(prop(".head-chip .chip-select > select:hover:not(:disabled)", "background-image")).toBe("");
  });
});

describe("sticky bars never hide what they stick over", () => {
  it("the group header gives its drawn height as the list's top scroll padding, while one is on screen", async () => {
    const rect = vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockReturnValue({ height: 57.2 } as DOMRect);
    const view = render(<LibraryView notify={vi.fn()} />);
    await flushAsync();
    expect(document.documentElement.style.getPropertyValue("--group-head-h")).toBe("58px");
    expect(getComputedStyle(screen.getByTestId("library-list")).getPropertyValue("scroll-padding-block-start")).toBe("var(--group-head-h, 0px)");
    view.unmount();
    expect(document.documentElement.style.getPropertyValue("--group-head-h")).toBe("");
    rect.mockRestore();
  });

  it("the player gives its height as the bottom scroll padding of the recording and of a narrow page, and is solid", () => {
    expect(prop(".detail", "scroll-padding-block-end")).toContain("var(--player-dock-h");
    expect(prop(".app.narrow .content.page-library", "scroll-padding-block-end")).toContain("var(--player-dock-h");
    expect(prop(".player-dock", "background")).toBe("var(--surface)");
    expect(prop(".player-dock", "backdrop-filter")).toBe("");
    const height = vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockReturnValue({ height: 96.4 } as DOMRect);
    const view = render(<PlayerDock playing={false} nowMs={0} durationMs={1000} onToggle={() => undefined} onSkip={() => undefined}>{null}</PlayerDock>);
    expect(document.documentElement.style.getPropertyValue("--player-dock-h")).toBe("97px");
    view.unmount();
    expect(document.documentElement.style.getPropertyValue("--player-dock-h")).toBe("");
    height.mockRestore();
  });
});
