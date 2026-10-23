import { useEffect, useState } from "react";
import { listen } from "@tauri-apps/api/event";
import { subscribeCaptions } from "../../lib/ipc";

/** Tiny unfocusable dictation box: shows what you say; text is typed into your app when you finish. */
export default function DictationApp() {
  const [visible, setVisible] = useState(false);
  const [finals, setFinals] = useState<string[]>([]);
  const [partial, setPartial] = useState("");
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    subscribeCaptions((e) => {
      if (e.kind === "clear") {
        setFinals([]);
        setPartial("");
        setMessage(null);
      } else if (e.kind === "partial") setPartial(e.text);
      else if (e.kind === "final") {
        setFinals((f) => [...f, e.text]);
        setPartial("");
      } else if (e.kind === "status") setMessage(e.message);
    }).catch(() => undefined);
    const un = listen<boolean>("overlay:visibility", (e) => setVisible(e.payload));
    return () => {
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);

  return (
    <div className={`overlay-root ${visible ? "" : "overlay-hidden"}`} style={{ alignItems: "flex-start" }}>
      <div className="overlay-card dictation-card">
        <div className="overlay-head">
          <span className="listening"><span /><span /><span /></span>
          <span>Dictating — press the shortcut again to insert</span>
        </div>
        {message && <div className="caption-line small" style={{ color: "#fda4af" }}>{message}</div>}
        <div className="caption-line">{finals.join(" ")} <span className="partial" style={{ opacity: 0.7 }}>{partial}</span></div>
      </div>
    </div>
  );
}
