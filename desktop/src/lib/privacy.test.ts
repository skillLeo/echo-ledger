import { describe, expect, it } from "vitest";
import { BADGE_HINT, BADGE_LABEL, checkedWhen, demoLeft, lastCheckLine, resultLine } from "./privacy";
import { conflictFor } from "./shortcutConflicts";

const NOW = new Date(2026, 10, 16, 15, 0);

describe("Share Check words", () => {
  it("says the result in one plain line, with the system and the day", () => {
    expect(resultLine("pass", null, "macOS 27.3", new Date(2026, 10, 16, 9, 0).toISOString(), "macos", NOW).text)
      .toBe("Hidden. People who see your whole screen won't see EchoLedger. Checked on macOS 27.3, today.");
    expect(resultLine("pass", null, "Windows 11 24H2", new Date(2026, 10, 15, 9, 0).toISOString(), "windows", NOW).text).toContain("Checked on Windows 11 24H2, yesterday.");
    expect(resultLine("fail", null, "macOS 27.3", NOW.toISOString(), "macos", NOW).text).toBe("Visible on this Mac in apps like Zoom and Teams. Use one-window sharing or your phone.");
  });

  it("names the error and offers its fix", () => {
    const permission = resultLine("error", "permission_missing", "macOS 27.3", NOW.toISOString(), "macos", NOW);
    expect(permission.fix).toBe("open_settings");
    expect(permission.text).toMatch(/Screen Recording/);
    expect(resultLine("error", "capture_failed", "Windows 11 24H2", NOW.toISOString(), "windows", NOW).fix).toBe("try_again");
    expect(resultLine("error", "control_missing", "Windows 11 24H2", NOW.toISOString(), "windows", NOW).text).toMatch(/nothing was checked/);
  });

  it("says an older Windows' black box as private, and a covered test window as nothing checked", () => {
    expect(resultLine("pass", "old_windows", "Windows 10 (build 18363)", NOW.toISOString(), "windows", NOW).text)
      .toBe("Private: people see a black box instead of EchoLedger. Checked on Windows 10 (build 18363), today.");
    const covered = resultLine("error", "covered", "macOS 27.3", NOW.toISOString(), "macos", NOW);
    expect(covered.text).toBe("Another window covered the test window, so nothing was checked. Move it away and try again.");
    expect(covered.fix).toBe("try_again");
  });

  it("dates older checks and says when nothing was checked", () => {
    expect(checkedWhen(new Date(2026, 10, 2).toISOString(), NOW)).toBe("on 2 Nov");
    expect(lastCheckLine(null, "macOS 27.3", "macos", NOW)).toBe("Not checked yet");
    expect(demoLeft(540)).toBe("9 min left");
    expect(demoLeft(null)).toBeNull();
  });

  it("keeps to the words rule", () => {
    const words = [...Object.values(BADGE_LABEL), ...Object.values(BADGE_HINT)].join(" ").toLowerCase();
    for (const banned of ["undetectable", "stealth", "invisible", "cheat", "bypass"]) expect(words).not.toContain(banned);
    expect(BADGE_LABEL).toEqual({ private: "Private", black_box: "Black box", not_checked: "Not checked", visible: "Visible in screen share" });
  });
});

describe("Hide all EchoLedger windows", () => {
  it("has a default that no browser or system key uses, and Hide Others is known as taken", () => {
    expect(conflictFor("CommandOrControl+Alt+Shift+P", "macos")).toBeNull();
    expect(conflictFor("CommandOrControl+Alt+Shift+P", "windows")).toBeNull();
    expect(conflictFor("CommandOrControl+Alt+H", "macos")).toMatch(/Hide Others/);
  });
});
