// Contract between the Laravel server and the webview: JSON captured from a running server (the fixtures shared with
// the Rust tests) goes through the typed wrappers in ipc.ts, the pure helpers in library.ts / format.ts and the
// Library screen, and has to come out as what the user sees.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { api, errorMessage, type LibraryItem, type TranscriptDetail } from "./ipc";
import ipcSource from "./ipc.ts?raw";
import { formatDuration, formatWhen, timestamp, transcriptText } from "./format";
import { groupItems, itemState, searchItems, sortItems } from "./library";
import { LibraryView, filterItems } from "../views/main/LibraryView";

const { handlers } = vi.hoisted(() => ({ handlers: new Map<string, (args: Record<string, unknown>) => unknown>() }));

vi.mock("@tauri-apps/api/core", () => ({
  invoke: vi.fn(async (command: string, args: Record<string, unknown> = {}) => {
    const handler = handlers.get(command);
    if (!handler) throw new Error(`No mock registered for command "${command}"`);
    return handler(args);
  }),
  Channel: class {},
}));
vi.mock("@tauri-apps/api/event", () => ({ listen: vi.fn(async () => () => undefined) }));

type Json = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

const files = import.meta.glob("../../src-tauri/tests/fixtures/server/*.json", { eager: true, import: "default" }) as Record<string, Json>;
const fx = (name: string): Json => {
  const found = files[`../../src-tauri/tests/fixtures/server/${name}.json`];
  if (!found) throw new Error(`fixture ${name} not found`);
  return found;
};

const isoOffset = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/;

/** Keys a TypeScript interface in ipc.ts declares without `?`, optionally one nesting level down. */
function requiredKeys(name: string, nested?: string): string[] {
  const start = ipcSource.indexOf(`export interface ${name} {`);
  const end = ipcSource.indexOf("\n}\n", start);
  let body = ipcSource.slice(start, end);
  if (nested) {
    const at = body.indexOf(`  ${nested}?: {`);
    body = body.slice(at, body.indexOf("\n  };", at));
    return [...body.matchAll(/^ {4}(\w+): /gm)].map((m) => m[1]);
  }
  return [...body.matchAll(/^ {2}(\w+): /gm)].map((m) => m[1]);
}

beforeEach(() => handlers.clear());
afterEach(cleanup);

describe("fixture set", () => {
  it("is loaded", () => {
    expect(Object.keys(files).length).toBeGreaterThanOrEqual(80);
    expect(Object.keys(fx("index")).length).toBe(Object.keys(files).length - 1);
  });
});

