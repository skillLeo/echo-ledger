// "After a call" changed (contract 1.8.0-e): a person whose calls were kept automatically is told once that each call
// now asks, with Always transcribe one press away. Closing it keeps Ask me; either answer clears it for good (the core's
// after_call_notice_answer). The same notice as today's banner, in the new shell.
import { useState } from "react";
import { api, errorMessage, type AppStatus } from "../../lib/ipc";
import { Banner, Button, Icons } from "../components";

export function AfterCallNoticeV2({ text, onStatus }: { text: string | null | undefined; onStatus: (status: AppStatus) => void }) {
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  if (done) return <Banner icon={Icons.CheckIcon} onClose={() => setDone(null)}>{done}</Banner>;
  if (!text) return null;
  const answer = async (alwaysTranscribe: boolean) => {
    if (busy) return;
    setBusy(true);
    setError(null);
    try {
      onStatus(await api.afterCallNoticeAnswer(alwaysTranscribe));
      if (alwaysTranscribe) setDone("Every call is now transcribed.");
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <Banner icon={Icons.BellIcon} testId="after-call-notice-v2" onClose={() => void answer(false)}
      action={<Button size="sm" loading={busy} loadingLabel="Saving…" onClick={() => void answer(true)}>Always transcribe</Button>}>
      <strong>{text}</strong>
      {error && <span className="el-field__error" role="alert">{error}</span>}
    </Banner>
  );
}
