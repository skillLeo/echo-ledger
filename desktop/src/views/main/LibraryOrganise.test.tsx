import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { LibraryView } from "./LibraryView";
import { BulkBar, OrganisePanel } from "./LibraryOrganise";
import type { LibraryItem } from "../../lib/ipc";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const CLIENTS = [{ id: "c1", name: "Acme Holdings", avatar: "AH" }, { id: "c2", name: "Northwind", pinned: true }];
const ITEMS: LibraryItem[] = [
  makeItem("a", { title: "Kickoff", started_at: new Date().toISOString(), pinned: true, labels: ["Invoice"], client: { id: "c1", name: "Acme Holdings", avatar: "AH" },
    transcript: { final_status: "completed", final_transcript_id: "tr-a", live_available: false, preview: null } }),
  makeItem("b", { title: "Old call", started_at: new Date(2026, 0, 5).toISOString(), status: "failed",
    transcript: { final_status: "failed", final_transcript_id: null, live_available: false, preview: null } }),
  makeItem("c", { title: "Briefing", started_at: new Date(2026, 0, 6).toISOString(), marker_count: 1,
    transcript: { final_status: "completed", final_transcript_id: "tr-c", live_available: false, preview: null } }),
];

beforeEach(() => {
  resetTauriMock();
  respond("library_list", ITEMS);
  respond("library_refresh", ITEMS);
  respond("list_clients", { data: CLIENTS });
  respondWith("transcript_detail", ({ id }) => makeTranscript({ id: id as string }));
  respondWith("session_detail", ({ id }) => ({ id, playback_url: null }));
});

async function renderLibrary() {
  const notify = vi.fn();
  render(<LibraryView notify={notify} />);
  await flushAsync();
  await within(screen.getByTestId("library-list")).findByText("Kickoff");
  return notify;
}

const list = () => screen.getByTestId("library-list");
const titles = () => [...list().querySelectorAll(".item-title")].map((n) => n.textContent?.replace("★ ", ""));
const views = () => screen.getByRole("group", { name: "Smart views" });

describe("library organisation (B15)", () => {
  it("shows smart views with counts and filters the list", async () => {
    await renderLibrary();
    expect(within(views()).getByRole("button", { name: /Pinned/ })).toHaveTextContent("Pinned1");
    fireEvent.click(within(views()).getByRole("button", { name: /Failed/ }));
    expect(titles()).toEqual(["Old call"]);
    fireEvent.click(within(views()).getByRole("button", { name: /Bookmarked/ }));
    expect(titles()).toEqual(["Briefing"]);
    fireEvent.click(within(views()).getByRole("button", { name: /Today/ }));
    expect(titles()).toEqual(["Kickoff"]);
    fireEvent.click(within(views()).getByRole("button", { name: /Waiting for decision/ }));
    expect(screen.getByText(/Recordings waiting for Keep or Discard on this computer are listed at the top/)).toBeInTheDocument();
  });

  it("marks pinned recordings and shows their labels", async () => {
    await renderLibrary();
    const card = within(list()).getByText("Kickoff").closest("button") as HTMLElement;
    expect(within(card).getByLabelText("Pinned")).toBeInTheDocument();
    expect(within(card).getByText("Invoice")).toBeInTheDocument();
  });

  it("filters by client folder and by label", async () => {
    await renderLibrary();
    fireEvent.change(screen.getByRole("combobox", { name: "Client folder" }), { target: { value: "c1" } });
    expect(titles()).toEqual(["Kickoff"]);
    fireEvent.change(screen.getByRole("combobox", { name: "Client folder" }), { target: { value: "" } });
    expect(titles()).toEqual(["Briefing", "Old call"]);
    fireEvent.change(screen.getByRole("combobox", { name: "Client folder" }), { target: { value: "*" } });
    fireEvent.change(screen.getByRole("combobox", { name: "Label" }), { target: { value: "Invoice" } });
    expect(titles()).toEqual(["Kickoff"]);
  });

  it("selects several recordings and runs a bulk action on them", async () => {
    respond("sessions_bulk", 2);
    const notify = await renderLibrary();
    fireEvent.click(screen.getByRole("button", { name: "Select" }));
    expect(screen.getByRole("toolbar", { name: "Selected recordings" })).toHaveTextContent("Select recordings");
    fireEvent.click(within(list()).getByText("Old call").closest("button")!);
    fireEvent.click(within(list()).getByText("Briefing").closest("button")!);
    expect(within(list()).getByText("Briefing").closest("button")).toHaveAttribute("aria-pressed", "true");
    expect(screen.getByRole("toolbar", { name: "Selected recordings" })).toHaveTextContent("2 selected");
    expect(document.querySelector(".detail h2")).toBeNull(); // selecting does not open a recording

    fireEvent.click(screen.getByRole("button", { name: "Pin" }));
    await waitFor(() => expect(callsOf("sessions_bulk")).toEqual([{ ids: ["b", "c"], action: "pin", clientId: null, label: null }]));
    expect(notify).toHaveBeenCalledWith("2 recordings updated");
  });
});

