// Desktop Urdu, D-259: the All projects page (ProjectsListV2: its cards and their lines, Sort, Request files and New
// project, the error, the loading and empty states, the tree of the page without the three levels, the name dialog's
// own words) and the app's two message regions (AppToastV2: Messages, Problems) in the account's language. Every count
// goes through tc(), every value sits in its own isolate, a project's line reads in its own words' direction. English
// is unchanged: the same words the page and classic's clientSummary have always said.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { ClientFolder, LibraryItem } from "../../lib/ipc";
import { flushAsync, resetTauriMock } from "../../test/tauri";
import { makeItem } from "../../test/fixtures";
import { FSI, PDI, resetLanguageForTests, setLanguage } from "../../i18n";
import { dayMonth } from "../../lib/format";
import { clientSummary } from "../../views/main/ClientAvatar";
import { nestedCount, projectRecordings } from "../../lib/projects";
import { setProjectsAccount } from "../shell/kept";
import { resetProjectSortForTests } from "../shell/projectSort";
import { AppToastV2 } from "../shell/AppToast";
import { NameDialogV2, NO_PROJECTS, noProjects, projectLine, ProjectsListV2 } from "./ProjectsListV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const iso = (value: string | number) => `${FSI}${value}${PDI}`;
/** Any Latin letter: what an Urdu line of the app's own words must not have (a project's name aside). */
const LATIN = /[A-Za-z]/;
const NOW = new Date(2026, 10, 26, 15, 0);
const at = (day: number, hour: number) => new Date(2026, 10, day, hour).toISOString();
const FOLDERS = [
  { id: "p1", name: "Acme", pinned: true, parent_id: null }, { id: "p2", name: "Contracts", parent_id: "p1" }, { id: "p7", name: "Invoices", parent_id: "p1" },
  { id: "p3", name: "Zed", parent_id: null }, { id: "p4", name: "Bilal Traders", parent_id: null },
] as unknown as ClientFolder[];
const ITEMS: LibraryItem[] = [
  makeItem("a", { title: "Kick-off", started_at: at(26, 9), client: { id: "p1", name: "Acme" } }),
  makeItem("b", { title: "Contract call", started_at: at(25, 11), client: { id: "p2", name: "Contracts" } }),
  makeItem("c", { title: "Zed call", started_at: at(12, 10), client: { id: "p3", name: "Zed" } }),
];
const page = (projects: ClientFolder[] | null, over: Partial<Parameters<typeof ProjectsListV2>[0]> = {}) => render(
  <ProjectsListV2 projects={projects} error={null} onRetry={() => undefined} items={ITEMS} expanded={new Set()} onToggle={() => undefined}
    onOpen={() => undefined} onNew={() => undefined} onRequest={() => undefined} overview {...over} />,
);
const cards = () => within(screen.getByRole("list", { name: /^(Projects|پروجیکٹس)$/ })).getAllByRole("button");
const line = (card: HTMLElement) => card.querySelector(".el-caption") as HTMLElement;
const urdu = () => act(() => setLanguage("ur", { root: false }));

beforeEach(() => {
  resetTauriMock();
  localStorage.clear();
  setProjectsAccount("ledger.example.test|ayesha@example.test|w1");
  resetProjectSortForTests();
  resetLanguageForTests();
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(NOW);
});
afterEach(async () => {
  await flushAsync();
  vi.useRealTimers();
  resetLanguageForTests();
});

