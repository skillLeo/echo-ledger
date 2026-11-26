// D-163 and the right-to-left follow-ups of the 2.0.3 review (D4, docs/v2/design-review/D4-urdu-arabic-typography.md):
// in an Urdu (right-to-left) window an English sentence keeps its full stop at its end, times read as in English, the
// player is never mirrored, and chevrons that point along the reading direction turn round. jsdom can't lay text out,
// so these read the styles the elements get; scripts/widthcheck.mjs measures the drawn order ("stop first",
// "reversed") in a real window.
import { afterEach, beforeAll, describe, expect, it } from "vitest";
import { render } from "@testing-library/react";
import { readdirSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { ExportMenu, PlayerDock } from "./TranscriptTools";
import { LinkRow, ActionRow } from "./SettingsControls";
import { CaptionPreview } from "./CaptureSettings";
import { ScreenViewer } from "./ScreenMoments";
import { makeScreen } from "../../test/fixtures";

const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "../../styles.css"), "utf8");
beforeAll(() => {
  const style = document.createElement("style");
  style.textContent = css;
  document.head.appendChild(style);
});
afterEach(() => { document.documentElement.dir = ""; });

/** Renders inside the classic window (.app) turned right to left (the root's dir, as the Urdu window sets it). */
const rtl = (ui: React.ReactElement) => {
  document.documentElement.dir = "rtl";
  const view = render(<div className="app">{ui}</div>);
  return { ...view, container: view.container.firstElementChild as HTMLElement };
};

describe("an English sentence in a right-to-left window", () => {
  it.each([
    ["a page's description", <p className="page-sub">11 recordings from your phone, this computer and the web.</p>],
    ["a notice", <div className="notice">Wrong script detected. Use Re-transcribe to try the next engine.</div>],
    ["a muted line", <div className="muted">You can play and read it.</div>],
    ["a settings description", <p>Warns you on the recording controls.</p>],
    ["a small line", <span className="small muted">A transcript that failed.</span>],
    ["a row's second line", <span className="item-sub">Northwind · 42:10</span>],
    ["a section label", <h2 className="queue-slim-label">2 calls waiting</h2>],
    ["a status pill", <span className="pill processing">Transcribing…</span>],
    ["a card title", <span className="action-card-title">Keep or discard?</span>],
  ])("%s takes its own direction and stays on the window's start side", (_name, ui) => {
    const { container } = rtl(ui);
    const el = container.firstElementChild as HTMLElement;
    expect(getComputedStyle(el).unicodeBidi).toBe("plaintext");
    expect(getComputedStyle(el).textAlign).toBe("match-parent");
  });

  it("a chip's words (a bookmark \"1:35 Budget\") keep their order, and the button keeps its own alignment", () => {
    const { container } = rtl(<button type="button" className="chip small">1:35 Budget</button>);
    const chip = container.querySelector("button")!;
    expect(getComputedStyle(chip).unicodeBidi).toBe("plaintext");
    expect(getComputedStyle(chip).textAlign).not.toBe("match-parent");
  });

  it("leaves v2 screens to their own rules (v2 has its own D4 tests)", () => {
    const { container } = rtl(<div className="el-root" data-shell="v2"><span className="el-meta">Northwind · 42:10</span></div>);
    expect(getComputedStyle(container.querySelector(".el-meta")!).unicodeBidi).not.toBe("plaintext");
  });

  it("leaves an element's own alignment alone (a centred empty line stays centred)", () => {
    const { container } = rtl(<p className="empty">No recordings yet.</p>);
    expect(getComputedStyle(container.querySelector("p")!).textAlign).toBe("center");
    expect(getComputedStyle(container.querySelector("p")!).unicodeBidi).toBe("plaintext");
  });
});

describe("Urdu in a segmented control (Roman Urdu · اردو · Original)", () => {
  it("gets the Urdu caption line (14/28), and the control grows with it instead of cutting it", () => {
    const { container } = render(<div className="segmented"><button type="button">Roman Urdu</button><button type="button" lang="ur">اردو</button></div>);
    const [latin, urdu] = Array.from(container.querySelectorAll("button"));
    expect(getComputedStyle(urdu).lineHeight).toBe("var(--el-text-urdu-caption-line, 28px)");
    expect(getComputedStyle(urdu).fontSize).toBe("14px");
    expect(["", "auto"]).toContain(getComputedStyle(latin).height);
    expect(getComputedStyle(latin).minHeight).toBe("30px");
  });
});

