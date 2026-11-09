import { beforeEach, describe, expect, it } from "vitest";
import { clampSidebar, findPages, initials, listKey, loadOpenGroups, loadSidebarWidth, paletteKeys, parsePreferences, saveOpenGroups, saveSidebarWidth, savedLine,
  SIDEBAR_DEFAULT, stepIndex } from "./shell";
import { libraryPlaces } from "./folders";
import { workspacesOf } from "../views/main/TopBar";
import { makeStatus } from "../test/fixtures";

beforeEach(() => window.localStorage.clear());

describe("the sidebar's width and groups (v1.6, P4.1)", () => {
  it("keeps the width between 200 and 320 px, 240 by default", () => {
    expect(clampSidebar(150)).toBe(200);
    expect(clampSidebar(400)).toBe(320);
    expect(clampSidebar(251.6)).toBe(252);
    expect(clampSidebar(Number.NaN)).toBe(SIDEBAR_DEFAULT);
    expect(loadSidebarWidth()).toBe(240);
    saveSidebarWidth(999);
    expect(loadSidebarWidth()).toBe(320);
    window.localStorage.setItem("echoledger-sidebar-width", "not a number");
    expect(loadSidebarWidth()).toBe(240);
  });

  it("opens Projects and Shared at first and remembers what was folded", () => {
    expect([...loadOpenGroups()]).toEqual(["projects", "shared"]);
    saveOpenGroups(new Set(["library"]));
    expect([...loadOpenGroups()]).toEqual(["library"]);
    window.localStorage.setItem("echoledger-sidebar-open", JSON.stringify(["library", "nonsense", 3]));
    expect([...loadOpenGroups()]).toEqual(["library"]);
  });
});

describe("Preferences kept on this computer (v1.6, P4.5)", () => {
  it("reads the saved choices and falls back to the defaults for anything else", () => {
    expect(parsePreferences(null)).toEqual({ sidebarStyle: "light", density: "comfortable", clock: "12" });
    expect(parsePreferences('{"sidebarStyle":"grey","density":"compact","clock":"24"}')).toEqual({ sidebarStyle: "grey", density: "compact", clock: "24" });
    expect(parsePreferences('{"sidebarStyle":"purple","clock":12}')).toEqual({ sidebarStyle: "light", density: "comfortable", clock: "12" });
    expect(parsePreferences("{broken")).toEqual({ sidebarStyle: "light", density: "comfortable", clock: "12" });
  });
});

describe("Find a page", () => {
  const pages = [
    { id: "home", label: "Home", keywords: "record ready" },
    { id: "devices", label: "Devices", group: "Account", keywords: "phones computers" },
    { id: "p1", label: "Acme Holdings", group: "Projects" },
  ];
  it("finds a page by its name, its group or its words, every word typed", () => {
    expect(findPages(pages, "dev").map((p) => p.id)).toEqual(["devices"]);
    expect(findPages(pages, "account phones").map((p) => p.id)).toEqual(["devices"]);
    expect(findPages(pages, "ACME").map((p) => p.id)).toEqual(["p1"]);
    expect(findPages(pages, "  ")).toEqual([]);
    expect(findPages(pages, "zzz")).toEqual([]);
  });
});

describe("the list's keys (v1.6, P4.4)", () => {
  const key = (k: string, mods: Partial<KeyboardEvent> = {}) => listKey({ key: k, ctrlKey: false, metaKey: false, altKey: false, shiftKey: false, ...mods });
  it("reads J, K, Enter, X and Esc, never with a modifier", () => {
    expect(key("j")).toBe("next");
    expect(key("K")).toBe("previous");
    expect(key("Enter")).toBe("open");
    expect(key("x")).toBe("pick");
    expect(key("Escape")).toBe("close");
    expect(key("k", { ctrlKey: true })).toBeNull();
    expect(key("j", { shiftKey: true })).toBeNull();
    expect(key("a")).toBeNull();
  });

  it("moves one row, stays at the ends, and starts at the first or the last", () => {
    expect(stepIndex(-1, 3, 1)).toBe(0);
    expect(stepIndex(-1, 3, -1)).toBe(2);
    expect(stepIndex(0, 3, 1)).toBe(1);
    expect(stepIndex(2, 3, 1)).toBe(2);
    expect(stepIndex(0, 3, -1)).toBe(0);
    expect(stepIndex(0, 0, 1)).toBe(-1);
  });
});

describe("small words of the shell", () => {
  it("says when a setting was saved", () => {
    const now = 1_000_000_000;
    expect(savedLine(now, now)).toBe("Saved · just now");
    expect(savedLine(now - 30_000, now)).toBe("Saved · just now");
    expect(savedLine(now - 3 * 60_000, now)).toBe("Saved · 3 min ago");
    expect(savedLine(now - 2 * 3_600_000, now)).toBe("Saved · 2 h ago");
  });

  it("makes initials and names the palette's keys per computer", () => {
    expect(initials("Sara Ahmed")).toBe("SA");
    expect(initials("hassam")).toBe("H");
    expect(initials("  ")).toBe("?");
    expect(initials(null)).toBe("?");
    expect(paletteKeys("macos")).toBe("⌘K");
    expect(paletteKeys("windows")).toBe("Ctrl+K");
  });
});

describe("the Library's places (v1.6, P4.2)", () => {
  it("lists All, Waiting, Pinned and Failed uploads, then the smart folders, adding what only this computer knows", () => {
    const places = libraryPlaces({ views: { waiting: 1, pinned: 3, failed: 2 }, folders: { inbox: 4, whatsapp: 2, zoom: 0, shared: 0 } }, { total: 9, waiting: 2, failed: 1, pinned: 0 }, null);
    expect(places.map((p) => [p.label, p.count])).toEqual([
      ["All", 9], ["Waiting", 3], ["Pinned", 3], ["Failed uploads", 3], ["Inbox", 4], ["WhatsApp", 2], ["Shared with me", 0],
    ]);
    expect(places.find((p) => p.label === "Inbox")?.drop).toBe(true);
    expect(places.filter((p) => p.drop)).toHaveLength(1);
  });

  it("keeps the views on a server that counts no folders", () => {
    expect(libraryPlaces(null, { total: 0, waiting: 0, failed: 0, pinned: 0 }, null).map((p) => p.label)).toEqual(["All", "Waiting", "Pinned", "Failed uploads"]);
  });
});

describe("the workspace switcher (v1.6, P4.3)", () => {
  const account = { name: "Sara", email: "s@example.test", email_verified: true, two_factor: false, google: false, created_at: null,
    workspace: { id: 2, name: "Acme team", role: "member" }, deletes_at: null };
  it("lists the server's workspaces with the current one ticked, else the one this computer works in", () => {
    const status = makeStatus();
    expect(workspacesOf({ ...account, workspaces: [{ id: 1, name: "Personal", role: "owner" }, { id: 2, name: "Acme team", role: "member" }] }, status)
      .map((w) => [w.name, w.current])).toEqual([["Personal", false], ["Acme team", true]]);
    expect(workspacesOf(account, status)).toEqual([{ key: "current", name: "Acme team", role: "member", current: true }]);
    expect(workspacesOf(null, makeStatus({ plan: { ...status.plan, workspace: null } }))[0].name).toBe("Personal");
  });
});
