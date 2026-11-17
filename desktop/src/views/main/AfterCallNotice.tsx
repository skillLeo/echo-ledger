// "After a call" changed (contract 1.8.0-e): v1.5's "Keep automatically" became Ask me, so a person who kept every call
// is told once, with Always transcribe one tap away. Closing it keeps Ask me; either answer clears it for good.
import { useState } from "react";
import { api, errorMessage } from "../../lib/ipc";
import { BellIcon } from "../../components/Icons";
import { StateBanner } from "../../components/StateBanner";
import type { Notify } from "../../lib/notice";

export function AfterCallNotice({ text, notify }: { text: string | null | undefined; notify: Notify }) {
  const [busy, setBusy] = useState(false);
  if (!text) return null;
  const answer = async (alwaysTranscribe: boolean) => {
    if (busy) return;
    setBusy(true);
    try {
      await api.afterCallNoticeAnswer(alwaysTranscribe);
      if (alwaysTranscribe) notify("Every call is now transcribed", false);
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setBusy(false);
    }
  };
  return (
    <StateBanner id="after-call" slot="after-call" state={text} tone="info" testId="after-call-notice" icon={<BellIcon size={16} />}
      onClose={() => void answer(false)}
      actions={<button type="button" className="btn compact" disabled={busy} onClick={() => void answer(true)}>Always transcribe</button>}>
      <span className="banner-text"><b>{text}</b></span>
    </StateBanner>
  );
}
