import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { Notice } from "./Notice";
import { ERROR_MS, MESSAGE_MS, WHEEL_END_MS } from "../lib/notice";

beforeEach(() => {
  vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "performance"] });
});
afterEach(() => {
  vi.useRealTimers();
});

function renderNotice(props: Partial<Parameters<typeof Notice>[0]> = {}) {
  const all = { onDismiss: vi.fn(), onOpen: vi.fn(), ...props };
  const view = render(<Notice kind="toast" role="status" timeoutMs={MESSAGE_MS} {...all}>Moved “Kick-off” to Acme</Notice>);
  const shell = screen.getByRole("status");
  // jsdom lays nothing out: the notice is 300 px wide.
  shell.getBoundingClientRect = () => ({ width: 300, height: 48, top: 0, left: 0, right: 300, bottom: 48, x: 0, y: 0, toJSON: () => ({}) });
  return { ...all, shell, view };
}
const advance = (ms: number) => act(() => {
  vi.advanceTimersByTime(ms);
});

describe("a notice (docs/DESIGN_SYSTEM.md Notices)", () => {
  it("goes where it is about when clicked, and closes as it goes", () => {
    const { onOpen, onDismiss } = renderNotice();
    fireEvent.click(screen.getByRole("button", { name: "Moved “Kick-off” to Acme" }));
    expect(onOpen).toHaveBeenCalledTimes(1);
    expect(onDismiss).toHaveBeenCalledTimes(1);
  });

  it("is clickable anywhere on it, not only on its words", () => {
    const { onOpen, shell } = renderNotice();
    fireEvent.click(shell);
    expect(onOpen).toHaveBeenCalledTimes(1);
  });

  it("is not clickable when it is about nothing in particular", () => {
    renderNotice({ onOpen: undefined });
    expect(screen.queryByRole("button", { name: "Moved “Kick-off” to Acme" })).not.toBeInTheDocument();
    expect(screen.getByRole("status")).not.toHaveClass("has-target");
  });

  it("closes with × (named Dismiss) and with Esc, without going anywhere", () => {
    const { onOpen, onDismiss } = renderNotice();
    fireEvent.click(screen.getByRole("button", { name: "Dismiss" }));
    expect(onDismiss).toHaveBeenCalledTimes(1);
    fireEvent.keyDown(screen.getByRole("button", { name: "Dismiss" }), { key: "Escape" });
    expect(onDismiss).toHaveBeenCalledTimes(2);
    expect(onOpen).not.toHaveBeenCalled();
  });

  it("hides by itself after its time, with a bar that runs for that time", () => {
    const { onDismiss, shell } = renderNotice();
    const bar = shell.querySelector(".notice-timer > span") as HTMLElement;
    expect(bar.style.animationDuration).toBe(`${MESSAGE_MS}ms`);
    advance(MESSAGE_MS - 10);
    expect(onDismiss).not.toHaveBeenCalled();
    advance(20);
    expect(onDismiss).toHaveBeenCalledTimes(1);
  });

  it("an error stays 10 seconds", () => {
    const { onDismiss } = renderNotice({ timeoutMs: ERROR_MS });
    advance(9_000);
    expect(onDismiss).not.toHaveBeenCalled();
    advance(1_100);
    expect(onDismiss).toHaveBeenCalledTimes(1);
  });

  it("pauses while the pointer is over it, the keyboard is on it or a finger touches it", () => {
    const { onDismiss, shell } = renderNotice();
    advance(2_000);
    fireEvent.mouseEnter(shell);
    expect(shell.querySelector(".notice-timer > span")).toHaveClass("paused");
    advance(30_000);
    expect(onDismiss).not.toHaveBeenCalled();
    fireEvent.mouseLeave(shell);
    expect(shell.querySelector(".notice-timer > span")).not.toHaveClass("paused");
    advance(3_900);
    expect(onDismiss).not.toHaveBeenCalled();
    // Focus: the 100 ms that are left wait while the keyboard is on it.
    fireEvent.focus(screen.getByRole("button", { name: "Dismiss" }));
    advance(10_000);
    expect(onDismiss).not.toHaveBeenCalled();
    fireEvent.blur(screen.getByRole("button", { name: "Dismiss" }), { relatedTarget: document.body });
    fireEvent.pointerDown(shell, { pointerId: 3, pointerType: "touch", button: 0, clientX: 10, clientY: 10 });
    advance(10_000);
    expect(onDismiss).not.toHaveBeenCalled();
    fireEvent.pointerUp(shell, { pointerId: 3, pointerType: "touch", clientX: 10, clientY: 10 });
    advance(200);
    expect(onDismiss).toHaveBeenCalledTimes(1);
  });

  it("has no timer when it is about something still true", () => {
    const { onDismiss, shell } = renderNotice({ timeoutMs: null });
    expect(shell.querySelector(".notice-timer")).toBeNull();
    advance(120_000);
    expect(onDismiss).not.toHaveBeenCalled();
  });

  it("follows a drag, leaves past a third of its width, and the drag is never a click", () => {
    const { onDismiss, onOpen, shell } = renderNotice();
    fireEvent.pointerDown(shell, { pointerId: 1, button: 0, clientX: 100, clientY: 20 });
    fireEvent.pointerMove(shell, { pointerId: 1, clientX: 140, clientY: 22 });
    expect(shell.style.transform).toBe("translateX(40px)");
    expect(Number(shell.style.opacity)).toBeLessThan(1);
    fireEvent.pointerMove(shell, { pointerId: 1, clientX: 220, clientY: 22 });
    advance(200);
    fireEvent.pointerUp(shell, { pointerId: 1, clientX: 220, clientY: 22 });
    fireEvent.click(shell);
    expect(onOpen).not.toHaveBeenCalled();
    advance(200);
    expect(onDismiss).toHaveBeenCalledTimes(1);
  });

  it("springs back from a short slow drag, still open and not clicked", () => {
    const { onDismiss, onOpen, shell } = renderNotice();
    fireEvent.pointerDown(shell, { pointerId: 1, button: 0, clientX: 100, clientY: 20 });
    fireEvent.pointerMove(shell, { pointerId: 1, clientX: 130, clientY: 20 });
    advance(300);
    fireEvent.pointerUp(shell, { pointerId: 1, clientX: 130, clientY: 20 });
    fireEvent.click(screen.getByRole("button", { name: "Moved “Kick-off” to Acme" }));
    expect(shell.style.transform).toBe("");
    expect(onOpen).not.toHaveBeenCalled();
    advance(300);
    expect(onDismiss).not.toHaveBeenCalled();
  });

  it("leaves on a trackpad's sideways swipe, and keeps a vertical scroll for the page", () => {
    const { onDismiss, shell } = renderNotice();
    fireEvent.wheel(shell, { deltaX: 0, deltaY: 60 });
    expect(shell.style.transform).toBe("");
    for (let i = 0; i < 8; i++) fireEvent.wheel(shell, { deltaX: -16, deltaY: 0 });
    expect(shell.style.transform).toBe("translateX(128px)");
    advance(WHEEL_END_MS + 10);
    advance(200);
    expect(onDismiss).toHaveBeenCalledTimes(1);
  });

  it("leaves at once without sliding when the system asks for reduced motion", () => {
    const matchMedia = window.matchMedia;
    window.matchMedia = ((q: string) => ({ matches: q.includes("reduce"), media: q, addEventListener() {}, removeEventListener() {} })) as unknown as typeof window.matchMedia;
    try {
      const { onDismiss, shell } = renderNotice();
      fireEvent.pointerDown(shell, { pointerId: 1, button: 0, clientX: 100, clientY: 20 });
      fireEvent.pointerMove(shell, { pointerId: 1, clientX: 250, clientY: 20 });
      fireEvent.pointerUp(shell, { pointerId: 1, clientX: 250, clientY: 20 });
      expect(onDismiss).toHaveBeenCalledTimes(1);
    } finally {
      window.matchMedia = matchMedia;
    }
  });
});
