import { describe, expect, it } from "vitest";
import { actionCount, badgeLabel, badgeSpoken, isFailure, isProblem, needsAction, railBadge, railBadgeLabel, repeatWords, uploadGroupTitle } from "./badge";

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

  it("2.0.0-v: the server's own problem (super admins only) is a problem on the badge until it is resolved", () => {
    expect(isProblem("server_problem")).toBe(true);
    expect(needsAction({ kind: "server_problem", read: false })).toBe(true);
    // "Fixed: uploads work again": the same item, resolved; a resolved problem of any kind no longer needs the person.
    expect(needsAction({ kind: "server_problem", read: false, resolved: true })).toBe(false);
    expect(needsAction({ kind: "upload_failed", read: false, resolved: true })).toBe(false);
    expect(actionCount([{ kind: "server_problem", read: false }, { kind: "server_problem", read: false, resolved: true }], 1)).toBe(2);
  });
});

describe("the rail's one Activity number (UX-08, BE-04)", () => {
  it("is the server's number (the same on the phone and the web console) plus the calls waiting that only this computer knows, in its colour", () => {
    const items = [item("transcript_failed"), item("upload_failed"), item("budget_reached")];
    // The server merged and resolved what it counts: its number wins over the list read here. Of the 2 calls waiting,
    // the one with a server session (captions opened one) is in the server's number already: only the other is added.
    expect(railBadge({ count: 1, label: "1", tone: "accent" }, items, 2, 1)).toEqual({ count: 2, tone: "accent", shared: true });
    expect(railBadge({ count: 1, label: "1", tone: "accent" }, items, 2, 0)).toEqual({ count: 1, tone: "accent", shared: true });
    expect(railBadge({ count: 2, label: "2", tone: "danger" }, [], 0)).toEqual({ count: 2, tone: "danger", shared: true });
    expect(railBadge({ count: 0, label: null, tone: "accent" }, items, 0)).toEqual({ count: 0, tone: "accent", shared: true });
  });

  it("offline or from an older server: counted here from the list, red only when something failed", () => {
    expect(railBadge(null, [item("budget_reached"), item("upgrade_approved")], 1)).toEqual({ count: 3, tone: "accent", shared: false });
    expect(railBadge(undefined, [item("budget_reached"), item("upload_failed")], 0)).toEqual({ count: 2, tone: "danger", shared: false });
    // A failure already read or resolved neither counts nor turns it red.
    expect(railBadge(null, [item("upload_failed", true), { kind: "upload_failed", read: false, resolved: true }], 0)).toEqual({ count: 0, tone: "accent", shared: false });
    expect(railBadge(null, null, 2)).toEqual({ count: 2, tone: "accent", shared: false });
    // Counted here, every call waiting counts (none of them is in a server number), and a missed recording needs action.
    expect(railBadge(null, [item("missed_recording")], 2, 1)).toEqual({ count: 3, tone: "accent", shared: false });
  });

  it("failures, as the server decides them (fail or error): not the server's problem, the monthly limit, an upgrade reply or a missed recording", () => {
    for (const kind of ["transcript_failed", "upload_failed", "export_error"]) expect(isFailure(kind), kind).toBe(true);
    for (const kind of ["server_problem", "budget_reached", "upgrade_approved", "missed_recording", null]) expect(isFailure(kind), String(kind)).toBe(false);
    // The server's needs-action rule: a missed recording needs the person, as a problem does.
    expect(needsAction({ kind: "missed_recording", read: false })).toBe(true);
    expect(needsAction({ kind: "missed_recording", read: true })).toBe(false);
  });

  it("says the number up to 99, then 99+; the group's line and how often", () => {
    expect([0, 1, 99, 100, 250].map(railBadgeLabel)).toEqual([null, "1", "99", "99+", "99+"]);
    expect(uploadGroupTitle(1)).toBe("1 recording didn't finish uploading");
    expect(uploadGroupTitle(3)).toBe("3 recordings didn't finish uploading");
    expect([undefined, null, 1, 2].map(repeatWords)).toEqual([null, null, null, "2 times"]);
  });
});
