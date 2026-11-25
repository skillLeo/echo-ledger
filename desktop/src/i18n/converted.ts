// The areas already in t() and tc() (desktop Urdu): the raw-text guard (guard.test.ts) refuses any text a person can
// see that isn't given to t() or tc() in these files and folders, so a converted screen stays converted. Paths are
// from src/, a folder ending in "/". Phase 0 converts nothing; each Phase 1 package adds its own.
export const CONVERTED: readonly string[] = [
  // D2: Shared with me, its read-only view and the Request files sheet, with the sharing words they show.
  "v2/shared/",
  "lib/sharing.ts",
];
