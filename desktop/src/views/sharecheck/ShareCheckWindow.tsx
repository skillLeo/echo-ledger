// The Share Check's own windows (since v1.8.1). The test window is built like every overlay and protected from
// captures: it fills itself with a magenta and black check pattern, the colour the core counts in the capture. The
// control is never protected and is solid green, so the capture must show it. The guided test's card is a protected
// window with one sentence, big enough to read on a phone in the meeting. Each tells the core once it has drawn.
import { useEffect } from "react";
import { api } from "../../lib/ipc";

/** The colours the core counts (Rust `privacy::pixels`: TEST, TEST_DARK, CONTROL). */
export const TEST_COLOUR = "rgb(255, 0, 255)";
export const TEST_DARK = "rgb(0, 0, 0)";
export const CONTROL_COLOUR = "rgb(0, 200, 0)";
export const CARD_TEXT = "If you can see this on your phone, EchoLedger is visible in your share.";

export default function ShareCheckWindow({ kind }: { kind: "test" | "control" | "card" }) {
  useEffect(() => {
    // Two frames: the first paint is on screen once the second is asked for.
    let second = 0;
    const first = window.requestAnimationFrame(() => {
      second = window.requestAnimationFrame(() => void api.shareCheckDrawn().catch(() => undefined));
    });
    return () => {
      window.cancelAnimationFrame(first);
      if (second) window.cancelAnimationFrame(second);
    };
  }, []);
  if (kind === "card") {
    return (
      <div className="check-card" data-testid="check-card">
        <p>{CARD_TEXT}</p>
      </div>
    );
  }
  const style = kind === "test"
    ? { backgroundColor: TEST_DARK, backgroundImage: `conic-gradient(${TEST_COLOUR} 25%, ${TEST_DARK} 0 50%, ${TEST_COLOUR} 0 75%, ${TEST_DARK} 0)`, backgroundSize: "32px 32px" }
    : { backgroundColor: CONTROL_COLOUR };
  return <div className="check-patch-window" data-testid={`check-${kind}`} style={style} aria-hidden="true" />;
}