describe("organise panel", () => {
  const item = ITEMS[0];
  const renderPanel = () => {
    const notify = vi.fn();
    render(<OrganisePanel item={item} clients={CLIENTS} knownLabels={["Invoice", "Q4"]} notify={notify} onClientCreated={vi.fn()} />);
    return notify;
  };

  it("pins, moves and edits labels with one change each", async () => {
    respondWith("session_organise", () => ({ data: item }));
    renderPanel();
    fireEvent.click(screen.getByRole("button", { name: "Pinned" }));
    await waitFor(() => expect(callsOf("session_organise")).toHaveLength(1));
    fireEvent.change(screen.getByRole("combobox", { name: "Client" }), { target: { value: "c2" } });
    await waitFor(() => expect(callsOf("session_organise")).toHaveLength(2));
    fireEvent.click(screen.getByRole("button", { name: "Remove label Invoice" }));
    await waitFor(() => expect(callsOf("session_organise")).toHaveLength(3));
    fireEvent.change(screen.getByLabelText("Add a label"), { target: { value: " Follow up " } });
    fireEvent.keyDown(screen.getByLabelText("Add a label"), { key: "Enter" });
    await waitFor(() => expect(callsOf("session_organise")).toHaveLength(4));
    expect(callsOf("session_organise").map((c) => c.patch)).toEqual([
      { pinned: false }, { client_id: "c2" }, { labels: [] }, { labels: ["Invoice", "Follow up"] },
    ]);
  });

  it("lists folders pinned first and creates a new one", async () => {
    respond("client_create", { data: { id: "c3", name: "Globex" } });
    respondWith("session_organise", () => ({ data: item }));
    renderPanel();
    const select = screen.getByRole("combobox", { name: "Client" }) as HTMLSelectElement;
    expect([...select.options].map((o) => o.text)).toEqual(["No client", "NO · Northwind", "AH · Acme Holdings", "New client…"]);
    fireEvent.change(select, { target: { value: "__new__" } });
    fireEvent.change(screen.getByLabelText("New client name"), { target: { value: "Globex" } });
    fireEvent.click(screen.getByRole("button", { name: "Create" }));
    await waitFor(() => expect(callsOf("session_organise")).toEqual([{ id: "a", patch: { client_id: "c3" } }]));
    expect(callsOf("client_create")).toEqual([{ name: "Globex", color: null, avatar: null }]);
  });

  it("says why a change failed", async () => {
    fail("session_organise", "A recording can have at most 20 labels.");
    const notify = renderPanel();
    fireEvent.change(screen.getByLabelText("Add a label"), { target: { value: "Q4" } });
    fireEvent.keyDown(screen.getByLabelText("Add a label"), { key: "Enter" });
    await waitFor(() => expect(notify).toHaveBeenCalledWith("A recording can have at most 20 labels.", true));
  });
});

describe("bulk bar", () => {
  const renderBar = (ids = ["a", "b"]) => {
    const props = { notify: vi.fn(), onDone: vi.fn() };
    render(<BulkBar ids={ids} clients={CLIENTS} knownLabels={["Invoice"]} onClientCreated={vi.fn()} {...props} />);
    return props;
  };

  it("moves, labels and exports the selection", async () => {
    respond("sessions_bulk", 2);
    respond("sessions_export", "C:\\Users\\me\\Downloads\\EchoLedger transcripts.zip");
    const { notify } = renderBar();
    fireEvent.change(screen.getByRole("combobox", { name: "Move to" }), { target: { value: "c1" } });
    await waitFor(() => expect(callsOf("sessions_bulk")).toHaveLength(1));
    fireEvent.change(screen.getByLabelText("Label"), { target: { value: "Q4" } });
    fireEvent.click(screen.getByRole("button", { name: "Add label" }));
    await waitFor(() => expect(callsOf("sessions_bulk")).toHaveLength(2));
    expect(callsOf("sessions_bulk")).toEqual([
      { ids: ["a", "b"], action: "move", clientId: "c1", label: null },
      { ids: ["a", "b"], action: "label", clientId: null, label: "Q4" },
    ]);
    fireEvent.change(screen.getByRole("combobox", { name: "Export format" }), { target: { value: "txt" } });
    fireEvent.click(screen.getByRole("button", { name: "Export transcripts" }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Saved C:\\Users\\me\\Downloads\\EchoLedger transcripts.zip"));
    expect(callsOf("sessions_export")).toEqual([{ ids: ["a", "b"], format: "txt" }]);
  });

  it("asks once more before deleting and then closes", async () => {
    respond("sessions_bulk", 2);
    const { onDone, notify } = renderBar();
    fireEvent.click(screen.getByRole("button", { name: "Delete" }));
    expect(callsOf("sessions_bulk")).toHaveLength(0);
    fireEvent.click(screen.getByRole("button", { name: "Delete 2 (restorable for 48 hours)" }));
    await waitFor(() => expect(onDone).toHaveBeenCalled());
    expect(notify).toHaveBeenCalledWith("2 deleted");
  });

  it("reports when nothing could be exported", async () => {
    fail("sessions_export", "None of these recordings has a finished transcript.");
    const { notify } = renderBar(["b"]);
    fireEvent.click(screen.getByRole("button", { name: "Export transcripts" }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("None of these recordings has a finished transcript.", true));
  });
});
