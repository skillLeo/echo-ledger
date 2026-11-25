// Settings › Transcription & language in the new look, with today's data and rules (AppSettings.tsx, Upgrade.tsx):
// the language recordings are transcribed in, the project whose names and terms help the transcript, and the person's
// own Groq key on Free (add, replace, remove after asking).
import { useEffect, useState, type FormEvent } from "react";
import { api, errorMessage, type Entitlements, type Settings, type TranscriptionLanguage } from "../../../lib/ipc";
import { isLocked, refreshEntitlements } from "../../../lib/entitlements";
import { confirmAction } from "../../../lib/confirm";
import { LANGUAGES } from "../../../views/main/AppSettings";
import { GROQ_KEYS } from "../../../views/main/Upgrade";
import { Button, Card, Icons, ListRow, TextField } from "../../components";
import { Group, ResetDefaults, SectionTitle, SelectSetting, type Patch } from "./rows";
import { t } from "../../../i18n";

export function LanguagePageV2({ settings, patch }: { settings: Settings; patch: Patch }) {
  return (
    <>
      <Group footer={<p>{t("The language your recordings are transcribed in. Roman Urdu and English views are chosen on each recording.")}</p>}>
        <SelectSetting<"" | TranscriptionLanguage> id="transcription.language" value={(settings.transcriptionLanguage ?? "") as "" | TranscriptionLanguage}
          options={LANGUAGES.map(([value, label]) => ({ value, label }))} onChange={(v) => void patch({ transcriptionLanguage: v === "" ? null : v })} />
      </Group>
      <ResetDefaults page={t("Language")} onReset={() => void patch({ transcriptionLanguage: null })} />
    </>
  );
}

export function WordsPageV2({ settings, patch }: { settings: Settings; patch: Patch }) {
  const [clients, setClients] = useState<{ id: string; name: string }[]>([]);
  useEffect(() => {
    api.clients().then((r) => setClients(r.data)).catch(() => undefined);
  }, []);
  return (
    <>
      <Group footer={<p>{t("A project's names and terms help the transcript of recordings in that project.")}</p>}>
        {/* "None" sends clientId: null (clears it); a missing key would keep the stored project. */}
        <SelectSetting id="words.project" value={settings.clientId ?? ""} options={[{ value: "", label: t("None") }, ...clients.map((c) => ({ value: c.id, label: c.name }))]}
          onChange={(v) => void patch({ clientId: v || null })} />
      </Group>
      <ResetDefaults page={t("Custom words")} onReset={() => void patch({ clientId: null })} />
    </>
  );
}

/** The person's own Groq key: Free transcribes with it; a paid plan uses it only when chosen on a recording. */
export function KeyPageV2({ e }: { e: Entitlements }) {
  const key = e.transcription_key;
  const [value, setValue] = useState("");
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  useEffect(() => {
    if (!saved) return;
    const timer = window.setTimeout(() => setSaved(false), 4000);
    return () => window.clearTimeout(timer);
  }, [saved]);
  const save = async (ev: FormEvent) => {
    ev.preventDefault();
    setBusy(true);
    setProblem(null);
    try {
      await api.setTranscriptionKey(value);
      setValue("");
      setSaved(true);
      await refreshEntitlements();
    } catch (err) {
      setProblem(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };
  const remove = async () => {
    if (!(await confirmAction({ title: t("Remove your Groq key?"), body: t("New recordings won't be transcribed until you add a key or upgrade."), confirm: t("Remove"), tone: "danger" }))) return;
    setBusy(true);
    setProblem(null);
    try {
      await api.deleteTranscriptionKey();
      await refreshEntitlements();
    } catch (err) {
      setProblem(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };
  const paid = !isLocked(e, "platform_transcription");
  return (
    <div className="el-settings-v2" data-testid="transcription-key">
      {key?.set ? (
        <Group footer={paid ? <p>{t("Your plan transcribes for you. Your own key is used only if you choose it on a recording.")}</p> : undefined}>
          <ListRow compact anchor="key.saved" icon={Icons.LockIcon} iconStyle="plain" title={t("Saved key")} value={`Ending in ${key.last4 ?? "…"}`}
            control={<Button size="sm" variant="danger" disabled={busy} onClick={() => void remove()}>{t("Remove key")}</Button>} />
        </Group>
      ) : <p className="el-secondary">{t("Transcripts on Free use your own free Groq key. It takes about 2 minutes.")}</p>}
      <Card label={key?.set ? t("Replace the key") : t("Add your key")}>
        <div className="el-form">
          <SectionTitle className="el-body-strong">{key?.set ? t("Replace the key") : t("Add your key")}</SectionTitle>
          <ol className="el-steps-list" aria-label={t("Steps")}>
            <li className="el-steps-list__item">
              <span>Open <strong>{GROQ_KEYS}</strong> and sign in. It's free.</span>
              <Button size="sm" onClick={() => void api.openGroqConsole().catch((err) => setProblem(errorMessage(err)))}>Open {GROQ_KEYS}</Button>
            </li>
            <li className="el-steps-list__item"><span>Choose <strong>{t("API Keys")}</strong>, then <strong>{t("Create API key")}</strong>, and copy it.</span></li>
            <li className="el-steps-list__item"><span>Paste it here and choose <strong>{t("Save")}</strong>.</span></li>
          </ol>
          <form className="el-key-paste" onSubmit={(ev) => void save(ev)}>
            <TextField label={t("Groq key")} type="password" autoComplete="off" spellCheck={false} placeholder={t("gsk_…")} dir="ltr" value={value} onChange={(ev) => setValue(ev.target.value)} />
            <Button type="submit" variant="primary" disabled={value.trim().length < 20} loading={busy} loadingLabel={t("Checking…")}>{t("Save")}</Button>
          </form>
          {problem && <p className="el-field__error" role="alert">{problem}</p>}
          {saved && <p className="el-secondary" role="status">{t("Key saved. New recordings are transcribed with it.")}</p>}
        </div>
      </Card>
    </div>
  );
}
