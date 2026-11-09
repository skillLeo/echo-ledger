import { describe, expect, it } from "vitest";
import { actionCount, badgeLabel, badgeSpoken, isProblem, needsAction } from "./badge";

const item = (kind: string | null, read = false) => ({ kind, read });

describe("the Activity badge counts only what needs the person (v1.6, P6)", () => {
  it("counts unread problems and upgrade replies, never finished transcripts, devices or shares", () => {
    const items = [
      item("transcript_failed"),
      item("upload_failed"),
      item("budget_reached"),
      item("upgrade_approved"),
      item("upgrade_declined"),
      item("transcript_ready"),
      item("device_paired"),
      item("shared_with_you"),
      item("budget_warning"),
      item(null),
    ];
    expect(actionCount(items)).toBe(5);
  });

  it("leaves out what was read", () => {
    expect(actionCount([item("transcript_failed", true), item("upgrade_approved", true), item("transcript_failed")])).toBe(1);
  });

  it("adds the calls waiting for Keep or Discard", () => {
    expect(actionCount([item("transcript_failed")], 2)).toBe(3);
    expect(actionCount([], 0)).toBe(0);
    expect(actionCount(null, 1)).toBe(1);
    expect(actionCount([], -3)).toBe(0);
  });

  it("is no longer 21 for a busy day: twenty finished transcripts and one failure count 1", () => {
    const busy = [...Array.from({ length: 20 }, () => item("transcript_ready")), item("transcript_failed")];
    expect(actionCount(busy)).toBe(1);
  });

  it("reads a problem the way the phone does", () => {
    expect(isProblem("transcript_failed")).toBe(true);
    expect(isProblem("sync_error")).toBe(true);
    expect(isProblem("capture_problem")).toBe(true);
    expect(isProblem("budget_reached")).toBe(true);
    expect(isProblem("transcript_ready")).toBe(false);
    expect(isProblem(null)).toBe(false);
    expect(needsAction({ kind: "upgrade_declined", read: false })).toBe(true);
    expect(needsAction({ kind: "upgrade_declined", read: true })).toBe(false);
  });

  it("says nothing at 0, the number up to 9, then 9+", () => {
    expect(badgeLabel(0)).toBeNull();
    expect(badgeLabel(-1)).toBeNull();
    expect(badgeLabel(Number.NaN)).toBeNull();
    expect(badgeLabel(1)).toBe("1");
    expect(badgeLabel(9)).toBe("9");
    expect(badgeLabel(10)).toBe("9+");
    expect(badgeLabel(22)).toBe("9+");
    expect(badgeSpoken(1)).toBe("1 needs you");
    expect(badgeSpoken(3)).toBe("3 need you");
  });
});
