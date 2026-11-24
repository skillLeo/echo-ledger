// A recording's row in the Library list (docs/PLAN_V2_1_POLISH.md UX-06 + DES-04): 56 px (44 px compact), a 32 px mark
// (a caller's initials on a tinted circle, a voice note's mic, a meeting's calendar) with the app's 14 px badge, then two
// lines: who or what and when; the first words or the state in the glossary's words, and how long ("2m 38s"). No device
// badge, no pin, no coloured chip but Failed · Retry; a meeting's screens mark stays (the project and the bookmarks are
// the Table's, the recording's and the filters'). On hover or keyboard focus the end side shows the row's actions
// (Transcribe for Audio only · Pin · Label · ⋯) in place of when and how long; the row's height doesn't change.
import type { MouseEvent as ReactMouseEvent, PointerEvent as ReactPointerEvent, ReactNode } from "react";
import { useId } from "react";
import type { LibraryItem } from "../../lib/ipc";
import { hasSourceLogo, SourceLogo } from "../../components/SourceLogos";
import { Badge, Icon, Icons, LabelDot, PickBox } from "../components";
import type { RowModel } from "./model";
import { sourceWords } from "./SourceAvatar";
import { ScreensMark } from "./screens";

const AVATAR_ICONS = { mic: Icons.MicIcon, calendar: Icons.CalendarIcon, person: Icons.UserIcon, file: Icons.WaveIcon } as const;

/** Line 2's words: the first words, or the state (grey; only Failed · Retry is in the danger colour, with its icon). */
export function RowMetaText({ r }: { r: Pick<RowModel, "meta" | "state" | "converting"> }) {
  const tone = r.state?.tone === "danger" ? " el-rec-state--danger" : r.state ? " el-rec-state--quiet" : "";
  return (
    <span className={`el-rec-state${tone}`} data-state={r.state?.key} data-testid={r.converting === "making" ? "row-converting" : undefined}>
      {r.state?.key === "failed" && <Icon icon={Icons.RefreshIcon} />}
      {r.converting === "making" && <span className="el-spinner" aria-hidden="true" />}
      <span dir="auto">{r.meta}</span>
    </span>
  );
}

/** The row's mark: initials or an icon on a tinted circle, the app's badge on it (named together: "WhatsApp, on a phone"). */
export function RowAvatar({ item, r }: { item: Pick<LibraryItem, "source_app" | "source_app_label" | "source_device">; r: Pick<RowModel, "avatar" | "app"> }) {
  return (
    <span className={`el-rec-avatar el-rec-avatar--tint-${r.avatar.tint}`} role="img" aria-label={sourceWords(item)}>
      {r.avatar.initials ? <span className="el-rec-avatar__initials" aria-hidden="true">{r.avatar.initials}</span>
        : <Icon icon={AVATAR_ICONS[r.avatar.icon]} />}
      <span className="el-rec-avatar__badge" aria-hidden="true">
        {hasSourceLogo(r.app) ? <SourceLogo app={r.app} size={14} />
          : <Icon icon={(p) => <Icons.SourceAppIcon app={r.app} {...p} />} />}
      </span>
    </span>
  );
}

export type RecordingRowProps = {
  item: LibraryItem;
  r: RowModel;
  compact: boolean;
  /** Selection mode: the box shows on every row, and a click ticks the row. */
  choosing: boolean;
  picked: boolean;
  labels: readonly { name: string; key: string; colour: string | null }[];
  onLabel: (key: string) => void;
  recovered: ReactNode;
  onClick: (e: ReactMouseEvent<HTMLButtonElement>) => void;
  onPointerDown: (e: ReactPointerEvent<HTMLButtonElement>) => void;
  onContextMenu: (e: ReactMouseEvent<HTMLElement>) => void;
  onPick: (e: ReactMouseEvent<HTMLElement>) => void;
  /** The hover actions (in place of when and how long), the ⋯ last. */
  actions?: ReactNode;
  /** The recording you came back from (UX-25): a quiet tint, not the selection's. */
  last?: boolean;
};

export function RecordingRow({ item, r, compact, choosing, picked, labels, onLabel, recovered, onClick, onPointerDown, onContextMenu, onPick, actions, last = false }: RecordingRowProps) {
  const id = useId();
  // Said with the row, though not all shown: pinned (its section says so), every label (up to three dots show), Recovered.
  const marks = [item.pinned ? "Pinned" : null, ...labels.map((l) => l.name)].filter(Boolean).join(", ");
  const described = [`${id}-meta`, marks && `${id}-marks`, recovered && `${id}-recovered`, `${id}-when`, `${id}-length`].filter(Boolean).join(" ");
  const cls = ["el-rec", compact && "el-rec--compact", choosing && "el-rec--picking", picked && "el-rec--selected", last && !picked && "el-rec--last", actions && "el-rec--actions"].filter(Boolean).join(" ");
  return (
    <li className={cls} onContextMenu={(e) => { e.preventDefault(); onContextMenu(e); }}>
      <button type="button" className="el-rec__hit" aria-labelledby={`${id}-title`} aria-describedby={described}
        aria-pressed={choosing ? picked : undefined} onClick={onClick} onPointerDown={onPointerDown} data-row-id={item.id}
        onKeyDown={(e) => { if (e.key === "ContextMenu" || (e.shiftKey && e.key === "F10")) { e.preventDefault(); onContextMenu(e as unknown as ReactMouseEvent<HTMLElement>); } }} />
      <span className="el-rec__pick">
        <PickBox checked={picked} label={`Select ${r.title}`} onClick={(e) => onPick(e)} />
      </span>
      {/* The mark only says who or what (a label dot still filters; Source is Filters', the kind tabs' and ⌘K's). */}
      <span className="el-rec__mark"><RowAvatar item={item} r={r} /></span>
      <span className="el-rec__main">
        <span className="el-rec__line">
          <span className="el-rec__title" id={`${id}-title`} dir="auto">{r.title}</span>
          {labels.slice(0, 3).map((l) => (
            <button key={l.key} type="button" className="el-rec__label" title={`Show only ${l.name}`} aria-label={`Show only label ${l.name}`} onClick={() => onLabel(l.key)}>
              <LabelDot name={l.name} colour={l.colour} decorative />
            </button>
          ))}
          {marks && <span className="el-visually-hidden" id={`${id}-marks`}>{marks}</span>}
          {recovered && <span className="el-rec__recovered" id={`${id}-recovered`}>{recovered}</span>}
          <span className="el-rec__end" id={`${id}-when`}><bdi>{r.when}</bdi></span>
        </span>
        <span className="el-rec__line el-rec__line--2 el-caption">
          <span className="el-rec__meta" id={`${id}-meta`}>{r.screens > 0 && <><ScreensMark count={r.screens} />{" "}</>}<RowMetaText r={r} /></span>
          <span className="el-rec__end" id={`${id}-length`}><bdi>{r.duration}</bdi></span>
        </span>
      </span>
      {actions && <span className="el-rec__actions">{actions}</span>}
    </li>
  );
}

/** A Badge for the rare chip a row still has (Recovered), as other lists show it. */
export const RowChip = ({ children }: { children: ReactNode }) => <Badge tone="accent">{children}</Badge>;
