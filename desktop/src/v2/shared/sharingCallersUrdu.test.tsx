// Desktop Urdu, D2: the sharing words' other callers with Urdu on. The share sheet (the recording page's Share) and a
// project's filing rules are in the account's language, every sentence whole; the classic windows still in English
// (the Request files sheet, the Shared page) keep exactly their English, check a link's state by its id and still
// offer Revoke. English is Sharing.test.tsx's and workspace.test.tsx's, unchanged.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import type { ClientFolder, ShareLink } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { answerConfirm } from "../../test/confirm";
import { answerConfirmForTests } from "../../lib/confirm";
import { audienceText, expiryChoices, filedByRuleText, limitsText, linkState, receivedText, requestState, revokeLinkQuestion, ruleKinds, ruleText, viewsText } from "../../lib/sharing";
import { FSI, PDI, resetLanguageForTests, setLanguage } from "../../i18n";
import { formatWhen } from "../../lib/format";
import { ShareDialog } from "../../views/main/ShareDialog";
import { RequestLinksSheet } from "../../views/main/RequestLinks";
import { SharedView } from "../../views/main/SharedView";
import { RulesV2 } from "../workspace/Rules";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const iso = (value: string | number) => `${FSI}${value}${PDI}`;
/** Any Arabic-script letter, or an isolate mark: what an English line must not have. */
const URDU_OR_ISOLATE = /[\u0600-\u06FF\u2066-\u2069]/;

const LINK: ShareLink = {
  id: "sh1", session_id: "s1", has_password: false, include_audio: true, expires_at: "2026-11-11T10:00:00Z", revoked_at: null, active: true,
  view_count: 3, last_viewed_at: "2026-11-02T10:00:00Z", created_by: "Imran", created_at: "2026-11-01T10:00:00Z",
  kind: "session", client_id: null, audience: "people", people: ["ana@example.com"], allow_download: true,
};
const OLD_LINK: ShareLink = { ...LINK, id: "sh0", active: false, revoked_at: "2026-11-01T12:00:00Z", audience: "public", people: [], view_count: 0, last_viewed_at: null, allow_download: false, include_audio: false };
const REQUEST = { id: "rq1", title: "Send me your voice notes", client_id: "c3", expires_at: null, max_files: 20, max_mb: 100, received: 4, revoked_at: null };
const PROJECTS: ClientFolder[] = [{ id: "c1", name: "Acme", parent_id: null } as ClientFolder, { id: "c3", name: "Invoices", parent_id: "c1" } as ClientFolder];

beforeEach(async () => {
  resetTauriMock();
  resetLanguageForTests();
  await setLanguage("ur", { root: false });
});
afterEach(async () => {
  await flushAsync();
  answerConfirmForTests(null);
  resetLanguageForTests();
});

