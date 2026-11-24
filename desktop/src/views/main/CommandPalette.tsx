// Ctrl / Cmd + K (plan B9): run an action, go to a page, or jump into a recording found in any transcript. Since v1.6
// (P4.3) it also makes things (Upload, New project, Request link, Invite) and opens every page by name (a project, a
// smart folder, a Settings page such as Devices); since v1.7 it finds every single setting too, in groups: Actions,
// Settings, Pages, Recordings. It is the window's one search: the top bar's field and the sidebar's Search open it.
import { useEffect, useId, useLayoutEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode } from "react";
import { api, type AppStatus, type SearchResult, type SmartFolder } from "../../lib/ipc";
import { timestamp } from "../../lib/format";
import { recordingName } from "../../lib/library";
import { snippetParts } from "../../lib/player";
import type { MainTab } from "../../lib/mainView";
import type { PageEntry } from "../../lib/shell";
import type { NoticeTarget } from "../../lib/notice";
import { featureWords, isLocked, lowestPlan, openUpgradeSheet, useEntitlements } from "../../lib/entitlements";
import { BadgeMark } from "./Upgrade";
import { LucideSearchIcon, LucideXIcon } from "../../components/Icons";
import { ACTIONS, actionLabel, available, hasHandler, runAction, type ActionContext } from "../../v2/actions/registry";

export interface PaletteCommand {
  id: string;
  label: string;
  /** Extra words the command answers to. */
  keywords?: string;
  /** A paid feature (v1.6.2): while the plan leaves it out the row wears its badge and opens the upgrade sheet instead. */
  feature?: string;
  run: () => void;
  /** How the row reads when its words need more than text (a question set apart in a <bdi>); `label` stays its name. */
  view?: ReactNode;
  /** A word after the row's name (a command's tick: On or Off). */
  detail?: string;
}

/** A text's words for matching (pure): lower case, split at whatever is not a letter, a combining mark or a digit. What
 * is typed is split the same way as the names, so "d'souza" finds "D'Souza Traders" and "ali & sons" finds "Ali & Sons";
 * a mark (Urdu's zer or pesh, an accent typed apart) stays inside its word. */
export const wordsOf = (text: string): string[] => text.toLowerCase().split(/[^\p{L}\p{M}\p{N}]+/u).filter(Boolean);

/** The commands and pages that open a place: "Go to Library", "Go to Devices · Account". */
const GO_TO = /^go to /i;

/**
 * How well a row matches what was typed (pure, D-087): 3 its name starts with it, 2 every typed word starts a word of
 * its name, 1 some word only starts a word of its keywords, 0 none (also a word found only inside another one: "ew" is
 * not "new"). Names come first, so "new" and Enter make a new project, never a voice note found by its keyword. Nothing
 * typed matches everything; only punctuation typed matches nothing.
 */
export function paletteScore(query: string, command: Pick<PaletteCommand, "label" | "keywords">): number {
  const typed = wordsOf(query);
  if (typed.length === 0) return query.trim() ? 0 : 1;
  const name = wordsOf(command.label);
  const keys = wordsOf(command.keywords ?? "");
  let byKeyword = false;
  for (const word of typed) {
    if (name.some((n) => n.startsWith(word))) continue;
    if (!keys.some((k) => k.startsWith(word))) return 0;
    byKeyword = true;
  }
  if (byKeyword) return 1;
  // The name starts with what was typed, word by word: the last word typed may be the start of one. "Go to X" is named
  // by X too, so "library" is Go to Library's own name and comes before "Sync the library".
  const last = typed.length - 1;
  const startsWithTyped = (words: string[]) => typed.every((word, i) => (i < last ? words[i] === word : words[i]?.startsWith(word)));
  const place = GO_TO.test(command.label) ? wordsOf(command.label.replace(GO_TO, "")) : null;
  return startsWithTyped(name) || (place !== null && startsWithTyped(place)) ? 3 : 2;
}

