// Ctrl / Cmd + K (plan B9): run an action, go to a page, or jump into a recording found in any transcript.
import { useEffect, useId, useMemo, useRef, useState } from "react";
import { api, type AppStatus, type SearchResult } from "../../lib/ipc";
import { timestamp } from "../../lib/format";
import { snippetParts } from "../../lib/player";
import type { MainTab } from "../../lib/mainView";

export interface PaletteCommand {
  id: string;
  label: string;
  /** Extra words the command answers to. */
  keywords?: string;
  run: () => void;
}

/** Every word of the query appears in the label or the keywords (case-insensitive). */
export function paletteMatches(query: string, command: Pick<PaletteCommand, "label" | "keywords">): boolean {
  const haystack = `${command.label} ${command.keywords ?? ""}`.toLowerCase();
  return query.toLowerCase().split(/\s+/).filter(Boolean).every((w) => haystack.includes(w));
}

/** The commands that make sense right now (recording or not, captions on or off). */
export function paletteCommands(status: AppStatus, actions: {
  go: (tab: MainTab) => void;
  run: (fn: () => Promise<unknown>, ok?: string) => void;
}): PaletteCommand[] {
  const recording = !!status.recording;
  const list: PaletteCommand[] = [
    recording
      ? { id: "stop", label: "Stop recording", keywords: "end finish save", run: () => actions.run(api.stopRecording) }
      : { id: "start", label: "Start recording", keywords: "record call begin", run: () => actions.run(() => api.startRecording()) },
  ];
  if (recording) {
    list.push(status.paused
      ? { id: "resume", label: "Resume recording", keywords: "continue", run: () => actions.run(api.resumeRecording) }
      : { id: "pause", label: "Pause recording", keywords: "off the record hold", run: () => actions.run(api.pauseRecording) });
    list.push({ id: "mark", label: "Bookmark this moment", keywords: "mark tag", run: () => actions.run(() => api.markMoment(), "Bookmarked") });
  }
  list.push(
    { id: "captions", label: status.captionsLive ? "Turn captions off" : "Turn captions on", keywords: "live subtitles", run: () => actions.run(api.toggleCaptions) },
    { id: "voice", label: "Capture voice note playing now", keywords: "whatsapp audio", run: () => actions.run(api.toggleVoiceNote) },
    ...(recording && status.recording?.mode !== "mic_note" ? [] : [{
      id: "mic_note", label: status.recording?.mode === "mic_note" ? "Stop and save voice note" : "Record a voice note", keywords: "microphone new dictate n",
      run: () => actions.run(api.toggleMicNote),
    }]),
    { id: "copy", label: "Copy the last 2 minutes of captions", keywords: "clipboard", run: () => actions.run(() => api.copyLastMinutes(2)) },
    { id: "sync", label: "Sync the library", keywords: "refresh download", run: () => actions.run(api.refreshLibrary, "Library synced") },
    { id: "library", label: "Go to Library", keywords: "recordings transcripts", run: () => actions.go("library") },
    { id: "clients", label: "Go to Clients", keywords: "workspace brief notes files ask", run: () => actions.go("clients") },
    { id: "record", label: "Go to Record", keywords: "microphone detection", run: () => actions.go("record") },
    { id: "activity", label: "Go to Activity", keywords: "notifications bell", run: () => actions.go("activity") },
    { id: "settings", label: "Go to Settings", keywords: "preferences shortcuts captions language", run: () => actions.go("settings") },
  );
  return list;
}

export function CommandPalette({ status, onClose, onGo, run, onOpenRecording }: {
  status: AppStatus;
  onClose: () => void;
  onGo: (tab: MainTab) => void;
  run: (fn: () => Promise<unknown>, ok?: string) => void;
  onOpenRecording: (id: string, ms: number) => void;
}) {
  const [query, setQuery] = useState("");
  const [index, setIndex] = useState(0);
  const [found, setFound] = useState<SearchResult["data"]>([]);
  const listId = useId();
  const inputRef = useRef<HTMLInputElement | null>(null);
  const commands = useMemo(() => paletteCommands(status, { go: onGo, run }), [status, onGo, run]);

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

  const options: PaletteCommand[] = [
    ...commands.filter((c) => paletteMatches(query, c)),
    ...found.map((r) => {
      const hit = r.hits[0];
      const ms = hit?.start_ms ?? 0;
      return { id: `rec-${r.session.id}`, label: r.session.title ?? "Recording", keywords: hit ? `${timestamp(ms)} ${hit.snippet}` : "", run: () => onOpenRecording(r.session.id, ms) };
    }),
  ];
  const selected = Math.min(index, Math.max(0, options.length - 1));

  const choose = (option: PaletteCommand | undefined) => {
    if (!option) return;
    onClose();
    option.run();
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
        <input ref={inputRef} className="input palette-input" autoFocus value={query} placeholder="Type a command or search transcripts…"
          role="combobox" aria-expanded="true" aria-controls={listId} aria-activedescendant={options[selected] ? `${listId}-${options[selected].id}` : undefined}
          onChange={(e) => { setQuery(e.target.value); setIndex(0); }} />
        <ul id={listId} className="palette-list" role="listbox" aria-label="Results">
          {options.length === 0 && <li className="palette-empty small muted">Nothing matches.</li>}
          {options.map((o, i) => {
            const record = found.find((r) => `rec-${r.session.id}` === o.id);
            const hit = record?.hits[0];
            return (
              <li key={o.id} id={`${listId}-${o.id}`} role="option" aria-selected={i === selected} className={`palette-item ${i === selected ? "active" : ""}`}
                onMouseEnter={() => setIndex(i)} onMouseDown={(e) => { e.preventDefault(); choose(o); }}>
                <span className="palette-label">{o.label}</span>
                {hit && (
                  <span className="palette-detail small muted" dir="auto">{timestamp(hit.start_ms)} · {snippetParts(hit.snippet, hit.highlights).map((p, k) => (p.hit ? <mark key={k}>{p.text}</mark> : <span key={k}>{p.text}</span>))}</span>
                )}
              </li>
            );
          })}
        </ul>
        <div className="palette-foot small muted">↑ ↓ to choose · Enter to run · Esc to close</div>
      </div>
    </div>
  );
}
