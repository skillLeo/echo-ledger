// After the v2.0 update, once (Rust `privacy::share::SHARE_NOTICE`): the main window now shows in screen shares unless
// the person hides it. Hide opens the duration list; closing it, or choosing a duration, clears it for good.
import { useState } from "react";
import { api, errorMessage } from "../../lib/ipc";
import { usePrivacyStatus } from "../../lib/privacy";
import { LucideEyeIcon } from "../../components/Icons";
import { StateBanner } from "../../components/StateBanner";
import { HideFromSharesButton } from "../../components/ShareVisibility";

export function ShareDefaultNotice({ text }: { text: string | null | undefined }) {
  const status = usePrivacyStatus();
  const [gone, setGone] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  if (!text || gone) return null;
  const close = () => void api.shareNoticeDismiss().then(() => setGone(true)).catch((e) => setProblem(errorMessage(e)));
  return (
    <StateBanner id="share-default" slot="share-default" state={text} tone="info" testId="share-default-notice" icon={<LucideEyeIcon size={16} />}
      onClose={close}
      actions={<HideFromSharesButton label="Hide ▸" callDetected={!!status?.callDetected} onChosen={() => setGone(true)} />}>
      <span className="banner-text"><b>{text}</b></span>
      {problem && <span className="privacy-line warning" role="alert">{problem}</span>}
    </StateBanner>
  );
}
