// Contract 2.0.0-aa (UX-18): an Activity item about a person shows them in the icon's place: their picture, kept here by
// its version (api.avatarImage), else their initials in their colour; with `actor` null, or no key from an older server,
// the kind's icon as before.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen } from "@testing-library/react";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeStatus } from "../../test/fixtures";
import { ActivityV2 } from "./ActivityV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 10, 25, 15, 0);
const PHOTO = "data:image/webp;base64,UklGRg==";
const at = new Date(2026, 10, 25, 9, 0).toISOString();
const ITEMS = [
  { id: "a1", kind: "shared_with_you", title: "Ana sent you Call with Omar", body: "for tomorrow", session_id: "s1", read: false, at,
    actor: { id: 12, name: "Ana Malik", avatar_url: "https://ledger.example.test/api/avatars/12/1763700000?signature=x", avatar_version: 1763700000, avatar_color: 3 } },
  { id: "a2", kind: "receipt_requested", title: "Sara asked for a receipt", body: "", session_id: null, read: false, at,
    actor: { id: 7, name: "Sara Khan", avatar_url: null, avatar_version: null, avatar_color: 5 } },
  { id: "a3", kind: "transcript_ready", title: "Transcript ready", body: "Acme kickoff", session_id: "s3", read: true, at, actor: null },
  { id: "a4", kind: "device_paired", title: "Pixel 9 paired", body: "It can now record.", session_id: null, read: true, at },
];

beforeEach(() => {
  resetTauriMock();
  vi.useFakeTimers({ toFake: ["Date"], now: NOW });
  respond("pending_list", []);
  respond("skipped_list", []);
  respond("upload_list", []);
  respond("library_list", []);
  respond("recovered_sessions", []);
  respond("activity_list", { data: ITEMS, unread_count: 2 });
  respondWith("account_avatar_image", (args) => ((args as { version: number }).version === 1763700000 ? PHOTO : null));
});
afterEach(() => vi.useRealTimers());

async function show() {
  render(<div className="el-root el-shell--nav"><ActivityV2 status={makeStatus()} onOpenRecording={vi.fn()} onReview={vi.fn()} onFailed={vi.fn()} onItems={vi.fn()} say={vi.fn()} /></div>);
  await act(flushAsync);
  await act(flushAsync);
}
const rowOf = (title: string) => screen.getByText(title).closest("li") as HTMLElement;

describe("Activity: the person an item is about (2.0.0-aa)", () => {
  it("their picture in the icon's place, asked for by its version", async () => {
    await show();
    const avatar = rowOf("Ana sent you Call with Omar").querySelector(".el-avatar");
    expect(avatar).not.toBeNull();
    expect(avatar).toHaveAttribute("aria-hidden", "true");
    expect(avatar!.querySelector("img")).toHaveAttribute("src", PHOTO);
    expect(rowOf("Ana sent you Call with Omar").querySelector(".el-row__leading")).toBeNull();
    expect(callsOf("account_avatar_image")).toContainEqual({ url: ITEMS[0].actor!.avatar_url, version: 1763700000 });
  });

  it("without a picture, their initials in their colour", async () => {
    await show();
    const avatar = rowOf("Sara asked for a receipt").querySelector(".el-avatar")!;
    expect(avatar).toHaveTextContent("SK");
    expect(avatar).toHaveAttribute("data-colour", "5");
    expect(avatar.querySelector("img")).toBeNull();
  });

  it("with actor null, or no key from an older server, the kind's icon as before", async () => {
    await show();
    for (const title of ["Transcript ready", "Pixel 9 paired"]) {
      expect(rowOf(title).querySelector(".el-avatar"), title).toBeNull();
      expect(rowOf(title).querySelector(".el-row__leading .el-icon"), title).not.toBeNull();
    }
  });

  it("a picture the server no longer gives (403, 404: the core's null) shows the initials quietly", async () => {
    respondWith("account_avatar_image", () => null);
    await show();
    const avatar = rowOf("Ana sent you Call with Omar").querySelector(".el-avatar")!;
    expect(avatar.querySelector("img")).toBeNull();
    expect(avatar).toHaveTextContent("AM");
    expect(avatar).toHaveAttribute("data-colour", "3");
  });
});
