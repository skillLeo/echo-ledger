// Contract 2.0.0-aa (UX-18) and lead decision 27: an Activity item about a person shows them in the icon's place, for
// the kinds that show a face (a recording sent to you, an upgrade asked for), as the web console does: their picture,
// kept here by its version (api.avatarImage, asked once per picture for the whole window), else their initials in
// their colour. Every other kind keeps its icon, whoever it is about; with `actor` null, no key (an older server) or
// something that is no person, the kind's icon as before.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeStatus } from "../../test/fixtures";
import { ActivityV2 } from "./ActivityV2";
import { forgetPersonPictures } from "./ActorAvatar";
import { PERSON_KINDS, personColour, personOf, shownPerson } from "../../lib/activityPerson";
import { initials } from "../settings/initials";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 10, 25, 15, 0);
const PHOTO = "data:image/webp;base64,UklGRg==";
const at = new Date(2026, 10, 25, 9, 0).toISOString();
const ANA = { id: 12, name: "Ana Malik", avatar_url: "https://ledger.example.test/api/avatars/12/1763700000?signature=x", avatar_version: 1763700000, avatar_color: 3 };
const SARA = { id: 7, name: "Sara Khan", avatar_url: null, avatar_version: null, avatar_color: 5 };
const ITEMS = [
  { id: "a1", kind: "shared_with_you", title: "Ana sent you Call with Omar", body: "for tomorrow", session_id: "s1", read: false, at, actor: ANA },
  { id: "a2", kind: "upgrade_requested", title: "Sara asked to upgrade to Pro", body: "", session_id: null, read: false, at, actor: SARA },
  { id: "a3", kind: "transcript_ready", title: "Transcript ready", body: "Acme kickoff", session_id: "s3", read: true, at, actor: null },
  { id: "a4", kind: "device_paired", title: "Pixel 9 paired", body: "It can now record.", session_id: null, read: true, at },
  // A warning or a receipt asked for keeps its tile, the only sign of what it is, even about a person.
  { id: "a5", kind: "upgrade_receipt_requested", title: "Sara asked for your receipt", body: "", session_id: null, read: false, at, actor: SARA },
  { id: "a6", kind: "unusual_usage", title: "Unusual use on your account", body: "", session_id: null, read: false, at, actor: ANA },
  // Nothing that is no person costs the row: the kind's icon.
  { id: "a7", kind: "shared_with_you", title: "Someone sent you Budget", body: "", session_id: "s7", read: true, at, actor: "Ana" },
  { id: "a8", kind: "shared_with_you", title: "Someone sent you Notes", body: "", session_id: "s8", read: true, at, actor: { id: 9, name: 42 } },
  // The same picture again: asked once.
  { id: "a9", kind: "shared_with_you", title: "Ana sent you Board call", body: "", session_id: "s9", read: true, at, actor: ANA },
];

beforeEach(() => {
  resetTauriMock();
  forgetPersonPictures();
  vi.useFakeTimers({ toFake: ["Date"], now: NOW });
  respond("pending_list", []);
  respond("skipped_list", []);
  respond("upload_list", []);
  respond("library_list", []);
  respond("recovered_sessions", []);
  respond("activity_list", { data: ITEMS, unread_count: 4 });
  respondWith("account_avatar_image", (args) => ((args as { version: number }).version === 1763700000 ? PHOTO : null));
});
afterEach(() => vi.useRealTimers());

async function show() {
  render(<div className="el-root el-shell--nav"><ActivityV2 status={makeStatus()} onOpenRecording={vi.fn()} onReview={vi.fn()} onFailed={vi.fn()} onItems={vi.fn()} say={vi.fn()} /></div>);
  await act(flushAsync);
  await act(flushAsync);
}
const rowOf = (title: string) => screen.getByText(title).closest("li") as HTMLElement;
const keepsIcon = (title: string) => {
  expect(rowOf(title).querySelector(".el-avatar"), title).toBeNull();
  expect(rowOf(title).querySelector(".el-row__leading .el-icon"), title).not.toBeNull();
};

