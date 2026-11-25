// Settings › Voice notes & dictation in the new look, with today's data and rules (DictationSettings.tsx): how the
// shortcut works, where the text goes, clean-up and sounds, then Replacements, Snippets and Recent dictations.
import { useEffect, useState } from "react";
import { api, errorMessage, type DictationHistoryEntry, type DictationMode, type Settings } from "../../../lib/ipc";
import { formatWhen } from "../../../lib/format";
import { confirmAction } from "../../../lib/confirm";
import { withEntry } from "../../../views/main/DictationSettings";
import { Button, Card, EmptyState, Icons, List, ListRow, TextArea, TextField } from "../../components";
import { Group, LinkSetting, ResetDefaults, SelectSetting, SwitchSetting, type Patch } from "./rows";
import { t } from "../../../i18n";

export type VoiceSub = "voice.replacements" | "voice.snippets" | "voice.history";

export function VoicePageV2({ settings, patch, onOpen }: { settings: Settings; patch: Patch; onOpen: (page: VoiceSub) => void }) {
  return (
    <>
      <Group title={t("Dictation")} footer={<p>{t("Say “new line” or “new paragraph” to break lines. Dictation is never saved as a recording.")}</p>}>
        <SelectSetting<DictationMode> id="voice.shortcut_mode" value={settings.dictationMode} onChange={(v) => void patch({ dictationMode: v })} />
        <SelectSetting<Settings["dictationOutput"]> id="voice.output" value={settings.dictationOutput} onChange={(v) => void patch({ dictationOutput: v })} />
        <SwitchSetting id="voice.cleanup" on={settings.dictationCleanup} onChange={(v) => void patch({ dictationCleanup: v })} />
        <SwitchSetting id="voice.sounds" on={settings.dictationSounds} onChange={(v) => void patch({ dictationSounds: v })} />
        <LinkSetting id="voice.replacements" value={String(settings.dictationReplacements.length)} onOpen={() => onOpen("voice.replacements")} />
        <LinkSetting id="voice.snippets" value={String(settings.dictationSnippets.length)} onOpen={() => onOpen("voice.snippets")} />
        <LinkSetting id="voice.history" onOpen={() => onOpen("voice.history")} />
      </Group>
      <ResetDefaults page={t("Voice notes & dictation")} onReset={() => void patch({ dictationMode: "toggle", dictationOutput: "type", dictationCleanup: true, dictationSounds: true })} />
    </>
  );
}

/** "When I say" → "Write", whole words, any capitals. */
export function ReplacementsPageV2({ settings, patch }: { settings: Settings; patch: Patch }) {
  const list = settings.dictationReplacements;
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const add = async () => {
    const next = withEntry(list, { from: from.trim(), to: to.trim() }, (e) => e.from);
    if (next && (await patch({ dictationReplacements: next }))) {
      setFrom("");
      setTo("");
    }
  };
  return (
    <>
      <Card>
        {list.length === 0 ? (
          <EmptyState icon={Icons.LanguagesIcon} title={t("No replacements yet")} body={t("Fix how names are spelled, or swap a short word for a longer one.")} />
        ) : (
          <List label={t("Replacements")} className="el-group__rows">
            {list.map((r) => (
              <ListRow key={r.from} compact title={<><span dir="auto">{r.from}</span> → <span dir="auto">{r.to || "(nothing)"}</span></>}
                control={<Button size="sm" variant="quiet" aria-label={`Remove ${r.from}`} onClick={() => void patch({ dictationReplacements: list.filter((x) => x !== r) })}>{t("Remove")}</Button>} />
            ))}
          </List>
        )}
      </Card>
      <Card>
        <form className="el-form" onSubmit={(e) => { e.preventDefault(); void add(); }}>
          <div className="el-settings-v2__pair">
            <TextField label={t("When I say")} value={from} maxLength={100} dir="auto" onChange={(e) => setFrom(e.target.value)} />
            <TextField label={t("Write")} value={to} maxLength={100} dir="auto" onChange={(e) => setTo(e.target.value)} />
          </div>
          <div className="el-page__actions"><Button type="submit" disabled={!from.trim()}>{t("Add replacement")}</Button></div>
        </form>
      </Card>
      <p className="el-caption el-secondary">{t("Whole words only, any capitals.")}</p>
    </>
  );
}