/** Whether a row matches what was typed at all ([`paletteScore`] above 0). */
export function paletteMatches(query: string, command: Pick<PaletteCommand, "label" | "keywords">): boolean {
  return paletteScore(query, command) > 0;
}

/** The rows that match, best first, keeping their own order between equals (pure). */
function ranked<T>(rows: readonly T[], score: (row: T) => number): { row: T; score: number }[] {
  return rows.map((row, at) => ({ row, score: score(row), at })).filter((r) => r.score > 0).sort((a, b) => b.score - a.score || a.at - b.at)
    .map(({ row, score: s }) => ({ row, score: s }));
}

/**
 * The new design has no smart folders: each is a filter of All recordings (an app's is its Source, the Inbox is No
 * project), and since v2.1 the palette is a way to it that a row's mark used to be (PLAN_V2_1_POLISH UX-06). Shared with
 * me stays a place ("Go to Shared with me").
 */
const V2_FOLDER_WORDS: Partial<Record<SmartFolder, string>> = {
  inbox: "Show recordings in no project", whatsapp: "Show WhatsApp recordings", whatsapp_business: "Show WhatsApp Business recordings",
  google_meet: "Show Meet recordings", zoom: "Show Zoom recordings", teams: "Show Teams recordings", phone: "Show SIM calls",
  voice_notes: "Show voice notes", imported: "Show imported recordings",
};

/** A smart folder of the Library the palette can open (the ones the sidebar lists). */
export interface PaletteFolder {
  folder: SmartFolder;
  label: string;
}

/** What the palette makes and opens beyond the pages (v1.6, P4.3); each is left out when missing. */
export interface PaletteExtra {
  upload?: () => void;
  newProject?: () => void;
  requestLink?: () => void;
  /** Invite people to the workspace (in the console). */
  invite?: () => void;
  /** Settings → a page (devices, plan, appearance…). */
  account?: (tab: string) => void;
  help?: () => void;
  /** Upgrade plan (Free and Pro), or "Your upgrade request" while one is open. */
  upgrade?: { open: boolean; run: () => void };
}

/** A setting the palette found (v1.7): its title, where it lives, and what opens it. */
export interface PaletteSetting {
  id: string;
  title: string;
  breadcrumb: string;
  open: () => void;
}

