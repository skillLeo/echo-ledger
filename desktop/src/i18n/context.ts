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
  // Between the parts of a name read out (D-253: the rail's "Activity, 3, something failed", the account's, a locked
  // command's ", Live Translate is in Pro"): Urdu's comma (،) in Urdu.
  "shell_context.separator": ", ",
  // Live's dot in the rail, said after its name: a call being recorded, or captions or dictation running (ur.json's
  // "recording" is the noun, ریکارڈنگ; "On" a switch's).
  "shell_context.live_recording": "recording",
  "shell_context.live_on": "on",
  // Pages in the window's search whose name reads otherwise in the catalogue (D-253): Upload opens the file picker
  // (ur.json's "Upload" is the verb), the Library's Waiting and No project places (a state, and an empty project field).
  "palette_context.upload": "Upload",
  "palette_context.waiting": "Waiting",
  "palette_context.no_project": "No project",
  // The new design's Record command: the verb (ریکارڈ کریں), where the rail's Record is the place (ur.json's "Record",
  // ریکارڈ).
  "palette_context.record": "Record",
  // D-261: the Record page's heading, Home in English, named in Urdu as the Record menu and the window's search name the
  // page (ریکارڈ کا صفحہ); and its and Live's Record button, the verb (ریکارڈ کریں), where the rail's Record is the place.
  "home_context.title": "Home",
  "home_context.record": "Record",
  // A project's page (D-261): a memory line's kind Change is a change that was made (تبدیلی), not the verb (ur.json's
  // "Change", بدلیں); a file's kind, Word (the format's name, as it is) and Text (a plain text file, ٹیکسٹ); and between
  // the ways a person can open the project ("Sent to them …; Through a share link"): Urdu's semicolon (؛) in Urdu.
  "workspace_context.memory_change": "Change",
  "workspace_context.kind_word": "Word",
  "workspace_context.kind_text": "Text",
  "workspace_context.ways_separator": "; ",
  // A call note's change (D-261, NotesSections): what was added, removed or changed, nouns (اضافہ, حذف, ترمیم), not the
  // buttons' verbs (ur.json's "Add" شامل کریں, "Remove" ہٹائیں, "Change" بدلیں).
  "notes_context.change_add": "Add",
  "notes_context.change_remove": "Remove",
  "notes_context.change_modify": "Change",
};