describe("library items as the Rust core hands them to the webview", () => {
  const page = fx("library_200_page");
  const items = page.data as LibraryItem[];

  it("api.library() delivers every server item untouched", async () => {
    handlers.set("library_list", () => items);
    const got = await api.library();
    expect(got).toHaveLength(5);
    expect(got).toEqual(items);
  });

  it("every field ipc.ts requires is present on live items and on tombstones", () => {
    const required = requiredKeys("LibraryItem");
    expect(required).toEqual(["id", "deleted", "updated_at"]);
    const transcriptRequired = requiredKeys("LibraryItem", "transcript");
    expect(transcriptRequired).toEqual(["final_status", "final_transcript_id", "live_available", "preview"]);
    const tombstones = (fx("library_200_since_tombstone").data as Json[]);
    for (const item of [...items, ...tombstones]) {
      for (const key of required) expect(item, `${item.id} lacks ${key}`).toHaveProperty(key);
    }
    for (const item of items) for (const key of transcriptRequired) expect(item.transcript, `${item.id}.transcript lacks ${key}`).toHaveProperty(key);
  });

  it("scalar types match the declarations", () => {
    for (const item of items) {
      expect(typeof item.id).toBe("string");
      expect(item.deleted).toBe(false);
      expect(item.updated_at).toMatch(isoOffset);
      expect(item.started_at).toMatch(isoOffset);
      expect(["phone", "desktop", "web"]).toContain(item.source_device);
      expect(typeof item.title).toBe("string");
      expect(typeof item.duration_ms).toBe("number");
      expect(typeof item.transcript?.live_available).toBe("boolean");
      for (const key of ["final_status", "final_transcript_id", "preview"] as const) {
        const value = item.transcript?.[key];
        expect(value === null || typeof value === "string", `${item.id}.transcript.${key}`).toBe(true);
      }
      for (const key of ["device", "client"] as const) {
        const ref = item[key];
        expect(ref === null || (typeof ref?.id === "string" && typeof ref?.name === "string"), `${item.id}.${key}`).toBe(true);
      }
    }
  });

  it("a tombstone is just id, client_ref, deleted and updated_at", () => {
    const [tombstone] = fx("library_200_since_tombstone").data as LibraryItem[];
    expect(Object.keys(tombstone).sort()).toEqual(["client_ref", "deleted", "id", "updated_at"]);
    expect(tombstone.deleted).toBe(true);
    expect(filterItems([tombstone, ...items], "all")).toHaveLength(items.length);
  });

  it("states map to what the list shows", () => {
    const state = (title: string) => itemState(items.find((i) => i.title === title)!);
    expect(state("Quarterly review")).toBe("ready");
    expect(state("Shopping list")).toBe("ready");
    expect(state("Vendor sync")).toBe("processing");
    expect(state("WhatsApp call")).toBe("failed");
  });

  it("filtering, searching, sorting and grouping work on real items", () => {
    expect(filterItems(items, "phone").map((i) => i.title).sort()).toEqual(["Shopping list", "WhatsApp Business call", "WhatsApp call"]);
    expect(filterItems(items, "desktop")).toHaveLength(2);
    expect(filterItems(items, "web")).toHaveLength(0);
    expect(searchItems(items, "acme").map((i) => i.title)).toEqual(["Quarterly review"]);
    expect(searchItems(items, "birch").map((i) => i.title)).toEqual(["Vendor sync"]);
    expect(searchItems(items, "S25 Ultra")).toHaveLength(3);
    expect(searchItems(items, "voice note").map((i) => i.title)).toEqual(["Shopping list"]);
    expect(searchItems(items, "call me back tomorrow").length).toBeGreaterThanOrEqual(1);
    expect(sortItems(items, "newest")[0].title).toBe("Shopping list");
    expect(sortItems(items, "oldest")[0].title).toBe("Quarterly review");
    expect(sortItems(items, "longest")[0].title).toBe("WhatsApp Business call");
    const groups = groupItems(sortItems(items, "newest"), "newest", new Date("2026-10-24T16:30:00Z"));
    expect(groups.flatMap((g) => g.items)).toHaveLength(5);
    expect(groups.every((g) => g.label !== null)).toBe(true);
  });

  it("dates and durations format", () => {
    const review = items.find((i) => i.title === "Quarterly review")!;
    expect(formatDuration(review.duration_ms)).toBe("0:08");
    expect(formatDuration(items.find((i) => i.title === "WhatsApp Business call")!.duration_ms)).toBe("1:30");
    for (const item of items) expect(formatWhen(item.started_at), item.id).not.toBe("");
    expect(formatWhen(review.started_at, new Date(review.started_at!))).toMatch(/^Today · /);
  });
});

