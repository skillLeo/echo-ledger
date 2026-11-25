// Desktop Urdu, D2: Shared with me, its read-only view and the Request files sheet in the account's language. With
// Urdu on, every word of these screens is the catalogue's (src/i18n/ur.json), each value (a name, a number, a note, a
// time) in its own isolate, plurals chosen as trans_choice does; the sharing words of lib/sharing.ts too. English is
// shared.test.tsx's, unchanged.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { useState } from "react";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { CALL_SEGMENTS, makeItem, makeTranscript } from "../../test/fixtures";
import { answerConfirm } from "../../test/confirm";
import { answerConfirmForTests } from "../../lib/confirm";
import type { ClientFolder, RequestLink, ShareLink, SharedItem } from "../../lib/ipc";
import type { SharedTab } from "../../views/main/Sidebar";
import {
  audienceText, downloadText, expiryChoices, filedByRuleText, limitsText, linkState, receivedText, requestState,
  revokeLinkQuestion, revokeRequestQuestion, ruleKinds, ruleText, viewsText,
} from "../../lib/sharing";
import { FSI, PDI, resetLanguageForTests, setLanguage, stripIsolates } from "../../i18n";
import { SharedV2 } from "./SharedV2";
import { playbackExpired } from "./SharedReader";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

/** A value in its isolate, as the words put it in Urdu. */
const iso = (value: string | number) => `${FSI}${value}${PDI}`;
/** U+200F RIGHT-TO-LEFT MARK: an Urdu line that sits in a dir="auto" place starts with it (it may begin with a Latin value). */
const RLM = "\u200F";
/**
 * The times in an element: in Urdu each is one piece in its own direction (dir="auto", not English's forced
 * left-to-right .el-ltr), its clock one left-to-right piece inside it, so an Urdu date ("23 نومبر · 2:00 PM") reads right
 * to left with its day first and "2:00 PM" stays whole.
 */
const timesIn = (el: Element) => [...el.querySelectorAll("span")].filter((s) => /\d:\d\d/.test(s.textContent ?? "") && !s.querySelector("span"))
  .map((s) => (s.getAttribute("dir") === "ltr" && s.parentElement?.getAttribute("dir") === "auto" ? "own direction" : s.className || "plain"));
/** An element's text without the isolates, to read a whole line. */
const plain = (el: Element) => stripIsolates(el.textContent ?? "");

const NOW = new Date(2026, 9, 28, 15, 0);
const sent = (id: string, overrides: Partial<SharedItem> = {}): SharedItem => ({
  id, kind: "session", from: { name: "Imran", email: "imran@acme.pk" }, note: null, title: null,
  sent_at: new Date(2026, 9, 27, 16, 5).toISOString(), saved_at: null, session: makeItem(`s-${id}`, { title: `Call ${id}` }), client: null, ...overrides,
});
const project = (id: string, name: string): ClientFolder => ({ id, name } as ClientFolder);
const shareLink = (id: string, overrides: Partial<ShareLink> = {}): ShareLink => ({
  id, session_id: "rec-1", has_password: false, include_audio: true, expires_at: null, revoked_at: null, active: true, view_count: 0,
  last_viewed_at: null, created_by: null, created_at: NOW.toISOString(), audience: "anyone", ...overrides,
});
const requestLink = (id: string, overrides: Partial<RequestLink> = {}): RequestLink => ({
  id, title: "Send me your voice notes", client_id: null, expires_at: null, max_files: 20, max_mb: 100, received: 0, revoked_at: null, ...overrides,
} as RequestLink);

const WITH_ME = [
  sent("a", { note: "Please check the totals" }),
  sent("b", { title: "Weekly sync", saved_at: NOW.toISOString(), from: { email: "sara@acme.pk" } }),
  sent("p", { kind: "project", session: null, title: "Acme", client: project("c9", "Acme") }),
];
const LINKS = [
  shareLink("l1", { view_count: 3, last_viewed_at: new Date(2026, 9, 28, 9, 0).toISOString(), expires_at: new Date(2026, 10, 4, 9, 0).toISOString() }),
  shareLink("l2", { session_id: null, client_id: "c1", kind: "project", audience: "people", people: ["omar@acme.pk"] }),
  shareLink("l3", { session_id: "gone", active: false, revoked_at: NOW.toISOString(), view_count: 1 }),
];
const REQUESTS = [
  requestLink("r1", { client_id: "c1", received: 4, expires_at: new Date(2026, 10, 30, 23, 59).toISOString() }),
  requestLink("r2", { title: "Old drop", revoked_at: NOW.toISOString(), max_files: null, max_mb: null, expires_at: new Date(2026, 9, 1).toISOString() }),
  requestLink("r3", { title: "One file", received: 1, max_files: 1, max_mb: 50 }),
];

