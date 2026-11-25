// Desktop Urdu, package D3b: the settings registry's words (titles, new-look titles, descriptions, option labels, the
// root headers) in the window's language through its accessors, the entries themselves English data as before (ids,
// search, tests read them). A call app's name and a language named in its own language stay as they are; a title read
// differently in its place has a context key. Search keeps every English word and also finds the Urdu ones.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen, within } from "@testing-library/react";
import { resetTauriMock, respond, flushAsync } from "../test/tauri";
import { CALL_APPS, ROOT_SECTIONS, SETTINGS, breadcrumbOf, optionLabel, pathOf, setting, titleOf, titleV2 } from "../lib/settingsRegistry";
import { searchSettings } from "../lib/settingsIndex";
import { SettingsV2 } from "../v2/settings/SettingsV2";
import ur from "./ur.json";
import { resetLanguageForTests, setLanguage, t } from "./index";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);

const urdu = () => act(() => setLanguage("ur", { root: false }));
const NAMES = new Set(CALL_APPS.map(([, name]) => name));

beforeEach(() => {
  resetTauriMock();
  resetLanguageForTests();
});
afterEach(() => resetLanguageForTests());

describe("the registry's words in the catalogue", () => {
  it("every word a row, a result or a breadcrumb shows has its Urdu (a call app's name aside; From and To by context key)", () => {
    const shown = new Set<string>(ROOT_SECTIONS.filter(Boolean));
    for (const e of SETTINGS) {
      if (!NAMES.has(e.title) && !e.id.startsWith("notifications.quiet_")) shown.add(e.title);
      if (e.titleV2) shown.add(e.titleV2);
      if (e.description) shown.add(e.description);
      for (const o of e.options ?? []) if (!o.lang) shown.add(o.label);
    }
    expect(shown.size).toBeGreaterThan(200);
    expect([...shown].filter((text) => !(text in ur))).toEqual([]);
    expect(["settings_context.quiet_from", "settings_context.quiet_to"].filter((key) => !(key in ur))).toEqual([]);
  });
});

describe("the registry's accessors", () => {
  it("in English: the entry itself, its words exactly as before", () => {
    const storage = SETTINGS.find((e) => e.id === "storage")!;
    expect(setting("storage")).toBe(storage);
    expect(titleOf("notifications.quiet_from")).toBe("From");
    expect(titleV2(setting("storage.bin"))).toBe("Trash");
    expect(breadcrumbOf(setting("storage.used"))).toBe("App › Storage");
    expect(optionLabel("appearance.theme", "system")).toBe("System");
  });

  it("in Urdu: a copy in the catalogue's words; ids, keys and keywords as they were", async () => {
    await urdu();
    const storage = setting("storage");
    expect(storage).not.toBe(SETTINGS.find((e) => e.id === "storage"));
    expect([storage.id, storage.title, storage.keywords]).toEqual(["storage", "اسٹوریج", SETTINGS.find((e) => e.id === "storage")!.keywords]);
    expect(titleV2(setting("storage.bin"))).toBe("ردی");
    expect(setting("lock.after").options?.map((o) => o.label)).toContain("5 منٹ بعد");
    expect(setting("lock.after").options?.map((o) => o.value)).toEqual(SETTINGS.find((e) => e.id === "lock.after")!.options!.map((o) => o.value));
    expect(optionLabel("appearance.theme", "system")).toBe("سسٹم");
    expect(pathOf(setting("storage.used"))).toEqual(["ایپ", "اسٹوریج"]);
    expect(breadcrumbOf(setting("storage.used"))).toBe("ایپ › اسٹوریج");
  });

  it("a call app's name and a language's own name stay; Quiet hours' From and To read as times, not a sender", async () => {
    await urdu();
    expect(titleOf("calls.app.whatsapp")).toBe("WhatsApp");
    expect(titleV2(setting("calls.app.zoom"))).toBe("Zoom");
    const language = setting("appearance.language").options!;
    expect(language.map((o) => o.label)).toEqual(SETTINGS.find((e) => e.id === "appearance.language")!.options!.map((o) => o.label));
    expect([titleOf("notifications.quiet_from"), titleOf("notifications.quiet_to")]).toEqual(["سے", "تک"]);
    expect(t("From")).toBe("ماخذ");
  });
});

describe("search in Urdu", () => {
  it("English words find what they always found; the Urdu titles find it too; the breadcrumb is in Urdu", async () => {
    const english = searchSettings("storage").settings.map((r) => r.entry.id);
    expect(english).toContain("storage");
    await urdu();
    expect(searchSettings("storage").settings.map((r) => r.entry.id)).toEqual(english);
    const found = searchSettings("اسٹوریج").settings;
    expect(found.map((r) => r.entry.id)).toContain("storage");
    expect(found.find((r) => r.entry.id === "storage.used")?.breadcrumb).toBe("ایپ › اسٹوریج");
    expect(searchSettings("خاموش اوقات").settings.map((r) => r.entry.id)).toContain("notifications.quiet");
    // A title with no Urdu keyword: found by its Urdu in Urdu only; back in English the index is English again.
    expect(searchSettings("تکنیکی").settings.map((r) => r.entry.id)).toContain("about.technical");
    await act(() => setLanguage("en", { root: false }));
    expect(searchSettings("تکنیکی").settings).toEqual([]);
  });
});

describe("Settings' home in Urdu", () => {
  it("each group's rows have their titles in Urdu, a help result too", async () => {
    respond("get_settings", null);
    respond("storage_info", null);
    await urdu();
    render(<div className="el-root"><SettingsV2 available={() => true} renderPage={() => null} /></div>);
    await act(flushAsync);
    const privacy = screen.getByRole("region", { name: "پرائیویسی اور ڈیٹا" });
    expect(within(privacy).getByRole("button", { name: /^اسٹوریج/ })).toBeInTheDocument();
    expect(within(privacy).getByRole("button", { name: /^ایپ لاک/ })).toBeInTheDocument();
  });
});
