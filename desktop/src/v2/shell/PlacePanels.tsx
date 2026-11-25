// The panels of the places other than the Library and Projects (PLAN_NAV_3_LEVELS §3): Live's parts, Ask's new
// question and its projects, Activity's All · Needs you · Done, and Settings' groups with a search. Each opens its
// page; the panel is only where the place's views are chosen (never the same thing at two levels).
import { useState, type ReactNode } from "react";
import { Icon, Icons, SearchField } from "../components";
import type { IconType } from "../components/Button";
import { GOAL_GROUPS, entryOf, groupOf, sectionFor } from "../settings/groups";
import { searchSettings } from "../../lib/settingsIndex";
import { SETTINGS, titleV2, type SettingEntry } from "../../lib/settingsRegistry";
import { t } from "../../i18n";

function Row({ icon, label, current, onClick, end, strong }: { icon?: IconType; label: string; current?: boolean; onClick: () => void; end?: ReactNode; strong?: boolean }) {
  return (
    <li>
      <button type="button" className="el-nav-item el-nav-item--row" aria-current={current ? "page" : undefined} onClick={onClick}>
        {icon && <Icon icon={icon} />}
        <span className="el-nav-item__label" dir="auto">{label}</span>
        {end !== undefined && end !== null && <>{" "}<span className={`el-nav-item__count el-caption el-num${strong ? " el-nav-item__count--strong" : " el-nav-item__count--quiet"}`}>{end}</span></>}
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

/**
 * Live: call recording On or Off (the Live page's Auto-record calls switch, UX-15: the same word for the same setting),
 * then Live Assist, Live Translate, Dictation and Captions on the Live page.
 */
export function LivePanel({ callRecording, onCalls, onPart, current }: {
  callRecording: boolean; onCalls: () => void; onPart: (part: "assist" | "translate" | "dictation" | "captions") => void; current?: string | null;
}) {
  return (
    <>
      <Group>
        <Row icon={Icons.PhoneIcon} label="Call recording" end={callRecording ? "On" : "Off"} onClick={onCalls} />
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

/**
 * Settings: a search over every setting (two letters or more: the matches, each opening its group's page scrolled to
 * it), else its groups; a group opens its page, with all its sections (UX-11: two levels, no inner list).
 */
export function SettingsPanel({ current, onOpen, available }: { current: string | null; onOpen: (id: string) => void; available: (entry: SettingEntry) => boolean }) {
  const [find, setFind] = useState("");
  const answer = find.trim().length >= 2 ? searchSettings(find, available) : null;
  const here = current ? groupOf(current) : null;
  // Setup & health is the rail's own (one home), so not a group here.
  const groups = GOAL_GROUPS.filter((g) => g.ids.some((id) => id !== "setup_health" && SETTINGS.some((e) => e.id === id && available(e))));
  return (
    <>
      <SearchField label={t("Search settings")} value={find} onChange={setFind} className="el-panel__search" />
      {answer ? (
        <Group title={t("Matches")}>
          {answer.settings.slice(0, 12).map((r) => <Row key={r.entry.id} label={titleV2(r.entry)} onClick={() => onOpen(sectionFor(r.entry))} />)}
          {answer.settings.length === 0 && <li className="el-caption el-secondary el-panel__none">{t("No setting matches")}</li>}
        </Group>
      ) : (
        <Group>
          {/* A group's English title is its id; its words are said in the window's language (desktop Urdu, D3). */}
          {groups.map((g) => <Row key={g.title} icon={g.icon} label={t(g.title)} current={here?.title === g.title} onClick={() => onOpen(entryOf(g))} />)}
        </Group>
      )}
    </>
  );
}