describe("a select's English words in a right-to-left window", () => {
  it("each Export option takes its own direction, so it reads \"Export…\" and \"Word (.docx)\", not \"…Export\"", () => {
    const { container } = rtl(<ExportMenu onExport={() => undefined} onPack={() => undefined} />);
    const options = Array.from(container.querySelectorAll("option"));
    expect(options.length).toBeGreaterThan(3);
    options.forEach((o) => expect(o).toHaveAttribute("dir", "auto"));
  });
});

describe("every classic select's options", () => {
  it("take their own direction (a closed select draws its option's words; in right to left \"Auto (English)\" would turn round)", () => {
    const dir = dirname(fileURLToPath(import.meta.url));
    // The classic views, and the classic shared component with a select (the screen-share choice).
    const files = [...readdirSync(dir).filter((f) => f.endsWith(".tsx") && !f.includes(".test.")).map((f) => join(dir, f)),
      join(dir, "../../components/ShareVisibility.tsx")];
    const missing = files
      .flatMap((f) => readFileSync(f, "utf8").split("\n").map((line, i) => [f.split("/").pop(), i + 1, line] as const))
      .filter(([, , line]) => /<option[\s>]/.test(line) && !/<option[^>]*dir="auto"/.test(line))
      .map(([f, n]) => `${f}:${n}`);
    expect(missing).toEqual([]);
  });
});

describe("the account menu's Light / Dark / System switch", () => {
  it("keeps its own 26 px height (the segmented control grew to fit Urdu, D4 §3.5)", () => {
    const { container } = render(<div className="menu-theme"><div className="segmented"><button type="button">Light</button></div></div>);
    expect(getComputedStyle(container.querySelector("button")!).minHeight).toBe("26px");
  });
});

describe("caption lines", () => {
  it("each preview line takes its own direction, as the overlay draws it", () => {
    const { container } = rtl(<CaptionPreview style={{ size: "medium", opacity: 80, compact: false, clientColor: null, meColor: null } as never} />);
    const lines = container.querySelectorAll(".caption-sample-lines > div");
    expect(lines).toHaveLength(3);
    lines.forEach((line) => expect(line).toHaveAttribute("dir", "auto"));
  });
});

describe("times read as in English", () => {
  it.each(["item-time", "player-time", "seg-time"])("a .%s is a left-to-right isolate", (cls) => {
    const { container } = rtl(<span className={cls}>8:12 AM</span>);
    const el = container.firstElementChild as HTMLElement;
    expect(getComputedStyle(el).direction).toBe("ltr");
    expect(getComputedStyle(el).unicodeBidi).toBe("isolate");
  });

  it("the player's transport, time and waveform are never mirrored", () => {
    const { container } = rtl(<PlayerDock playing={false} nowMs={0} durationMs={135_000} onToggle={() => undefined} onSkip={() => undefined}>{null}</PlayerDock>);
    expect(container.querySelector(".player-row")).toHaveAttribute("dir", "ltr");
  });
});

describe("chevrons along the reading direction", () => {
  it("a settings row's chevron points to the start side (left) in right to left", () => {
    const { container } = rtl(<LinkRow id="profile" title="Profile" chevron onOpen={() => undefined} />);
    expect(getComputedStyle(container.querySelector(".set-trail")!).transform).toBe("scaleX(-1)");
  });

  it("but not the external-link icon or an open/close disclosure, and nothing turns in left to right", () => {
    const ext = rtl(<LinkRow id="help" title="Help" external onOpen={() => undefined} />);
    expect(getComputedStyle(ext.container.querySelector(".set-trail")!).transform).not.toBe("scaleX(-1)");
    const disclosure = render(<ActionRow id="copy" title="Copy diagnostics" onClick={() => undefined} expanded={false} />);
    expect(getComputedStyle(disclosure.container.querySelector(".set-trail.disclosure")!).transform).toBe("rotate(90deg)");
    document.documentElement.dir = "";
    const ltr = render(<LinkRow id="profile" title="Profile" chevron onOpen={() => undefined} />);
    expect(getComputedStyle(ltr.container.querySelector(".set-trail")!).transform).not.toBe("scaleX(-1)");
  });

  it("the screens of a recording run left to right in every language, as ← and → step through them", () => {
    const shots = [makeScreen(1, 60_000), makeScreen(2, 90_000)];
    rtl(<ScreenViewer screens={shots} index={0} onIndex={() => undefined} onClose={() => undefined} />);
    expect(document.querySelector(".screen-viewer-body")).toHaveAttribute("dir", "ltr");
  });

  it.each([".crumb-step svg", ".back-link svg"])("%s turns round in right to left", (selector) => {
    const [wrap, el] = selector.split(" ");
    const { container } = rtl(<span className={wrap.slice(1)}><svg /></span>);
    expect(getComputedStyle(container.querySelector(el)!).transform).toBe("scaleX(-1)");
  });
});