describe("All projects in English, as it always was", () => {
  it("the cards' lines are classic's clientSummary and the folders; Sort, Request files, New project", async () => {
    page(FOLDERS);
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: "All projects" })).toBeInTheDocument();
    for (const p of FOLDERS.filter((f) => !f.parent_id)) {
      const folders = FOLDERS.filter((f) => f.parent_id === p.id).length;
      const classic = clientSummary(nestedCount(p, ITEMS, FOLDERS), projectRecordings(ITEMS, FOLDERS, p.id, true)[0]?.started_at);
      expect(projectLine(p, FOLDERS, ITEMS, folders)).toBe(`${classic}${folders > 0 ? ` · ${folders} folder${folders === 1 ? "" : "s"}` : ""}`);
    }
    expect(cards().map((c) => line(c).textContent)).toEqual(["2 recordings · Last call Today · 2 folders", "No recordings yet", `1 recording · Last call ${dayMonth(new Date(2026, 10, 12))}`]);
    expect(cards().map((c) => line(c).getAttribute("dir"))).toEqual(["auto", "auto", "auto"]);
    expect(cards()[0]).toHaveTextContent(/^AAcme, pinned/);
    expect(screen.getByTitle("Pinned")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Sort: Name" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Sort: Name" }));
    expect(within(screen.getByRole("menu", { name: "Sort projects" })).getAllByRole("menuitemradio").map((i) => i.textContent)).toEqual(["Name", "Recent"]);
    expect(screen.getByRole("button", { name: "Request files" })).toHaveAttribute("title", "A link anyone can use to send you voice notes and files");
    expect(screen.getByRole("button", { name: "New project" })).toBeInTheDocument();
  });

  it("the error, the loading and the empty states, the tree and the name dialog", async () => {
    const { rerender } = page(null);
    expect(screen.getByRole("status")).toHaveTextContent("Loading projects");
    rerender(<ProjectsListV2 projects={FOLDERS} error="The server didn't answer." onRetry={() => undefined} items={ITEMS} expanded={new Set(["p1"])}
      onToggle={() => undefined} onOpen={() => undefined} onNew={() => undefined} onRequest={() => undefined} />);
    expect(screen.getByTestId("projects-error")).toHaveTextContent("Couldn't load the projects. The server didn't answer.");
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { level: 1, name: "Projects" })).toBeInTheDocument();
    expect(screen.getByText(/^Calls, files, a brief and notes together, for each client or piece of work\./)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Hide the folders in Acme" })).toBeInTheDocument();
    expect(screen.getAllByText(", folder in Acme, level 2", { selector: ".el-visually-hidden" })).toHaveLength(2);
    rerender(<ProjectsListV2 projects={[]} error={null} onRetry={() => undefined} items={[]} expanded={new Set()} onToggle={() => undefined}
      onOpen={() => undefined} onNew={() => undefined} onRequest={() => undefined} overview />);
    expect(screen.getByRole("region", { name: NO_PROJECTS })).toHaveTextContent("New projectRequest files from a client");
    expect([NO_PROJECTS, noProjects()]).toEqual(["Keep each client's calls, files and notes together", "Keep each client's calls, files and notes together"]);
    render(<NameDialogV2 title="New project" submitLabel="Add project" onSubmit={async () => true} onClose={() => undefined} />);
    const dialog = screen.getByRole("dialog", { name: "New project" });
    expect(within(dialog).getByLabelText("Name")).toBeInTheDocument();
    expect(within(dialog).getByRole("button", { name: "Cancel" })).toBeInTheDocument();
  });

  it("the message regions: Messages and Problems", () => {
    render(<AppToastV2 message={null} onClose={() => undefined} duration={() => 0} />);
    expect(screen.getByRole("status", { name: "Messages" })).toBeInTheDocument();
    expect(screen.getByRole("alert", { name: "Problems" })).toBeInTheDocument();
  });
});

