import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, screen } from "@testing-library/react";

// The entry point picks the view from the window label; every window follows the saved theme (C1).
async function boot(label: string | Error) {
  vi.resetModules();
  document.body.innerHTML = '<div id="root"></div>';
  vi.doMock("@tauri-apps/api/webviewWindow", () => ({
    getCurrentWebviewWindow: () => {
      if (label instanceof Error) throw label;
      return { label };
    },
  }));
  vi.doMock("./views/main/MainApp", () => ({ default: () => <div>main view</div> }));
  vi.doMock("./views/overlay/OverlayApp", () => ({ default: () => <div>overlay view</div> }));
  vi.doMock("./views/dictation/DictationApp", () => ({ default: () => <div>dictation view</div> }));
  vi.doMock("./views/pill/PillApp", () => ({ default: () => <div>pill view</div> }));
  vi.doMock("./views/review/ReviewApp", () => ({ default: () => <div>review view</div> }));
  await import("./main");
}

describe("entry point", () => {
  beforeEach(() => {
    // main.tsx mounts through ReactDOM.createRoot itself, outside Testing Library's act() wrapper.
    (globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = false;
    window.localStorage.clear();
    delete document.documentElement.dataset.window;
    delete document.documentElement.dataset.theme;
  });

  afterEach(() => {
    cleanup();
    (globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
    vi.doUnmock("@tauri-apps/api/webviewWindow");
  });

  it("renders the main window, tags the document and applies the saved theme", async () => {
    window.localStorage.setItem("echoledger-theme", "light");
    await boot("main");
    expect(await screen.findByText("main view")).toBeInTheDocument();
    expect(document.documentElement.dataset.window).toBe("main");
    expect(document.documentElement.dataset.theme).toBe("light");
  });

  it("renders the caption overlay for the overlay window in the saved theme", async () => {
    window.localStorage.setItem("echoledger-theme", "light");
    await boot("overlay");
    expect(await screen.findByText("overlay view")).toBeInTheDocument();
    expect(document.documentElement.dataset.window).toBe("overlay");
    expect(document.documentElement.dataset.theme).toBe("light");
  });

  it("renders the dictation box for the dictation window", async () => {
    await boot("dictation");
    expect(await screen.findByText("dictation view")).toBeInTheDocument();
    expect(document.documentElement.dataset.window).toBe("dictation");
    expect(document.documentElement.dataset.theme).toBeDefined();
  });

  it.each([
    ["pill", "pill view"],
    ["review", "review view"],
  ])("renders the %s window's view in the saved theme", async (label, text) => {
    window.localStorage.setItem("echoledger-theme", "light");
    await boot(label);
    expect(await screen.findByText(text)).toBeInTheDocument();
    expect(document.documentElement.dataset.window).toBe(label);
    expect(document.documentElement.dataset.theme).toBe("light");
  });

  it("treats an unknown window label as the main window", async () => {
    await boot("settings-popup");
    expect(await screen.findByText("main view")).toBeInTheDocument();
  });

  it("treats a plain browser (no Tauri window) as the main window", async () => {
    await boot(new Error("window.__TAURI_INTERNALS__ is undefined"));
    expect(await screen.findByText("main view")).toBeInTheDocument();
    expect(document.documentElement.dataset.window).toBe("main");
    expect(document.documentElement.dataset.theme).toBeDefined();
  });
});
