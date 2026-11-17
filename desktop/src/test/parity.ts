// Parity between today's page and its v2 page (render-and-collect): render today's page in each of its states, collect
// the accessible name of every control it shows, and map each one to where the new page has it. A control added to
// today's page fails the test until it is mapped; a mapping today's page no longer has fails it too.
import { screen, within } from "@testing-library/react";

export const CONTROL_ROLES = ["button", "link", "checkbox", "switch", "combobox", "textbox", "searchbox", "radio", "menuitem", "tab", "slider", "spinbutton"] as const;
export type ControlRole = (typeof CONTROL_ROLES)[number];

/** Fields with no role (a password, a time, a date, a colour, a file) are collected as "<type>: <their label>". */
const ROLELESS = ["password", "time", "date", "datetime-local", "color", "file"] as const;
export type RolelessField = (typeof ROLELESS)[number];
const ROLELESS_SELECTOR = ROLELESS.map((t) => `input[type="${t}"]`).join(", ");
const fieldName = (el: HTMLInputElement): string =>
  el.getAttribute("aria-label") ?? (el.labels?.[0]?.textContent ?? "").trim();

/** Adds "role: name" for every control on the page (or inside `root`). */
export function collectControls(into: Set<string>, root?: HTMLElement): Set<string> {
  const q = root ? within(root) : screen;
  for (const role of CONTROL_ROLES) q.queryAllByRole(role, { name: (n) => (into.add(`${role}: ${n}`), true) });
  for (const el of (root ?? document.body).querySelectorAll<HTMLInputElement>(ROLELESS_SELECTOR)) into.add(`${el.type}: ${fieldName(el)}`);
  return into;
}

/** The controls with this role and name on the page (a field with no role is found by its type and label). */
export function findControls(role: ControlRole | RolelessField, name: string | RegExp): HTMLElement[] {
  if ((ROLELESS as readonly string[]).includes(role)) {
    return [...document.querySelectorAll<HTMLInputElement>(`input[type="${role}"]`)]
      .filter((el) => (typeof name === "string" ? fieldName(el) === name : name.test(fieldName(el))));
  }
  return screen.queryAllByRole(role as ControlRole, { name });
}

const matches = (key: string | RegExp, name: string) => (typeof key === "string" ? key === name : key.test(name));

/** Today's controls with no mapping. */
export function unmapped(today: readonly string[], keys: readonly (string | RegExp)[]): string[] {
  return today.filter((name) => !keys.some((k) => matches(k, name)));
}

/** Mappings that match nothing on today's page. */
export function stale(today: readonly string[], keys: readonly (string | RegExp)[]): string[] {
  return keys.filter((k) => !today.some((name) => matches(k, name))).map(String);
}
