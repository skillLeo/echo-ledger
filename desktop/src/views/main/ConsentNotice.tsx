// The recording-consent notice of the first-run setup and Settings → Privacy & consent (since v1.5), and the server's
// Terms and Privacy pages when the server has them (a v1.5 server, which sends a plan).
import { api } from "../../lib/ipc";
import { CONSENT_NOTICE, LEGAL_HINT, RECORDING_SHOWN } from "../../lib/consent";

export function ConsentNotice({ legal }: { legal: boolean }) {
  return (
    <div className="consent-notice" data-testid="consent-notice">
      <p className="small">{CONSENT_NOTICE} {RECORDING_SHOWN}</p>
      {legal && (
        <div className="row small">
          <span className="muted">{LEGAL_HINT}</span>
          <span className="spacer" />
          <button type="button" className="btn ghost compact" onClick={() => void api.openServerPage("terms").catch(() => undefined)}>Terms</button>
          <button type="button" className="btn ghost compact" onClick={() => void api.openServerPage("privacy").catch(() => undefined)}>Privacy</button>
        </div>
      )}
    </div>
  );
}
