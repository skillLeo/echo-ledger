// FIX-03 (PLAN_V2_1_POLISH), the desktop part: one message at a time in the whole window, each with its ✕, and Esc
// closes it when nothing else on screen takes Esc (a dialog, a menu, the page's own open recording or filters).
import { afterEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { useState } from "react";
import { Toast, ToastRegion } from "./Toast";
import { AppToastV2, type AppMessage } from "../shell/AppToast";

afterEach(() => vi.useRealTimers());

/** A page's own message (the Library's Undo, or its error) and the app's, side by side as the window has them. */
function Window({ onUndo = () => undefined }: { onUndo?: () => void }) {
  const [page, setPage] = useState<{ id: number; text: string; error?: boolean } | null>(null);
  const [app, setApp] = useState<AppMessage | null>(null);
  const seq = (n: number) => n + 1;
  return (
    <>
      <button type="button" onClick={() => setPage((m) => ({ id: seq(m?.id ?? 0), text: "Moved to Trash" }))}>Delete</button>
      <button type="button" onClick={() => setPage((m) => ({ id: seq(m?.id ?? 0), text: "The recording couldn't be moved.", error: true }))}>Fail</button>
      <button type="button" onClick={() => setApp((m) => ({ id: seq(m?.id ?? 0), text: "Synced", error: false }))}>Sync</button>
      <button type="button" onClick={() => setApp((m) => ({ id: seq(m?.id ?? 0), text: "The upload stopped.", error: true }))}>Upload fails</button>
      <ToastRegion>
        {page && <Toast key={page.id} message={page.text} duration={0} onAction={page.error ? undefined : onUndo} error={page.error}
          onClose={() => setPage(null)} />}
      </ToastRegion>
      <AppToastV2 message={app} duration={() => 0} onClose={() => setApp(null)} />
    </>
  );
}
const shown = () => [...document.querySelectorAll(".el-toast .el-toast__message")].map((m) => m.textContent);

describe("one message at a time (FIX-03)", () => {
  it("a new message, from anywhere in the window, takes the place of the one on screen", () => {
    const onUndo = vi.fn();
    render(<Window onUndo={onUndo} />);
    fireEvent.click(screen.getByRole("button", { name: "Delete" }));
    expect(shown()).toEqual(["Moved to Trash"]);
    fireEvent.click(screen.getByRole("button", { name: "Sync" }));
    expect(shown()).toEqual(["Synced"]);
    // Replaced is not undone.
    expect(onUndo).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: "Delete" }));
    expect(shown()).toEqual(["Moved to Trash"]);
  });

  it("two quick actions never show two messages at once", () => {
    render(<Window />);
    act(() => {
      fireEvent.click(screen.getByRole("button", { name: "Delete" }));
      fireEvent.click(screen.getByRole("button", { name: "Sync" }));
    });
    expect(shown()).toHaveLength(1);
  });

  it("an error is never replaced by a message from elsewhere: that one waits until the error closes", () => {
    render(<Window />);
    fireEvent.click(screen.getByRole("button", { name: "Fail" }));
    fireEvent.click(screen.getByRole("button", { name: "Sync" }));
    expect(shown()).toEqual(["The recording couldn't be moved."]);
    // A newer one from elsewhere replaces the one waiting (one at a time, the newest).
    fireEvent.click(screen.getByRole("button", { name: "Sync" }));
    expect(shown()).toEqual(["The recording couldn't be moved."]);
    fireEvent.click(screen.getByRole("button", { name: "Dismiss" }));
    expect(shown()).toEqual(["Synced"]);
    // The app's error: the page's message waits for it too.
    fireEvent.click(screen.getByRole("button", { name: "Dismiss" }));
    fireEvent.click(screen.getByRole("button", { name: "Upload fails" }));
    fireEvent.click(screen.getByRole("button", { name: "Delete" }));
    expect(shown()).toEqual(["The upload stopped."]);
    fireEvent.keyDown(document.body, { key: "Escape" });
    expect(shown()).toEqual(["Moved to Trash"]);
    // A newer error does replace an error.
    fireEvent.click(screen.getByRole("button", { name: "Upload fails" }));
    fireEvent.click(screen.getByRole("button", { name: "Fail" }));
    expect(shown()).toEqual(["The recording couldn't be moved."]);
  });

  it("a waiting message's time starts only once it is on screen", () => {
    vi.useFakeTimers();
    const onClose = vi.fn();
    const { rerender } = render(<><Toast message="Problem" duration={0} error onClose={() => undefined} /></>);
    rerender(<><Toast message="Problem" duration={0} error onClose={() => undefined} /><ToastRegion><Toast message="Later" duration={1000} onClose={onClose} /></ToastRegion></>);
    act(() => vi.advanceTimersByTime(5000));
    expect(onClose).not.toHaveBeenCalled();
    expect(shown()).toEqual(["Problem"]);
  });

  it("each has its ✕", () => {
    const onClose = vi.fn();
    render(<Toast message="Saved" duration={0} onClose={onClose} />);
    fireEvent.click(screen.getByRole("button", { name: "Dismiss" }));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it("Esc closes it from anywhere in the window", () => {
    const onClose = vi.fn();
    render(<><button type="button">Elsewhere</button><Toast message="Saved" duration={0} onClose={onClose} /></>);
    screen.getByRole("button", { name: "Elsewhere" }).focus();
    fireEvent.keyDown(screen.getByRole("button", { name: "Elsewhere" }), { key: "Escape" });
    expect(onClose).toHaveBeenCalledTimes(1);
    fireEvent.keyDown(document.body, { key: "Escape" });
    expect(onClose).toHaveBeenCalledTimes(2);
  });

  it("but not while something else takes Esc: a dialog or a menu open, or the page's own Esc", () => {
    const onClose = vi.fn();
    const { rerender } = render(<Toast message="Saved" duration={0} onClose={onClose} />);
    const dialog = document.createElement("div");
    dialog.setAttribute("role", "dialog");
    dialog.setAttribute("aria-modal", "true");
    document.body.append(dialog);
    fireEvent.keyDown(document.body, { key: "Escape" });
    dialog.remove();
    const menu = document.createElement("ul");
    menu.setAttribute("role", "menu");
    document.body.append(menu);
    fireEvent.keyDown(document.body, { key: "Escape" });
    menu.remove();
    expect(onClose).not.toHaveBeenCalled();
    // The page closed its open recording with this Esc first (it prevents the default): the message stays, even when the
    // page's listener (on the document, in the bubble phase) was added after the toast came.
    rerender(<Toast message="Saved" duration={0} onClose={onClose} />);
    const taken = (e: KeyboardEvent) => e.preventDefault();
    document.addEventListener("keydown", taken);
    fireEvent.keyDown(document.body, { key: "Escape" });
    document.removeEventListener("keydown", taken);
    expect(onClose).not.toHaveBeenCalled();
    fireEvent.keyDown(document.body, { key: "Escape" });
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it("an error stays until it is closed or its time is up, like any message, and is the one on screen", () => {
    vi.useFakeTimers();
    const onClose = vi.fn();
    render(<AppToastV2 message={{ id: 1, text: "The upload stopped.", error: true }} duration={() => 8000} onClose={onClose} />);
    expect(shown()).toEqual(["The upload stopped."]);
    act(() => vi.advanceTimersByTime(8000));
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