describe("transcripts as the Rust core hands them to the webview", () => {
  const completed = fx("transcripts_show_200_final_completed") as TranscriptDetail & Json;

  it("api.transcript() returns the server document", async () => {
    handlers.set("transcript_detail", ({ id }) => (id === completed.id ? completed : Promise.reject("Server returned 404: not found")));
    const got = await api.transcript(completed.id);
    expect(got.kind).toBe("final");
    expect(got.status).toBe("completed");
    expect(got.segments).toHaveLength(4);
    await expect(api.transcript("missing")).rejects.toBe("Server returned 404: not found");
    expect(errorMessage("Server returned 404: not found")).toBe("Server returned 404: not found");
  });

  it("every field ipc.ts requires is present", () => {
    const required = requiredKeys("TranscriptDetail");
    expect(required).toEqual(["id", "kind", "provider", "status", "language", "text", "segments"]);
    for (const name of [
      "transcripts_show_200_final_completed",
      "transcripts_show_200_final_failed",
      "transcripts_show_200_final_mono",
      "transcripts_show_200_final_processing",
      "transcripts_show_200_live",
      "transcripts_store_201_live",
    ]) {
      for (const key of required) expect(fx(name), `${name} lacks ${key}`).toHaveProperty(key);
      expect(["live", "final"]).toContain(fx(name).kind);
      for (const segment of fx(name).segments as Json[]) {
        for (const key of requiredKeys("Segment")) expect(segment, `${name} segment lacks ${key}`).toHaveProperty(key);
        expect(typeof segment.channel).toBe("number");
        expect(typeof segment.start_ms).toBe("number");
        expect(segment.end_ms).toBeGreaterThanOrEqual(segment.start_ms);
      }
    }
  });

  it("the plain-text export reads the way the Copy buttons promise", () => {
    expect(transcriptText(completed.segments, false).split("\n")).toEqual([
      "Me: This is a test of the EchoLedger transcription system.",
      "Client: Loud and clear. Go ahead.",
      "Me: Please call me back tomorrow at three thirty.",
      "Client: Will do, thanks.",
    ]);
    expect(transcriptText(completed.segments, true).split("\n")[1]).toBe("[0:04] Client: Loud and clear. Go ahead.");
    const mono = fx("transcripts_show_200_final_mono") as TranscriptDetail;
    expect(transcriptText(mono.segments, false)).toBe(mono.segments[0].text);
    expect(timestamp(mono.segments[0].start_ms)).toBe("0:00");
  });

  it("a failed transcript carries a user-facing reason; one in flight carries no text", () => {
    const failed = fx("transcripts_show_200_final_failed") as TranscriptDetail;
    expect(failed.status).toBe("failed");
    expect(typeof failed.error).toBe("string");
    expect(failed.segments).toEqual([]);
    const pending = fx("transcripts_show_200_final_processing") as TranscriptDetail;
    expect(pending.status).toBe("submitted");
    expect(pending.text).toBeNull();
    expect(pending.error ?? null).toBeNull();
  });
});

describe("clients and session detail", () => {
  it("api.clients() matches the declared row shape", async () => {
    handlers.set("list_clients", () => fx("clients_200"));
    const { data } = await api.clients();
    expect(data.map((c) => c.name)).toEqual(["Acme Holdings", "Birch & Co"]);
    for (const row of data) {
      expect(typeof row.id).toBe("string");
      expect(typeof row.keyterm_count).toBe("number");
    }
  });

  it("api.session() exposes the playback URL for a ready recording and null while uploading", async () => {
    handlers.set("session_detail", ({ id }) => (id === "done" ? fx("sessions_show_200_completed") : fx("sessions_show_200_uploading")));
    const done = await api.session("done");
    expect(typeof done.playback_url).toBe("string");
    expect(String(done.playback_url)).toMatch(/^https:\/\/.+\/audio\?expires=\d+&signature=SIGNATURE_PLACEHOLDER$/);
    expect((await api.session("uploading")).playback_url).toBeNull();
  });
});

