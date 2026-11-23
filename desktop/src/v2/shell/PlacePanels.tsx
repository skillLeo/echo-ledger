// The panels of the places other than the Library and Projects (PLAN_NAV_3_LEVELS §3): Live's parts, Ask's new
// question and its projects, Activity's All · Needs you · Done, and Settings' groups with a search. Each opens its
// page; the panel is only where the place's views are chosen (never the same thing at two levels).
import { useState, type ReactNode } from "react";
import { Icon, Icons, SearchField } from "../components";
import type { IconType } from "../components/Button";
import { GOAL_GROUPS } from "../settings/SettingsV2";

function Row({ icon, label, current, onClick, end, strong }: { icon?: IconType; label: string; current?: boolean; onClick: () => void; end?: ReactNode; strong?: boolean }) {
  return (
    <li>
      <button type="button" className="el-nav-item el-nav-item--row" aria-current={current ? "page" : undefined} onClick={onClick}>
        {icon && <Icon icon={icon} />}
        <span className="el-nav-item__label" dir="auto">{label}</span>
        {end !== undefined && end !== null && <span className={`el-nav-item__count el-caption el-num${strong ? " el-nav-item__count--strong" : " el-nav-item__count--quiet"}`}>{end}</span>}
      </button>
    </li>
  );
}
const Group = ({ title, children }: { title?: string; children: ReactNode }) => (
  <section className="el-nav-section" aria-label={title}>
    {title && <h3 className="el-nav-section__title el-caption el-panel__group-title">{title}</h3>}
    <ul className="el-nav-list" aria-label={title}>{children}</ul>
  </section>
);

/** Live: call recording (on or off), then Live Assist, Live Translate, Dictation and Captions on the Live page. */
export function LivePanel({ callRecording, onCalls, onPart, current }: {
  callRecording: boolean; onCalls: () => void; onPart: (part: "assist" | "translate" | "dictation" | "captions") => void; current?: string | null;
}) {
  return (
    <>
      <Group>
        <Row icon={Icons.PhoneIcon} label="Call recording" end={callRecording ? "Ready" : "Off"} onClick={onCalls} />
      </Group>
      <Group title="During a call">
        <Row icon={Icons.SparkleIcon} label="Live Assist" current={current === "assist"} onClick={() => onPart("assist")} />
        <Row icon={Icons.LanguagesIcon} label="Live Translate" current={current === "translate"} onClick={() => onPart("translate")} />
        <Row icon={Icons.MicIcon} label="Dictation" current={current === "dictation"} onClick={() => onPart("dictation")} />
        <Row icon={Icons.CaptionsIcon} label="Captions" current={current === "captions"} onClick={() => onPart("captions")} />
      </Group>
    </>
  );
}

/** Ask: a new question (across every project), or one about a project. */
export function AskPanel({ projects, current, onAsk }: { projects: readonly { id: string; name: string }[]; current: string | null; onAsk: (projectId: string | null) => void }) {
  const [find, setFind] = useState("");
  const q = find.trim().toLowerCase();
  const shown = q ? projects.filter((p) => p.name.toLowerCase().includes(q)) : projects;
  return (
    <>
      <Group>
        <Row icon={Icons.PlusIcon} label="New question" current={current === null} onClick={() => onAsk(null)} />
      </Group>
      {projects.length > 0 && (
        <Group title="About a project">
          {projects.length > 10 && <li><SearchField label="Search projects" value={find} onChange={setFind} className="el-panel__search" /></li>}
          {shown.map((p) => <Row key={p.id} icon={Icons.FolderIcon} label={p.name} current={current === p.id} onClick={() => onAsk(p.id)} />)}
        </Group>
      )}
    </>
  );
}

export type ActivityShow = "all" | "needs" | "done";
/** Activity: everything, what needs the person, what is done. */
export function ActivityPanel({ show, needs, onShow }: { show: ActivityShow; needs: number; onShow: (show: ActivityShow) => void }) {
  return (
    <Group title="Activity">
      <Row icon={Icons.BellIcon} label="All" current={show === "all"} onClick={() => onShow("all")} />
      <Row icon={Icons.ErrorIcon} label="Needs you" current={show === "needs"} onClick={() => onShow("needs")} end={needs || null} strong />
      <Row icon={Icons.CheckIcon} label="Done" current={show === "done"} onClick={() => onShow("done")} />
    </Group>
  );
}

/** Settings: a search, then its groups; a group opens its first page. */
export function SettingsPanel({ current, onOpen, available }: { current: string | null; onOpen: (id: string) => void; available?: (id: string) => boolean }) {
  const [find, setFind] = useState("");
  const q = find.trim().toLowerCase();
  const groups = GOAL_GROUPS.map((g) => ({ ...g, ids: g.ids.filter((id) => !available || available(id)) }))
    .filter((g) => g.ids.length > 0 && (!q || g.title.toLowerCase().includes(q) || g.description.toLowerCase().includes(q)));
  return (
    <>
      <SearchField label="Search settings" value={find} onChange={setFind} className="el-panel__search" />
      <Group>
        {groups.map((g) => <Row key={g.title} icon={g.icon} label={g.title} current={!!current && g.ids.includes(current)} onClick={() => onOpen(g.ids[0])} />)}
        {groups.length === 0 && <li className="el-caption el-secondary el-panel__none">No setting matches</li>}
      </Group>
    </>
  );
}
