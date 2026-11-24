// A screen's part of the one code path (UX-21, UX-22): what it does for actions.json's ids, for its kind of thing (a
// view, a label…), registered while it is mounted. The newest functions run, so they always see the screen's state.
import { useEffect, useRef } from "react";
import { registerAction, type ActionContext } from "./registry";

export function useActions(kind: string, runs: Readonly<Record<string, ((ctx: ActionContext) => void) | undefined>>): void {
  const latest = useRef(runs);
  latest.current = runs;
  const ids = Object.keys(runs).filter((id) => runs[id]).join(" ");
  useEffect(() => {
    const offs = ids.split(" ").filter(Boolean).map((id) => registerAction(id, (ctx) => latest.current[id]?.(ctx), kind));
    return () => offs.forEach((off) => off());
  }, [ids, kind]);
}
