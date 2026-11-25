// Desktop-only Urdu (desktop Urdu): the catalogue's keys whose Urdu here deliberately differs from the web's
// lang/ur.json, each with its reason (a word that reads differently in the app's place, a shorter line for a narrow
// control). i18n.test.tsx lets exactly these differ from the web's; scripts/i18nExtract.mjs --seed=web keeps them.
export const DESKTOP_OVERRIDES: Readonly<Record<string, string>> = {};
