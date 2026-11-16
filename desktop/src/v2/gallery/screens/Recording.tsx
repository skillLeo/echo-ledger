import { useState } from "react";
import { Badge, Button, Card, Icons, Player, Select, Tabs, Transcript } from "../../components";
import { COPY, type Lang } from "../content";

type Tab = "transcript" | "summary" | "ask" | "moments" | "details";

/** Gate 3 (b): one recording: title, the player, the transcript with speakers, and its actions. */
export function RecordingScreen({ lang }: { lang: Lang }) {
  const t = COPY[lang].recording;
  const [tab, setTab] = useState<Tab>("transcript");
  const [playing, setPlaying] = useState(false);
  const [position, setPosition] = useState(38);
  const [speed, setSpeed] = useState("1");
  const active = t.segments.reduce((at, s, i) => (s.start <= position ? i : at), 0);

  return (
    <div className="g-page">
      <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="g-back">{t.back}</Button>
      <header className="g-header g-header--recording">
        <div className="g-header__titles">
          <h1 className="el-title-l" dir="auto">{t.title}</h1>
          <p className="g-meta el-secondary">
            <span dir="auto"><span className="g-meta__source"><Icons.SourceAppIcon app="whatsapp" /></span>{t.meta}</span>
            <Badge tone="success">{t.status}</Badge>
          </p>
        </div>
        <div className="g-header__actions">
          <Button icon={Icons.ShareIcon}>{t.share}</Button>
          <Button icon={Icons.FolderIcon}>{t.move}</Button>
          <Button variant="danger" icon={Icons.TrashIcon}>{t.remove}</Button>
        </div>
      </header>
      <Card className="g-player">
        <Player playing={playing} position={position} duration={1104} onToggle={() => setPlaying(!playing)} onSeek={setPosition}
          labels={{ play: t.play, pause: t.pause, seek: t.seek, of: t.of }}
          end={<Select label={t.speed} hideLabel value={speed} onChange={setSpeed} className="g-speed"
            options={["1", "1.25", "1.5", "2"].map((v) => ({ value: v, label: `${v}×`, dir: "ltr" as const }))} />} />
      </Card>
      <Tabs label={t.tabsLabel} value={tab} onChange={setTab}
        tabs={[
          { value: "transcript", label: t.tabs.transcript }, { value: "summary", label: t.tabs.summary },
          { value: "ask", label: t.tabs.ask }, { value: "moments", label: t.tabs.moments }, { value: "details", label: t.tabs.details },
        ]}>
        {tab === "transcript" && <Transcript label={t.transcriptLabel} segments={t.segments} activeIndex={active} lang={lang} />}
      </Tabs>
    </div>
  );
}
