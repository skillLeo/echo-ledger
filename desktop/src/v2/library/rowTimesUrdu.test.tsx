// Desktop Urdu, D-258: a Library row's time and length in the account's language. The time is the one every date line
// says (lib/format clockTime, whenParts' time): its part of the day first, in ICU's own Urdu words, on every engine
// ("صبح 7:10", as a Mac's WebKit already wrote it), kept whole; the 24-hour clock as before ("07:10"). The length has
// its units' words ("2 منٹ 38 سیکنڈ"), as one right-to-left run. English is unchanged, and the lines that stay English
// in every language (Copy for AI's header, the classic pages' times and Quiet hours) keep their English in Urdu too.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen, within } from "@testing-library/react";
import type { LibraryItem } from "../../lib/ipc";
import { flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem } from "../../test/fixtures";
import { clockTime, formatTime, setClock24, timeRange, whenParts } from "../../lib/format";
import { backAbout } from "../../views/main/ServerBanners";
import { FSI, PDI, RLI, resetLanguageForTests, setLanguage, t } from "../../i18n";
import { rowDuration, rowModel, rowWhen } from "./model";
import { aiHeader } from "./organise";
import { LibraryV2 } from "./LibraryV2";
import { resetSyncClock } from "./syncClock";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NBSP = " ";
const iso = (value: string | number) => `${FSI}${value}${PDI}`;
/** A length in Urdu: its words as one right-to-left run. */
const run = (words: string) => `${RLI}${words}${PDI}`;
const NOW = new Date(2026, 10, 26, 23, 30);
const on = (hour: number, minute: number, day = 26) => new Date(2026, 10, day, hour, minute);
const at = (hour: number, minute: number, day = 26) => on(hour, minute, day).toISOString();
const done = (preview: string) => ({ final_status: "completed", final_transcript_id: `tr-${preview}`, live_available: false, preview });
const ITEMS: LibraryItem[] = [
  makeItem("morning", { title: "Bilal", source_app: "whatsapp", source_app_label: "WhatsApp", started_at: at(7, 10), duration_ms: 742_000, transcript: done("Payment terms") }),
  makeItem("night", { title: "Sana", source_app: "zoom", source_app_label: "Zoom", started_at: at(21, 5), duration_ms: 44_000, transcript: done("Kick-off") }),
  makeItem("older", { title: "Imran", source_app: "phone_call", source_app_label: "Phone call", started_at: new Date(2026, 10, 12, 16, 30).toISOString(), duration_ms: 3_840_000, transcript: done("Audit") }),
];

beforeEach(() => {
  resetTauriMock();
  resetSyncClock();
  resetLanguageForTests();
  setClock24(false);
  window.localStorage.clear();
});
afterEach(async () => {
  await flushAsync();
  setClock24(false);
  vi.useRealTimers();
  resetLanguageForTests();
});
const urdu = () => act(() => setLanguage("ur", { root: false }));