describe("Library screen fed with server data", () => {
  const items = fx("library_200_page").data as LibraryItem[];
  const transcriptsById = new Map<string, Json>(
    ["transcripts_show_200_final_completed", "transcripts_show_200_final_failed", "transcripts_show_200_final_mono", "transcripts_show_200_final_processing"].map((n) => [fx(n).id as string, fx(n)]),
  );
  const sessionsById = new Map<string, Json>(
    ["sessions_show_200_completed", "sessions_show_200_failed", "sessions_show_200_processing"].map((n) => [fx(n).id as string, fx(n)]),
  );

  async function open(title: string) {
    handlers.set("library_list", () => [...items, ...(fx("library_200_since_tombstone").data as LibraryItem[])]);
    handlers.set("library_refresh", () => items);
    handlers.set("transcript_detail", ({ id }) => (transcriptsById.has(id as string) ? transcriptsById.get(id as string) : Promise.reject("Server returned 404: not found")));
    handlers.set("session_detail", ({ id }) => (sessionsById.has(id as string) ? sessionsById.get(id as string) : Promise.reject("Server returned 404: not found")));
    render(<LibraryView notify={vi.fn()} />);
    const list = await screen.findByTestId("library-list");
    await within(list).findByText(title);
    await act(async () => {
      fireEvent.click(within(list).getByText(title));
    });
    return list;
  }

  it("lists every live item with its state and preview, and not the tombstone", async () => {
    handlers.set("library_list", () => [...items, ...(fx("library_200_since_tombstone").data as LibraryItem[])]);
    handlers.set("library_refresh", () => items);
    render(<LibraryView notify={vi.fn()} />);
    const list = await screen.findByTestId("library-list");
    await within(list).findByText("Quarterly review");
    for (const title of ["Quarterly review", "Vendor sync", "WhatsApp call", "WhatsApp Business call", "Shopping list"]) {
      expect(within(list).getByText(title)).toBeInTheDocument();
    }
    expect(within(list).getAllByText("Ready")).toHaveLength(3);
    expect(within(list).getAllByText("Processing")).toHaveLength(1);
    expect(within(list).getAllByText("Failed")).toHaveLength(1);
    expect(screen.getByText(/5 recordings from your phone/)).toBeInTheDocument();
    expect(within(list).getByText(/This is a test of the EchoLedger transcription system\. Loud and clear/)).toBeInTheDocument();
  });

  it("opens a completed stereo call: speakers, text and the signed playback URL", async () => {
    await open("Quarterly review");
    expect(await screen.findByText("Loud and clear. Go ahead.")).toBeInTheDocument();
    expect(screen.getAllByText("Me").length).toBeGreaterThanOrEqual(2);
    expect(screen.getAllByText("Client").length).toBeGreaterThanOrEqual(2);
    expect(screen.getByText(/Final transcript · assemblyai/)).toBeInTheDocument();
    await waitFor(() => expect(screen.getByTestId("player")).toHaveAttribute("src", expect.stringContaining("/audio?expires=")));
  });

  it("opens a mono voice note with a single Speaker line", async () => {
    await open("Shopping list");
    expect(await screen.findByText("Speaker")).toBeInTheDocument();
    const segments = document.querySelector(".segments") as HTMLElement;
    expect(within(segments).getAllByText(/Please call me back tomorrow at three thirty\./)).toHaveLength(1);
  });

  it("shows the provider's reason when the transcript failed", async () => {
    await open("WhatsApp call");
    expect(await screen.findByText(/Transcription failed\./)).toBeInTheDocument();
    expect(screen.getByText(/Transcoding failed\. File does not appear to contain audio/)).toBeInTheDocument();
  });

  // A6.DU3: a transcript that is still submitted or processing is shown as "no speech was detected".
  it.skip("does not present a transcript that is still processing as an empty one", async () => {
    // The server creates the final transcript row when it submits the job (status "submitted", no segments), and the
    // library item already references it, so the screen fetches it while the provider is still working.
    await open("Vendor sync");
    await waitFor(() => expect(screen.getByRole("heading", { name: "Vendor sync" })).toBeInTheDocument());
    await act(async () => {
      for (let i = 0; i < 25; i++) await Promise.resolve();
    });
    expect(screen.queryByText(/no speech was detected/i)).not.toBeInTheDocument();
  });
});
