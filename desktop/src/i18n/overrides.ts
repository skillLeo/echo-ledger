// Desktop-only Urdu (desktop Urdu): the catalogue's keys whose Urdu here deliberately differs from the web's
// lang/ur.json, each with its reason (a word that reads differently in the app's place, a shorter line for a narrow
// control, a term decided before the web has it). i18n.test.tsx lets exactly these differ from the web's, and fails
// once one no longer does (drop it then); scripts/i18nExtract.mjs --seed=web keeps them. EL-UX's terms (Trash ردی,
// Organise منظم کریں, layout انداز) are the web's since fix/W-urdu-terms.
export const DESKTOP_OVERRIDES: Readonly<Record<string, string>> = {
  "You'll be signed out on every phone, computer and browser, including this one.":
    "This computer, not this browser: in the app \"this one\" is the computer it runs on (اس کمپیوٹر سے بھی), where the web's line says this browser (اس براؤزر سے بھی).",
  ":count recordings":
    "Two forms: the account card's count of recordings (one English form, given to tc() alone) says ریکارڈنگ for 1, where the web's line has the plural only.",
  // Decision 26: Import is امپورٹ on every platform (the phone's word); the web's terms-6 brings the same, and these two
  // go once it is on v1.1.
  "Import": "A term decided before the web has it (decision 26): امپورٹ, the import sheet's word on the desktop and the phone; the web still says درآمد until terms-6.",
  "Imported": "A term decided before the web has it (decision 26): امپورٹ شدہ, as Import; the web still says درآمد شدہ until terms-6.",
};
