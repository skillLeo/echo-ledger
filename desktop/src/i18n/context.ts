// Words whose meaning depends on where they stand (desktop Urdu): ur.json is one list for the whole app, keyed by the
// English, so a word that means something else in one place gets a key of its own, "<area>_context.<name>", as the
// web's lang/{en,ur}/<area>_context.php (LEAD DECISIONS 22). This is the English of each such key (what t() shows in
// English, as before); ur.json has its Urdu under the same key. i18n.test.tsx checks the two match.
export const CONTEXT_EN: Readonly<Record<string, string>> = {
  // A saved view's name while My views are still being read (ur.json's "View" is the layout: انداز).
  "library_context.saved_view": "View",
};
