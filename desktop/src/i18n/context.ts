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
  // A filter section's dot while it is on (a filter applied, لگا ہے; ur.json's "On" is a switch's, آن).
  "library_context.filter_on": "on",
  // The word Upgrade inside a Plan & usage meter's line ("… of your minutes. Upgrade."): in Urdu the noun alone (اپ
  // گریڈ), the sentence says کریں after it; the button Upgrade is the verb (اپ گریڈ کریں).
  "plan_context.upgrade": "Upgrade",
  // Quiet hours' two times, a row each under Quiet hours (ur.json's "From" is a sender's, ماخذ).
  "settings_context.quiet_from": "From",
  "settings_context.quiet_to": "To",
};
