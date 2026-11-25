// The words in a source file, found with TypeScript's own parser (desktop Urdu): what scripts/i18nExtract.mjs counts
// and what the raw-text guard (guard.test.ts) refuses in an area already converted. Never part of the app: only the
// script and the tests import it (it needs the compiler).
//   - text between JSX tags;
//   - the props that are words (aria-label, title, placeholder, label, meta, hint, body…), and any other string prop that
//     reads as words (value="Not connected"), except the few that never are (NOT_WORD_PROPS: className, id, role…);
//   - other string and template literals that read as words ("Couldn't sync", `Clear ${name}`), not ids, keys, class
//     names, URLs, event names or comparisons (e.key === "Escape").
// Text given to t() or tc() (or to a name they were given: `const { t: say } = useT()`, `import { t as say }`) is
// "translated"; anything else "raw". A template's values become Laravel's placeholders
// (`Clear ${name}` → "Clear :name"), the catalogue's keys.
import ts from "typescript";

export type FoundText = { line: number; kind: "jsx text" | "prop" | "literal"; text: string; translated: boolean };

/** Props whose value is words. */
export const WORD_PROPS = new Set([
  "aria-label", "aria-description", "aria-valuetext", "aria-roledescription", "title", "placeholder", "alt", "label", "meta", "hint",
  "body", "heading", "description", "subtitle", "caption", "footer", "text", "message", "tooltip", "confirmLabel", "cancelLabel",
  "emptyText", "detail", "summary", "note", "help", "words", "action", "actionLabel", "okLabel", "loadingLabel", "doneLabel",
]);
/**
 * String props that are never words: classes, ids, roles, types, links, keys, a variant's, size's, tone's or icon's
 * name, an anchor, SVG path data, a font, key names; any data-* and any …Class or …ClassName.
 */
export const NOT_WORD_PROPS = new Set([
  "className", "id", "role", "type", "href", "src", "key", "anchor", "variant", "size", "tone", "icon", "d", "fontFamily",
  "aria-keyshortcuts", "keyshortcuts",
]);
const notWordProp = (name: string) => NOT_WORD_PROPS.has(name) || name.startsWith("data-") || /Class(Name)?$/.test(name);
/** Object properties that are never words, beside NOT_WORD_PROPS: a menu item's shortcut ("Del", "X"), its keys. */
const NOT_WORD_FIELDS = new Set([...NOT_WORD_PROPS, "shortcut", "keys"]);
/** The calls that translate: t("…"), tc("…|…", n) and a hook's own (useT().t). */
const TRANSLATE_CALLS = new Set(["t", "tc"]);
/** Calls whose string arguments are never shown: ids, selectors, events, storage, the core's commands. */
const NOT_SHOWN_CALLS = /^(invoke|querySelector|querySelectorAll|getElementById|closest|matches|addEventListener|removeEventListener|dispatchEvent|getItem|setItem|removeItem|getAttribute|setAttribute|removeAttribute|hasAttribute|toggleAttribute|getPropertyValue|setProperty|respond|fail|respondWith|emit|listen|once|registerAction|runAction|shortcutOf|setting|require|import|startsWith|endsWith|includes|split|join|replace|replaceAll|padStart|toLocaleString|toLocaleDateString|toLocaleTimeString|DateTimeFormat|NumberFormat|RegExp|Event|CustomEvent|KeyboardEvent|MouseEvent|log|warn|error|info|debug|trace|has|get|set|delete|test|exec|match|localeCompare|createElement|getContext|measureText|mark|measure|keyMatches|matchesShortcut|track|flag|useFlag|isOn)$/;

