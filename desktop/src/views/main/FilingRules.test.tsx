// Filing rules on a project's page (docs/API.md "Filing rules", since v1.5): the list, adding a title, app or contact
// rule, removing one, and the core's refusals in plain words.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { anyTarget } from "../../test/fixtures";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { RulesSection } from "./FilingRules";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const PROJECT = { id: "c1", name: "Acme" };
const RULES = [{ id: 7, kind: "title", value: "Acme", created_at: "2026-11-04T09:00:00Z" }, { id: 8, kind: "app", value: "zoom", created_at: null }];

async function renderRules(rules: unknown = { data: RULES }) {
  respond("client_rules", rules);
  const notify = vi.fn();
  render(<RulesSection project={PROJECT} notify={notify} />);
  await flushAsync();
  return notify;
}
const edit = () => fireEvent.click(screen.getByRole("button", { name: "Edit rules" }));

beforeEach(() => resetTauriMock());
afterEach(async () => {
  await flushAsync();
  vi.restoreAllMocks();
});

describe("filing rules", () => {
  it("sums the rules up and lists them to edit", async () => {
    await renderRules();
    expect(screen.getByText("Title contains “Acme” · From Zoom")).toBeInTheDocument();
    edit();
    const list = screen.getByRole("list", { name: "Acme filing rules" });
    expect(within(list).getAllByRole("listitem").map((li) => li.textContent)).toEqual(["Title contains “Acme”", "From Zoom"]);
    expect(callsOf("client_rules")).toEqual([{ id: "c1" }]);
  });

  it("adds a title rule", async () => {
    respondWith("client_rule_add", ({ kind, value }) => ({ data: { id: 9, kind, value } }));
    const notify = await renderRules({ data: [] });
    expect(screen.getByText("None yet")).toBeInTheDocument();
    edit();
    expect(screen.getByRole("button", { name: "Add rule" })).toBeDisabled();
    fireEvent.change(screen.getByRole("textbox", { name: "Title contains" }), { target: { value: "  weekly sync " } });
    fireEvent.click(screen.getByRole("button", { name: "Add rule" }));
    await waitFor(() => expect(callsOf("client_rule_add")).toEqual([{ id: "c1", kind: "title", value: "weekly sync" }]));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Rule added: Title contains “weekly sync”", false, anyTarget));
    expect(within(await screen.findByRole("list", { name: "Acme filing rules" })).getByText("Title contains “weekly sync”")).toBeInTheDocument();
    expect(screen.getByRole("textbox", { name: "Title contains" })).toHaveValue("");
  });

  it("adds an app rule from the list of apps, and a contact", async () => {
    respondWith("client_rule_add", ({ kind, value }) => ({ data: { id: String(value), kind, value } }));
    await renderRules({ data: [] });
    edit();
    fireEvent.change(screen.getByRole("combobox", { name: "Kind of rule" }), { target: { value: "app" } });
    const apps = screen.getByRole("combobox", { name: "App" }) as HTMLSelectElement;
    expect([...apps.options].map((o) => o.text)).toContain("Microsoft Teams");
    fireEvent.change(apps, { target: { value: "microsoft_teams" } });
    fireEvent.click(screen.getByRole("button", { name: "Add rule" }));
    await waitFor(() => expect(callsOf("client_rule_add")).toEqual([{ id: "c1", kind: "app", value: "microsoft_teams" }]));
    fireEvent.change(screen.getByRole("combobox", { name: "Kind of rule" }), { target: { value: "contact" } });
    fireEvent.change(screen.getByRole("textbox", { name: "Contact number or email" }), { target: { value: "+92 300 1234567" } });
    fireEvent.click(screen.getByRole("button", { name: "Add rule" }));
    await waitFor(() => expect(callsOf("client_rule_add")[1]).toEqual({ id: "c1", kind: "contact", value: "+92 300 1234567" }));
    expect(await screen.findByText("Contact +92 300 1234567")).toBeInTheDocument();
  });

  it("says in plain words which project already has the rule", async () => {
    fail("client_rule_add", "Zed Logistics already has this rule. Remove it there first, or file these recordings by something else.");
    await renderRules();
    edit();
    fireEvent.change(screen.getByRole("textbox", { name: "Title contains" }), { target: { value: "weekly" } });
    fireEvent.click(screen.getByRole("button", { name: "Add rule" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Zed Logistics already has this rule.");
    expect(screen.getByRole("textbox", { name: "Title contains" })).toHaveValue("weekly");
  });

  it("removes a rule", async () => {
    respond("client_rule_delete", undefined);
    await renderRules();
    edit();
    fireEvent.click(screen.getByRole("button", { name: "Remove the rule From Zoom" }));
    await waitFor(() => expect(callsOf("client_rule_delete")).toEqual([{ id: "c1", rule: "8" }]));
    await waitFor(() => expect(screen.queryByText("From Zoom")).not.toBeInTheDocument());
  });

  it("says when the rules could not be read", async () => {
    fail("client_rules", "Network error: offline");
    render(<RulesSection project={PROJECT} notify={vi.fn()} />);
    expect(await screen.findByText("Couldn't load them. Check the connection.")).toBeInTheDocument();
  });

  it("is not shown on a server without filing rules", async () => {
    await renderRules(null);
    expect(screen.queryByText("Filing rules")).not.toBeInTheDocument();
  });
});
