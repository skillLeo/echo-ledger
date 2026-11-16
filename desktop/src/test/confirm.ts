// The app's confirm dialog in tests (lib/confirm.ts): every question is answered at once by a mock that receives the
// question as one sentence (its title, then its body), so a test says what was asked and what was answered.
import { vi, type Mock } from "vitest";
import { answerConfirmForTests } from "../lib/confirm";

export let confirmAsked: Mock<(question: string) => boolean | undefined> = vi.fn();

/** Answers every question with the returned mock (no answer set: "no"). */
export function answerConfirm(): Mock<(question: string) => boolean | undefined> {
  const asked = vi.fn<(question: string) => boolean | undefined>();
  confirmAsked = asked;
  answerConfirmForTests((q) => !!asked([q.title, typeof q.body === "string" ? q.body : null].filter(Boolean).join(" ")));
  return asked;
}
