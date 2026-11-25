// Desktop-only Urdu (desktop Urdu): the catalogue's keys whose Urdu here deliberately differs from the web's
// lang/ur.json, each with its reason (a word that reads differently in the app's place, a shorter line for a narrow
// control, a term decided before the web has it). i18n.test.tsx lets exactly these differ from the web's, and fails
// once one no longer does (drop it then); scripts/i18nExtract.mjs --seed=web keeps them.
const TERMS = "EL-UX's term (2026-11-25), until the web's lang/ur.json has it (fix/W-urdu-terms)";
export const DESKTOP_OVERRIDES: Readonly<Record<string, string>> = {
  "Empty trash": `${TERMS}: Trash is ردی`,
  Organise: `${TERMS}: Organise is منظم کریں`,
  "Sort & display": `${TERMS}: layout is انداز`,
  View: `${TERMS}: View is the layout, انداز (a saved view is library_context.saved_view, ویو)`,
};
