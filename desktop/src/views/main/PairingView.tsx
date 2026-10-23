import { useState } from "react";
import { api, errorMessage, type AppStatus } from "../../lib/ipc";
import { ShieldIcon } from "../../components/Icons";

/** First run: pair with the owner's EchoLedger server using the one-time code (or pasted QR payload). */
export function PairingView({ onPaired, notify }: { onPaired: (s: AppStatus) => void; notify: (t: string, e?: boolean) => void }) {
  const [serverUrl, setServerUrl] = useState("https://");
  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [busy, setBusy] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    try {
      onPaired(await api.pair(serverUrl.trim(), code.trim(), name.trim()));
      notify("Paired. Your library will sync now.");
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
        <div className="brand" style={{ padding: "0 0 14px" }}><span className="brand-mark" />EchoLedger</div>
        <h1 className="page-title">Pair this computer</h1>
        <p className="page-sub">In your EchoLedger admin, open <b>Devices → Pair a device</b>. Enter the server address and the code (or paste the QR text).</p>
        {!isQr && (
          <label className="field">Server address
            <input className="input" value={serverUrl} onChange={(e) => setServerUrl(e.target.value)} placeholder="https://ledger.example.com" required />
          </label>
        )}
        <label className="field">Pairing code
          <input className="input" value={code} onChange={(e) => setCode(e.target.value)} placeholder="7KQ2-M9XD" autoFocus required />
        </label>
        <label className="field">Name for this computer (optional)
          <input className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="Work laptop" />
        </label>
        <button className="btn primary" type="submit" disabled={busy} style={{ width: "100%", justifyContent: "center" }}>
          {busy ? "Pairing…" : "Pair"}
        </button>
        <p className="small muted row" style={{ marginTop: 14 }}><ShieldIcon size={14} />The access token is stored in your OS keychain. Speech-provider keys never leave your server.</p>
      </form>
    </div>
  );
}
