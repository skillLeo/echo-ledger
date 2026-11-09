// Settings › Recording › Voice notes & dictation (plan B14; rows of v1.7): press-to-start or hold-to-talk, where the
// text goes, clean-up and sounds, then three sub-pages: replacements, voice snippets, and the recent dictations kept on
// this computer.
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
import { setting } from "../../lib/settingsRegistry";
import { EntityRow, LinkRow, ResetRow, Section, SelectRow, SwitchRow } from "./SettingsControls";

type Patch = (p: SettingsPatch) => Promise<boolean>;

const opts = <T extends string>(id: string) => (setting(id).options ?? []).map((o) => [o.value as T, o.label] as const);

/** Adds an entry unless one with the same key (ignoring case) exists; returns null when nothing changes. */
export function withEntry<T>(list: T[], entry: T, key: (e: T) => string): T[] | null {
  const k = key(entry).trim().toLowerCase();
  if (!k || list.some((e) => key(e).trim().toLowerCase() === k)) return null;
  return [...list, entry];
}

/** Recording › Voice notes & dictation. Dictation never makes a recording or asks to keep one. */
export function VoicePage({ settings, patch, onOpen }: { settings: Settings; patch: Patch; onOpen: (page: "voice.replacements" | "voice.snippets" | "voice.history") => void }) {
  return (
    <>
      <Section title="Dictation" footer={<><p>{setting("voice.cleanup").description}</p><p>Say “new line” or “new paragraph” to break lines. Dictation is never saved as a recording.</p></>}>
        <SelectRow id="voice.shortcut_mode" value={settings.dictationMode} options={opts<DictationMode>("voice.shortcut_mode")} width={300} onChange={(v) => void patch({ dictationMode: v })} />
        <SelectRow id="voice.output" value={settings.dictationOutput} options={opts<Settings["dictationOutput"]>("voice.output")} width={300} onChange={(v) => void patch({ dictationOutput: v })} />
        <SwitchRow id="voice.cleanup" on={settings.dictationCleanup} onChange={(v) => void patch({ dictationCleanup: v })} />
        <SwitchRow id="voice.sounds" on={settings.dictationSounds} onChange={(v) => void patch({ dictationSounds: v })} />
        <LinkRow id="voice.replacements" value={String(settings.dictationReplacements.length)} onOpen={() => onOpen("voice.replacements")} chevron />
        <LinkRow id="voice.snippets" value={String(settings.dictationSnippets.length)} onOpen={() => onOpen("voice.snippets")} chevron />
        <LinkRow id="voice.history" onOpen={() => onOpen("voice.history")} chevron />
      </Section>
      <ResetRow page="Voice notes & dictation" onReset={() => void patch({ dictationMode: "toggle", dictationOutput: "type", dictationCleanup: true, dictationSounds: true })} />
    </>
  );
}

/** Voice notes & dictation › Replacements: "When I say" → "Write", whole words, any capitals. */
export function ReplacementsPage({ settings, patch }: { settings: Settings; patch: Patch }) {
  const list = settings.dictationReplacements;
  const onSave = (dictationReplacements: DictationReplacement[]) => patch({ dictationReplacements });
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
    <Section footer={<p>Fix how names are spelled, or swap a short word for a longer one. Whole words only, any capitals.</p>}>
      <div role="list" aria-label="Replacements">
        {list.map((r) => (
          <EntityRow key={r.from} title={`${r.from} → ${r.to || "(nothing)"}`}>
            <button type="button" className="btn ghost compact" aria-label={`Remove ${r.from}`} onClick={() => void onSave(list.filter((x) => x !== r))}>Remove</button>
          </EntityRow>
        ))}
      </div>
      <form className="set-row set-form" onSubmit={(e) => { e.preventDefault(); void add(); }}>
        <label className="sr-only" htmlFor={fromId}>When I say</label>
        <input id={fromId} className="input" placeholder="When I say" value={from} maxLength={100} onChange={(e) => setFrom(e.target.value)} />
        <label className="sr-only" htmlFor={toId}>Write</label>
        <input id={toId} className="input" placeholder="Write" value={to} maxLength={100} onChange={(e) => setTo(e.target.value)} />
        <button type="submit" className="btn compact" disabled={!from.trim()}>Add replacement</button>
      </form>
    </Section>
  );
}

/** Voice notes & dictation › Snippets: say the phrase and the saved text goes in. */
export function SnippetsPage({ settings, patch }: { settings: Settings; patch: Patch }) {
  const list = settings.dictationSnippets;
  const onSave = (dictationSnippets: DictationSnippet[]) => patch({ dictationSnippets });
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
    <Section footer={<p>Say the phrase and the saved text goes in, for example “insert signature”.</p>}>
      <div role="list" aria-label="Snippets">
        {list.map((s) => (
          <EntityRow key={s.trigger} title={s.trigger} sub={<span className="dictation-snippet">{s.text}</span>}>
            <button type="button" className="btn ghost compact" aria-label={`Remove ${s.trigger}`} onClick={() => void onSave(list.filter((x) => x !== s))}>Remove</button>
          </EntityRow>
        ))}
      </div>
      <form className="set-row set-form" onSubmit={(e) => { e.preventDefault(); void add(); }}>
        <label className="sr-only" htmlFor={triggerId}>Phrase</label>
        <input id={triggerId} className="input" placeholder="Phrase" value={trigger} maxLength={100} onChange={(e) => setTrigger(e.target.value)} />
        <label className="sr-only" htmlFor={textId}>Text to insert</label>
        <textarea id={textId} className="input" rows={2} placeholder="Text to insert" value={text} maxLength={2000} onChange={(e) => setText(e.target.value)} />
        <button type="submit" className="btn compact" disabled={!trigger.trim() || !text.trim()}>Add snippet</button>
      </form>
    </Section>
  );
}

/** Voice notes & dictation › Recent dictations: the last 50, kept on this computer only. */
export function HistoryPage() {
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
    if (!window.confirm("Clear your recent dictations? They're deleted from this computer.")) return;
    try {
      await api.clearDictationHistory();
      setEntries([]);
    } catch (e) {
      setError(errorMessage(e));
    }
  };
  return (
    <>
      <Section footer={<><p>The last 50, kept on this computer only. Copy one if it went to the wrong place.</p>{error && <p className="error-text">{error}</p>}</>}>
        {entries.length === 0 ? <div className="set-row"><span className="set-title muted">Nothing dictated yet.</span></div> : (
          <div role="list" aria-label="Recent dictations">
            {entries.map((e, i) => (
              <EntityRow key={`${e.at}-${i}`} title={<span className="dictation-snippet">{e.text}</span>} sub={formatWhen(e.at)}>
                <button type="button" className="btn ghost compact" onClick={() => void copy(i)}>{copied === i ? "Copied" : "Copy"}</button>
              </EntityRow>
            ))}
          </div>
        )}
      </Section>
      {entries.length > 0 && (
        <div className="set-reset"><button type="button" className="btn text danger" onClick={() => void clear()}>Clear history</button></div>
      )}
    </>
  );
}
