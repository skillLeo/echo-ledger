import { describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { readFileSync, readdirSync, statSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { confirmAction } from "../../lib/confirm";
import { ConfirmHost } from "./ConfirmHost";

describe("the app's own confirm dialog (UI sweep rule 2)", () => {
  it("asks in the window's dialog and answers with the button pressed", async () => {
    render(<ConfirmHost />);
    let answer: Promise<boolean> = Promise.resolve(false);
    act(() => { answer = confirmAction({ title: "Delete this recording?", body: "It can't be undone.", confirm: "Delete" }); });
    const dialog = screen.getByRole("alertdialog", { name: "Delete this recording?" });
    expect(dialog).toHaveTextContent("It can't be undone.");
    fireEvent.click(screen.getByRole("button", { name: "Delete" }));
    await expect(answer).resolves.toBe(true);
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    act(() => { answer = confirmAction({ title: "Sign out?", confirm: "Sign out" }); });
    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));
    await expect(answer).resolves.toBe(false);
  });

  /** An opener that asks, as every caller does, and counts how often it asked. */
  function Opener({ asked, answers }: { asked: { count: number }; answers: boolean[] }) {
    return (
      <button type="button" onClick={() => {
        asked.count += 1;
        void confirmAction({ title: "Delete this recording?", body: "It can't be undone.", confirm: "Delete", tone: "danger" }).then((yes) => answers.push(yes));
      }}>Delete</button>
    );
  }

  it("is an alert dialog read with its question and what follows, Cancel focused, its button red only when it deletes", () => {
    render(<ConfirmHost />);
    act(() => { void confirmAction({ title: "Delete this recording?", body: "It can't be undone.", confirm: "Delete", tone: "danger" }); });
    const dialog = screen.getByRole("alertdialog", { name: "Delete this recording?" });
    expect(dialog).toHaveAttribute("aria-modal", "true");
    expect(dialog).toHaveAccessibleDescription("It can't be undone.");
    expect(document.activeElement).toBe(screen.getByRole("button", { name: "Cancel" }));
    expect(screen.getByRole("button", { name: "Delete" })).toHaveClass("danger");
    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));
    act(() => { void confirmAction({ title: "Put the original text back?", confirm: "Restore" }); });
    expect(screen.getByRole("button", { name: "Restore" })).toHaveClass("primary");
    expect(screen.getByRole("button", { name: "Restore" })).not.toHaveClass("danger");
  });

  it("keeps Tab inside, and Enter answers Cancel instead of pressing the opener again", async () => {
    const asked = { count: 0 };
    const answers: boolean[] = [];
    render(<><ConfirmHost /><Opener asked={asked} answers={answers} /><a href="#behind">Behind</a></>);
    const opener = screen.getByRole("button", { name: "Delete" });
    opener.focus();
    fireEvent.click(opener);
    const dialog = screen.getByRole("alertdialog");
    const cancel = within(dialog).getByRole("button", { name: "Cancel" });
    const yes = within(dialog).getByRole("button", { name: "Delete" });
    expect(document.activeElement).toBe(cancel);
    fireEvent.keyDown(document.activeElement!, { key: "Tab" });
    expect(document.activeElement).toBe(yes);
    fireEvent.keyDown(document.activeElement!, { key: "Tab" });
    expect(document.activeElement).toBe(cancel, "never the page behind");
    fireEvent.keyDown(document.activeElement!, { key: "Tab", shiftKey: true });
    expect(document.activeElement).toBe(yes);
    fireEvent.keyDown(document.activeElement!, { key: "Tab", shiftKey: true });
    // Enter presses what has the focus: Cancel, not the opener behind.
    fireEvent.click(document.activeElement!);
    await act(async () => undefined);
    expect(answers).toEqual([false]);
    expect(asked.count).toBe(1);
    expect(screen.queryByRole("alertdialog")).not.toBeInTheDocument();
    expect(document.activeElement).toBe(opener, "the focus goes back to the opener");
  });

  it("answers Escape itself: nothing behind it hears it, and the focus goes back", async () => {
    const asked = { count: 0 };
    const answers: boolean[] = [];
    const behind = vi.fn();
    window.addEventListener("keydown", behind);
    try {
      render(<><ConfirmHost /><Opener asked={asked} answers={answers} /></>);
      const opener = screen.getByRole("button", { name: "Delete" });
      opener.focus();
      fireEvent.click(opener);
      fireEvent.keyDown(document.activeElement!, { key: "Escape" });
      await act(async () => undefined);
      expect(answers).toEqual([false]);
      expect(behind).not.toHaveBeenCalled();
      expect(screen.queryByRole("alertdialog")).not.toBeInTheDocument();
      expect(document.activeElement).toBe(opener);
    } finally {
      window.removeEventListener("keydown", behind);
    }
  });

  it("answers no without a dialog on screen, never the browser's", async () => {
    await expect(confirmAction({ title: "Delete?", confirm: "Delete" })).resolves.toBe(false);
  });

  it("is the only way the app asks: no window.confirm anywhere", () => {
    const src = join(dirname(fileURLToPath(import.meta.url)), "../..");
    const files: string[] = [];
    const walk = (dir: string) => {
      for (const name of readdirSync(dir)) {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) walk(path);
        else if (/\.tsx?$/.test(name) && !/\.test\.tsx?$/.test(name)) files.push(path);
      }
    };
    walk(src);
    // window.confirm(…) and a bare confirm(anything), never a name that only ends in "confirm" (confirmAction, onConfirm).
    const asking = files.filter((f) => /window\.confirm\s*\(|(?<![\w.$])confirm\s*\(/.test(readFileSync(f, "utf8")));
    expect(asking).toEqual([]);
  });
});