describe("classic windows in an Urdu window keep their English", () => {
  it("the Request files sheet: an active link reads Active and still has Revoke, its lines exactly the English", async () => {
    respond("request_links", { data: [REQUEST, { ...REQUEST, id: "rq0", title: "Old drop", revoked_at: "2026-11-01T12:00:00Z" }] });
    respond("request_link_revoke", undefined);
    const asked = answerConfirm();
    asked.mockReturnValue(true);
    render(<RequestLinksSheet projects={PROJECTS} notify={vi.fn()} onClose={vi.fn()} />);
    const list = await screen.findByRole("list", { name: "Request links" });
    const [active, revoked] = within(list).getAllByRole("listitem");
    expect(active).toHaveTextContent("Acme › Invoices · 4 files received · Up to 20 files of 100 MB");
    expect(active).toHaveTextContent("Active · never stops");
    expect(active.textContent).not.toMatch(URDU_OR_ISOLATE);
    expect(revoked).toHaveTextContent("Revoked · never stops");
    expect(within(revoked).queryByRole("button", { name: "Revoke" })).toBeNull();
    expect(screen.getAllByRole("option").map((o) => o.textContent)).toEqual(expect.arrayContaining(["Never", "In 1 hour", "In 7 days", "On a date…"]));
    fireEvent.click(within(active).getByRole("button", { name: "Revoke" }));
    await waitFor(() => expect(callsOf("request_link_revoke")).toEqual([{ id: "rq1" }]));
    expect(asked).toHaveBeenCalledWith("Revoke this request link? It stops taking files from everyone who has it.");
  });

  it("the Shared page: the counts, the state and Revoke go by the link's id", async () => {
    respond("shared_with_me", { data: [] });
    respond("shares_of", { data: [LINK, OLD_LINK] });
    respond("request_links", { data: [REQUEST] });
    respond("library_list", []);
    render(<SharedView tab="links" onTab={vi.fn()} notify={vi.fn()} projects={PROJECTS} onOpenShared={vi.fn()} onOpenRecording={vi.fn()} onOpenProject={vi.fn()} />);
    await act(flushAsync);
    expect(screen.getAllByRole("button", { name: "Revoke" })).toHaveLength(1);
    const rows = screen.getAllByRole("listitem");
    expect(rows[0]).toHaveTextContent("Only ana@example.com · 3 views · last viewed");
    expect(rows[0].querySelector(".badge")).toHaveClass("state-active");
    expect(rows[0].querySelector(".badge")).toHaveTextContent("Active");
    expect(rows[1].querySelector(".badge")).toHaveClass("state-revoked");
    // The sharing words are English and never isolated; a date is lib/format's, in the window's language (its own run).
    const lines = rows.map((r) => r.querySelector(".data-main")?.textContent ?? "").join(" ");
    expect(lines.replace(/\u2067[\s\S]*?\u2069\u2069/g, "")).not.toMatch(/[\u2066-\u2069]/);
  });

  it("the words in English whatever the window's language (lang \"en\"), with no isolates", () => {
    expect(audienceText(LINK, "en")).toBe("Only ana@example.com");
    expect(linkState(OLD_LINK, "en")).toBe("Revoked");
    expect(requestState(REQUEST, Date.now(), "en")).toBe("Active");
    expect(viewsText({ view_count: 1, last_viewed_at: null }, "en")).toBe("1 view");
    expect(receivedText(4, "en")).toBe("4 files received");
    expect(limitsText({ max_files: 1, max_mb: 100 }, "en")).toBe("Up to 1 file of 100 MB");
    expect(ruleText({ kind: "app", value: "zoom" }, "en")).toBe("From Zoom");
    expect(filedByRuleText({ title: "Standup", source_app: "zoom" }, [{ id: 1, kind: "app", value: "zoom" }], "en")).toBe("Filed by rule: from Zoom");
    expect(expiryChoices("en").map(([, w]) => w)).toEqual(["Never", "In 1 hour", "In 1 day", "In 7 days", "In 30 days", "On a date…"]);
    expect(ruleKinds("en").map(([, w]) => w)).toEqual(["Title contains", "App", "Contact number or email"]);
    expect(revokeLinkQuestion("en")).toEqual({ title: "Revoke this link?", body: "It stops working for everyone who has it.", confirm: "Revoke", tone: "danger" });
    // The window's language by default.
    expect(ruleText({ kind: "app", value: "zoom" })).toBe(`${iso("Zoom")} سے`);
  });
});

