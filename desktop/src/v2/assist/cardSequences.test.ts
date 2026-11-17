// The Live Assist card's shared sequences (docs/v2/assist-card-sequences.json): relay messages in order and the card
// after each one. The phone's reducer runs the same file, so both apps show the same card for the same messages.
import { existsSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import type { AssistMessage } from "./adapter";
import { cardReducer, EMPTY_CARD, shown, type CardState } from "./card";

type Status = "streaming" | "done" | "cancelled" | "superseded" | "failed" | "skipped";
type Expect = {
  shown_id: string | null;
  text: string;
  status: Status | null;
  skipped: boolean;
  /** Suggestions by id, on the card or not. */
  also?: { id: string; text: string; status: Status }[];
};
type Sequence = { name: string; steps: { message: AssistMessage; expect: Expect }[] };

const FILE = join(dirname(fileURLToPath(import.meta.url)), "..", "..", "..", "..", "docs", "v2", "assist-card-sequences.json");
const load = (): Sequence[] => {
  if (!existsSync(FILE)) throw new Error(`The shared card sequences are missing: ${FILE}`);
  return (JSON.parse(readFileSync(FILE, "utf8")) as { sequences: Sequence[] }).sequences;
};

/** The card as the file describes it. */
function cardOf(state: CardState, also: Expect["also"]): Expect {
  const s = shown(state);
  const card: Expect = { shown_id: state.shownId, text: s?.text ?? "", status: s?.status ?? null, skipped: state.skipped };
  if (also) {
    card.also = also.map(({ id }) => {
      const x = state.suggestions.find((y) => y.id === id);
      return { id, text: x?.text ?? "(not on the card's list)", status: x?.status ?? ("(none)" as Status) };
    });
  }
  return card;
}

describe("the shared Live Assist card sequences", () => {
  const sequences = load();

  it("has every sequence", () => {
    expect(sequences.length).toBeGreaterThanOrEqual(16);
    for (const seq of sequences) expect(seq.steps.length, seq.name).toBeGreaterThan(0);
  });

  it.each(sequences.map((s) => [s.name, s] as const))("%s", (_, seq) => {
    let state = EMPTY_CARD;
    seq.steps.forEach((step, i) => {
      state = cardReducer(state, step.message);
      expect(cardOf(state, step.expect.also), `step ${i + 1} (${step.message.type})`).toEqual(step.expect);
    });
  });
});
