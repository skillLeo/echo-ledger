// The panels of Live, Ask, Activity and Settings (PLAN_NAV_3_LEVELS §3, §4), and Settings in three levels: the groups
// and the search are the panel's (never on the page too), a group's page has its sections in an inner list beside it.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/react";
import { resetTauriMock, respond } from "../../test/tauri";
import { makeSettings } from "../../test/fixtures";
import { ActivityPanel, AskPanel, LivePanel, SettingsPanel } from "./PlacePanels";
import { SettingsV2 } from "../settings/SettingsV2";
import { SHOW_PANEL_EVENT } from "./NavRail";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const everything = () => true;

describe("the places' panels", () => {
  beforeEach(() => {
    resetTauriMock();
    respond("get_settings", makeSettings());
    respond("storage_info", null);
  });

  it("Live: call recording Ready or Off, then each part of the Live page", () => {
    const onPart = vi.fn();
    const onCalls = vi.fn();
    const { rerender } = render(<LivePanel callRecording onCalls={onCalls} onPart={onPart} />);
    expect(screen.getByRole("button", { name: /Call recording/ })).toHaveTextContent("Ready");
    fireEvent.click(screen.getByRole("button", { name: /Call recording/ }));
    expect(onCalls).toHaveBeenCalled();
    for (const [name, part] of [["Live Assist", "assist"], ["Live Translate", "translate"], ["Dictation", "dictation"], ["Captions", "captions"]] as const) {
      fireEvent.click(screen.getByRole("button", { name }));
      expect(onPart).toHaveBeenLastCalledWith(part);
    }
    rerender(<LivePanel callRecording={false} onCalls={onCalls} onPart={onPart} />);
    expect(screen.getByRole("button", { name: /Call recording/ })).toHaveTextContent("Off");
  });

  it("Ask: a new question, or one about a project", () => {
    const onAsk = vi.fn();
    render(<AskPanel projects={[{ id: "p1", name: "Acme" }]} current={null} onAsk={onAsk} />);
    expect(screen.getByRole("button", { name: "New question" })).toHaveAttribute("aria-current", "page");
    fireEvent.click(screen.getByRole("button", { name: "Acme" }));
    expect(onAsk).toHaveBeenLastCalledWith("p1");
  });

  it("Activity: All, Needs you (its count bold), Done", () => {
    const onShow = vi.fn();
    render(<ActivityPanel show="needs" needs={3} onShow={onShow} />);
    expect(screen.getByRole("button", { name: /Needs you/ })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("button", { name: /Needs you/ }).querySelector(".el-nav-item__count--strong")).toHaveTextContent("3");
    fireEvent.click(screen.getByRole("button", { name: "Done" }));
    expect(onShow).toHaveBeenLastCalledWith("done");
  });

  it("Settings: its groups (a group opens its first page) and a search over every setting", () => {
    const onOpen = vi.fn();
    render(<SettingsPanel current="mic" onOpen={onOpen} available={everything} />);
    const groups = screen.getAllByRole("button").map((b) => b.textContent);
    expect(groups.slice(0, 4)).toEqual(["Recording", "Transcription & language", "Live & overlays", "Privacy & data"]);
    expect(screen.getByRole("button", { name: "Recording" })).toHaveAttribute("aria-current", "page");
    fireEvent.click(screen.getByRole("button", { name: "Appearance" }));
    expect(onOpen).toHaveBeenLastCalledWith("appearance");
    fireEvent.change(screen.getByRole("searchbox", { name: "Search settings" }), { target: { value: "storage" } });
    expect(screen.getByRole("list", { name: "Matches" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Recording" })).toBeNull();
  });

  it("Settings' panel leaves Setup & health to the rail; the home's Show groups asks for the panel", () => {
    render(<SettingsPanel current={null} onOpen={() => undefined} available={everything} />);
    expect(screen.queryByRole("button", { name: "Setup & health" })).toBeNull();
    const asked = vi.fn();
    window.addEventListener(SHOW_PANEL_EVENT, asked);
    render(<SettingsV2 available={everything} renderPage={() => null} inPanel />);
    fireEvent.click(screen.getByRole("button", { name: "Show groups" }));
    expect(asked).toHaveBeenCalled();
    window.removeEventListener(SHOW_PANEL_EVENT, asked);
  });

  // The Projects panel and All projects: projectsPanel.test.tsx (UX-09).

  it("Settings' page in three levels: the home has no groups (they are the panel's); a page has its group's sections beside it", () => {
    const renderPage = vi.fn((section: { id: string }) => <p>Page {section.id}</p>);
    const { rerender } = render(<SettingsV2 available={everything} renderPage={renderPage} inPanel />);
    expect(screen.queryByRole("region", { name: "Recording" })).toBeNull();
    expect(screen.queryByRole("searchbox")).toBeNull();
    rerender(<SettingsV2 available={everything} renderPage={renderPage} inPanel section={{ id: "calls", seq: 1 }} />);
    const inner = within(screen.getByRole("navigation", { name: "Recording sections" }));
    expect(inner.getAllByRole("button").map((b) => b.textContent)).toHaveLength(4);
    expect(inner.getAllByRole("button").find((b) => b.getAttribute("aria-current") === "page")).toBeDefined();
    expect(screen.getByText("Page calls")).toBeInTheDocument();
    fireEvent.click(inner.getAllByRole("button")[1]);
    expect(screen.getByText("Page mic")).toBeInTheDocument();
  });
});