/** Text that reads as words: letters, and a capital, a space, an apostrophe or an ellipsis; not an id, a key or a class. */
export function wordy(text: string): boolean {
  const t = text.trim();
  if (!/[A-Za-z]/.test(t) || t.length < 2) return false;
  if (/^(https?:|mailto:|data:|\/|\.\/|#|--|@)/.test(t)) return false;
  if (/^[a-z0-9_.:\-/[\]=]+$/.test(t)) return false; // ids, keys, class names, paths, events
  if (/^[a-z][a-zA-Z0-9]*$/.test(t)) return false; // camelCase keys
  if (/^[A-Z0-9_]+$/.test(t) && t.length > 3) return false; // CONSTANTS
  // One PascalCase compound with no space (ArrowLeft, PageDown, WhatsApp): a key's or a type's name, or a brand's,
  // never words to translate.
  if (/^[A-Z][a-z0-9]+([A-Z][a-z0-9]*)+$/.test(t)) return false;
  // A key with its namespace ("date:This week", "project:No project"): a group's or a setting's id.
  if (/^[a-z][a-z0-9_]*:\S/.test(t)) return false;
  // A key chord or its start (Ctrl+, Alt+:value, Shift+Del): keys keep their Latin names in every language.
  if (/^(Ctrl|Alt|Shift|Meta|Cmd|Option|Fn)\+/.test(t)) return false;
  if (/[{};]|\bpx\b|var\(--|^\s*[.#][a-z]/.test(t) && !/\s[a-z]+\s/.test(t)) return false; // CSS, selectors
  if (/^(el-|is-|has-|data-|aria-)/.test(t)) return false;
  if (/^[\w-]+(\s+[\w-]+)*$/.test(t) && /(^|\s)el-/.test(t)) return false; // class lists
  return /^[A-Z]/.test(t) || /\s/.test(t) || /'/.test(t) || /…$/.test(t);
}

/** A template literal as a catalogue key: `Clear ${name}` → "Clear :name" (a value with no simple name: ":value"). */
export function templateKey(node: ts.TemplateExpression | ts.NoSubstitutionTemplateLiteral): string {
  if (ts.isNoSubstitutionTemplateLiteral(node)) return node.text;
  let out = node.head.text;
  for (const span of node.templateSpans) {
    const e = span.expression;
    const name = ts.isIdentifier(e) ? e.text : ts.isPropertyAccessExpression(e) ? e.name.text : "value";
    out += `:${name}${span.literal.text}`;
  }
  return out;
}

/** A template's key without its values (":done · :value" → " · "). */
const withoutValues = (key: string) => key.replace(/:[A-Za-z_]\w*/g, "");
/** Whether a template's key has words: more than one-letter units beside its values, and wordy with each value a word. */
const hasWords = (key: string) => (withoutValues(key).match(/[A-Za-z]+/g) ?? []).some((w) => w.length > 1) && wordy(key.replace(/:[A-Za-z_]\w*/g, "x"));

const calleeName = (call: ts.CallExpression | ts.NewExpression): string => {
  const callee = call.expression;
  return ts.isIdentifier(callee) ? callee.text : ts.isPropertyAccessExpression(callee) ? callee.name.text : "";
};
/** Whether the string is the text given to t() or tc() (their first argument), under any of `names`. */
const translatedBy = (node: ts.Node, names: ReadonlySet<string>): boolean => {
  const parent = node.parent;
  return !!parent && ts.isCallExpression(parent) && parent.arguments[0] === node && names.has(calleeName(parent));
};

/** The names t and tc go by in a file: their own, and any they are given (`{ t: say } = useT()`, `{ t as say }`). */
function translatorsIn(sf: ts.SourceFile): Set<string> {
  const names = new Set(TRANSLATE_CALLS);
  const visit = (node: ts.Node): void => {
    if (ts.isBindingElement(node) && node.propertyName && ts.isIdentifier(node.propertyName) && ts.isIdentifier(node.name) && TRANSLATE_CALLS.has(node.propertyName.text)) names.add(node.name.text);
    if (ts.isImportSpecifier(node) && node.propertyName && TRANSLATE_CALLS.has(node.propertyName.text)) names.add(node.name.text);
    ts.forEachChild(node, visit);
  };
  visit(sf);
  return names;
}

/** Whether a literal sits in the value of a prop that is never words (className={on ? "a b" : "c"}), not deeper in JSX. */
function inNotWordProp(node: ts.Node, sf: ts.SourceFile): boolean {
  for (let at = node.parent; at; at = at.parent) {
    if (ts.isJsxAttribute(at)) return notWordProp(at.name.getText(sf));
    if (ts.isJsxElement(at) || ts.isJsxSelfClosingElement(at) || ts.isJsxFragment(at) || ts.isFunctionLike(at) || ts.isSourceFile(at)) return false;
  }
  return false;
}

/** Every piece of text a person can see in one .ts or .tsx file. */
export function textsIn(path: string, code: string): FoundText[] {
  const sf = ts.createSourceFile(path, code, ts.ScriptTarget.Latest, true, path.endsWith("x") ? ts.ScriptKind.TSX : ts.ScriptKind.TS);
  const found: FoundText[] = [];
  const translators = translatorsIn(sf);
  const add = (node: ts.Node, kind: FoundText["kind"], text: string, translated = false) => {
    const t = text.replace(/\s+/g, " ").trim();
    if (t) found.push({ line: sf.getLineAndCharacterOfPosition(node.getStart(sf)).line + 1, kind, text: t, translated });
  };
  const visit = (node: ts.Node): void => {
    if (ts.isImportDeclaration(node) || ts.isExportDeclaration(node) || (ts.isTypeNode(node) && !ts.isTypeLiteralNode(node))) return;
    if (ts.isJsxText(node)) {
      if (/[A-Za-z]/.test(node.text)) add(node, "jsx text", node.text);
      return;
    }
    if (ts.isJsxAttribute(node)) {
      const name = node.name.getText(sf);
      const init = node.initializer;
      if (!init) return;
      if (WORD_PROPS.has(name)) {
        if (ts.isStringLiteral(init)) {
          if (/[A-Za-z]/.test(init.text)) add(init, "prop", init.text);
          return;
        }
        const e = ts.isJsxExpression(init) ? init.expression : undefined;
        if (e && (ts.isTemplateExpression(e) || ts.isNoSubstitutionTemplateLiteral(e))) {
          // Only values and punctuation (aria-label={`, ${count}`}) has no words to translate.
          if (/[A-Za-z]/.test(withoutValues(templateKey(e)))) add(e, "prop", templateKey(e));
          return;
        }
      } else if (ts.isStringLiteral(init)) {
        // Any other prop that reads as words (value="Not connected"), unless it is one that never is.
        if (!notWordProp(name) && wordy(init.text)) add(init, "prop", init.text);
        return;
      }
    }
    if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node) || ts.isTemplateExpression(node)) {
      const text = ts.isTemplateExpression(node) ? templateKey(node) : node.text;
      if (translatedBy(node, translators)) {
        add(node, "literal", text, true);
        if (ts.isTemplateExpression(node)) ts.forEachChild(node, visit);
        return;
      }
      const parent = node.parent;
      // Names, not text: an object's key, an index, a case, an equality test, a call that takes ids, a field that is
      // never words ({ shortcut: "Del" }).
      if ((ts.isPropertyAssignment(parent) && parent.name === node) || ts.isElementAccessExpression(parent) || ts.isCaseClause(parent)) return;
      if (ts.isPropertyAssignment(parent) && parent.initializer === node && NOT_WORD_FIELDS.has(parent.name.getText(sf).replace(/^["']|["']$/g, ""))) return;
      if (ts.isBinaryExpression(parent) && [ts.SyntaxKind.EqualsEqualsEqualsToken, ts.SyntaxKind.ExclamationEqualsEqualsToken, ts.SyntaxKind.EqualsEqualsToken, ts.SyntaxKind.ExclamationEqualsToken, ts.SyntaxKind.InKeyword].includes(parent.operatorToken.kind)) return;
      if ((ts.isCallExpression(parent) || ts.isNewExpression(parent)) && NOT_SHOWN_CALLS.test(calleeName(parent))) return;
      if (ts.isJsxAttribute(parent) || inNotWordProp(node, sf)) return;
      // A template of values and punctuation (`${done} · ${count}`) or of values with one-letter units (`${m}m ${s}s`)
      // has no words; any other is read with each value as a word ("x back").
      if (ts.isTemplateExpression(node) ? hasWords(text) : wordy(text)) add(node, "literal", text);
      if (ts.isTemplateExpression(node)) ts.forEachChild(node, visit);
      return;
    }
    ts.forEachChild(node, visit);
  };
  visit(sf);
  return found;
}
