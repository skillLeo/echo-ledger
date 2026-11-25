// Words whose meaning depends on where they stand (desktop Urdu): ur.json is one list for the whole app, keyed by the
// English, so a word that means something else in one place gets a key of its own, "<area>_context.<name>", as the
// web's lang/{en,ur}/<area>_context.php (LEAD DECISIONS 22). This is the English of each such key (what t() shows in
// English, as before); ur.json has its Urdu under the same key. i18n.test.tsx checks the two match.
export const CONTEXT_EN: Readonly<Record<string, string>> = {
  // A saved view's name while My views are still being read (ur.json's "View" is the layout: انداز).
  "library_context.saved_view": "View",
  // The filter panel's Custom dates, the first day and the last (ur.json's "From" is a sender's, ماخذ): سے and تک.
  "library_context.date_start": "From",
  "library_context.date_end": "To",
  // The filter panel's own dates (Custom: the two days chosen); a bare "Custom" means other things elsewhere.
  "library_context.date_custom": "Custom",
  // Between a filter pill's values ("Source: WhatsApp, Zoom"): Urdu's comma (،) in Urdu.
  "library_context.values_separator": ", ",
  // A filter section's dot while it is on (a filter applied, لگا ہے; ur.json's "On" is a switch's, آن).
  "library_context.filter_on": "on",
  // The word Upgrade inside a Plan & usage meter's line ("… of your minutes. Upgrade."): in Urdu the noun alone (اپ
  // گریڈ), the sentence says کریں after it; the button Upgrade is the verb (اپ گریڈ کریں).
  "plan_context.upgrade": "Upgrade",
  // Quiet hours' two times, a row each under Quiet hours (ur.json's "From" is a sender's, ماخذ).
  "settings_context.quiet_from": "From",
  "settings_context.quiet_to": "To",
  // The Mac menu bar's Edit, View and Find (Edit › Find) menus (D-248): menus' names, not the buttons' verbs (ur.json's
  // "Edit" is ترمیم کریں, "Find" تلاش کریں) nor the layout (انداز).
  "actions_context.menu_edit": "Edit",
  "actions_context.menu_view": "View",
  "actions_context.menu_find": "Find",
  // The window's Zoom (Window › Zoom) and the avatar's: the size, not the meeting app (ur.json's "Zoom" is the brand).
  "actions_context.zoom": "Zoom",
};
