import { describe, expect, it } from "vitest";
import { Countdown, DRAG_START_PX, dragOpacity, ERROR_MS, MESSAGE_MS, swipeOutcome, SwipeTracker, WHEEL_END_MS } from "./notice";

describe("the notice timer (docs/DESIGN_SYSTEM.md Notices 3)", () => {
  it("runs 6 s for a message and 10 s for an error", () => {
    expect(MESSAGE_MS).toBe(6000);
    expect(ERROR_MS).toBe(10_000);
  });

  it("pauses and resumes where it left off", () => {
    const timer = new Countdown(6000, 0);
    expect(timer.remaining(2000)).toBe(4000);
    timer.pause(2000);
    expect(timer.paused).toBe(true);
    expect(timer.remaining(9000)).toBe(4000);
    expect(timer.expired(60_000)).toBe(false);
    timer.resume(9000);
    expect(timer.remaining(12_000)).toBe(1000);
    expect(timer.expired(13_000)).toBe(true);
    expect(timer.remaining(20_000)).toBe(0);
  });

  it("ignores a pause while paused and a resume while running", () => {
    const timer = new Countdown(6000, 0);
    timer.resume(1000);
    expect(timer.remaining(1000)).toBe(5000);
    timer.pause(1000);
    timer.pause(3000);
    expect(timer.remaining(5000)).toBe(5000);
  });
});

describe("swiping a notice away (docs/DESIGN_SYSTEM.md Notices 4)", () => {
  it("leaves past a third of its width either way, else springs back", () => {
    expect(swipeOutcome(-130, 360, 0)).toBe("leave-left");
    expect(swipeOutcome(120, 360, 0)).toBe("leave-right");
    expect(swipeOutcome(119, 360, 0)).toBe("spring");
    expect(swipeOutcome(-40, 360, 0)).toBe("spring");
  });

  it("leaves on a quick flick that went far enough, in the flick's direction only", () => {
    expect(swipeOutcome(40, 360, 0.9)).toBe("leave-right");
    expect(swipeOutcome(-40, 360, -0.9)).toBe("leave-left");
    expect(swipeOutcome(10, 360, 2)).toBe("spring");
    expect(swipeOutcome(40, 360, -0.9)).toBe("spring");
    expect(swipeOutcome(40, 360, 0.3)).toBe("spring");
  });

  it("fades as it is dragged", () => {
    expect(dragOpacity(0, 360)).toBe(1);
    expect(dragOpacity(60, 360)).toBeCloseTo(0.675);
    expect(dragOpacity(-500, 360)).toBeCloseTo(0.35);
  });

  it("is a tap until the pointer moves sideways, and a drag is never a click", () => {
    const tracker = new SwipeTracker(360);
    tracker.down(100, 50, 0);
    expect(tracker.move(103, 51, 10)).toBe(0);
    expect(tracker.up(20)).toBe("tap");
    tracker.down(100, 50, 100);
    expect(tracker.move(100 + DRAG_START_PX, 50, 110)).toBe(DRAG_START_PX);
    expect(tracker.isDrag).toBe(true);
    expect(tracker.up(400)).toBe("spring");
  });

  it("leaves the page's own vertical scroll alone", () => {
    const tracker = new SwipeTracker(360);
    tracker.down(100, 50, 0);
    expect(tracker.move(110, 80, 10)).toBe(0);
    expect(tracker.isDrag).toBe(false);
    expect(tracker.up(20)).toBe("tap");
  });

  it("follows the drag and decides on letting go: far, flicked or back", () => {
    const far = new SwipeTracker(300);
    far.down(0, 0, 0);
    for (let i = 1; i <= 10; i++) far.move(-12 * i, 0, i * 40);
    expect(far.offset).toBe(-120);
    expect(far.up(400 + 300)).toBe("leave-left");

    const flick = new SwipeTracker(300);
    flick.down(0, 0, 0);
    flick.move(20, 0, 10);
    flick.move(50, 0, 40);
    expect(flick.up(45)).toBe("leave-right");

    const slow = new SwipeTracker(300);
    slow.down(0, 0, 0);
    slow.move(20, 0, 200);
    slow.move(50, 0, 800);
    expect(slow.up(1000)).toBe("spring");
  });

  it("takes a trackpad's sideways scroll as a swipe, and ignores a vertical one", () => {
    const tracker = new SwipeTracker(300);
    expect(tracker.wheel(0, 40, 0)).toBe(0);
    for (let i = 1; i <= 8; i++) tracker.wheel(15, 1, i * 16);
    expect(tracker.offset).toBe(-120);
    expect(tracker.wheelEnd(8 * 16 + WHEEL_END_MS)).toBe("leave-left");
    expect(tracker.offset).toBe(0);
    tracker.wheel(-10, 0, 1000);
    tracker.wheel(-10, 0, 1300);
    expect(tracker.wheelEnd(1300 + WHEEL_END_MS)).toBe("spring");
  });
});
