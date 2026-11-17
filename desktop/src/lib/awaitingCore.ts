// Commands the interface wraps before the core has them. The list is short-lived by design:
// - ipc.contract.test.ts fails the day the core adds one, so it comes off the list and its arguments are checked;
// - releaseGate.test.ts fails a release build while the list has a command without a declared safe fallback (what the
//   app does without it, and why that is safe), and fails every run once the app's version reaches `removeBy`.

/** A command the core doesn't have yet; `fallback` says why the app is safe without it (none: a release waits for it). */
export type AwaitedCommand = { name: string; fallback?: string };

export const AWAITING_CORE: { removeBy: string; commands: readonly AwaitedCommand[] } = {
  /** The app version by which the list must be empty. */
  removeBy: "2.0.0",
  /** Core batch 4 (the checks' advisory rows are a field of ready_check, not a command). */
  commands: [
    {
      name: "live_translate_status",
      fallback: "Without it the Live tab works the answer out from the system and the plan, and shows Live Translate as unavailable where it can't run.",
    },
    {
      name: "diagnostic_report_copy",
      fallback: "Nothing in the app calls it yet: Setup & health shows no Copy the report button until the core has it.",
    },
  ],
};

/** The names on the list. */
export const awaitedNames = (list: readonly AwaitedCommand[] = AWAITING_CORE.commands): string[] => list.map((c) => c.name);

/** "1.10.2" ≥ "1.9.0", by number. */
export function versionAtLeast(version: string, floor: string): boolean {
  const a = version.split(/[.+-]/).slice(0, 3).map((n) => Number.parseInt(n, 10) || 0);
  const b = floor.split(".").map((n) => Number.parseInt(n, 10) || 0);
  for (let i = 0; i < 3; i++) if ((a[i] ?? 0) !== (b[i] ?? 0)) return (a[i] ?? 0) > (b[i] ?? 0);
  return true;
}

/** Why a build can't ship as it is (empty: it can). */
export function releaseBlockers({ release, version, awaiting, removeBy = AWAITING_CORE.removeBy }: {
  release: boolean;
  version: string;
  awaiting: readonly AwaitedCommand[];
  removeBy?: string;
}): string[] {
  const out: string[] = [];
  if (awaiting.length === 0) return out;
  if (versionAtLeast(version, removeBy)) {
    out.push(`version ${version} has reached ${removeBy}: the core must have ${awaitedNames(awaiting).join(", ")}, and AWAITING_CORE must be empty`);
  }
  const unsafe = awaiting.filter((c) => !c.fallback?.trim());
  if (release && unsafe.length > 0) out.push(`a release needs ${awaitedNames(unsafe).join(", ")} from the core: no safe fallback is declared`);
  return out;
}
