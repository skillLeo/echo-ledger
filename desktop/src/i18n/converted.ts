// The areas already in t() and tc() (desktop Urdu): the raw-text guard (guard.test.ts) refuses any text a person can
// see that isn't given to t() or tc() in these files and folders, so a converted screen stays converted. Paths are
// from src/, a folder ending in "/". Each Phase 1 package adds its own.
export const CONVERTED: readonly string[] = [
  // D1: the Library (the list, its rows and table, the sidebar's views and foot, Get started), its search and the
  // transcript's Find and tools, and Trash.
  "v2/library/LibraryV2.tsx",
  "v2/library/LibraryNav.tsx",
  "v2/library/LibraryTop.tsx",
  "v2/library/LibraryTable.tsx",
  "v2/library/RecordingRow.tsx",
  "v2/library/SourceAvatar.tsx",
  "v2/library/TranscriptToolsV2.tsx",
  "v2/library/model.ts",
  "v2/library/scope.ts",
  "v2/library/syncClock.ts",
];