describe("the share sheet in Urdu (the recording page's Share)", () => {
  async function renderShare(start: "link" | "send" = "link") {
    respond("shares_of", { data: [LINK, OLD_LINK] });
    const props = { notify: vi.fn(), onClose: vi.fn() };
    render(<ShareDialog target={{ kind: "session", id: "s1", title: "Kick-off", hasAudio: true }} start={start} view="roman" {...props} />);
    await act(flushAsync);
    return props;
  }

  it("its title, parts, who can open it, downloads, expiry and the links, each line whole Urdu, Revoke asking in Urdu", async () => {
    respond("share_revoke", undefined);
    const asked = answerConfirm();
    asked.mockReturnValue(true);
    const { notify } = await renderShare();
    expect(screen.getByRole("dialog", { name: `${iso("Kick-off")} شیئر کریں` })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "شیئر لنک" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "ساتھی کو بھیجیں" })).toBeInTheDocument();
    expect(screen.getByRole("group", { name: "کون کھول سکتا ہے" })).toBeInTheDocument();
    expect(screen.getByText("صرف وہ لوگ جنہیں میں چنوں")).toBeInTheDocument();
    expect(screen.getByRole("switch", { name: "ڈاؤن لوڈ کی اجازت دیں" })).toBeInTheDocument();
    expect(screen.getByLabelText("کام کرنا بند کرے")).toBeInTheDocument();
    expect(screen.getByText(`صفحہ ${iso("رومن اردو")} ویو میں کھلتا ہے۔`)).toBeInTheDocument();
    const rows = within(screen.getByRole("list", { name: "شیئر لنکس" })).getAllByRole("listitem");
    expect(rows[0]).toHaveTextContent(`صرف ${iso("ana@example.com")}`);
    expect(rows[0]).toHaveTextContent(/^.*فعال · .+ کو بنا · .+ کو بند ہو گا · ڈاؤن لوڈ کی اجازت ہے/);
    // Each date is formatWhen's own run (its day, then its time whole), isolated in the line: its part of the day, then the clock.
    expect(rows[0].textContent).toContain(`${iso(formatWhen(LINK.created_at))} کو بنا · ${iso(formatWhen(LINK.expires_at!))} کو بند ہو گا`);
    expect(formatWhen(LINK.expires_at!)).toMatch(/^\u2067\u2068[^\u2069]+\u2069 · \u2068[^\s\u2069]+(\u00A0[^\s\u2069\d]+)?\u00A0\d{1,2}:\d{2}\u2069\u2069$/);
    expect(rows[0]).toHaveTextContent(`${iso(`${iso(3)} بار دیکھا گیا`)} · آخری بار`);
    expect(rows[1]).toHaveTextContent("واپس لیا گیا · ");
    expect(rows[1]).toHaveTextContent("کو بند ہو گیا · صرف پڑھیں اور سنیں");
    // No English word is left in a link's lines.
    expect(rows.map((r) => r.querySelector(".link-main")?.textContent ?? "").join(" ")).not.toMatch(/\b(made|stops|stopped|never|Downloads|views?|Revoked|Active)\b/);
    fireEvent.click(within(rows[0]).getByRole("button", { name: "واپس لیں" }));
    await waitFor(() => expect(callsOf("share_revoke")).toEqual([{ id: "sh1" }]));
    expect(asked).toHaveBeenCalledWith("یہ لنک واپس لیں؟ یہ ہر اس شخص کے لیے کام کرنا بند کر دے گا جس کے پاس یہ ہے۔");
    await waitFor(() => expect(notify).toHaveBeenCalledWith("لنک واپس لیا گیا: یہ فوراً کام کرنا بند کر دیتا ہے", false, undefined));
    expect(screen.getAllByRole("button", { name: "بند کریں" }).length).toBeGreaterThan(0);
  });

  it("only the people chosen: the emails' checks, and Send to a teammate with its message", async () => {
    respond("sent_of", { data: [] });
    respond("send_to_teammate", 2);
    const { notify } = await renderShare();
    fireEvent.click(screen.getByRole("radio", { name: "صرف وہ لوگ جنہیں میں چنوں" }));
    fireEvent.change(screen.getByLabelText("ان کی ای میلز"), { target: { value: "ana@example.com, bo" } });
    expect(screen.getByText(`ای میل نہیں: ${iso("bo")}`)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "ساتھی کو بھیجیں" }));
    await act(flushAsync);
    expect(screen.getByText("وہ اسے میرے ساتھ شیئر شدہ میں پاتے ہیں، چلا اور پڑھ سکتے ہیں، اور اپنی کاپی محفوظ کر سکتے ہیں۔ جن لوگوں کا یہاں اکاؤنٹ نہیں انہیں یہ نہیں ملے گا۔")).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("ان کی ای میلز"), { target: { value: "ana@example.com, omar@example.com" } });
    fireEvent.click(screen.getByRole("button", { name: "بھیجیں" }));
    await act(flushAsync);
    expect(notify).toHaveBeenCalledWith(`${iso(2)} افراد کو بھیج دیا گیا`, false, undefined);
  });
});

describe("a project's filing rules in Urdu", () => {
  it("the heading, the rules, the kind picker, Add rule, and the messages with the rule whole in them", async () => {
    respond("client_rules", { data: [{ id: 7, kind: "title", value: "Qureshi" }] });
    respond("client_rule_add", { data: { id: 9, kind: "app", value: "zoom" } });
    respond("client_rule_delete", null);
    const say = vi.fn();
    render(<RulesV2 projectId="c1" projectName="Acme" say={say} />);
    await act(flushAsync);
    expect(screen.getByRole("heading", { name: "رکھنے کے قواعد" })).toBeInTheDocument();
    expect(screen.getByText(`جو کالز کسی قاعدے سے میل کھائیں، وہ خود بخود ${iso("Acme")} میں چلی جاتی ہیں۔`)).toBeInTheDocument();
    expect(screen.getByRole("list", { name: `${iso("Acme")} کے رکھنے کے قواعد` })).toHaveTextContent(`عنوان میں “${iso("Qureshi")}” ہو`);
    fireEvent.click(screen.getByRole("combobox", { name: /^قاعدے کی قسم/ }));
    expect(screen.getAllByRole("option").map((o) => o.textContent)).toEqual(["عنوان میں ہو", "ایپ", "رابطہ نمبر یا ای میل"]);
    fireEvent.click(screen.getByRole("option", { name: "ایپ" }));
    fireEvent.click(screen.getByRole("button", { name: "قاعدہ شامل کریں" }));
    await act(flushAsync);
    expect(say).toHaveBeenLastCalledWith(`قاعدہ شامل ہو گیا: ${iso(`${iso("Zoom")} سے`)}`);
    // An app's rule starts with its name in Latin letters: its line still reads right to left, from its own words.
    expect(screen.getByText(`${iso("Zoom")} سے`)).toHaveAttribute("dir", "rtl");
    fireEvent.click(screen.getByRole("button", { name: `قاعدہ ہٹائیں: ${iso(`عنوان میں “${iso("Qureshi")}” ہو`)}` }));
    await act(flushAsync);
    expect(say).toHaveBeenLastCalledWith(`قاعدہ ہٹا دیا گیا: ${iso(`عنوان میں “${iso("Qureshi")}” ہو`)}`, expect.objectContaining({ label: "واپس کریں" }));
  });
});
