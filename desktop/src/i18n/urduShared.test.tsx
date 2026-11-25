// Desktop Urdu, the shared fixes (every screen): a line's direction from its own words (lineDir) in a row, a toast and a
// dialog; a date and its time as two isolated parts of one right-to-left run (lib/format formatWhen); and the CSS that
// keeps a Sheet's head clear of a Mac's window buttons, a page's title at the window's start edge, and a date on one
// line. English as before: dir="auto", the same date words.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen } from "@testing-library/react";
import { createElement, Fragment } from "react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { FSI, lineDir, PDI, piecesDir, resetLanguageForTests, RLI, setLanguage } from "./index";
import { formatWhen, setClock24, whenPart, whenParts } from "../lib/format";
import { shortWhen } from "../views/main/ActivityCentre";
import { clientSummary } from "../views/main/ClientAvatar";
import { rowTime } from "../views/main/LibraryView";
import { Dialog, ListRow, Sheet, Toast, ToastRegion } from "../v2/components";

const here = dirname(fileURLToPath(import.meta.url));
const RLM = "\u200F";
const NBSP = "\u00A0";
const urdu = () => act(() => setLanguage("ur", { root: false }));
/** "Imran کی بھیجی ہوئی ریکارڈنگ" as t() writes it in Urdu: the name in its isolate first. */
const SENT_BY = `${FSI}Imran${PDI} کی بھیجی ہوئی ریکارڈنگ`;

beforeEach(() => {
  resetLanguageForTests({});
  setClock24(false);
});
afterEach(() => resetLanguageForTests());

describe("lineDir: a line's direction from its own words", () => {
  it("in English always auto, as the app has drawn it", () => {
    expect([lineDir(SENT_BY, "en"), lineDir("Payment terms", "en"), lineDir("آج", "en")]).toEqual(["auto", "auto", "auto"]);
  });

  it("in Urdu by its first letter outside its isolates", () => {
    // The browsers' dir="auto" takes Imran's I: left to right. Outside the isolate the line is Urdu.
    expect(lineDir(SENT_BY, "ur")).toBe("rtl");
    expect(lineDir("Payment terms and the March invoice.", "ur")).toBe("ltr");
    expect(lineDir(`${FSI}3${PDI} ریکارڈنگز`, "ur")).toBe("rtl");
    // An isolate the value left open hides what follows, as UAX #9 reads it.
    expect(lineDir(`${FSI}Imran ہٹائیں`, "ur")).toBe("auto");
    // Nested isolates are skipped as a whole; digits before a Latin word don't decide.
    expect(lineDir(`${FSI}a${FSI}b${PDI}c${PDI} ریکارڈ`, "ur")).toBe("rtl");
    expect(lineDir("3 recordings", "ur")).toBe("ltr");
    expect(lineDir(`${FSI}3${PDI} recordings`, "ur")).toBe("ltr");
    // Right-to-left letters past the basic plane (Adlam, Hanifi Rohingya).
    expect([lineDir("\u{1E900}\u{1E901}", "ur"), lineDir("\u{10D00}", "ur")]).toEqual(["rtl", "rtl"]);
    // Only an isolate, or no letter at all: the browser's choice.
    expect([lineDir(`${FSI}Acme Ltd${PDI}`, "ur"), lineDir("12:34", "ur"), lineDir("", "ur")]).toEqual(["auto", "auto", "auto"]);
    // A right-to-left mark (D2's lines) or a left-to-right one decides.
    expect([lineDir(`${RLM}${SENT_BY}`, "ur"), lineDir(`\u200EPayment`, "ur")]).toEqual(["rtl", "ltr"]);
    // Text in a list (an RLM before a line); anything drawn otherwise stays auto.
    expect(lineDir([RLM, "Imran", " ok"], "ur")).toBe("rtl");
    expect(lineDir(createElement("b", null, "Imran"), "ur")).toBe("auto");
    expect(lineDir(["a", createElement(Fragment, null, "b")], "ur")).toBe("auto");
  });
});

describe("a row, a toast and a dialog take the line's direction in Urdu", () => {
  const show = () => render(
    <>
      <ul><ListRow title="Supplier call with Bilal" meta={SENT_BY} endTop={formatWhen("2026-11-23T09:00:00Z", new Date("2026-11-25T12:00:00Z"))} value="Payment terms" /></ul>
      <ToastRegion><Toast message={SENT_BY} onClose={vi.fn()} duration={60_000} /></ToastRegion>
      <Dialog open title="Sign out" description={SENT_BY} onClose={vi.fn()} />
    </>,
  );

  it("English: dir=auto everywhere, as before", () => {
    show();
    expect(screen.getByText("Supplier call with Bilal")).toHaveAttribute("dir", "auto");
    for (const el of screen.getAllByText(SENT_BY)) expect(el).toHaveAttribute("dir", "auto");
    expect(screen.getByText("Payment terms")).toHaveAttribute("dir", "auto");
  });

  it("Urdu: an Urdu line that starts with a Latin name reads right to left; an English one left to right", async () => {
    await urdu();
    show();
    const lines = screen.getAllByText(SENT_BY);
    // The row's meta, the toast's message and the dialog's description.
    expect(lines).toHaveLength(3);
    for (const el of lines) expect(el).toHaveAttribute("dir", "rtl");
    expect(screen.getByText("Supplier call with Bilal")).toHaveAttribute("dir", "ltr");
    expect(screen.getByText("Payment terms")).toHaveAttribute("dir", "ltr");
  });
});

