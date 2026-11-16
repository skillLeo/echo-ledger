import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { galleryParams } from "./guard";
import { Gallery } from "./Gallery";

const here = dirname(fileURLToPath(import.meta.url));

describe("the gallery guard", () => {
  it("reads the screen, theme and language, with safe defaults", () => {
    expect(galleryParams("?v2gallery")).toEqual({ screen: "library", theme: "light", lang: "en" });
    expect(galleryParams("?v2gallery&screen=settings&theme=dark&lang=ur")).toEqual({ screen: "settings", theme: "dark", lang: "ur" });
    expect(galleryParams("?screen=<x>&lang=fr")).toEqual({ screen: "library", theme: "light", lang: "en" });
  });

  it("main.tsx reaches the gallery only behind an inline development-build check, and imports nothing of it", () => {
    const main = readFileSync(join(here, "..", "..", "main.tsx"), "utf8");
    expect(main.split("\n").filter((line) => line.includes("v2/gallery") && !line.trimStart().startsWith("//"))).toHaveLength(1);
    const guard = 'if (import.meta.env.DEV && new URLSearchParams(location.search).has("v2gallery")) {';
    expect(main).toContain(guard);
    const branch = main.slice(main.indexOf(guard));
    expect(branch.slice(0, branch.indexOf("} else {"))).toContain('import("./v2/gallery/mount")');
    expect(main).not.toMatch(/^import .*v2\//m);
  });
});

describe("the entry point", () => {
  async function boot(search: string) {
    vi.resetModules();
    document.body.innerHTML = '<div id="root"></div>';
    window.history.replaceState(null, "", `/${search}`);
    vi.doMock("@tauri-apps/api/webviewWindow", () => ({ getCurrentWebviewWindow: () => ({ label: "main" }) }));
    vi.doMock("../../views/main/MainApp", () => ({ default: () => <div>main view</div> }));
    await import("../../main");
  }

  beforeEach(() => {
    (globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = false;
  });
  afterEach(() => {
    cleanup();
    vi.unstubAllEnvs();
    vi.doUnmock("@tauri-apps/api/webviewWindow");
    vi.doUnmock("../../views/main/MainApp");
    window.history.replaceState(null, "", "/");
    (globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
  });

  it("shows the gallery in a development build with ?v2gallery", async () => {
    await boot("?v2gallery&screen=settings&lang=ur&theme=dark");
    expect(await screen.findByRole("heading", { name: "ترتیبات", level: 1 })).toBeInTheDocument();
    expect(screen.queryByText("main view")).toBeNull();
    expect(document.documentElement.dataset.theme).toBe("dark");
  });

  it("shows the app, never the gallery, when the build is not a development build", async () => {
    vi.stubEnv("DEV", false);
    await boot("?v2gallery");
    expect(await screen.findByText("main view")).toBeInTheDocument();
    expect(screen.queryByRole("navigation")).toBeNull();
  });

  it("shows the app without ?v2gallery", async () => {
    await boot("");
    expect(await screen.findByText("main view")).toBeInTheDocument();
  });
});

describe("the Gate 3 screens", () => {
  it("library: rows with time and duration, the filters, and Undo", () => {
    render(<Gallery screen="library" lang="en" />);
    expect(screen.getByRole("heading", { name: "Library", level: 1 })).toBeInTheDocument();
    const list = screen.getByRole("list", { name: "Recordings" });
    const row = within(list).getByRole("button", { name: "Bilal Qureshi · Qureshi Textiles" });
    expect(row).toHaveAccessibleDescription(/Payment terms for the March shipment.*11:42 am.*18:24/);
    fireEvent.click(screen.getByRole("button", { name: "Untranscribed 3" }));
    expect(within(list).getAllByRole("button")).toHaveLength(3);
    fireEvent.click(screen.getByRole("button", { name: "Today 4" }));
    expect(within(screen.getByRole("list", { name: "Recordings" })).getAllByRole("button")).toHaveLength(4);
    fireEvent.click(screen.getByRole("button", { name: "Undo" }));
    expect(screen.queryByText("Voice note moved to Trash")).toBeNull();
  });

  it("library search with no match shows the empty state and clears", () => {
    render(<Gallery screen="library" lang="en" />);
    fireEvent.change(screen.getByRole("searchbox", { name: "Search recordings" }), { target: { value: "Peshawar" } });
    expect(screen.getByRole("heading", { name: "No recordings match" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Clear filters" }));
    expect(screen.getByRole("list", { name: "Recordings" })).toBeInTheDocument();
  });

  it("recording: title, player, tabs and the transcript with speakers", () => {
    render(<Gallery screen="recording" lang="en" />);
    expect(screen.getByRole("heading", { name: "Payment terms for the March shipment", level: 1 })).toBeInTheDocument();
    expect(screen.getByRole("slider", { name: "Position" })).toHaveAttribute("aria-valuetext", "0:38 of 18:24");
    expect(screen.getByRole("tab", { name: "Transcript", selected: true })).toBeInTheDocument();
    const transcript = screen.getByRole("list", { name: "Transcript" });
    expect(within(transcript).getAllByText("Bilal Qureshi")).toHaveLength(3);
    for (const name of ["Share", "Move", "Delete"]) expect(screen.getByRole("button", { name })).toBeInTheDocument();
  });

  it("settings: the profile card with the plan and Upgrade, grouped rows, and search", () => {
    render(<Gallery screen="settings" lang="en" />);
    const profile = screen.getByRole("region", { name: "Ayesha Siddiqui" });
    expect(within(profile).getByText("Free plan")).toBeInTheDocument();
    expect(within(profile).getByRole("button", { name: "Upgrade" })).toBeInTheDocument();
    expect(screen.getByRole("switch", { name: "Private overlay" })).toHaveAttribute("aria-checked", "true");
    fireEvent.change(screen.getByRole("searchbox", { name: "Search settings" }), { target: { value: "storage" } });
    expect(screen.queryByRole("region", { name: "Ayesha Siddiqui" })).toBeNull();
    expect(screen.getAllByRole("button", { name: /./ }).map((b) => b.textContent || b.getAttribute("aria-label"))).toContain("Clear search");
    expect(screen.getByRole("button", { name: "Storage" })).toHaveAccessibleDescription("3.2 GB of 10 GB");
  });

  it.each(["library", "recording", "settings"] as const)("%s in Urdu is right-to-left with Urdu words", (name) => {
    render(<Gallery screen={name} lang="ur" />);
    const root = document.querySelector(".el-root")!;
    expect(root).toHaveAttribute("dir", "rtl");
    expect(root).toHaveAttribute("lang", "ur");
    expect(screen.getByRole("heading", { level: 1 }).textContent).toMatch(/[؀-ۿ]/);
    expect(screen.getByRole("navigation", { name: "مرکزی" })).toBeInTheDocument();
  });
});
