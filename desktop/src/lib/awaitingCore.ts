// Commands the interface wraps before the core has them. The list is short-lived by design:
// - ipc.contract.test.ts fails the day the core adds one, so it comes off the list and its arguments are checked;
// - releaseGate.test.ts fails a release build while the list has a command without a declared safe fallback (what the
//   app does without it, and why that is safe), and fails every run once the app's version reaches `removeBy`.

/**
 * A command the core doesn't have yet. `fallback` says why the app is safe without it (none: a release waits for it),
 * and `callers` are the only source files (under src/) allowed to call it, so a fallback that rests on who calls the
 * command can be checked: ipc.contract.test.ts fails when it is called anywhere else.
 */
export type AwaitedCommand = { name: string; fallback?: string; callers: readonly string[] };

export const AWAITING_CORE: { removeBy: string; commands: readonly AwaitedCommand[] } = {
  /** The app version by which the list must be empty. */
  removeBy: "2.0.0",
  /** Core batches 3 and 4 brought live_translate_status and diagnostic_report_copy. A command the interface
   * wraps before the core has it goes here, with its fallback and callers. */
  commands: [
    {
      name: "ui_design_set",
      fallback: "Without it the design is chosen on this computer only (v2/flags.ts): classic stays the default, the new design opt-in.",
      callers: ["lib/ipc.ts", "v2/flags.ts"],
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