describe("formatWhen: a date and its time", () => {
  const now = new Date(2026, 10, 25, 15, 0);
  const at = (day: number, hour: number) => new Date(2026, 10, day, hour, 0).toISOString();

  it("English as it always was", () => {
    expect(formatWhen(at(25, 14), now)).toMatch(/^Today · 2:00\sPM$/);
    expect(formatWhen(at(24, 9), now)).toMatch(/^Yesterday · 9:00\sAM$/);
    expect(formatWhen(at(23, 14), now)).toBe(`${new Date(2026, 10, 23).toLocaleDateString([], { month: "short", day: "numeric" })} · ${new Date(2026, 10, 23, 14).toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit", hour12: true })}`);
  });

  it("Urdu: one right-to-left run of two isolated parts, the time kept whole", async () => {
    resetLanguageForTests({ Today: "آج", Yesterday: "کل" });
    await urdu();
    const time = (h: number) => new Date(2026, 10, 25, h).toLocaleTimeString("ur-PK", { hour: "numeric", minute: "2-digit", hour12: true }).replace(/\s/g, NBSP);
    expect(formatWhen(at(25, 14), now)).toBe(`${RLI}${FSI}آج${PDI} · ${FSI}${time(14)}${PDI}${PDI}`);
    expect(formatWhen(at(24, 9), now)).toBe(`${RLI}${FSI}کل${PDI} · ${FSI}${time(9)}${PDI}${PDI}`);
    const date = new Date(2026, 10, 23).toLocaleDateString("ur-PK", { month: "short", day: "numeric" });
    expect(formatWhen(at(23, 14), now)).toBe(`${RLI}${FSI}${date}${PDI} · ${FSI}${time(14)}${PDI}${PDI}`);
    // PM never on a line of its own; the marks balance.
    expect(formatWhen(at(23, 14), now)).not.toMatch(/\d [AP]M/);
    let open = 0;
    for (const char of formatWhen(at(23, 14), now)) {
      if (char === RLI || char === FSI) open++;
      if (char === PDI) open--;
      expect(open).toBeGreaterThanOrEqual(0);
    }
    expect(open).toBe(0);
  });
});

describe("whenParts: one part of a date where a place shows only one, in Urdu never split from formatWhen's run", () => {
  const now = new Date(2026, 10, 25, 15, 0);
  const at = (day: number, hour: number) => new Date(2026, 10, day, hour, 0).toISOString();
  const balanced = (text: string) => {
    let open = 0;
    for (const char of text) {
      if (char === RLI || char === FSI || char === "\u2066") open++;
      if (char === PDI) open--;
      if (open < 0) return false;
    }
    return open === 0;
  };
  beforeEach(() => vi.useFakeTimers({ toFake: ["Date"], now }));
  afterEach(() => vi.useRealTimers());

  it("English: the same words as splitting formatWhen's line did", () => {
    expect(whenParts(at(25, 14), now)).toMatchObject({ day: "Today", today: true, yesterday: false });
    expect(whenParts(null)).toBeNull();
    expect(whenParts("not a date")).toBeNull();
    for (const iso of [at(25, 14), at(24, 9), at(23, 14)]) {
      const line = formatWhen(iso, now);
      const [day, time] = line.split(" · ");
      expect(shortWhen(iso)).toBe(line.startsWith("Today · ") ? time : day);
      expect(rowTime(iso)).toBe(line.startsWith("Today · ") || line.startsWith("Yesterday · ") ? time : day);
      expect(clientSummary(2, iso)).toBe(`2 recordings · Last call ${day}`);
    }
    expect(shortWhen(null)).toBe("");
  });

  it("Urdu: Activity's today shows its time and an older one its day, each in a closed isolate", async () => {
    resetLanguageForTests({ Today: "آج", Yesterday: "کل" });
    await urdu();
    const time = (h: number) => new Date(2026, 10, 25, h).toLocaleTimeString("ur-PK", { hour: "numeric", minute: "2-digit", hour12: true }).replace(/\s/g, NBSP);
    const date = new Date(2026, 10, 23).toLocaleDateString("ur-PK", { month: "short", day: "numeric" });
    expect(shortWhen(at(25, 14))).toBe(`${FSI}${time(14)}${PDI}`);
    expect(shortWhen(at(23, 14))).toBe(`${FSI}${date}${PDI}`);
    expect(shortWhen(at(24, 9))).toBe(`${FSI}کل${PDI}`);
    expect(rowTime(at(24, 9))).toBe(`${FSI}${time(9)}${PDI}`);
    for (const iso of [at(25, 14), at(24, 9), at(23, 14)]) expect([shortWhen(iso), rowTime(iso), formatWhen(iso, now)].every(balanced)).toBe(true);
    expect(whenPart("x")).toBe(`${FSI}x${PDI}`);
  });

  it("Urdu: a project's summary keeps its day closed, so what follows stays after it", async () => {
    resetLanguageForTests({ Today: "آج", Yesterday: "کل" });
    await urdu();
    const date = new Date(2026, 10, 23).toLocaleDateString("ur-PK", { month: "short", day: "numeric" });
    const summary = clientSummary(2, at(23, 14));
    expect(summary).toBe(`2 recordings · Last call ${FSI}${date}${PDI}`);
    // ProjectsListV2 adds " · 3 folders": outside every isolate, after the date.
    const line = `${summary} · 3 folders`;
    expect(balanced(line)).toBe(true);
    expect(line.endsWith(`${PDI} · 3 folders`)).toBe(true);
    expect(clientSummary(0, at(23, 14))).toBe("No recordings yet");
  });
});

