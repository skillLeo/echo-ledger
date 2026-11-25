import type { MenuItem } from "./Menu";
import { ClockIcon, CopyIcon, SparkleIcon, TranscriptIcon } from "./icons";
import { t } from "../../i18n";

export type CopyHandlers = {
  /** The transcript as it reads on screen. */
  text?: () => void;
  withTimestamps?: () => void;
  withoutTimestamps?: () => void;
  /** The transcript with its title, people and date, ready to paste into an assistant. */
  forAi?: () => void;
};

export type CopyWords = { text: string; withTimestamps: string; withoutTimestamps: string; forAi: string };

/** The items' words, in the window's language whenever they are read. */
export const COPY_WORDS: CopyWords = {
  get text() {
    return t("Copy text");
  },
  get withTimestamps() {
    return t("Copy with timestamps");
  },
  get withoutTimestamps() {
    return t("Copy without timestamps");
  },
  get forAi() {
    return t("Copy for AI");
  },
};

/**
 * The Copy menu's items, each with its icon, in the same order everywhere (a recording's ⋮, the selection bar, the
 * transcript's own menu). An action with no handler is left out, so a page offers only what it can do. `group` puts
 * them under one header when they share a menu with other actions.
 */
export function copyMenuItems(on: CopyHandlers, words: Partial<CopyWords> = {}, group?: string): MenuItem[] {
  const w = { ...COPY_WORDS, ...words };
  const all: [keyof CopyHandlers, MenuItem["icon"]][] = [
    ["text", CopyIcon],
    ["withTimestamps", ClockIcon],
    ["withoutTimestamps", TranscriptIcon],
    ["forAi", SparkleIcon],
  ];
  return all.flatMap(([k, icon]) => (on[k] ? [{ label: w[k], icon, onPick: on[k], ...(group ? { group } : {}) }] : []));
}
