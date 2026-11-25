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
  // D2: Shared with me, its read-only view and the Request files sheet, with the sharing words they show.
  "v2/shared/",
  "lib/sharing.ts",
  // and what the new design opens with them: the share sheet (the recording page's Share) and a project's filing rules.
  "views/main/ShareDialog.tsx",
  "v2/workspace/Rules.tsx",
  // D3a: Settings' pages, their rows and frame, the home with the account card, in the new look (the groups' words are
  // translated where they are shown: groups.ts keeps them as English ids; the registry's words are D3b's).
  "v2/settings/AccountCard.tsx",
  "v2/settings/SettingsV2.tsx",
  "v2/settings/accountKit.tsx",
  "v2/settings/initials.ts",
  "v2/settings/palette.ts",
  "v2/settings/pages/AboutV2.tsx",
  "v2/settings/pages/AppV2.tsx",
  "v2/settings/pages/CallsV2.tsx",
  "v2/settings/pages/CaptionsV2.tsx",
  "v2/settings/pages/DevicesV2.tsx",
  "v2/settings/pages/LockV2.tsx",
  "v2/settings/pages/MacCheckV2.tsx",
  "v2/settings/pages/NotificationsV2.tsx",
  "v2/settings/pages/PlanV2.tsx",
  "v2/settings/pages/PrivacyV2.tsx",
  "v2/settings/pages/ProfileV2.tsx",
  "v2/settings/pages/RecordingInputV2.tsx",
  "v2/settings/pages/SecurityV2.tsx",
  "v2/settings/pages/SettingsPagesV2.tsx",
  "v2/settings/pages/SharePrivacyV2.tsx",
  "v2/settings/pages/StartupV2.tsx",
  "v2/settings/pages/StorageV2.tsx",
  "v2/settings/pages/TranscriptionV2.tsx",
  "v2/settings/pages/VoiceV2.tsx",
  "v2/settings/pages/route.ts",
  "v2/settings/pages/rows.tsx",
  // D4: the recording page, its dialogs (rename, transcribe, move, label, change language, Send to WhatsApp; also the
  // Library's), its steps, offers and screens, the player and the copy menu, and the lib words they show (languages,
  // progress, the waiting and upload words). lib/player.ts's hint is playerHint() (its one other word is the Space key's
  // name, Latin in every language) and lib/format.ts's words are sourceAppLabel's and prettyShortcut's: neither file is
  // listed whole.
  "v2/library/RecordingPage.tsx",
  "v2/library/organise.tsx",
  "v2/library/recordingExtras.tsx",
  "v2/library/screens.tsx",
  "v2/components/media.tsx",
  "v2/components/copyMenu.ts",
  "lib/languages.ts",
  "lib/progress.ts",
  "lib/pending.ts",
  // D1b: the Library's leftovers: the filter panel and its pills, the filters' and the facets' names, labels and their
  // dialogs, My views' messages, the queue at the top of the list (waiting calls, imports, uploads) and the import sheet.
  "v2/library/FilterPanel.tsx",
  "v2/library/filters.ts",
  "v2/library/facets.ts",
  "v2/library/lists.tsx",
  "v2/library/myViews.ts",
  "v2/library/QueueV2.tsx",
  "v2/library/ImportSheetV2.tsx",
  "v2/library/fitRow.ts",
];