/** Say the phrase and the saved text goes in. */
export function SnippetsPageV2({ settings, patch }: { settings: Settings; patch: Patch }) {
  const list = settings.dictationSnippets;
  const [trigger, setTrigger] = useState("");
  const [text, setText] = useState("");
  const add = async () => {
    if (!text.trim()) return;
    const next = withEntry(list, { trigger: trigger.trim(), text: text.trim() }, (e) => e.trigger);
    if (next && (await patch({ dictationSnippets: next }))) {
      setTrigger("");
      setText("");
    }
  };
  return (
    <>
      <Card>
        {list.length === 0 ? (
          <EmptyState icon={Icons.TranscriptIcon} title={t("No snippets yet")} body={t("Say the phrase and the saved text goes in, for example “insert signature”.")} />
        ) : (
          <List label={t("Snippets")} className="el-group__rows">
            {list.map((s) => (
              <ListRow key={s.trigger} compact title={s.trigger} meta={s.text}
                control={<Button size="sm" variant="quiet" aria-label={`Remove ${s.trigger}`} onClick={() => void patch({ dictationSnippets: list.filter((x) => x !== s) })}>{t("Remove")}</Button>} />
            ))}
          </List>
        )}
      </Card>
      <Card>
        <form className="el-form" onSubmit={(e) => { e.preventDefault(); void add(); }}>
          <TextField label={t("Phrase")} value={trigger} maxLength={100} dir="auto" onChange={(e) => setTrigger(e.target.value)} />
          <TextArea label={t("Text to insert")} value={text} rows={3} maxLength={2000} dir="auto" onChange={(e) => setText(e.target.value)} />
          <div className="el-page__actions"><Button type="submit" disabled={!trigger.trim() || !text.trim()}>{t("Add snippet")}</Button></div>
        </form>
      </Card>
    </>
  );
}

/** The last 50 dictations, kept on this computer only. */
export function HistoryPageV2() {
  const [entries, setEntries] = useState<DictationHistoryEntry[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [copied, setCopied] = useState<number | null>(null);
  useEffect(() => {
    api.dictationHistory().then(setEntries, (e) => { setEntries([]); setError(errorMessage(e)); });
  }, []);
  const copy = async (i: number) => {
    try {
      await navigator.clipboard.writeText(entries![i].text);
      setCopied(i);
    } catch (e) {
      setError(errorMessage(e));
    }
  };
  const clear = async () => {
    if (!(await confirmAction({ title: t("Clear your recent dictations?"), body: t("They're deleted from this computer."), confirm: t("Clear"), tone: "danger" }))) return;
    try {
      await api.clearDictationHistory();
      setEntries([]);
    } catch (e) {
      setError(errorMessage(e));
    }
  };
  return (
    <>
      <Card>
        {entries && entries.length === 0 ? (
          <EmptyState icon={Icons.KeyboardIcon} title={t("Nothing dictated yet")} body={t("What you dictate appears here, so you can copy it if it went to the wrong place.")} />
        ) : (
          <List label={t("Recent dictations")} className="el-group__rows">
            {(entries ?? []).map((e, i) => (
              <ListRow key={`${e.at}-${i}`} compact title={e.text} meta={formatWhen(e.at)}
                control={<Button size="sm" variant="quiet" aria-label={copied === i ? t("Copied") : `Copy “${e.text.slice(0, 40)}”`} onClick={() => void copy(i)}>{copied === i ? t("Copied") : t("Copy")}</Button>} />
            ))}
          </List>
        )}
      </Card>
      {error && <p className="el-field__error" role="alert">{error}</p>}
      <p className="el-caption el-secondary">{t("The last 50, kept on this computer only.")}</p>
      {entries && entries.length > 0 && (
        <div className="el-settings-v2__reset"><Button variant="danger" icon={Icons.TrashIcon} onClick={() => void clear()}>{t("Clear history")}</Button></div>
      )}
    </>
  );
}