describe("a row's time", () => {
  it("English as it always was: 7:10 am, 12-hour by default, 07:10 with the 24-hour clock", () => {
    expect([on(7, 10), on(12, 5), on(14, 5), on(16, 30), on(19, 5), on(21, 5), on(0, 15)].map((d) => formatTime(d)))
      .toEqual(["7:10 am", "12:05 pm", "2:05 pm", "4:30 pm", "7:05 pm", "9:05 pm", "12:15 am"]);
    expect(rowWhen(at(7, 10), NOW)).toBe("7:10 am");
    expect(rowWhen(at(7, 10, 25), NOW)).toBe("Yesterday");
    expect(rowWhen(at(7, 10, 12), NOW)).toBe("12 Nov");
    expect(clockTime(on(14, 5))).toMatch(/^2:05\sPM$/);
    setClock24(true);
    expect([formatTime(on(7, 10)), formatTime(on(19, 5)), clockTime(on(19, 5))]).toEqual(["07:10", "19:05", "19:05"]);
    expect(rowWhen(at(21, 5), NOW, true)).toBe("21:05");
  });

  it("Urdu: its part of the day first, in ICU's words (morning, afternoon, evening, night), kept whole", async () => {
    await urdu();
    const urduTime = (part: string, clock: string) => `${part.replace(/ /g, NBSP)}${NBSP}${clock}`;
    expect(formatTime(on(7, 10))).toBe(urduTime("صبح", "7:10"));
    expect(formatTime(on(11, 42))).toBe(urduTime("صبح", "11:42"));
    expect(formatTime(on(12, 5))).toBe(urduTime("دوپہر", "12:05"));
    expect(formatTime(on(14, 5))).toBe(urduTime("دوپہر", "2:05"));
    expect(formatTime(on(16, 30))).toBe(urduTime("سہ پہر", "4:30"));
    expect(formatTime(on(19, 5))).toBe(urduTime("شام", "7:05"));
    expect(formatTime(on(21, 5))).toBe(urduTime("رات", "9:05"));
    expect(formatTime(on(0, 15))).toBe(urduTime("رات", "12:15"));
    // The row's time is the one every date line says (whenParts' time), Latin digits, no am/pm left.
    for (const d of [on(7, 10), on(14, 5), on(19, 5), on(21, 5)]) {
      expect(rowWhen(d.toISOString(), NOW)).toBe(whenParts(d.toISOString(), NOW)?.time);
      expect(rowWhen(d.toISOString(), NOW)).not.toMatch(/[ap]m|[AP]M/);
    }
    expect(rowWhen(at(21, 5, 25), NOW, true)).toBe(urduTime("رات", "9:05"));
    expect(rowWhen(at(7, 10, 25), NOW)).toBe(t("Yesterday"));
    expect(rowWhen(at(7, 10, 25), NOW)).toBe("کل");
    // The words are ICU's own (CLDR's ur), as a Mac's WebKit writes ur-PK: the day's part, a space, the clock.
    const parts = new Intl.DateTimeFormat("ur-PK", { hour: "numeric", minute: "2-digit", hourCycle: "h12", dayPeriod: "short" } as Intl.DateTimeFormatOptions).formatToParts(on(19, 5));
    expect(clockTime(on(19, 5))).toBe(`${parts.find((p) => p.type === "dayPeriod")?.value} 7:05`);
  });

  it("Urdu on an engine that gives no part of the day: the formatter's own whole time, never a space and no word", async () => {
    await urdu();
    const original = Intl.DateTimeFormat.prototype.formatToParts;
    const spy = vi.spyOn(Intl.DateTimeFormat.prototype, "formatToParts").mockImplementation(function (this: Intl.DateTimeFormat, date?: Date | number) {
      return original.call(this, date).filter((p) => p.type !== "dayPeriod");
    });
    try {
      const own = new Intl.DateTimeFormat("ur-PK", { hour: "numeric", minute: "2-digit", hourCycle: "h12", dayPeriod: "short" } as Intl.DateTimeFormatOptions);
      for (const d of [on(7, 10), on(14, 5), on(21, 5)]) {
        const time = clockTime(d);
        expect(time).not.toBe("");
        expect(time).not.toMatch(/^\s/);
        expect(time).toBe(own.format(d));
        expect(formatTime(d)).toBe(own.format(d).replace(/\s/g, NBSP));
      }
      expect(spy).toHaveBeenCalled();
      // English doesn't ask for the parts: "7:10 am" as always.
      await act(() => setLanguage("en", { root: false }));
      expect(formatTime(on(7, 10))).toBe("7:10 am");
    } finally {
      spy.mockRestore();
    }
  });

  it("Urdu with the 24-hour clock: 07:10 and 21:05, as before", async () => {
    await urdu();
    setClock24(true);
    expect([formatTime(on(7, 10)), formatTime(on(21, 5)), clockTime(on(21, 5))]).toEqual(["07:10", "21:05", "21:05"]);
    expect(rowWhen(at(21, 5), NOW)).toBe("21:05");
    expect(whenParts(at(21, 5), NOW)?.time).toBe("21:05");
  });

  it("the lines that stay English keep their English time in Urdu: Copy for AI's header, the classic pages, Quiet hours", async () => {
    await urdu();
    expect(formatTime(on(15, 42), "en")).toBe("3:42 pm");
    expect(aiHeader(makeItem("x", { title: "Call", started_at: at(15, 42), duration_ms: 60_000 }), [])).toMatch(/, 3:42 pm( · |$)/m);
    expect(timeRange("22:00", "07:00")).toBe("10 pm–7 am");
    expect(backAbout(at(23, 50), NOW)).toMatch(/^Back about 11:50\sPM\.$/);
    expect(clockTime(on(23, 50), "en")).toMatch(/^11:50\sPM$/);
    setClock24(true);
    expect([formatTime(on(15, 42), "en"), timeRange("22:00", "07:00")]).toEqual(["15:42", "22:00–07:00"]);
  });
});