/** The commands that make sense right now (recording or not, captions on or off). */
export function paletteCommands(status: AppStatus, actions: {
  go: (tab: MainTab) => void;
  run: (fn: () => Promise<unknown>, ok?: string, target?: NoticeTarget) => void;
  /** The smart folders (since v1.5), here too so they open with the sidebar collapsed. */
  folders?: readonly PaletteFolder[];
  openFolder?: (folder: SmartFolder) => void;
  extra?: PaletteExtra;
  /** The new design's words: its Record page is classic's Home. */
  v2?: boolean;
}): PaletteCommand[] {
  const extra = actions.extra ?? {};
  const recording = !!status.recording;
  const openFolder = actions.openFolder;
  const list: PaletteCommand[] = [
    recording
      ? { id: "stop", label: "Stop recording", keywords: "end finish save", run: () => actions.run(api.stopRecording) }
      : { id: "start", label: actions.v2 ? "Record" : "Record now", keywords: "record now start recording call meeting voice note begin", run: () => actions.run(api.recordNow) },
  ];
  if (recording) {
    list.push(status.paused
      ? { id: "resume", label: "Resume recording", keywords: "continue", run: () => actions.run(api.resumeRecording) }
      : { id: "pause", label: "Pause recording", keywords: "off the record hold", run: () => actions.run(api.pauseRecording) });
    list.push({ id: "mark", label: "Bookmark this moment", keywords: "mark tag", run: () => actions.run(() => api.markMoment(), "Bookmarked", { kind: "page", page: "record" }) });
  }
  list.push(
    { id: "captions", label: status.captionsLive ? "Turn captions off" : "Turn captions on", keywords: "live subtitles", run: () => actions.run(api.toggleCaptions) },
    { id: "voice", label: "Capture voice note playing now", keywords: "whatsapp audio", run: () => actions.run(api.toggleVoiceNote) },
    ...(recording && status.recording?.mode !== "mic_note" ? [] : [{
      id: "mic_note", label: status.recording?.mode === "mic_note" ? "Stop and save voice note" : "Record a voice note", keywords: "microphone new dictate n",
      run: () => actions.run(api.toggleMicNote),
    }]),
    { id: "live-translate", label: "Live Translate", keywords: "translate subtitles captions game video language", feature: "live_translate", run: () => actions.go("record") },
    { id: "copy", label: "Copy the last 2 minutes of captions", keywords: "clipboard", run: () => actions.run(() => api.copyLastMinutes(2)) },
    ...(extra.upload ? [{ id: "upload", label: "Upload files", keywords: "import audio video voice notes add", run: extra.upload }] : []),
    ...(extra.newProject ? [{ id: "new-project", label: "New project", keywords: "create client folder add", run: extra.newProject }] : []),
    ...(extra.requestLink ? [{ id: "request-link", label: "Request link", keywords: "request files send me voice notes upload link", run: extra.requestLink }] : []),
    ...(extra.invite ? [{ id: "invite", label: "Invite people to the workspace", keywords: "invite team teammate member add person", feature: "team", run: extra.invite }] : []),
    { id: "sync", label: "Sync the library", keywords: "refresh download", run: () => actions.run(api.refreshLibrary, "Library synced", { kind: "library", view: null }) },
    { id: "library", label: "Go to Library", keywords: "recordings transcripts", run: () => actions.go("library") },
    ...(openFolder ? (actions.folders ?? []).map(({ folder, label }) => (
      actions.v2 && V2_FOLDER_WORDS[folder]
        ? { id: `folder-${folder}`, label: V2_FOLDER_WORDS[folder]!, keywords: `${label} source filter show only library recordings`, run: () => openFolder(folder) }
        : { id: `folder-${folder}`, label: `Go to ${label}`, keywords: "smart folder library recordings", run: () => openFolder(folder) }
    )) : []),
    { id: "projects", label: "Go to Projects", keywords: "clients folders workspace brief notes files ask", run: () => actions.go("projects") },
    { id: "record", label: actions.v2 ? "Go to Record page" : "Go to Home", keywords: actions.v2 ? "home record microphone detection start live assist" : "record microphone detection start", run: () => actions.go("record") },
    { id: "ready", label: "Check calls will be recorded", keywords: "ready check permissions microphone fix readiness", run: () => actions.go("record") },
    { id: "activity", label: "Go to Activity", keywords: "notifications bell", run: () => actions.go("activity") },
    { id: "shared", label: "Go to Shared", keywords: "shared with me links request links", run: () => actions.go("shared") },
    { id: "settings", label: "Go to Settings", keywords: "settings preferences account profile", run: () => actions.go("settings") },
    ...(extra.upgrade ? [extra.upgrade.open
      ? { id: "upgrade", label: "Your upgrade request", keywords: "plan payment receipt pending status", run: extra.upgrade.run }
      : { id: "upgrade", label: "Upgrade plan", keywords: "pro ultra buy price plan", run: extra.upgrade.run }] : []),
    ...(extra.help ? [{ id: "help", label: "Help & shortcuts", keywords: "keys keyboard help", run: extra.help }] : []),
  );
  return list;
}