describe("Activity: the person an item is about (2.0.0-aa)", () => {
  it("their picture in the icon's place, asked for once per picture; the row is still named by its title alone", async () => {
    await show();
    const avatar = rowOf("Ana sent you Call with Omar").querySelector(".el-avatar")!;
    expect(avatar).toHaveAttribute("aria-hidden", "true");
    expect(avatar.querySelector("img")).toHaveAttribute("src", PHOTO);
    expect(rowOf("Ana sent you Board call").querySelector(".el-avatar img")).toHaveAttribute("src", PHOTO);
    expect(callsOf("account_avatar_image")).toEqual([{ url: ANA.avatar_url, version: 1763700000 }]);
    expect(screen.getByRole("button", { name: "Ana sent you Call with Omar" })).toBeInTheDocument();
  });

  it("without a picture, their initials in their colour", async () => {
    await show();
    const avatar = rowOf("Sara asked to upgrade to Pro").querySelector(".el-avatar")!;
    expect(avatar).toHaveTextContent("SK");
    expect(avatar).toHaveAttribute("data-colour", "5");
    expect(avatar.querySelector("img")).toBeNull();
  });

  it("only the kinds that show a face: a receipt asked for and unusual use keep their icons, whoever they are about", async () => {
    expect([...PERSON_KINDS]).toEqual(["shared_with_you", "upgrade_requested"]);
    await show();
    keepsIcon("Sara asked for your receipt");
    keepsIcon("Unusual use on your account");
  });

  it("with actor null, no key from an older server, or something that is no person: the kind's icon, and the page stands", async () => {
    await show();
    for (const title of ["Transcript ready", "Pixel 9 paired", "Someone sent you Budget", "Someone sent you Notes"]) keepsIcon(title);
    expect(personOf("Ana")).toBeNull();
    expect(personOf({ id: 9, name: 42 })).toBeNull();
    expect(personOf([ANA])).toBeNull();
    expect(personOf({ id: 3, name: null })).toMatchObject({ id: 3, name: null, avatar_url: null });
    expect(shownPerson({ kind: "unusual_usage", actor: ANA })).toBeNull();
  });

  it("a picture the server no longer gives (403, 404: the core's null) shows the initials, and isn't asked for again", async () => {
    respondWith("account_avatar_image", () => null);
    await show();
    const avatar = rowOf("Ana sent you Call with Omar").querySelector(".el-avatar")!;
    expect(avatar.querySelector("img")).toBeNull();
    expect(avatar).toHaveTextContent("AM");
    expect(avatar).toHaveAttribute("data-colour", "3");
    expect(callsOf("account_avatar_image")).toHaveLength(1);
  });

  it("the core's call failing (locked, an error) shows the initials", async () => {
    fail("account_avatar_image", "EchoLedger is locked.");
    await show();
    const avatar = rowOf("Ana sent you Call with Omar").querySelector(".el-avatar")!;
    expect(avatar.querySelector("img")).toBeNull();
    expect(avatar).toHaveTextContent("AM");
  });

  it("offline or the server busy (an error, not null) isn't kept: the next time the page shows, it asks again", async () => {
    fail("account_avatar_image", "The server could not be reached.");
    await show();
    expect(rowOf("Ana sent you Call with Omar").querySelector(".el-avatar img")).toBeNull();
    expect(callsOf("account_avatar_image")).toHaveLength(1);
    cleanup();
    respondWith("account_avatar_image", () => PHOTO);
    await show();
    expect(callsOf("account_avatar_image")).toHaveLength(2);
    expect(rowOf("Ana sent you Call with Omar").querySelector(".el-avatar img")).toHaveAttribute("src", PHOTO);
    // No picture to have (null) is kept until the next sign-in forgets it.
    cleanup();
    forgetPersonPictures();
    respondWith("account_avatar_image", () => null);
    await show();
    cleanup();
    await show();
    expect(callsOf("account_avatar_image")).toHaveLength(3);
  });

  it("a picture that won't draw shows the initials", async () => {
    await show();
    const img = rowOf("Ana sent you Call with Omar").querySelector(".el-avatar img")!;
    fireEvent.error(img);
    const avatar = rowOf("Ana sent you Call with Omar").querySelector(".el-avatar")!;
    expect(avatar.querySelector("img")).toBeNull();
    expect(avatar).toHaveTextContent("AM");
  });

  it("their colour: the server's, else the id modulo 8 (the server's rule), else from the name", () => {
    expect(personColour({ id: 12, avatar_color: 3 })).toBe(3);
    expect(personColour({ id: 13, avatar_color: null })).toBe(5);
    expect(personColour({ id: "21", avatar_color: 9 })).toBe(5);
    expect(personColour({ id: "01JB", avatar_color: null })).toBeNull();
  });

  it("an Urdu name's two initials stay two letters (a zero-width non-joiner, as the web)", () => {
    expect(initials("عائشہ خان", null)).toBe("ع‌خ");
    expect(initials("عائشہ", null)).toBe("ع");
    expect(initials("Ana Malik", null)).toBe("AM");
  });
});
