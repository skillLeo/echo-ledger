import { Menu, type MenuItem } from "./Menu";
import { SortIcon } from "./icons";

export type SortGroupOption<V extends string = string> = { value: V; label: string };
export type Density = "comfortable" | "compact";

export type SortGroupValue<S extends string = string, G extends string = string> = { sort: S; group: G; density: Density };

export type SortGroupOptions<S extends string = string, G extends string = string> = {
  sort: readonly SortGroupOption<S>[];
  group: readonly SortGroupOption<G>[];
  density: readonly SortGroupOption<Density>[];
};

export type SortKey = "newest" | "oldest" | "longest" | "name";
export type GroupKey = "date" | "source" | "project" | "none";

/** The Library's choices; a screen passes its own lists to add or leave out a choice. */
export const SORT_GROUP_OPTIONS: SortGroupOptions<SortKey, GroupKey> = {
  sort: [
    { value: "newest", label: "Newest" },
    { value: "oldest", label: "Oldest" },
    { value: "longest", label: "Longest" },
    { value: "name", label: "Name A–Z" },
  ],
  group: [
    { value: "date", label: "Date" },
    { value: "source", label: "Source" },
    { value: "project", label: "Project" },
    { value: "none", label: "None" },
  ],
  density: [
    { value: "comfortable", label: "Comfortable" },
    { value: "compact", label: "Compact" },
  ],
};

export type SortGroupWords = { label: string; sort: string; group: string; density: string };

const WORDS: SortGroupWords = { label: "Sort and group", sort: "Sort", group: "Group by", density: "Density" };

/**
 * The button on the search field's end side that orders and groups a list: one menu with three sets of choices (Sort,
 * Group by, Density), each a radio set under its header. It is controlled: every pick calls onChange with the whole
 * new value, and the menu stays open so a person can change more than one set in a row.
 */
export function SortGroupMenu<S extends string, G extends string>({ value, onChange, options, words = {}, disabled }: {
  value: SortGroupValue<S, G>;
  onChange: (next: SortGroupValue<S, G>) => void;
  options: SortGroupOptions<S, G>;
  words?: Partial<SortGroupWords>;
  disabled?: boolean;
}) {
  const w = { ...WORDS, ...words };
  const set = <K extends keyof SortGroupValue<S, G>>(k: K, list: readonly SortGroupOption<SortGroupValue<S, G>[K]>[]): MenuItem[] =>
    list.map((o) => ({
      label: o.label,
      group: w[k],
      radio: true,
      checked: value[k] === o.value,
      keepOpen: true,
      onPick: () => { if (value[k] !== o.value) onChange({ ...value, [k]: o.value }); },
    }));
  const items = [...set("sort", options.sort), ...set("group", options.group), ...set("density", options.density)];
  return <Menu label={w.label} items={items} button={{ variant: "quiet", icon: SortIcon, title: w.label, disabled, className: "el-sortgroup" }} />;
}