beforeEach(async () => {
  resetTauriMock();
  vi.useFakeTimers({ toFake: ["Date"], now: NOW });
  respond("shared_with_me", { data: WITH_ME });
  respond("shares_of", { data: LINKS });
  respond("request_links", { data: REQUESTS });
  respond("library_list", [makeItem("rec-1", { title: "Acme kickoff" })]);
  respond("shared_save", { session_id: "copy-1" });
  respond("shared_remove", undefined);
  respond("share_revoke", undefined);
  respond("request_link_revoke", undefined);
  respond("session_detail", { playback_url: "https://audio.test/a.m4a", speaker_names: { Client: "Imran" } });
  respond("transcript_detail", makeTranscript());
  resetLanguageForTests();
  await setLanguage("ur", { root: false });
});
afterEach(() => {
  vi.useRealTimers();
  answerConfirmForTests(null);
  resetLanguageForTests();
});

function Page({ start = "with-me", props }: { start?: SharedTab; props: ReturnType<typeof makeProps> }) {
  const [tab, setTab] = useState<SharedTab>(start);
  return <div className="el-root"><SharedV2 {...props} tab={tab} onTab={(t) => { setTab(t); props.onTab(t); }} projects={[project("c1", "Acme Ltd")]} /></div>;
}
const makeProps = () => ({ notify: vi.fn(), onOpenRecording: vi.fn(), onOpenProject: vi.fn(), onSaved: vi.fn(), onTab: vi.fn(), onFocusHandled: vi.fn() });
async function show(start: SharedTab = "with-me") {
  const props = makeProps();
  render(<Page start={start} props={props} />);
  await act(flushAsync);
  return props;
}
const row = (title: string) => screen.getByText(title).closest("li") as HTMLElement;
const more = (title: string) => `${iso(title)} کے مزید اختیارات`;
const menuOf = (title: string) => {
  fireEvent.click(within(row(title)).getByRole("button", { name: more(title) }));
  return screen.getByRole("menu");
};

