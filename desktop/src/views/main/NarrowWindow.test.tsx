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

describe("a recording on a narrow page (the half-screen window)", () => {
  it("marks the open recording so it takes the list's place, and Back brings the list back with focus on its row", async () => {
    const list = await renderLibrary();
    const library = document.querySelector(".library")!;
    expect(library).not.toHaveClass("has-open");
    fireEvent.click(list.getByText("Zoom with Acme").closest("button")!);
    await flushAsync();
    expect(library).toHaveClass("has-open");
    expect(document.querySelector(".library-main")).toHaveClass("has-open");
    // Shown by .app.narrow only (the stylesheet is loaded here, so jsdom hides it and can't name it): found by its class.
    const back = document.querySelector<HTMLButtonElement>(".detail .detail-back")!;
    expect(back.tagName).toBe("BUTTON");
    expect(back).toHaveTextContent(/^Back$/);
    const raf = vi.spyOn(window, "requestAnimationFrame").mockImplementation((f) => { f(0); return 0; });
    fireEvent.click(back);
    await flushAsync();
    expect(library).not.toHaveClass("has-open");
    expect(document.activeElement).toBe(document.querySelector('[data-row="a"]'));
    raf.mockRestore();
  });

  it("hides the list and the toolbar only on a narrow page, and Back only there", () => {
    expect(prop(".app.narrow .library.has-open > .list", "display")).toBe("none");
    expect(prop(".app.narrow .library-main.has-open > .library-toolbar", "display")).toBe("none");
    expect(prop(".detail-back", "display")).toBe("none");
    expect(prop(".app.narrow .library.has-open .detail-back", "display")).toBe("inline-flex");
  });

  it("with nothing open shows the list as the page: no empty recording box, the page scrolls, headers stick at its edge", () => {
    expect(prop(".app.narrow .library:not(.has-open):not(.as-table) > .detail", "display")).toBe("none");
    expect(prop(".app.narrow .library:not(.as-table) > .list", "max-height")).toBe("none");
    expect(prop(".app.narrow .library:not(.as-table) > .list", "overflow")).toBe("visible");
    expect(prop(".app.narrow .library:not(.as-table) .group-head", "top")).toBe("calc(-1 * var(--space-5))");
    expect(prop(".app.narrow .content.page-library", "scroll-padding-block-start")).toContain("var(--group-head-h)");
  });

  it("fits the top bar in a half-screen window", () => {
    expect(prop(".app.narrow .topbar", "gap")).toBe("var(--space-1)");
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
    expect(prop('.item-title[dir="auto"]', "text-align")).toBe("left");
    expect(prop('[dir="rtl"] .activity-title > [dir="auto"]', "text-align")).toBe("right");
    expect(prop('.item-title[dir="auto"]', "unicode-bidi")).toBe("plaintext");
    expect(prop(".item-title", "text-overflow")).toBe("ellipsis");
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
  it("keeps a row brought into view under the group header, not behind it", () => {
    expect(prop(".list", "scroll-padding-block-start")).toBe("var(--group-head-h)");
    expect(prop(".group-head", "background")).toBe("var(--surface)");
  });

  it("keeps the last lines and the line Follow plays clear of the player (its height is the scroll padding)", () => {
    expect(prop(".detail", "scroll-padding-block-end")).toContain("var(--player-dock-h");
    expect(prop(".app.narrow .content.page-library", "scroll-padding-block-end")).toContain("var(--player-dock-h");
    const height = vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockReturnValue({ height: 96.4 } as DOMRect);
    const view = render(<PlayerDock playing={false} nowMs={0} durationMs={1000} onToggle={() => undefined} onSkip={() => undefined}>{null}</PlayerDock>);
    expect(document.documentElement.style.getPropertyValue("--player-dock-h")).toBe("97px");
    view.unmount();
    expect(document.documentElement.style.getPropertyValue("--player-dock-h")).toBe("");
    height.mockRestore();
  });
});