describe("a row's length", () => {
  it("English as it always was: 44s, 2m 38s, 1h 04m", () => {
    expect([44_000, 158_000, 120_000, 3_840_000, 3_600_000, 1_104_000, 742_000, 0, null].map((ms) => rowDuration(ms)))
      .toEqual(["44s", "2m 38s", "2m 00s", "1h 04m", "1h 00m", "18m 24s", "12m 22s", "", ""]);
  });

  it("Urdu: the units' words (under a minute, under an hour, over an hour), each number isolated, one right-to-left run", async () => {
    await urdu();
    expect(rowDuration(44_000)).toBe(run(`${iso(44)} سیکنڈ`));
    expect(rowDuration(1_000)).toBe(run(`${iso(1)} سیکنڈ`));
    expect(rowDuration(158_000)).toBe(run(`${iso(2)} منٹ ${iso(38)} سیکنڈ`));
    expect(rowDuration(742_000)).toBe(run(`${iso(12)} منٹ ${iso(22)} سیکنڈ`));
    expect(rowDuration(3_840_000)).toBe(run(`${iso(1)} گھنٹہ ${iso(4)} منٹ`));
    expect(rowDuration(7_500_000)).toBe(run(`${iso(2)} گھنٹے ${iso(5)} منٹ`));
    // A whole minute or hour says only that.
    expect(rowDuration(120_000)).toBe(run(`${iso(2)} منٹ`));
    expect(rowDuration(3_600_000)).toBe(run(`${iso(1)} گھنٹہ`));
    expect(rowDuration(7_200_000)).toBe(run(`${iso(2)} گھنٹے`));
    expect([rowDuration(0), rowDuration(null)]).toEqual(["", ""]);
    for (const ms of [44_000, 158_000, 3_840_000]) expect(rowDuration(ms)).not.toMatch(/[A-Za-z]/);
  });

  it("the row model carries both, in the window's language and back", async () => {
    const item = ITEMS[0];
    expect([rowModel(item, NOW).when, rowModel(item, NOW).duration]).toEqual(["7:10 am", "12m 22s"]);
    await urdu();
    expect([rowModel(item, NOW).when, rowModel(item, NOW).duration]).toEqual([`صبح${NBSP}7:10`, run(`${iso(12)} منٹ ${iso(22)} سیکنڈ`)]);
    await act(() => setLanguage("en", { root: false }));
    expect([rowModel(item, NOW).when, rowModel(item, NOW).duration]).toEqual(["7:10 am", "12m 22s"]);
  });
});

describe("in the Library", () => {
  const show = async () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(NOW);
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("library_folder", []);
    respond("bin_list", { data: [] });
    render(<LibraryV2 />);
    await act(flushAsync);
  };
  /** A row's when and its length, by its title. */
  const ends = (title: string) => {
    const row = screen.getByText(title).closest("li") as HTMLElement;
    return [...row.querySelectorAll(".el-rec__end")].map((el) => el.textContent);
  };

  it("English: today's rows 7:10 am and 9:05 pm, an older one 12 Nov, the lengths 12m 22s, 44s and 1h 04m", async () => {
    await show();
    expect(ends("Bilal")).toEqual(["7:10 am", "12m 22s"]);
    expect(ends("Sana")).toEqual(["9:05 pm", "44s"]);
    expect(ends("Imran")).toEqual(["12 Nov", "1h 04m"]);
  });

  it("Urdu: صبح 7:10 and رات 9:05, the lengths in words, nothing left in English", async () => {
    await urdu();
    await show();
    expect(ends("Bilal")).toEqual([`صبح${NBSP}7:10`, run(`${iso(12)} منٹ ${iso(22)} سیکنڈ`)]);
    expect(ends("Sana")).toEqual([`رات${NBSP}9:05`, run(`${iso(44)} سیکنڈ`)]);
    const older = ends("Imran");
    expect(older[1]).toBe(run(`${iso(1)} گھنٹہ ${iso(4)} منٹ`));
    expect(older[0]).toBe(new Date(2026, 10, 12).toLocaleDateString("ur-PK", { day: "numeric", month: "short" }));
    // Each sits in its own <bdi>, so it reads in its own direction at the row's end.
    const row = screen.getByText("Bilal").closest("li") as HTMLElement;
    expect([...row.querySelectorAll(".el-rec__end")].every((el) => el.firstElementChild?.tagName === "BDI")).toBe(true);
    expect(within(row).queryByText(/\b(am|pm|\d+m|\d+s)\b/)).toBeNull();
  });
});
