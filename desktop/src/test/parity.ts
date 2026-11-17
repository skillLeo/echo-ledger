// Parity between today's page and its v2 page (render-and-collect): render today's page in each of its states, collect
// the accessible name of every control it shows, and map each one to where the new page has it. A control added to
// today's page fails the test until it is mapped; a mapping today's page no longer has fails it too.
import { screen, within } from "@testing-library/react";

export const CONTROL_ROLES = ["button", "link", "checkbox", "switch", "combobox", "textbox", "searchbox", "radio", "menuitem", "tab", "slider", "spinbutton"] as const;
export type ControlRole = (typeof CONTROL_ROLES)[number];

/** Adds "role: name" for every control on the page (or inside `root`). */
export function collectControls(into: Set<string>, root?: HTMLElement): Set<string> {
  const q = root ? within(root) : screen;
  for (const role of CONTROL_ROLES) q.queryAllByRole(role, { name: (n) => (into.add(`${role}: ${n}`), true) });
  return into;
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
