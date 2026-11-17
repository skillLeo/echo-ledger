import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import MainApp from "../../views/main/MainApp";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeSettings, makeStatus } from "../../test/fixtures";
import { AskV2, OFFLINE, TRY_AGAIN, askFailureWords, citationValue } from "./AskV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const PROJECTS = [{ id: "c1", name: "Qureshi Textiles" }, { id: "c2", name: "Mehran Foods" }];
const ANSWER = {
  data: {
    answer: "Bilal agreed to 40% in advance and the balance against the bill of lading.",
    citations: [
      { type: "call" as const, session_id: "bilal", title: "Payment terms for the March shipment", at_ms: 31_000, quote: "This time we need forty percent in advance." },
      { type: "file" as const, file_id: "f1", title: "Proforma March.pdf", page: 2, quote: null },
    ],
  },
};

beforeEach(() => resetTauriMock());

const props = () => ({ onOpenAt: vi.fn(), onOpenProject: vi.fn(), onLibrary: vi.fn() });

describe("Ask v2 (as on the phone)", () => {
  it("no projects: says Ask works on a project's recordings, with a way to Library", () => {
    const p = props();
    render(<AskV2 projects={[]} {...p} />);
    expect(screen.getByRole("region", { name: "No projects yet" })).toHaveTextContent("Put recordings in a project from Library first.");
    fireEvent.click(screen.getByRole("button", { name: "Open Library" }));
    expect(p.onLibrary).toHaveBeenCalled();
  });

  it("before asking: the project, the question and the idle state; All recordings isn't offered until the server has it", () => {
    render(<AskV2 projects={PROJECTS} {...props()} />);
    expect(screen.getByRole("combobox", { name: /Ask about/ })).toHaveAccessibleName("Ask about Qureshi Textiles");
    expect(screen.queryByText(/All recordings/)).toBeNull();
    fireEvent.click(screen.getByRole("combobox", { name: /Ask about/ }));
    expect(screen.getAllByRole("option").map((o) => o.textContent)).toEqual(PROJECTS.map((p) => p.name));
    expect(screen.getByRole("button", { name: "Ask" })).toBeDisabled();
    expect(screen.getByRole("region", { name: "Ask your recordings" })).toBeInTheDocument();
  });

  it("asks the chosen project in the chosen language and shows the answer with where it comes from", async () => {
    let answer: (v: unknown) => void = () => undefined;
    respondWith("client_ask", () => new Promise((r) => (answer = r)));
    const p = props();
    render(<AskV2 projects={PROJECTS} {...p} />);
    fireEvent.click(screen.getByRole("combobox", { name: /Ask about/ }));
    fireEvent.click(screen.getByRole("option", { name: "Mehran Foods" }));
    fireEvent.change(screen.getByRole("textbox", { name: "Your question" }), { target: { value: "What did Bilal agree on payment?" } });
    fireEvent.click(screen.getByRole("radio", { name: "Roman Urdu" }));
    fireEvent.click(screen.getByRole("button", { name: "Ask" }));
    expect(screen.getByRole("status", { busy: true })).toHaveTextContent("Asking");
    expect(callsOf("client_ask")).toEqual([{ id: "c2", question: "What did Bilal agree on payment?", language: "roman_urdu" }]);
    await act(async () => answer(ANSWER));
    expect(screen.getByText(ANSWER.data.answer)).toBeInTheDocument();
    const from = screen.getByRole("list", { name: "From" });
    const call = within(from).getByRole("button", { name: "Payment terms for the March shipment" });
    expect(call).toHaveAccessibleDescription(/forty percent in advance.*0:31/);
    fireEvent.click(call);
    expect(p.onOpenAt).toHaveBeenCalledWith("bilal", 31_000);
    fireEvent.click(within(from).getByRole("button", { name: "Proforma March.pdf" }));
    expect(p.onOpenProject).toHaveBeenCalledWith("c2");
  });

  it("a failed answer says so in plain words, with Ask again", async () => {
    fail("client_ask", "Network error: error sending request");
    render(<AskV2 projects={PROJECTS} {...props()} />);
    fireEvent.change(screen.getByRole("textbox", { name: "Your question" }), { target: { value: "When is the delivery?" } });
    fireEvent.click(screen.getByRole("button", { name: "Ask" }));
    await act(flushAsync);
    const alert = screen.getByRole("alert");
    expect(alert).toHaveTextContent("The answer couldn't be made. Check the internet connection and try again.");
    respond("client_ask", ANSWER);
    fireEvent.click(within(alert).getByRole("button", { name: "Ask" }));
    await act(flushAsync);
    expect(screen.getByText(ANSWER.data.answer)).toBeInTheDocument();
  });

  it("failures: the connection only when offline, the server's own sentence for people, else Try again", () => {
    expect(askFailureWords("Network error: dns error", true)).toBe(OFFLINE);
    expect(askFailureWords("This month's AI budget ($5.00) is used up. Raise it in Settings → AI.", false)).toBe(OFFLINE);
    expect(askFailureWords("This month's AI budget ($5.00) is used up. Raise it in Settings → AI.", true)).toBe("This month's AI budget ($5.00) is used up. Raise it in Settings → AI.");
    expect(askFailureWords("AI isn't set up on the server yet. Ask the person who runs the console.", true)).toBe("AI isn't set up on the server yet. Ask the person who runs the console.");
    expect(askFailureWords("Server returned 502: Bad gateway", true)).toBe(TRY_AGAIN);
    expect(askFailureWords("The speech engine could not be reached. Try again in a moment.", true)).toBe(TRY_AGAIN);
    expect(askFailureWords(new Error(""), true)).toBe(TRY_AGAIN);
  });

  it("another project puts the answer away", async () => {
    respond("client_ask", ANSWER);
    render(<AskV2 projects={PROJECTS} {...props()} />);
    fireEvent.change(screen.getByRole("textbox", { name: "Your question" }), { target: { value: "When is the delivery?" } });
    fireEvent.click(screen.getByRole("button", { name: "Ask" }));
    await act(flushAsync);
    expect(screen.getByText(ANSWER.data.answer)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("combobox", { name: /Ask about/ }));
    fireEvent.click(screen.getByRole("option", { name: "Mehran Foods" }));
    expect(screen.queryByText(ANSWER.data.answer)).toBeNull();
    expect(screen.getByRole("region", { name: "Ask your recordings" })).toBeInTheDocument();
    expect(screen.getByRole("textbox", { name: "Your question" })).toHaveValue("When is the delivery?");
  });

  it("offers the answer languages the server takes", () => {
    render(<AskV2 projects={PROJECTS} {...props()} />);
    expect(screen.getAllByRole("radio").map((r) => r.textContent)).toEqual(["English", "Roman Urdu"]);
  });

  it("a source's line: the moment of a call, the page of a file", () => {
    expect(citationValue({ type: "call", title: "x", at_ms: 754_000 })).toBe("12:34");
    expect(citationValue({ type: "file", title: "x", page: 3 })).toBe("Page 3");
    expect(citationValue({ type: "call", title: "x" })).toBeUndefined();
  });
});

describe("the app with ui_v2.ask", () => {
  it("the shell's Ask opens Ask on the projects", async () => {
    respond("app_status", makeStatus());
    respond("library_list", [makeItem("a")]);
    respond("library_refresh", [makeItem("a")]);
    respond("get_settings", makeSettings());
    respond("list_clients", { data: [{ id: "c1", name: "Qureshi Textiles" }] });
    respond("mic_users", []);
    respond("session_detail", {});
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.ask": true }, ui_classic: false });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(within(await screen.findByRole("navigation", { name: "Main" })).getByRole("button", { name: "Ask" }));
    await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "Ask" })).toBeInTheDocument();
    expect(screen.getByRole("combobox", { name: /Ask about/ })).toHaveAccessibleName("Ask about Qureshi Textiles");
  });
});
