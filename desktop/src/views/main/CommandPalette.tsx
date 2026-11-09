// Ctrl / Cmd + K (plan B9): run an action, go to a page, or jump into a recording found in any transcript. Since v1.6
// (P4.3) it also makes things (Upload, New project, Request link, Invite) and opens every page "Find a page" knows
// (a project, a smart folder, a Settings page such as Devices); since v1.7 it finds every single setting too, in groups:
// Actions, Settings, Pages, Recordings.
import { useEffect, useId, useMemo, useRef, useState } from "react";
import { api, type AppStatus, type SearchResult, type SmartFolder } from "../../lib/ipc";
import { timestamp } from "../../lib/format";
import { snippetParts } from "../../lib/player";
import type { MainTab } from "../../lib/mainView";
import { findPages, type PageEntry } from "../../lib/shell";
import type { NoticeTarget } from "../../lib/notice";
import { featureWords, isLocked, lowestPlan, openUpgradeSheet, useEntitlements } from "../../lib/entitlements";
import { BadgeMark } from "./Upgrade";
import { LucideSearchIcon, LucideXIcon } from "../../components/Icons";

export interface PaletteCommand {
  id: string;
  label: string;
  /** Extra words the command answers to. */
  keywords?: string;
  /** A paid feature (v1.6.2): while the plan leaves it out the row wears its badge and opens the upgrade sheet instead. */
  feature?: string;
  run: () => void;
}

/** Every word of the query appears in the label or the keywords (case-insensitive). */
export function paletteMatches(query: string, command: Pick<PaletteCommand, "label" | "keywords">): boolean {
  const haystack = `${command.label} ${command.keywords ?? ""}`.toLowerCase();
  return query.toLowerCase().split(/\s+/).filter(Boolean).every((w) => haystack.includes(w));
}

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
}): PaletteCommand[] {
  const extra = actions.extra ?? {};
  const recording = !!status.recording;
  const openFolder = actions.openFolder;
  const list: PaletteCommand[] = [
    recording
      ? { id: "stop", label: "Stop recording", keywords: "end finish save", run: () => actions.run(api.stopRecording) }
      : { id: "start", label: "Record now", keywords: "start recording call meeting voice note begin", run: () => actions.run(api.recordNow) },
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
      { id: `folder-${folder}`, label: `Go to ${label}`, keywords: "smart folder library recordings", run: () => openFolder(folder) }
    )) : []),
    { id: "projects", label: "Go to Projects", keywords: "clients folders workspace brief notes files ask", run: () => actions.go("projects") },
    { id: "record", label: "Go to Home", keywords: "record microphone detection start", run: () => actions.go("record") },
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

export function CommandPalette({ status, onClose, onGo, run, onOpenRecording, folders, onFolder, pages = [], extra, settings }: {
  status: AppStatus;
  onClose: () => void;
  onGo: (tab: MainTab) => void;
  run: (fn: () => Promise<unknown>, ok?: string, target?: NoticeTarget) => void;
  onOpenRecording: (id: string, ms: number) => void;
  /** The Library's smart folders the sidebar lists, and what opens one. */
  folders?: readonly PaletteFolder[];
  onFolder?: (folder: SmartFolder) => void;
  /** Every page "Find a page" knows (projects, places, Account tabs), found by name once something is typed. */
  pages?: readonly (PageEntry & { open: () => void })[];
  extra?: PaletteExtra;
  /** Every single setting by name (v1.7), from the settings registry. */
  settings?: (query: string) => PaletteSetting[];
}) {
  const [query, setQuery] = useState("");
  const entitlements = useEntitlements();
  const [index, setIndex] = useState(0);
  const [found, setFound] = useState<SearchResult["data"]>([]);
  const listId = useId();
  const inputRef = useRef<HTMLInputElement | null>(null);
  const commands = useMemo(() => paletteCommands(status, { go: onGo, run, folders, openFolder: onFolder, extra }), [status, onGo, run, folders, onFolder, extra]);

  useEffect(() => {
    const q = query.trim();
    if (q.length < 2) {
      setFound([]);
      return;
    }
    let alive = true;
    const timer = window.setTimeout(() => {
      api.searchLibrary({ q }).then((r) => alive && setFound(r.data.slice(0, 6))).catch(() => alive && setFound([]));
    }, 250);
    return () => {
      alive = false;
      window.clearTimeout(timer);
    };
  }, [query]);

  const matching = commands.filter((c) => paletteMatches(query, c));
  // Pages by name (a project, a folder, Devices…) that no command above already opens.
  const pageHits = findPages(pages, query).filter((p) => !matching.some((c) => c.label === `Go to ${p.label}`)).slice(0, 6)
    .map((p) => ({ id: `page-${p.id}`, label: `Go to ${p.label}${p.group ? ` · ${p.group}` : ""}`, run: p.open }));
  const settingHits: (PaletteCommand & { crumb: string })[] = query.trim().length >= 2 && settings
    ? settings(query).map((x) => ({ id: `setting-${x.id}`, label: x.title, crumb: x.breadcrumb, run: x.open })) : [];
  const recordings: PaletteCommand[] = found.map((r) => {
    const hit = r.hits[0];
    const ms = hit?.start_ms ?? 0;
    return { id: `rec-${r.session.id}`, label: r.session.title ?? "Recording", keywords: hit ? `${timestamp(ms)} ${hit.snippet}` : "", run: () => onOpenRecording(r.session.id, ms) };
  });
  const groups: [string, PaletteCommand[]][] = ([["Actions", matching], ["Settings", settingHits], ["Pages", pageHits], ["Recordings", recordings]] as [string, PaletteCommand[]][])
    .filter(([, list]) => list.length > 0);
  const options: PaletteCommand[] = groups.flatMap(([, list]) => list);
  const selected = Math.min(index, Math.max(0, options.length - 1));

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
      onClose();
    } else if (e.key === "ArrowDown") {
      e.preventDefault();
      setIndex((selected + 1) % Math.max(1, options.length));
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setIndex((selected - 1 + options.length) % Math.max(1, options.length));
    } else if (e.key === "Enter") {
      e.preventDefault();
      choose(options[selected]);
    }
  };

  return (
    <div className="palette-backdrop" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}>
      <div className="palette" role="dialog" aria-modal="true" aria-label="Command palette" onKeyDown={onKey}>
        <div className="palette-field">
          <LucideSearchIcon size={16} />
          <input ref={inputRef} className="input palette-input" autoFocus value={query} placeholder="Search recordings, settings, pages… or type a command"
            role="combobox" aria-expanded="true" aria-controls={listId} aria-activedescendant={options[selected] ? `${listId}-${options[selected].id}` : undefined}
            onChange={(e) => { setQuery(e.target.value); setIndex(0); }} />
          {query && <button type="button" className="btn ghost icon-only palette-clear" aria-label="Clear" onMouseDown={(e) => e.preventDefault()} onClick={() => { setQuery(""); setIndex(0); inputRef.current?.focus(); }}><LucideXIcon size={14} /></button>}
        </div>
        <div id={listId} className="palette-list" role="listbox" aria-label="Results">
          {options.length === 0 && <div className="palette-empty small muted" role="presentation">Nothing matches.</div>}
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
                    onMouseEnter={() => setIndex(i)} onMouseDown={(e) => { e.preventDefault(); choose(o); }}>
                    <span className="palette-label">{o.label}{o.feature && <BadgeMark feature={o.feature} />}</span>
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
