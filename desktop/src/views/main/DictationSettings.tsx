// Settings → Dictation (plan B14): press-to-start or hold-to-talk, clean-up and sounds, where the text goes, the
// dictionary and replacements, voice snippets, and the recent dictations kept on this computer.
import { useEffect, useId, useState } from "react";
import {
  api,
  errorMessage,
  type DictationHistoryEntry,
  type DictationMode,
  type DictationReplacement,
  type DictationSnippet,
  type Settings,
  type SettingsPatch,
} from "../../lib/ipc";
import { formatWhen } from "../../lib/format";
import { SelectRow, Toggle } from "./SettingsControls";

type Patch = (p: SettingsPatch) => Promise<boolean>;

const MODES: readonly (readonly [DictationMode, string])[] = [["toggle", "Press to start, press again to insert"], ["push_to_talk", "Hold the shortcut while speaking"]];
const OUTPUTS: readonly (readonly [Settings["dictationOutput"], string])[] = [["type", "Type into the focused app"], ["clipboard", "Copy to clipboard"]];

/** Adds an entry unless one with the same key (ignoring case) exists; returns null when nothing changes. */
export function withEntry<T>(list: T[], entry: T, key: (e: T) => string): T[] | null {
  const k = key(entry).trim().toLowerCase();
  if (!k || list.some((e) => key(e).trim().toLowerCase() === k)) return null;
  return [...list, entry];
}

