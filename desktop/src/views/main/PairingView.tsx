import { useState } from "react";
import { api, errorMessage, type AppStatus } from "../../lib/ipc";
import { ShieldIcon } from "../../components/Icons";
import { Wordmark } from "../../components/BrandMark";

/**
 * Pair with an EchoLedger server using the one-time code (or pasted QR payload): servers older than accounts, and
 * "Sign in a device" in the console (since v1.5). `notice` says why the sign-in screen sent the person here;
 * `onUseAccount` goes back to it.
 */
export function PairingView({ onPaired, notify, notice, onUseAccount }: {
  onPaired: (s: AppStatus) => void;
  notify: (t: string, e?: boolean) => void;
  notice?: string | null;
  onUseAccount?: () => void;
}) {
  const [serverUrl, setServerUrl] = useState("https://");
  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [busy, setBusy] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    try {
      onPaired(await api.pair(serverUrl.trim(), code.trim(), name.trim()));
      notify("Signed in. Your library will sync now.");
    } catch (err) {
      notify(errorMessage(err), true);
    } finally {
      setBusy(false);
    }
  };

  const isQr = code.trim().startsWith("{");
  return (
    <div style={{ display: "grid", placeItems: "center", height: "100%", padding: 24 }}>
      <form className="card" style={{ width: 440 }} onSubmit={submit}>
        <Wordmark />
        <h1 className="page-title">Sign in with a code</h1>
        <p className="page-sub">In the EchoLedger console, open <b>Profile → Devices → Sign in a device</b> (on an older server, <b>Devices → Pair a device</b>). Enter the server address and the code (or paste the QR text).</p>
        {notice && <div className="notice small" role="status" style={{ marginBottom: 12 }}>{notice}</div>}
        {!isQr && (
          <label className="field">Server address
            <input className="input" value={serverUrl} onChange={(e) => setServerUrl(e.target.value)} placeholder="https://ledger.example.com" required />
          </label>
        )}
        <label className="field">Sign-in code
          <input className="input" value={code} onChange={(e) => setCode(e.target.value)} placeholder="7KQ2-M9XD" autoFocus required />
        </label>
        <label className="field">Name for this computer (optional)
          <input className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="Work laptop" />
        </label>
        <button className="btn primary" type="submit" disabled={busy} style={{ width: "100%", justifyContent: "center" }}>
          {busy ? "Signing in…" : "Sign in with code"}
        </button>
        {onUseAccount && <button type="button" className="btn ghost small sign-in-pair" onClick={onUseAccount}>Sign in with an account instead</button>}
        <p className="small muted row" style={{ marginTop: 14 }}><ShieldIcon size={14} />The access token is stored in your OS keychain. Speech-provider keys never leave your server.</p>
      </form>
    </div>
  );
}
