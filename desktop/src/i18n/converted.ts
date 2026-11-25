// The areas already in t() and tc() (desktop Urdu): the raw-text guard (guard.test.ts) refuses any text a person can
// see that isn't given to t() or tc() in these files and folders, so a converted screen stays converted. Paths are
// from src/, a folder ending in "/". Phase 0 converts nothing; each Phase 1 package adds its own.
export const CONVERTED: readonly string[] = [];