function Replacements({ list, onSave }: { list: DictationReplacement[]; onSave: (l: DictationReplacement[]) => Promise<boolean> }) {
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const fromId = useId();
  const toId = useId();
  const add = async () => {
    const next = withEntry(list, { from: from.trim(), to: to.trim() }, (e) => e.from);
    if (next && (await onSave(next))) {
      setFrom("");
      setTo("");
    }
  };
  return (
    <div>
      <b>Dictionary and replacements</b>
      <p className="small muted">Fix how names are spelled, or swap a short word for a longer one. Whole words only, any capitals.</p>
      {list.length > 0 && (
        <div className="site-list" role="list" aria-label="Replacements">
          {list.map((r) => (
            <div className="site-rule" role="listitem" key={r.from}>
              <div className="site-main"><div className="site-pattern">{r.from} → {r.to || "(nothing)"}</div></div>
              <button type="button" className="btn ghost small" aria-label={`Remove ${r.from}`} onClick={() => void onSave(list.filter((x) => x !== r))}>Remove</button>
            </div>
          ))}
        </div>
      )}
      <div className="row" style={{ flexWrap: "wrap", gap: 8, marginTop: 8 }}>
        <label className="sr-only" htmlFor={fromId}>When I say</label>
        <input id={fromId} className="input" style={{ width: 180 }} placeholder="When I say" value={from} maxLength={100} onChange={(e) => setFrom(e.target.value)} />
        <label className="sr-only" htmlFor={toId}>Write</label>
        <input id={toId} className="input" style={{ width: 180 }} placeholder="Write" value={to} maxLength={100} onChange={(e) => setTo(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter") void add(); }} />
        <button type="button" className="btn" disabled={!from.trim()} onClick={() => void add()}>Add replacement</button>
      </div>
    </div>
  );
}

function Snippets({ list, onSave }: { list: DictationSnippet[]; onSave: (l: DictationSnippet[]) => Promise<boolean> }) {
  const [trigger, setTrigger] = useState("");
  const [text, setText] = useState("");
  const triggerId = useId();
  const textId = useId();
  const add = async () => {
    if (!text.trim()) return;
    const next = withEntry(list, { trigger: trigger.trim(), text: text.trim() }, (e) => e.trigger);
    if (next && (await onSave(next))) {
      setTrigger("");
      setText("");
    }
  };
  return (
    <div>
      <b>Voice snippets</b>
      <p className="small muted">Say the phrase and the saved text goes in, for example “insert signature”.</p>
      {list.length > 0 && (
        <div className="site-list" role="list" aria-label="Snippets">
          {list.map((s) => (
            <div className="site-rule" role="listitem" key={s.trigger}>
              <div className="site-main"><div className="site-pattern">{s.trigger}</div><div className="small muted dictation-snippet">{s.text}</div></div>
              <button type="button" className="btn ghost small" aria-label={`Remove ${s.trigger}`} onClick={() => void onSave(list.filter((x) => x !== s))}>Remove</button>
            </div>
          ))}
        </div>
      )}
      <div className="row" style={{ flexWrap: "wrap", gap: 8, marginTop: 8, alignItems: "flex-start" }}>
        <label className="sr-only" htmlFor={triggerId}>Phrase</label>
        <input id={triggerId} className="input" style={{ width: 180 }} placeholder="Phrase" value={trigger} maxLength={100} onChange={(e) => setTrigger(e.target.value)} />
        <label className="sr-only" htmlFor={textId}>Text to insert</label>
        <textarea id={textId} className="input" rows={2} style={{ width: 260 }} placeholder="Text to insert" value={text} maxLength={2000} onChange={(e) => setText(e.target.value)} />
        <button type="button" className="btn" disabled={!trigger.trim() || !text.trim()} onClick={() => void add()}>Add snippet</button>
      </div>
    </div>
  );
}

function History() {
  const [entries, setEntries] = useState<DictationHistoryEntry[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [copied, setCopied] = useState<number | null>(null);
  useEffect(() => {
    api.dictationHistory().then(setEntries, (e) => setError(errorMessage(e)));
  }, []);
  const copy = async (i: number) => {
    try {
      await navigator.clipboard.writeText(entries[i].text);
      setCopied(i);
    } catch (e) {
      setError(errorMessage(e));
    }
  };
  const clear = async () => {
    try {
      await api.clearDictationHistory();
      setEntries([]);
    } catch (e) {
      setError(errorMessage(e));
    }
  };
  return (
    <div>
      <div className="row"><b>Recent dictations</b><span className="spacer" />{entries.length > 0 && <button type="button" className="btn ghost small" onClick={() => void clear()}>Clear history</button>}</div>
      <p className="small muted">The last 50, kept on this computer only. Copy one if it went to the wrong place.</p>
      {error && <p className="small error-text">{error}</p>}
      {entries.length === 0 ? <p className="small muted">Nothing dictated yet.</p> : (
        <div className="site-list" role="list" aria-label="Recent dictations">
          {entries.map((e, i) => (
            <div className="site-rule" role="listitem" key={`${e.at}-${i}`}>
              <div className="site-main"><div className="dictation-snippet">{e.text}</div><div className="small muted">{formatWhen(e.at)}</div></div>
              <button type="button" className="btn ghost small" onClick={() => void copy(i)}>{copied === i ? "Copied" : "Copy"}</button>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/** The whole Dictation card. Dictation never makes a recording or asks to keep one. */
export function DictationCard({ settings, patch }: { settings: Settings; patch: Patch }) {
  return (
    <div className="card dictation-settings">
      <b>Dictation</b>
      <p className="small muted">Speak into any app with the dictation shortcut. Dictation is never saved as a recording.</p>
      <SelectRow label="How the shortcut works" value={settings.dictationMode} options={MODES} width={280} onChange={(v) => void patch({ dictationMode: v })} />
      <SelectRow label="Dictation output" value={settings.dictationOutput} options={OUTPUTS} onChange={(v) => void patch({ dictationOutput: v })} />
      <Toggle label="Clean up the text" hint="Removes “um” and repeated words, adds capitals and a full stop." on={settings.dictationCleanup} onChange={(v) => void patch({ dictationCleanup: v })} />
      <Toggle label="Start and stop sounds" on={settings.dictationSounds} onChange={(v) => void patch({ dictationSounds: v })} />
      <p className="small muted">Say “new line” or “new paragraph” to break lines.</p>
      <Replacements list={settings.dictationReplacements} onSave={(dictationReplacements) => patch({ dictationReplacements })} />
      <Snippets list={settings.dictationSnippets} onSave={(dictationSnippets) => patch({ dictationSnippets })} />
      <History />
    </div>
  );
}