describe("D-247: a Dialog's and a Sheet's ×", () => {
  it("is named Close in the window's language; a caller's own word wins", async () => {
    const { unmount } = render(<><Dialog open title="Rename" onClose={() => undefined} /><Sheet open title="Request files" onClose={() => undefined} /></>);
    expect(document.querySelectorAll('[data-close="true"]')).toHaveLength(2);
    for (const button of document.querySelectorAll('[data-close="true"]')) expect(button).toHaveAttribute("aria-label", "Close");
    unmount();
    // The app's own catalogue (this file's other cases run on an empty one).
    resetLanguageForTests();
    await act(() => setLanguage("ur", { root: false }));
    const urduView = render(<><Dialog open title="Rename" onClose={() => undefined} /><Sheet open title="Request files" onClose={() => undefined} /></>);
    for (const button of document.querySelectorAll('[data-close="true"]')) expect(button).toHaveAttribute("aria-label", "بند کریں");
    urduView.unmount();
    render(<Dialog open title="Rename" closeLabel="Dismiss" onClose={() => undefined} />);
    expect(document.querySelector('[data-close="true"]')).toHaveAttribute("aria-label", "Dismiss");
  });
});

describe("D-245: a row's meta line built of pieces", () => {
  // A time first, then Urdu: dir="auto" took the time's AM for the line's direction and cut the Urdu at its start.
  const pieced = createElement(Fragment, null, createElement("span", null, "12:52 AM"), " · ", createElement("bdi", null, "Google Meet"), " · آج");
  it("in Urdu reads right to left; plain text as before (Latin left to right, Urdu right to left, digits auto)", async () => {
    await urdu();
    expect(piecesDir(pieced)).toBe("rtl");
    expect(piecesDir([createElement("mark", { key: "m" }, "invoice"), " was paid"])).toBe("rtl");
    expect(piecesDir("Payment terms")).toBe("ltr");
    expect(piecesDir(SENT_BY)).toBe("rtl");
    expect(piecesDir("3")).toBe("auto");
    render(<ul><ListRow title="Budget review" meta={pieced} /></ul>);
    expect(document.querySelector(".el-row__meta-text")).toHaveAttribute("dir", "rtl");
  });
  it("in English auto, as always", () => {
    expect(piecesDir(pieced, "en")).toBe("auto");
    render(<ul><ListRow title="Budget review" meta={pieced} /></ul>);
    expect(document.querySelector(".el-row__meta-text")).toHaveAttribute("dir", "auto");
  });
});

describe("the CSS (v2.css)", () => {
  const css = readFileSync(join(here, "..", "v2", "styles", "v2.css"), "utf8");
  it("a Mac's Sheet right to left keeps the window buttons' 80 points clear; full screen and Windows don't", () => {
    expect(css).toContain('[data-platform="macos"][data-titlebar][dir="rtl"] .el-sheet .el-dialog__head {\n  padding-inline-end: calc(var(--el-desktop-titlerow-keep-clear) / var(--el-zoom, 1) + var(--el-space-8));');
  });
  it("a page's title and the line under it line up at the window's start edge, as rows do", () => {
    expect(css).toMatch(/\.el-page__titles > \*, \.el-page__head > h1 \{\n {2}text-align: match-parent;\n {2}text-align: -webkit-match-parent;/);
  });
  it("in Urdu a date stays on one line in its box", () => {
    expect(css).toContain(":lang(ur) .el-ltr { white-space: nowrap; }");
  });
});