export function CommandPalette({ status, onClose, onGo, run, onOpenRecording, folders, onFolder, pages = [], extra, settings, look = "classic", initialQuery = "", inRow = false, onShowAll, onAsk, actionState }: {
  status: AppStatus;
  onClose: () => void;
  onGo: (tab: MainTab) => void;
  run: (fn: () => Promise<unknown>, ok?: string, target?: NoticeTarget) => void;
  onOpenRecording: (id: string, ms: number) => void;
  /** The Library's smart folders the sidebar lists, and what opens one. */
  folders?: readonly PaletteFolder[];
  onFolder?: (folder: SmartFolder) => void;
  /** Every page by name (projects, places, Settings pages), found once something is typed. */
  pages?: readonly (PageEntry & { open: () => void })[];
  extra?: PaletteExtra;
  /** Every single setting by name (v1.7), from the settings registry. */
  settings?: (query: string) => PaletteSetting[];
  /** "v2": drawn with the new design's values (v2.css .el-palette), the same rows, keys and ranking. */
  look?: "classic" | "v2";
  /** What was typed already (the title row's field, UX-20): the search starts from it. */
  initialQuery?: string;
  /**
   * The title row's search of everything (UX-20): under the row's field, in its groups (Recordings, Projects, Ask,
   * Settings, Commands), with "Show all in the list" and Ask. Elsewhere (classic, the sidebar shell) as before.
   */
  inRow?: boolean;
  /** "Show all in the list": the Library becomes the results of what was typed. */
  onShowAll?: (query: string) => void;
  /** Ask the projects what was typed. */
  onAsk?: (question: string) => void;
  /** The window's state for the action list's commands (UX-21): can record, recording, the ticks' states. */
  actionState?: ActionContext["state"];
}) {
  const [query, setQuery] = useState(initialQuery);
  const entitlements = useEntitlements();
  // The row the arrows or the pointer chose, by its id (rows arriving later never move the choice to another row);
  // none, or gone: the best match (Enter runs it).
  const [chosenId, setChosen] = useState<string | null>(null);
  // Where focus goes back on Esc or a press outside: what had it when the search opened, else the title row's search
  // (WebKit leaves focus on the page when a button is clicked). A choice that goes somewhere keeps focus there.
  const opener = useRef<HTMLElement | null>(null);
  if (opener.current === null && typeof document !== "undefined") {
    const active = document.activeElement;
    opener.current = active instanceof HTMLElement && active !== document.body ? active
      : document.querySelector<HTMLElement>(".el-titlerow__field, button.el-titlerow__search, .el-titlerow__middle .el-button");
  }
  const dismiss = () => {
    onClose();
    if (opener.current?.isConnected) opener.current.focus();
  };
  const [found, setFound] = useState<SearchResult["data"]>([]);
  // The recordings' search is still out: "Nothing matches" waits for it.
  const [lookingUp, setLookingUp] = useState(false);
  const listId = useId();
  const inputRef = useRef<HTMLInputElement | null>(null);
  // Under the three levels' title row (UX-20) the results drop down from its search field, 560 px wide and centred on
  // it (kept inside the window), over a lighter scrim: the field itself grows into the search.
  const [fromRow, setFromRow] = useState<CSSProperties | null>(null);
  useLayoutEffect(() => {
    if (look !== "v2" || !inRow) return;
    const cell = document.querySelector<HTMLElement>(".el-titlerow__middle");
    const box = cell?.getBoundingClientRect();
    if (!box || !box.width) return;
    const wide = parseFloat(getComputedStyle(document.documentElement).getPropertyValue("--el-desktop-search-results-width")) || 560;
    const half = Math.min(wide, window.innerWidth - 16) / 2;
    const centre = Math.min(Math.max(box.left + box.width / 2, half + 8), window.innerWidth - half - 8);
    setFromRow({ position: "fixed", top: Math.max(4, box.top + box.height / 2 - 18), left: centre });
  }, [look, inRow]);
  // Where the pointer last was over the list: only its own movement chooses a row (see `pointed`).
  const pointer = useRef<{ x: number; y: number } | null>(null);
  const commands = useMemo(() => paletteCommands(status, { go: onGo, run, folders, openFolder: onFolder, extra, v2: look === "v2" }), [status, onGo, run, folders, onFolder, extra, look]);

  useEffect(() => {
    const q = query.trim();
    if (q.length < 2) {
      setFound([]);
      setLookingUp(false);
      return;
    }
    let alive = true;
    setLookingUp(true);
    const timer = window.setTimeout(() => {
      api.searchLibrary({ q })
        .then((r) => alive && setFound(r.data.slice(0, 6)))
        .catch(() => alive && setFound([]))
        .finally(() => alive && setLookingUp(false));
    }, 250);
    return () => {
      alive = false;
      window.clearTimeout(timer);
    };
  }, [query]);

  // Every group ranked by name first; a word only inside another word never matches (D-087).
  const actions = ranked(commands, (c) => paletteScore(query, c));
  const matching = actions.map((a) => a.row);
  // Pages by name (a project, a folder, Devices…) that no command above already opens.
  const pageRows = ranked(pages.filter((p) => !matching.some((c) => c.label === `Go to ${p.label}`)),
    (p) => (query.trim() ? paletteScore(query, { label: p.label, keywords: `${p.group ?? ""} ${p.keywords ?? ""}` }) : 0)).slice(0, 6);
  const pageHits = pageRows.map(({ row: p }) => ({ id: `page-${p.id}`, label: `Go to ${p.label}${p.group ? ` · ${p.group}` : ""}`, run: p.open }));
  const settingHits: (PaletteCommand & { crumb: string })[] = query.trim().length >= 2 && settings
    ? settings(query).map((x) => ({ id: `setting-${x.id}`, label: x.title, crumb: x.breadcrumb, run: x.open })) : [];
  const recordings: PaletteCommand[] = found.map((r) => {
    const hit = r.hits[0];
    const ms = hit?.start_ms ?? 0;
    return { id: `rec-${r.session.id}`, label: recordingName(r.session.title), keywords: hit ? `${timestamp(ms)} ${hit.snippet}` : "", run: () => onOpenRecording(r.session.id, ms) };
  });
  // How well each shown row matches: a setting or recording found by what it says, not its name, counts as a keyword.
  const nameScore = (label: string) => Math.max(1, paletteScore(query, { label }));
  const q = query.trim();
  // The title row's groups (UX-20): the recordings first, then projects, Ask, settings and every command (the palette's,
  // the pages it goes to, and the action list's that this window can run now, UX-21).
  const rowGroups = (): [string, PaletteCommand[], number[]][] => {
    const isProject = (id: string) => id.startsWith("project-");
    const projectRows = pageRows.filter((p) => isProject(p.row.id));
    const otherPages = pageRows.filter((p) => !isProject(p.row.id));
    // The window's own state (can it record, the ticks), so a command shows only where it can run, in its state's words.
    // The search list speaks in sentence case (3b: only the Mac's menus use Title Case).
    const ctx: ActionContext = { platform: status.platform === "macos" ? "macos" : "windows", state: { recording: !!status.recording, ...actionState } };
    const words = (id: string) => actionLabel(id, { ...ctx, platform: "windows" });
    const taken = new Set(matching.map((c) => c.label.toLowerCase()));
    const registry = ranked(ACTIONS.filter((a) => hasHandler(a.id) && available(a.id, ctx) && !taken.has(words(a.id).toLowerCase())),
      (a) => (q ? paletteScore(query, { label: words(a.id), keywords: `${a.label} ${a.menu ?? ""}` }) : 0))
      .map(({ row: a, score }) => ({
        row: { id: `action-${a.id}`, label: words(a.id), detail: a.state?.check ? (ctx.state?.[a.state.check] ? "On" : "Off") : undefined,
          run: () => void runAction(a.id, ctx) } as PaletteCommand,
        score,
      }));
    const showAll: PaletteCommand[] = onShowAll && q.length >= 2 ? [{ id: "show-all", label: "Show all in the list", run: () => onShowAll(q) }] : [];
    const ask: PaletteCommand[] = onAsk && q.length >= 2
      ? [{ id: "ask", label: `Ask about “${q}”`, view: <>Ask about “<bdi>{q}</bdi>”</>, run: () => onAsk(q) }] : [];
    const otherHits = otherPages.map(({ row: p }) => ({ id: `page-${p.id}`, label: `Go to ${p.label}${p.group ? ` · ${p.group}` : ""}`, run: p.open }));
    return [
      ["Recordings", [...recordings, ...showAll], [...recordings.map((r) => nameScore(r.label)), ...showAll.map(() => 0)]],
      ["Projects", projectRows.map(({ row: p }) => ({ id: `page-${p.id}`, label: p.label, run: p.open })), projectRows.map((p) => p.score)],
      ["Ask", ask, ask.map(() => 1)],
      ["Settings", settingHits, settingHits.map((s) => nameScore(s.label))],
      ["Commands", [...matching, ...otherHits, ...registry.map((r) => r.row)], [...actions.map((a) => a.score), ...otherPages.map((p) => p.score), ...registry.map((r) => r.score)]],
    ];
  };
  const scored: [string, PaletteCommand[], number[]][] = inRow ? rowGroups() : [
    ["Actions", matching, actions.map((a) => a.score)],
    ["Settings", settingHits, settingHits.map((s) => nameScore(s.label))],
    ["Pages", pageHits, pageRows.map((p) => p.score)],
    ["Recordings", recordings, recordings.map((r) => nameScore(r.label))],
  ];
  const shownGroups = scored.filter(([, list]) => list.length > 0);
  const groups: [string, PaletteCommand[]][] = shownGroups.map(([name, list]) => [name, list]);
  const options: PaletteCommand[] = groups.flatMap(([, list]) => list);
  const scores: number[] = shownGroups.flatMap(([, , s]) => s);
  // Enter runs the best match (the first of the best, in the order shown) until the arrows move. Recordings come in later
  // (from 250 ms): they never take the highlight from a row already shown for what was typed, so Enter runs what was on
  // screen; they (or "Show all in the list") are the best match only when nothing else is listed.
  const late = (o: PaletteCommand) => o.id.startsWith("rec-") || o.id === "show-all";
  const settled = options.map((o, i) => (late(o) ? -1 : scores[i]));
  const pool = settled.some((s) => s >= 0) ? settled : scores;
  const best = pool.reduce((at, s, i) => (s > pool[at] ? i : at), 0);
  const chosenAt = chosenId === null ? -1 : options.findIndex((o) => o.id === chosenId);
  const selected = chosenAt >= 0 ? chosenAt : Math.min(best, Math.max(0, options.length - 1));
  const choice = (at: number) => setChosen(options[at]?.id ?? null);

  // The pointer chooses a row only by moving: rows re-ranked or arriving under a still pointer (recordings coming in, a
  // group appearing) leave what Enter runs alone, so a mouse entering a row or a move event in place chooses nothing.
  const pointed = (e: React.MouseEvent, i: number) => {
    const last = pointer.current;
    pointer.current = { x: e.clientX, y: e.clientY };
    if (last && (last.x !== e.clientX || last.y !== e.clientY) && i !== selected) choice(i);
  };

  const choose = (option: PaletteCommand | undefined) => {
    if (!option) return;
    onClose();
    // The server still refuses a locked feature (`feature_not_in_plan`); the palette shows what it would bring instead.
    if (option.feature && isLocked(entitlements, option.feature)) openUpgradeSheet(option.feature);
    else option.run();
  };

  const onKey = (e: React.KeyboardEvent) => {
    if (e.key === "Escape") {
      e.preventDefault();
      dismiss();
    } else if (e.key === "ArrowDown") {
      e.preventDefault();
      choice((selected + 1) % Math.max(1, options.length));
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      choice((selected - 1 + options.length) % Math.max(1, options.length));
    } else if (e.key === "Enter") {
      e.preventDefault();
      choose(options[selected]);
    }
  };

  return (
    <div className={`palette-backdrop${look === "v2" ? " el-root el-palette" : ""}${inRow && look === "v2" ? " el-palette--row" : ""}`} onMouseDown={(e) => {
      // Its own default (focus moving to the page) is stopped, or the browser would take back the focus given to the opener.
      if (e.target !== e.currentTarget) return;
      e.preventDefault();
      dismiss();
    }}>
      <div className="palette" role="dialog" aria-modal="true" aria-label="Command palette" onKeyDown={onKey} style={fromRow ?? undefined}>
        <div className="palette-field">
          <LucideSearchIcon size={16} />
          <input ref={inputRef} className="input palette-input" autoFocus value={query} placeholder="Search recordings, settings, pages… or type a command"
            role="combobox" aria-expanded="true" aria-controls={listId} aria-activedescendant={options[selected] ? `${listId}-${options[selected].id}` : undefined}
            dir="auto" onChange={(e) => { setQuery(e.target.value); setChosen(null); }} />
          {query && <button type="button" className="btn ghost icon-only palette-clear" aria-label="Clear" onMouseDown={(e) => e.preventDefault()} onClick={() => { setQuery(""); setChosen(null); inputRef.current?.focus(); }}><LucideXIcon size={14} /></button>}
        </div>
        <div id={listId} className="palette-list" role="listbox" aria-label="Results">
          {options.length === 0 && <div className="palette-empty small muted" role="status">{emptyLine(query, lookingUp)}</div>}
          {groups.map(([name, list]) => (
            <div key={name} role="group" aria-labelledby={`${listId}-g-${name}`} className="palette-group">
              <div id={`${listId}-g-${name}`} className="palette-group-head" role="presentation">{name}</div>
              {list.map((o) => {
                const i = options.indexOf(o);
                const record = found.find((r) => `rec-${r.session.id}` === o.id);
                const hit = record?.hits[0];
                const crumb = (o as { crumb?: string }).crumb;
                return (
                  <div key={o.id} id={`${listId}-${o.id}`} role="option" aria-selected={i === selected} className={`palette-item ${i === selected ? "active" : ""}`}
                    onMouseMove={(e) => pointed(e, i)} onMouseDown={(e) => { e.preventDefault(); choose(o); }}>
                    <span className="palette-label">{record && inRow ? marked(o.label, query) : o.view ?? o.label}{o.feature && <BadgeMark feature={o.feature} />}</span>
                    {o.detail && <span className="palette-detail small muted">{o.detail}</span>}
                    {o.feature && isLocked(entitlements, o.feature) && <span className="sr-only">, {featureWords(entitlements, o.feature).name} is in {lowestPlan(entitlements, o.feature)?.name ?? "a paid plan"}</span>}
                    {crumb && <span className="palette-detail small muted">{crumb}</span>}
                    {hit && (
                      <span className="palette-detail small muted" dir="auto">{timestamp(hit.start_ms)} · {snippetParts(hit.snippet, hit.highlights).map((p, k) => (p.hit ? <mark key={k}>{p.text}</mark> : <span key={k}>{p.text}</span>))}</span>
                    )}
                  </div>
                );
              })}
            </div>
          ))}
        </div>
        <div className="palette-foot small muted">↑ ↓ to choose · Enter to run · Esc to close</div>
      </div>
    </div>
  );
}

/** A recording's title with the words typed marked (pure): each typed word where a word of the title starts with it. */
export function marked(title: string, query: string): ReactNode {
  const words = wordsOf(query);
  if (!words.length) return title;
  // Plain text between the marks (text nodes, not elements), so the row's name reads as the title does.
  const out: ReactNode[] = [];
  let text = "";
  title.split(/([^\p{L}\p{M}\p{N}]+)/u).forEach((part, i) => {
    const word = words.find((w) => part.toLowerCase().startsWith(w));
    if (!word) {
      text += part;
      return;
    }
    if (text) out.push(text);
    out.push(<mark key={i}>{part.slice(0, word.length)}</mark>);
    text = part.slice(word.length);
  });
  if (text) out.push(text);
  return out;
}

/** What the palette says when nothing is listed (pure): recordings are searched from two letters, so one letter asks
 * for another rather than saying nothing matches. */
export function emptyLine(query: string, lookingUp: boolean): string {
  const q = query.trim();
  if (q.length === 1) return "Type one more letter to search recordings.";
  if (lookingUp) return "Searching recordings and transcripts…";
  return `Nothing matches “${q}” in recordings, transcripts, settings or pages.`;
}
