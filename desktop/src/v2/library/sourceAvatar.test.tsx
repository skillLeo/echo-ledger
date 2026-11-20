// The real logos on Library rows (PLAN_V2_PARITY 3; parity.csv DT-LIB-063, 064, DT-REC-007, 008, 140): each known
// source in its own mark, the neutral glyph for the rest, and the device it was recorded on as a badge.
import { describe, expect, it, vi } from "vitest";
import { act, render, screen, within } from "@testing-library/react";
import { hasSourceLogo, SourceLogo } from "../../components/SourceLogos";
import { makeItem } from "../../test/fixtures";
import { flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { SourceAvatar, sourceWords } from "./SourceAvatar";
import { LibraryV2 } from "./LibraryV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const PLAN = ["whatsapp", "whatsapp_business", "phone_call", "google_meet", "zoom", "microsoft_teams", "slack", "voice_note"];

describe("source logos", () => {
  it("every source the plan names has its own mark; an unknown one has none", () => {
    for (const app of PLAN) expect(hasSourceLogo(app), app).toBe(true);
    expect(hasSourceLogo("telegram")).toBe(true);
    expect(hasSourceLogo("discord")).toBe(false);
    expect(hasSourceLogo(null)).toBe(false);
    // Only the marks' own names: never a property every object has.
    expect(hasSourceLogo("constructor")).toBe(false);
    expect(hasSourceLogo("toString")).toBe(false);
    expect(render(<SourceLogo app="constructor" />).container).toBeEmptyDOMElement();
    const { container } = render(<SourceLogo app="carrier_pigeon" />);
    expect(container).toBeEmptyDOMElement();
  });

  it("the marks differ from each other (no two sources drawn alike, but WhatsApp Web, which is WhatsApp)", () => {
    const drawn = PLAN.map((app) => render(<SourceLogo app={app} />).container.innerHTML);
    expect(new Set(drawn).size).toBe(PLAN.length);
  });

  it("a row's mark says the app and the device; the badge shows the device; no logo means the neutral glyph", () => {
    render(<SourceAvatar item={makeItem("a", { source_app: "whatsapp", source_app_label: "WhatsApp", source_device: "phone" })} />);
    const mark = screen.getByRole("img", { name: "WhatsApp, on a phone" });
    expect(mark.querySelector("svg.source-logo")).not.toBeNull();
    expect(mark.querySelector(".el-source__device")).not.toBeNull();
    render(<SourceAvatar item={makeItem("b", { source_app: "discord", source_app_label: "Discord", source_device: "desktop" })} />);
    const plain = screen.getByRole("img", { name: "Discord, on a computer" });
    expect(plain.querySelector("svg.source-logo")).toBeNull();
    expect(plain).not.toHaveClass("el-source--logo");
    expect(sourceWords({ source_app: "voice_note", source_app_label: "Voice note", source_device: "web" })).toBe("Voice note, on the web");
    expect(sourceWords({ source_app: undefined, source_app_label: undefined, source_device: undefined })).toBe("Other");
  });

  it("Library rows carry the marks", async () => {
    resetTauriMock();
    const items = [
      makeItem("z", { title: "Kick-off", source_app: "zoom", source_app_label: "Zoom", source_device: "desktop" }),
      makeItem("t", { title: "Standup", source_app: "microsoft_teams", source_app_label: "Microsoft Teams", source_device: "desktop", pinned: true }),
    ];
    respond("library_list", items);
    respond("library_refresh", items);
    render(<LibraryV2 />);
    await act(flushAsync);
    const list = screen.getByRole("list", { name: "Recordings" });
    expect(within(list).getByRole("img", { name: "Zoom, on a computer" })).toBeInTheDocument();
    expect(within(list).getByRole("img", { name: "Microsoft Teams, on a computer" })).toBeInTheDocument();
    // A pinned row says so on its second line, and the row's button reads it.
    expect(list.querySelectorAll(".el-row__pin")).toHaveLength(1);
    expect(within(list).getByRole("button", { name: "Standup" })).toHaveAccessibleDescription(/^Pinned/);
  });

  it("the recording page shows the source's mark and says where it was recorded in Details", async () => {
    resetTauriMock();
    const item = makeItem("z", { title: "Kick-off", source_app: "zoom", source_app_label: "Zoom", source_device: "phone", device: { id: "d", name: "Ayesha's phone" } });
    respond("library_list", [item]);
    respond("library_refresh", [item]);
    respond("session_detail", { playback_url: null });
    render(<LibraryV2 openRequest={{ id: "z", seq: 1 }} />);
    await act(flushAsync);
    expect(screen.getByRole("img", { name: "Zoom, on a phone" })).toBeInTheDocument();
    screen.getByRole("tab", { name: "Details" }).click();
    await act(flushAsync);
    const details = document.querySelector(".el-details") as HTMLElement;
    expect(within(details).getByText("Recorded on").nextElementSibling).toHaveTextContent("Phone");
    expect(within(details).getByText("Device").nextElementSibling).toHaveTextContent("Ayesha's phone");
  });
});