describe("Shared in Urdu", () => {
  it("the page: its title, description, New request link and the three tabs with their counts, each value isolated", async () => {
    await show();
    expect(screen.getByRole("heading", { level: 1, name: "شیئر شدہ" })).toBeInTheDocument();
    expect(screen.getByText("ساتھیوں کی بھیجی ہوئی چیزیں، آپ کے بنائے ہوئے لنکس، اور وہ لنکس جن سے لوگ آپ کو فائلیں بھیجتے ہیں۔")).toHaveAttribute("dir", "auto");
    expect(screen.getByRole("button", { name: "نیا درخواست کا لنک" })).toBeInTheDocument();
    const tabs = within(screen.getByRole("tablist", { name: "شیئر شدہ" })).getAllByRole("tab").map((t) => t.textContent);
    expect(tabs).toEqual([`${iso("میرے ساتھ")} · ${iso(3)}`, `${iso("میرے لنکس")} · ${iso(2)}`, `${iso("درخواست کے لنکس")} · ${iso(2)}`]);
    fireEvent.click(screen.getByRole("tab", { name: `${iso("میرے لنکس")} · ${iso(2)}` }));
    expect(screen.getByRole("list", { name: "میرے لنکس" })).toBeInTheDocument();
  });

  it("With me: Recording from and Project from, the time one left-to-right piece, the note isolated, Saved", async () => {
    await show();
    expect(screen.getByRole("list", { name: "میرے ساتھ شیئر شدہ" })).toBeInTheDocument();
    const meta = row("Call a").querySelector(".el-row__meta-text") as HTMLElement;
    // The line starts with a right-to-left mark: it begins with a Latin name, and its place (dir="auto") still reads it
    // right to left.
    expect(meta).toHaveAttribute("dir", "auto");
    expect(meta.textContent).toMatch(new RegExp(`^${RLM}${FSI}Imran${PDI} کی بھیجی ہوئی ریکارڈنگ · `));
    expect(meta.textContent).toContain(` · “${iso("Please check the totals")}”`);
    expect(timesIn(row("Call a"))).toEqual(["own direction"]);
    expect(row("Weekly sync")).toHaveTextContent(`${iso("sara@acme.pk")} کی بھیجی ہوئی ریکارڈنگ`);
    expect(row("Weekly sync")).toHaveTextContent("محفوظ کیا");
    expect(row("Acme")).toHaveTextContent(`${iso("Imran")} کا بھیجا ہوا پروجیکٹ`);
    // A sender with no name or email is "A teammate".
    cleanup();
    respond("shared_with_me", { data: [sent("x", { from: null })] });
    await show();
    expect(row("Call x")).toHaveTextContent(`${iso("ایک ساتھی")} کی بھیجی ہوئی ریکارڈنگ`);
  });

  it("With me's menu, Save and Remove, with their messages", async () => {
    respond("shared_save", { client_id: "c9" });
    const { notify } = await show();
    expect(within(menuOf("Call a")).getAllByRole("menuitem").map((i) => i.textContent)).toEqual(["کھولیں", "میری لائبریری میں محفوظ کریں", "ہٹائیں"]);
    fireEvent.keyDown(screen.getByRole("menu"), { key: "Escape" });
    fireEvent.click(within(menuOf("Acme")).getByRole("menuitem", { name: "میری لائبریری میں محفوظ کریں" }));
    await act(flushAsync);
    expect(notify).toHaveBeenLastCalledWith(`آپ کے پروجیکٹس میں ${iso("Acme")} محفوظ ہو گیا`, false, { kind: "project", id: "c9" });
    respond("shared_save", { session_id: "copy-1" });
    fireEvent.click(within(menuOf("Call a")).getByRole("menuitem", { name: "میری لائبریری میں محفوظ کریں" }));
    await act(flushAsync);
    expect(notify).toHaveBeenLastCalledWith("آپ کی لائبریری میں محفوظ ہو گیا", false, { kind: "recording", id: "copy-1" });
    fireEvent.contextMenu(row("Weekly sync"));
    expect(screen.getByRole("menu", { name: `${iso("Weekly sync")} کے اختیارات` })).toBeInTheDocument();
    fireEvent.click(within(screen.getByRole("menu")).getByRole("menuitem", { name: "ہٹائیں" }));
    await act(flushAsync);
    expect(callsOf("shared_remove")).toEqual([{ id: "b" }]);
    expect(notify).toHaveBeenLastCalledWith("میرے ساتھ شیئر شدہ سے ہٹا دیا گیا", false, undefined);
  });

  it("loading, empty and the load error with Retry", async () => {
    respondWith("shared_with_me", () => new Promise(() => undefined));
    render(<Page props={makeProps()} />);
    expect(screen.getByRole("status")).toHaveTextContent("آپ کے ساتھ شیئر کی گئی چیزیں لوڈ ہو رہی ہیں…");
    cleanup();
    respond("shared_with_me", null);
    respond("shares_of", { data: [] });
    respond("request_links", null);
    await show();
    expect(screen.getByText("ابھی آپ کو کچھ نہیں بھیجا گیا")).toBeInTheDocument();
    expect(screen.getByText("جب کوئی ساتھی آپ کو ریکارڈنگ یا پروجیکٹ بھیجے گا، وہ یہاں نظر آئے گا۔")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("tab", { name: `${iso("میرے لنکس")} · ${iso(0)}` }));
    expect(screen.getByText("ابھی کوئی لنک نہیں")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("tab", { name: `${iso("درخواست کے لنکس")} · ${iso(0)}` }));
    expect(screen.getByText("ابھی کوئی درخواست کا لنک نہیں")).toBeInTheDocument();
    expect(screen.getByText("درخواست کے لنک سے کوئی بھی، بغیر اکاؤنٹ کے، آپ کو وائس نوٹس اور فائلیں بھیج سکتا ہے۔")).toBeInTheDocument();
    cleanup();
    fail("shares_of", "The server is unreachable.");
    respondWith("request_links", () => new Promise(() => undefined));
    respondWith("shared_with_me", () => new Promise(() => undefined));
    await show("links");
    expect(screen.getByRole("alert")).toHaveTextContent(`سب کچھ لوڈ نہیں ہو سکا۔ ${iso("The server is unreachable.")}`);
    expect(screen.getByRole("button", { name: "دوبارہ کوشش کریں" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("tab", { name: "درخواست کے لنکس" }));
    expect(screen.queryByText("آپ کے درخواست کے لنکس لوڈ ہو رہے ہیں…")).toBeNull();
  });

  it("My links: audience, views (one and many), until, the state, and Revoke's question and message", async () => {
    const { notify } = await show("links");
    const first = row("Acme kickoff");
    expect(plain(first)).toContain("لنک والا کوئی بھی · 3 بار دیکھا گیا · آخری بار ");
    expect(plain(first)).toContain(" دیکھا گیا · ");
    expect(first.textContent).toContain(`${iso(`${iso(3)} بار دیکھا گیا`)} · آخری بار ${FSI}`);
    expect(timesIn(first)).toEqual(["own direction", "own direction"]);
    expect(plain(first)).toMatch(/ تک/);
    expect(first).toHaveTextContent("فعال");
    expect(row(`پروجیکٹ: ${iso("Acme Ltd")}`)).toHaveTextContent(`صرف ${iso("omar@acme.pk")} · ابھی کسی نے نہیں دیکھا`);
    expect(row("ایک ریکارڈنگ")).toHaveTextContent(`${iso(1)} بار دیکھا گیا`);
    expect(row("ایک ریکارڈنگ")).toHaveTextContent("واپس لیا گیا");

    const asked = answerConfirm();
    asked.mockReturnValue(true);
    fireEvent.click(within(menuOf("Acme kickoff")).getByRole("menuitem", { name: "واپس لیں" }));
    await act(flushAsync);
    expect(asked).toHaveBeenCalledWith("یہ لنک واپس لیں؟ یہ ہر اس شخص کے لیے کام کرنا بند کر دے گا جس کے پاس یہ ہے۔");
    expect(callsOf("share_revoke")).toEqual([{ id: "l1" }]);
    expect(notify).toHaveBeenCalledWith("لنک واپس لیا گیا: یہ فوراً کام کرنا بند کر دیتا ہے", false, undefined);
  });

  it("Request links: into, received (one and many), the limits, when it stops, and Revoke's question and message", async () => {
    const { notify } = await show("requests");
    const active = row("Send me your voice notes");
    expect(active.querySelector(".el-row__meta-text")?.textContent).toBe(`${RLM}${iso("Acme Ltd")} میں · ${iso(4)} فائلیں موصول ہوئیں · زیادہ سے زیادہ ${iso(20)} فائلیں، ہر ایک ${iso(100)} MB تک`);
    expect(plain(active)).toMatch(/ کو بند ہو گا/);
    expect(timesIn(active)).toEqual(["own direction"]);
    expect(row("Old drop")).toHaveTextContent("کسی پروجیکٹ میں نہیں · ابھی کچھ موصول نہیں ہوا");
    expect(plain(row("Old drop"))).toMatch(/ کو بند ہو گیا/);
    expect(row("One file")).toHaveTextContent(`کسی پروجیکٹ میں نہیں · ${iso(1)} فائل موصول ہوئی · زیادہ سے زیادہ ${iso(1)} فائل، ہر ایک ${iso(50)} MB تک`);
    expect(row("One file")).toHaveTextContent("کبھی بند نہیں ہوتا");

    const asked = answerConfirm();
    asked.mockReturnValue(true);
    fireEvent.click(within(menuOf("Send me your voice notes")).getByRole("menuitem", { name: "واپس لیں" }));
    await act(flushAsync);
    expect(asked).toHaveBeenCalledWith("یہ درخواست کا لنک واپس لیں؟ یہ ہر اس شخص سے فائلیں لینا بند کر دے گا جس کے پاس یہ ہے۔");
    expect(notify).toHaveBeenCalledWith("درخواست کا لنک واپس لیا گیا", false, undefined);
  });

  it("follows a change of language while it is on screen, back to the same English", async () => {
    resetLanguageForTests();
    await show();
    expect(screen.getByRole("heading", { level: 1, name: "Shared" })).toBeInTheDocument();
    expect(row("Call a")).toHaveTextContent("Recording from Imran · Yesterday · 4:05 PM");
    await act(() => setLanguage("ur", { root: false }));
    expect(screen.getByRole("heading", { level: 1, name: "شیئر شدہ" })).toBeInTheDocument();
    expect(row("Call a")).toHaveTextContent(`${iso("Imran")} کی بھیجی ہوئی ریکارڈنگ`);
    await act(() => setLanguage("en", { root: false }));
    expect(screen.getByRole("tab", { name: "With me · 3" })).toBeInTheDocument();
    expect(row("Call a").querySelector(".el-row__meta-text")?.textContent).toBe("Recording from Imran · Yesterday · 4:05 PM · “Please check the totals”");
    expect(timesIn(row("Call a"))).toEqual(["el-ltr"]);
  });
});

describe("a shared recording, read-only, in Urdu", () => {
  async function openReader(session: Partial<SharedItem["session"] & object> = {}) {
    respond("shared_with_me", { data: [sent("a", {
      note: "Please check the totals",
      session: makeItem("s-a", { title: "Call a", duration_ms: 486_000, source_device: "phone", source_device_label: null, transcript: { final_status: "completed", final_transcript_id: "tr-s-a", live_available: false, preview: null, final_language: "ur" },
        client: { id: "c1", name: "Acme Ltd" } as ClientFolder, ...session } as never),
    })] });
    const props = await show();
    fireEvent.click(within(row("Call a")).getByRole("button", { name: "Call a" }));
    await act(flushAsync);
    return props;
  }

  it("the head: back to Shared, Read-only, Transcribed in, Save to my library, the ⋮ and who sent it with the note", async () => {
    await openReader();
    expect(screen.getByRole("button", { name: "شیئر شدہ" })).toBeInTheDocument();
    expect(screen.getByText("صرف پڑھنے کے لیے")).toBeInTheDocument();
    expect(screen.getByText(`${RLM}${iso("Urdu")} میں ٹرانسکرائب ہوئی`)).toHaveAttribute("dir", "auto");
    expect(screen.getByTestId("recorded").textContent).toMatch(new RegExp(`^${RLM}`));
    expect(timesIn(screen.getByTestId("recorded"))).toEqual(["own direction", "el-num"]);
    // The source line, its logo first, starts at the window's start edge too.
    expect(screen.getByTestId("source-line").firstChild?.textContent).toBe(RLM);
    expect(screen.getByTestId("source-line")).toHaveTextContent("فون");
    expect(screen.getByRole("button", { name: "میری لائبریری میں محفوظ کریں" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "اس ریکارڈنگ کے مزید اختیارات" }));
    expect(within(screen.getByRole("menu")).getAllByRole("menuitem").map((i) => i.textContent)).toEqual(["ہٹائیں"]);
    fireEvent.keyDown(screen.getByRole("menu"), { key: "Escape" });
    const banner = screen.getByTestId("reader-sent");
    expect(banner.textContent).toContain(`منجانب ${iso("Imran")} · `);
    expect(banner.textContent).toContain(` · “${iso("Please check the totals")}” آپ اسے چلا اور پڑھ سکتے ہیں۔ میری لائبریری میں محفوظ کریں سے ایک کاپی بنتی ہے جسے آپ بدل سکتے ہیں۔`);
    expect(timesIn(banner)).toEqual(["own direction"]);
    // Saved: the badge.
    fireEvent.click(screen.getByRole("button", { name: "میری لائبریری میں محفوظ کریں" }));
    await act(flushAsync);
    expect(screen.getByText("میری لائبریری میں محفوظ")).toBeInTheDocument();
  });

  it("the player's labels, and its audio errors", async () => {
    fail("session_detail", "The server is unreachable.");
    await openReader();
    expect(screen.getByTestId("audio-error")).toHaveTextContent(`آڈیو لوڈ نہیں ہوئی۔ ${iso("The server is unreachable.")}`);
    respond("session_detail", { playback_url: "https://audio.test/a.m4a", speaker_names: {}, markers: [{ id: "m1", at_ms: 65_000, tag: "Invoice", note: null }] });
    fireEvent.click(within(screen.getByTestId("audio-error")).getByRole("button", { name: "دوبارہ کوشش کریں" }));
    await act(flushAsync);
    expect(screen.getByRole("combobox", { name: /^چلانے کی رفتار/ })).toBeInTheDocument();
    expect(screen.getByRole("switch", { name: "خاموشی چھوڑ دیں" })).toBeInTheDocument();
    expect(screen.getByRole("switch", { name: "ساتھ ساتھ چلیں" })).toBeInTheDocument();
    expect(screen.getByRole("list", { name: "بک مارکس" })).toBeInTheDocument();
    // The link ran out and the fresh one asked for is the same: it says so.
    fireEvent.error(screen.getByTestId("player"));
    await act(flushAsync);
    expect(screen.getByText("آڈیو کے لنک کی میعاد ختم ہو گئی۔ ریکارڈنگ دوبارہ کھولیں۔")).toBeInTheDocument();
  });

  it("the transcript's kind, Find and Copy, and the copy messages", async () => {
    const writeText = vi.fn(() => Promise.resolve());
    Object.assign(navigator, { clipboard: { writeText } });
    respond("transcript_detail", makeTranscript({ edited_at: NOW.toISOString() }));
    const { notify } = await openReader();
    const bar = screen.getByRole("toolbar", { name: "تلاش اور کاپی" });
    expect(bar).toHaveTextContent("حتمی ٹرانسکرپٹ · ترمیم شدہ");
    expect(within(bar).getByRole("button", { name: "تلاش کریں" })).toHaveAttribute("title", "اس ٹرانسکرپٹ میں تلاش کریں (Ctrl+F)");
    fireEvent.click(within(bar).getByRole("button", { name: "متن کاپی کریں" }));
    await act(flushAsync);
    expect(notify).toHaveBeenLastCalledWith("کاپی ہو گیا", false, undefined);
    // The menu's own items are the shared parts' (v2/components), still in English.
    fireEvent.click(within(bar).getByRole("button", { name: "کاپی کے مزید طریقے" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Copy with timestamps" }));
    await act(flushAsync);
    expect(notify).toHaveBeenLastCalledWith("وقت کے ساتھ کاپی ہو گیا", false, undefined);
    fireEvent.click(within(bar).getByRole("button", { name: "کاپی کے مزید طریقے" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Copy for AI" }));
    await act(flushAsync);
    expect(notify).toHaveBeenLastCalledWith("اے آئی کے لیے کاپی ہو گیا، غیر یقینی الفاظ کے نوٹ کے ساتھ", false, undefined);
    // What goes to the clipboard has no isolates.
    expect(writeText).toHaveBeenLastCalledWith(expect.not.stringMatching(/[\u2066-\u2069]/));
    writeText.mockImplementationOnce(() => Promise.reject(new Error("denied")));
    fireEvent.click(within(bar).getByRole("button", { name: "متن کاپی کریں" }));
    await act(flushAsync);
    expect(notify).toHaveBeenLastCalledWith("کلپ بورڈ تک رسائی نہیں ہو سکی", true);

    fireEvent.click(screen.getByRole("button", { name: "شیئر شدہ" }));
    respond("transcript_detail", makeTranscript({ kind: "live" }));
    fireEvent.click(within(row("Call a")).getByRole("button", { name: "Call a" }));
    await act(flushAsync);
    expect(screen.getByRole("toolbar", { name: "تلاش اور کاپی" })).toHaveTextContent("لائیو کیپشنز (حتمی ٹرانسکرپٹ باقی)");
  });

  it("Show as, the view's line, the original words and what a second transcription heard", async () => {
    respond("transcript_detail", makeTranscript({ views: { available: ["roman"], status: "ready" } }));
    respond("transcript_views", { status: "ready", available: ["roman"], roman: ["Salam, main Sam hoon.", "Hi Sam, thanks for calling back.", null, null], english: null, model: null, made_at: null, error: null, kept: { roman: [1] } });
    await openReader();
    const showAs = screen.getByRole("radiogroup", { name: "بطور دکھائیں" });
    expect(within(showAs).getAllByRole("radio").map((r) => r.textContent)).toEqual(["رومن اردو", "اصل"]);
    fireEvent.click(within(showAs).getByRole("radio", { name: "رومن اردو" }));
    await act(flushAsync);
    expect(screen.getByText(`${iso("رومن اردو")} ویو۔ اصل ٹرانسکرپٹ نہیں بدلا۔`)).toBeInTheDocument();
    expect(screen.getByText("· اصل الفاظ")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "شیئر شدہ" }));
    respond("transcript_detail", makeTranscript({ segments: [{ ...CALL_SEGMENTS[0], check: { provider: "groq", label: "Groq Whisper", text: "Hello, this is Tam." } }] }));
    fireEvent.click(within(row("Call a")).getByRole("button", { name: "Call a" }));
    await act(flushAsync);
    expect(screen.getByText(`دوسری ٹرانسکرپشن نے سنا: ${iso("Hello, this is Tam.")}`)).toBeInTheDocument();
  });

  it("the transcript's states: loading, didn't load, failed with Add my Groq key, empty, none yet; and a recording that didn't open", async () => {
    respondWith("transcript_detail", () => new Promise(() => undefined));
    await openReader();
    expect(within(screen.getByRole("region", { name: "ٹرانسکرپٹ" })).getByRole("status")).toHaveTextContent("ٹرانسکرپٹ لوڈ ہو رہا ہے");
    const back = () => fireEvent.click(screen.getByRole("button", { name: "شیئر شدہ" }));
    const reopen = async () => {
      fireEvent.click(within(row("Call a")).getByRole("button", { name: "Call a" }));
      await act(flushAsync);
    };
    back();
    fail("transcript_detail", "The server is unreachable.");
    await reopen();
    expect(screen.getByText("ٹرانسکرپٹ لوڈ نہیں ہوا")).toBeInTheDocument();
    back();
    respond("transcript_detail", makeTranscript({ status: "failed", error_code: "needs_transcription_key", segments: [] }));
    await reopen();
    expect(screen.getByText("ٹرانسکرپشن ناکام ہو گئی۔")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "اپنی Groq کلید شامل کریں" })).toBeInTheDocument();
    back();
    respond("transcript_detail", makeTranscript({ status: "failed", error_code: "own_key_rejected", segments: [] }));
    await reopen();
    expect(screen.getByRole("button", { name: "اپنی Groq کلید چیک کریں" })).toBeInTheDocument();
    back();
    respond("transcript_detail", makeTranscript({ segments: [] }));
    await reopen();
    expect(screen.getByText("ٹرانسکرپٹ خالی ہے")).toBeInTheDocument();
    expect(screen.getByText("اس ریکارڈنگ میں کوئی بات سنائی نہیں دی۔")).toBeInTheDocument();
    back();
    cleanup();
    respond("shared_with_me", { data: [sent("a", { session: makeItem("s-a", { title: "Call a", transcript: { final_status: "none", final_transcript_id: null, live_transcript_id: null, live_available: false, preview: null } } as never) })] });
    await show();
    await reopen();
    expect(screen.getByText("ابھی ٹرانسکرپٹ نہیں")).toBeInTheDocument();
    back();
    fail("session_detail", "The server is unreachable.");
    fail("transcript_detail", "The server is unreachable.");
    respond("shared_with_me", { data: [sent("a")] });
    cleanup();
    await show();
    await reopen();
    expect(screen.getByText("یہ ریکارڈنگ نہیں کھلی")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "دوبارہ کوشش کریں" })).toBeInTheDocument();
  });
});

describe("the Request files sheet in Urdu", () => {
  it("its labels, choices, checks and messages; the title people see starts in Urdu", async () => {
    const writeText = vi.fn(() => Promise.resolve());
    Object.assign(navigator, { clipboard: { writeText } });
    respond("request_link_create", { data: requestLink("r9", { url: "https://echo.test/r/abc" }) });
    const { notify } = await show();
    fireEvent.click(screen.getByRole("button", { name: "نیا درخواست کا لنک" }));
    const sheet = screen.getByRole("dialog", { name: "فائلیں منگوائیں" });
    expect(sheet).toHaveTextContent("درخواست کے لنک والا کوئی بھی، بغیر اکاؤنٹ کے، آپ کو وائس نوٹس، آڈیو اور ویڈیو بھیج سکتا ہے۔");
    expect(within(sheet).getByLabelText("لوگوں کو نظر آنے والا عنوان")).toHaveValue("مجھے اپنے وائس نوٹس بھیجیں");
    fireEvent.click(within(sheet).getByRole("combobox", { name: /^فائلیں کہاں جائیں/ }));
    expect(screen.getByRole("option", { name: "کوئی پروجیکٹ نہیں" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("option", { name: "Acme Ltd" }));
    fireEvent.click(within(sheet).getByRole("combobox", { name: /^کام کرنا بند کرے/ }));
    expect(screen.getAllByRole("option").map((o) => o.textContent)).toEqual(["کبھی نہیں", "1 گھنٹے میں", "1 دن میں", "7 دن میں", "30 دن میں", "کسی تاریخ پر…"]);
    fireEvent.click(screen.getByRole("option", { name: "کسی تاریخ پر…" }));
    expect(within(sheet).getByLabelText("دن")).toHaveAttribute("type", "date");
    fireEvent.change(within(sheet).getByLabelText("دن"), { target: { value: "2026-12-01" } });
    expect(sheet).toHaveTextContent("فائلیں، 1 سے 100");
    expect(sheet).toHaveTextContent("ہر فائل کے MB، 1 سے 2048");
    fireEvent.change(within(sheet).getByLabelText("زیادہ سے زیادہ"), { target: { value: "500" } });
    fireEvent.change(within(sheet).getByLabelText("سب سے بڑی فائل (MB)"), { target: { value: "5000" } });
    expect(sheet).toHaveTextContent("1 سے 100 تک فائلیں چنیں۔");
    expect(sheet).toHaveTextContent("1 سے 2048 MB تک چنیں۔");
    expect(within(sheet).getByRole("button", { name: "لنک بنائیں" })).toBeDisabled();
    fireEvent.change(within(sheet).getByLabelText("زیادہ سے زیادہ"), { target: { value: "5" } });
    fireEvent.change(within(sheet).getByLabelText("سب سے بڑی فائل (MB)"), { target: { value: "50" } });
    fireEvent.click(within(sheet).getByRole("button", { name: "لنک بنائیں" }));
    await act(flushAsync);
    expect(callsOf("request_link_create")[0]).toMatchObject({ options: { title: "مجھے اپنے وائس نوٹس بھیجیں", clientId: "c1", maxFiles: 5, maxMb: 50 } });
    expect(within(sheet).getByText("ابھی کاپی کر لیں۔ یہ صرف ایک بار دکھایا جاتا ہے۔")).toBeInTheDocument();
    fireEvent.click(within(sheet).getByRole("button", { name: "لنک کاپی کریں" }));
    await act(flushAsync);
    expect(writeText).toHaveBeenCalledWith("https://echo.test/r/abc");
    expect(notify).toHaveBeenCalledWith("لنک کاپی ہو گیا", false, { kind: "shared", tab: "requests" });
    expect(within(sheet).getByRole("button", { name: "اپنے درخواست کے لنکس دیکھیں" })).toBeInTheDocument();
    fireEvent.click(within(sheet).getAllByRole("button", { name: "بند کریں" }).at(-1)!);
    expect(screen.queryByRole("dialog")).toBeNull();
  });
});

describe("the sharing words (lib/sharing.ts)", () => {
  it("in Urdu: who may open, the state, downloads, the filing rules, the expiry and rule choices, and the revoke questions", () => {
    expect(audienceText({ audience: "public", has_password: false })).toBe("لنک والا کوئی بھی");
    expect(audienceText({ audience: "password", has_password: true })).toBe("لنک اور پاس ورڈ والا کوئی بھی");
    expect(audienceText({ audience: "people", people: [], has_password: false })).toBe("صرف چنے ہوئے لوگ");
    expect(audienceText({ audience: "people", people: ["ana@example.com", "bo@x.org"], has_password: false })).toBe(`صرف ${iso("ana@example.com, bo@x.org")}`);
    expect([linkState({ active: true, revoked_at: null }), linkState({ active: false, revoked_at: "x" }), linkState({ active: false, revoked_at: null })]).toEqual(["فعال", "واپس لیا گیا", "میعاد ختم"]);
    expect(requestState({ revoked_at: null, expires_at: "2026-01-01T00:00:00Z" })).toBe("میعاد ختم");
    expect(downloadText({ allow_download: true, include_audio: false })).toBe("ڈاؤن لوڈ کی اجازت ہے");
    expect(downloadText({ allow_download: false, include_audio: true })).toBe("صرف پڑھیں اور سنیں");
    expect(ruleText({ kind: "title", value: "Acme" })).toBe(`عنوان میں “${iso("Acme")}” ہو`);
    expect(ruleText({ kind: "app", value: "zoom" })).toBe(`${iso("Zoom")} سے`);
    expect(ruleText({ kind: "contact", value: "+92 300 1234567" })).toBe(`رابطہ ${iso("+92 300 1234567")}`);
    const rules = [{ id: 1, kind: "app" as const, value: "zoom" }, { id: 2, kind: "title" as const, value: "Acme" }];
    expect(filedByRuleText({ title: "Weekly ACME sync", source_app: "zoom" }, rules)).toBe(`قاعدے سے رکھی گئی: عنوان میں ${iso("Acme")} ہو`);
    expect(filedByRuleText({ title: "Standup", source_app: "zoom" }, rules)).toBe(`قاعدے سے رکھی گئی: ${iso("Zoom")} سے`);
    expect(filedByRuleText({ title: "Standup", source_app: "whatsapp" }, rules)).toBe("ایک قاعدے سے رکھی گئی");
    expect(expiryChoices().map(([, words]) => words)).toEqual(["کبھی نہیں", "1 گھنٹے میں", "1 دن میں", "7 دن میں", "30 دن میں", "کسی تاریخ پر…"]);
    expect(ruleKinds().map(([, words]) => words)).toEqual(["عنوان میں ہو", "ایپ", "رابطہ نمبر یا ای میل"]);
    expect(revokeLinkQuestion()).toEqual({ title: "یہ لنک واپس لیں؟", body: "یہ ہر اس شخص کے لیے کام کرنا بند کر دے گا جس کے پاس یہ ہے۔", confirm: "واپس لیں", tone: "danger" });
    expect(revokeRequestQuestion().title).toBe("یہ درخواست کا لنک واپس لیں؟");
    // A window still in English asks for English ("en"): the same words as always, whatever the window's language.
    expect(revokeRequestQuestion("en")).toEqual({ title: "Revoke this request link?", body: "It stops taking files from everyone who has it.", confirm: "Revoke", tone: "danger" });
    // The recording page's expired-link line is read when it is shown, in the language of the moment.
    expect(playbackExpired()).toBe("آڈیو کے لنک کی میعاد ختم ہو گئی۔ ریکارڈنگ دوبارہ کھولیں۔");
  });

  it("plurals choose as trans_choice does, in Urdu and in English", async () => {
    expect(viewsText({ view_count: 1, last_viewed_at: null })).toBe(`${iso(1)} بار دیکھا گیا`);
    expect(viewsText({ view_count: 0, last_viewed_at: null })).toBe("ابھی کسی نے نہیں دیکھا");
    expect(receivedText(1)).toBe(`${iso(1)} فائل موصول ہوئی`);
    expect(receivedText(4)).toBe(`${iso(4)} فائلیں موصول ہوئیں`);
    expect(receivedText(0)).toBe("ابھی کچھ موصول نہیں ہوا");
    expect(limitsText({ max_files: 1, max_mb: null })).toBe(`زیادہ سے زیادہ ${iso(1)} فائل`);
    expect(limitsText({ max_files: 20, max_mb: null })).toBe(`زیادہ سے زیادہ ${iso(20)} فائلیں`);
    expect(limitsText({ max_files: 1, max_mb: 100 })).toBe(`زیادہ سے زیادہ ${iso(1)} فائل، ہر ایک ${iso(100)} MB تک`);
    expect(limitsText({ max_files: null, max_mb: 50 })).toBe(`ہر فائل ${iso(50)} MB تک`);
    expect(limitsText({ max_files: null, max_mb: null })).toBe("");
    await setLanguage("en", { root: false });
    expect(viewsText({ view_count: 1, last_viewed_at: null })).toBe("1 view");
    expect(viewsText({ view_count: 2, last_viewed_at: null })).toBe("2 views");
    expect(receivedText(1)).toBe("1 file received");
    expect(receivedText(2)).toBe("2 files received");
    expect(limitsText({ max_files: 1, max_mb: 100 })).toBe("Up to 1 file of 100 MB");
    expect(limitsText({ max_files: 2, max_mb: 100 })).toBe("Up to 2 files of 100 MB");
    expect(limitsText({ max_files: 1, max_mb: null })).toBe("Up to 1 file");
    expect(limitsText({ max_files: null, max_mb: 50 })).toBe("Files of up to 50 MB");
    expect(ruleText({ kind: "app", value: "zoom" })).toBe("From Zoom");
    expect(audienceText({ audience: "people", people: ["a@b.co"], has_password: false })).toBe("Only a@b.co");
  });
});
