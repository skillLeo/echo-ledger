// "Are you sure?" in the app's own dialog (UI sweep rule 2): never the browser's window.confirm, whose look and title
// are the webview's. `confirmAction` asks; the one <ConfirmHost /> of the window (views/main/ConfirmHost.tsx) shows
// the question and answers it.
import type { ReactNode } from "react";

export interface ConfirmQuestion {
  /** The question, short ("Delete this recording?"). */
  title: string;
  /** What happens, in one or two sentences. */
  body?: ReactNode;
  /** The button that does it ("Delete", "Sign out"). */
  confirm: string;
  cancel?: string;
  /** "danger" (red) only for what deletes, discards or signs out; "primary" otherwise (the default). */
  tone?: "danger" | "primary";
}

export type PendingQuestion = ConfirmQuestion & { answer: (yes: boolean) => void };
type Pending = PendingQuestion;

let showing: ((question: Pending | null) => void) | null = null;
let current: Pending | null = null;
let fakeAnswer: ((question: ConfirmQuestion) => boolean) | null = null;

/** Asks in the window's confirm dialog; true when the person confirms. Without a dialog on screen it does nothing. */
export function confirmAction(question: ConfirmQuestion): Promise<boolean> {
  if (fakeAnswer) return Promise.resolve(fakeAnswer(question));
  return new Promise((resolve) => {
    // A second question answers the first as "no" (one dialog at a time).
    current?.answer(false);
    if (!showing) {
      resolve(false);
      return;
    }
    const pending: Pending = {
      ...question,
      answer: (yes) => {
        if (current === pending) {
          current = null;
          showing?.(null);
        }
        resolve(yes);
      },
    };
    current = pending;
    showing(pending);
  });
}

/** Whether a question is on screen (a sheet behind it leaves its keys alone meanwhile). */
export function isConfirmOpen(): boolean {
  return current !== null;
}

/** Tests answer every question at once (and see what was asked); null puts the dialog back. */
export function answerConfirmForTests(answer: ((question: ConfirmQuestion) => boolean) | null): void {
  fakeAnswer = answer;
}

/** The host shows each question (null: none); returns how to stop. */
export function hostConfirm(show: (question: PendingQuestion | null) => void): () => void {
  showing = show;
  return () => {
    if (showing === show) showing = null;
    current?.answer(false);
  };
}