describe("All projects in Urdu", () => {
  it("the title, Sort and its choices, Request files, New project; each card's line in Urdu, its counts by tc()", async () => {
    await urdu();
    page(FOLDERS);
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: "تمام پروجیکٹس" })).toBeInTheDocument();
    const said = cards().map((c) => line(c).textContent);
    expect(said).toEqual([
      `${iso(2)} ریکارڈنگز · آخری کال ${iso("آج")} · ${iso(2)} فولڈرز`,
      "ابھی کوئی ریکارڈنگ نہیں",
      `${iso(1)} ریکارڈنگ · آخری کال ${iso(dayMonth(new Date(2026, 10, 12)))}`,
    ]);
    // Read in its own words' direction (dir="auto" would take the first count's digits for left to right).
    expect(cards().map((c) => line(c).getAttribute("dir"))).toEqual(["rtl", "rtl", "rtl"]);
    for (const text of said) expect(text?.replace(/[⁨][^⁩]*[⁩]/g, "")).not.toMatch(LATIN);
    // One folder, one recording: the counts' own forms.
    expect(projectLine(FOLDERS[0], FOLDERS, ITEMS.slice(0, 1), 1)).toBe(`${iso(1)} ریکارڈنگ · آخری کال ${iso("آج")} · ${iso(1)} فولڈر`);
    expect(cards()[0]).toHaveTextContent(/^AAcme، پن شدہ/);
    expect(screen.getByTitle("پن شدہ")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: `ترتیب: ${iso("نام")}` }));
    const menu = screen.getByRole("menu", { name: "پروجیکٹس کی ترتیب" });
    expect(within(menu).getAllByRole("menuitemradio").map((i) => i.textContent)).toEqual(["نام", "حالیہ"]);
    fireEvent.click(within(menu).getByRole("menuitemradio", { name: "حالیہ" }));
    expect(screen.getByRole("button", { name: `ترتیب: ${iso("حالیہ")}` })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "فائلیں منگوائیں" })).toHaveAttribute("title", "ایسا لنک جس سے کوئی بھی آپ کو وائس نوٹس اور فائلیں بھیج سکے");
    expect(screen.getByRole("button", { name: "نیا پروجیکٹ" })).toBeInTheDocument();
    expect(screen.getByRole("list", { name: "پروجیکٹس" })).toBeInTheDocument();
  });

  it("the error, the loading and the empty states, the tree and the name dialog's own words", async () => {
    await urdu();
    const { rerender } = page(null);
    expect(screen.getByRole("status")).toHaveTextContent("پروجیکٹس لوڈ ہو رہے ہیں");
    rerender(<ProjectsListV2 projects={FOLDERS} error="The server didn't answer." onRetry={() => undefined} items={ITEMS} expanded={new Set(["p1"])}
      onToggle={() => undefined} onOpen={() => undefined} onNew={() => undefined} onRequest={() => undefined} />);
    // The server's own words follow the app's sentence.
    expect(screen.getByTestId("projects-error")).toHaveTextContent("پروجیکٹس لوڈ نہیں ہو سکے۔ The server didn't answer.");
    expect(screen.getByRole("button", { name: "دوبارہ کوشش کریں" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { level: 1, name: "پروجیکٹس" })).toBeInTheDocument();
    expect(screen.getByText(/^ہر کلائنٹ یا کام کے لیے کالز، فائلیں، بریف اور نوٹس ایک ساتھ۔/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: `${iso("Acme")} کے فولڈرز چھپائیں` })).toBeInTheDocument();
    expect(screen.getAllByText(`، ${iso("Acme")} میں فولڈر، سطح ${iso(2)}`, { selector: ".el-visually-hidden" })).toHaveLength(2);
    rerender(<ProjectsListV2 projects={FOLDERS} error={null} onRetry={() => undefined} items={ITEMS} expanded={new Set()}
      onToggle={() => undefined} onOpen={() => undefined} onNew={() => undefined} onRequest={() => undefined} />);
    expect(screen.getByRole("button", { name: `${iso("Acme")} کے فولڈرز دکھائیں` })).toBeInTheDocument();
    rerender(<ProjectsListV2 projects={[]} error={null} onRetry={() => undefined} items={[]} expanded={new Set()} onToggle={() => undefined}
      onOpen={() => undefined} onNew={() => undefined} onRequest={() => undefined} overview />);
    const empty = screen.getByRole("region", { name: "ہر کلائنٹ کی کالز، فائلیں اور نوٹس ایک ساتھ رکھیں" });
    expect(within(empty).getAllByRole("button").map((b) => b.textContent)).toEqual(["نیا پروجیکٹ", "کسی کلائنٹ سے فائلیں منگوائیں"]);
    expect(noProjects("en")).toBe(NO_PROJECTS);
    render(<NameDialogV2 title="نیا پروجیکٹ" submitLabel="شامل کریں" onSubmit={async () => true} onClose={() => undefined} />);
    const dialog = screen.getByRole("dialog", { name: "نیا پروجیکٹ" });
    expect(within(dialog).getByLabelText("نام")).toBeInTheDocument();
    expect(within(dialog).getByRole("button", { name: "منسوخ کریں" })).toBeInTheDocument();
  });

  it("follows a change of language while it is on screen, back to the same English", async () => {
    page(FOLDERS);
    await act(flushAsync);
    await urdu();
    expect(screen.getByRole("heading", { level: 1, name: "تمام پروجیکٹس" })).toBeInTheDocument();
    expect(line(cards()[0]).textContent).toBe(`${iso(2)} ریکارڈنگز · آخری کال ${iso("آج")} · ${iso(2)} فولڈرز`);
    await act(() => setLanguage("en", { root: false }));
    expect(screen.getByRole("heading", { level: 1, name: "All projects" })).toBeInTheDocument();
    expect(line(cards()[0]).textContent).toBe("2 recordings · Last call Today · 2 folders");
    expect(screen.getByRole("button", { name: "Sort: Name" })).toBeInTheDocument();
  });

  it("the message regions: پیغامات and مسائل, and back", async () => {
    render(<AppToastV2 message={{ id: 1, text: "Saved", error: false }} onClose={() => undefined} duration={() => 0} />);
    await urdu();
    expect(screen.getByRole("status", { name: "پیغامات" })).toHaveTextContent("Saved");
    expect(screen.getByRole("alert", { name: "مسائل" })).toBeInTheDocument();
    await act(() => setLanguage("en", { root: false }));
    expect(screen.getByRole("status", { name: "Messages" })).toBeInTheDocument();
    expect(screen.getByRole("alert", { name: "Problems" })).toBeInTheDocument();
  });
});
