import { describe, expect, it } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
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
    const dialog = screen.getByRole("dialog", { name: "Delete this recording?" });
    expect(dialog).toHaveTextContent("It can't be undone.");
    fireEvent.click(screen.getByRole("button", { name: "Delete" }));
    await expect(answer).resolves.toBe(true);
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    act(() => { answer = confirmAction({ title: "Sign out?", confirm: "Sign out" }); });
    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));
    await expect(answer).resolves.toBe(false);
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
    const asking = files.filter((f) => /window\.confirm\s*\(|\bconfirm\s*\(\s*["`]/.test(readFileSync(f, "utf8")));
    expect(asking).toEqual([]);
  });
});
