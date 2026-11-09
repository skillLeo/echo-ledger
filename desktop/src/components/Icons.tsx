// The app's one icon family (docs/DESIGN_SYSTEM.md "Icons", since v1.5 the same metaphors as the phone and the console):
// line glyphs on a 24-unit grid with one stroke (ICON_STROKE), round caps and joins, inline SVG (no icon font or CDN).
// Outlined at rest; `filled` (the sidebar's pages) or on a tinted tile (app tiles, selected rows) when active or
// selected. Small dots and media controls are filled shapes, never another stroke. ControlIcons.tsx draws the
// recording controls on the same base; Icons.test.tsx keeps the rule.
import type { ReactNode, SVGProps } from "react";

export type IconProps = SVGProps<SVGSVGElement> & { size?: number };
/** A page or a place that has a filled form for when it is active or selected. */
type StateIconProps = IconProps & { filled?: boolean };

/** One stroke width for every icon in the app (C1); the control icons use the same value. */
export const ICON_STROKE = 1.75;

/** The base of every icon: the 24-unit grid, the one stroke, round ends, hidden from assistive tools. */
export function IconSvg({ size = 18, children, ...rest }: IconProps & { children: ReactNode }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={ICON_STROKE}
      strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false" {...rest}>
      {children}
    </svg>
  );
}
const Svg = IconSvg;
/** A filled dot (the "more" dots, a grip, the palette's colours): a shape, not a thicker stroke. */
const Dot = ({ x, y, r = 1.3 }: { x: number; y: number; r?: number }) => <circle cx={x} cy={y} r={r} fill="currentColor" stroke="none" />;
const fill = (filled?: boolean) => (filled ? "currentColor" : "none");

/** A phone (the device a recording came from). */
export const PhoneIcon = (p: IconProps) => (
  <Svg {...p}><rect x="7" y="2.5" width="10" height="19" rx="2.2" /><path d="M11 18.5h2" /></Svg>
);
/** This computer or another desktop (a laptop, so it never reads as Screens' monitor). */
export const DesktopIcon = (p: IconProps) => (
  <Svg {...p}><rect x="4.5" y="5" width="15" height="10.5" rx="1.5" /><path d="M2.5 19h19" /></Svg>
);
export const GlobeIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3z" /></Svg>
);
export const ChatIcon = (p: IconProps) => (
  <Svg {...p}><path d="M20.5 11.5a8.5 8.5 0 0 1-12.4 7.5L3.5 20l1.1-4.2A8.5 8.5 0 1 1 20.5 11.5z" /></Svg>
);
export const StoreIcon = (p: IconProps) => (
  <Svg {...p}><path d="M4 9.5V20h16V9.5M3 9.5l1.6-5h14.8l1.6 5a2.6 2.6 0 0 1-5.1.6 2.6 2.6 0 0 1-5.1 0 2.6 2.6 0 0 1-5.1 0A2.6 2.6 0 0 1 3 9.5zM10 20v-5h4v5" /></Svg>
);
export const VideoIcon = (p: IconProps) => (
  <Svg {...p}><rect x="3" y="6" width="12.5" height="12" rx="2" /><path d="M15.5 10.5 21 7v10l-5.5-3.5" /></Svg>
);
/** The microphone: voice notes and what uses the microphone. */
export const MicIcon = (p: IconProps) => (
  <Svg {...p}><rect x="9" y="2.5" width="6" height="12" rx="3" /><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21" /></Svg>
);
/** Any other app. */
export const DotsIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="12" cy="12" r="9" /><Dot x={8} y={12} r={1.1} /><Dot x={12} y={12} r={1.1} /><Dot x={16} y={12} r={1.1} /></Svg>
);
export const CaptionsIcon = (p: IconProps) => (
  <Svg {...p}><rect x="3" y="5" width="18" height="14" rx="2.5" /><path d="M7 11h3M12 11h5M7 15h7" /></Svg>
);
const GEAR = "M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z";
/** Settings: the gear, the one Settings glyph (General's sliders are TuneIcon). */
export const SettingsIcon = ({ filled, ...p }: StateIconProps) => (
  <Svg {...p}>
    {filled
      ? <path d={`${GEAR}M12 8.6a3.4 3.4 0 1 0 0 6.8 3.4 3.4 0 1 0 0-6.8z`} fill="currentColor" fillRule="evenodd" />
      : <><circle cx="12" cy="12" r="3" /><path d={GEAR} /></>}
  </Svg>
);
/** The Library: a video library (the phone's), the recordings in front of their stack, the play mark inside. */
export const LibraryIcon = ({ filled, ...p }: StateIconProps) => (
  <Svg {...p}>
    <rect x="7.5" y="3.5" width="13" height="13" rx="2.5" />
    {filled
      ? <path d="M10 3.5h8A2.5 2.5 0 0 1 20.5 6v8a2.5 2.5 0 0 1-2.5 2.5h-8A2.5 2.5 0 0 1 7.5 14V6A2.5 2.5 0 0 1 10 3.5zM12.3 7.2v5.6l4.5-2.8z" fill="currentColor" fillRule="evenodd" stroke="none" />
      : <path d="M12.5 7.6v4.8l4-2.4-4-2.4z" />}
    <path d="M3.5 8v10A2.5 2.5 0 0 0 6 20.5h10" />
  </Svg>
);
/** Recording: the record dot inside its ring (the record control itself shows the dot alone, RecordDotIcon). */
export const RecordIcon = ({ filled, ...p }: StateIconProps) => (
  <Svg {...p}><circle cx="12" cy="12" r="8.5" /><Dot x={12} y={12} r={filled ? 5 : 3.5} /></Svg>
);
export const RefreshIcon = (p: IconProps) => (
  <Svg {...p}><path d="M20 11a8 8 0 0 0-14.3-4.9L4 8M4 4v4h4M4 13a8 8 0 0 0 14.3 4.9L20 16M20 20v-4h-4" /></Svg>
);
export const CloseIcon = (p: IconProps) => (
  <Svg {...p}><path d="M6 6l12 12M18 6 6 18" /></Svg>
);
export const GripIcon = (p: IconProps) => (
  <Svg {...p}><Dot x={9} y={6} /><Dot x={15} y={6} /><Dot x={9} y={12} /><Dot x={15} y={12} /><Dot x={9} y={18} /><Dot x={15} y={18} /></Svg>
);
export const CopyIcon = (p: IconProps) => (
  <Svg {...p}><rect x="8" y="8" width="12" height="12" rx="2" /><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2" /></Svg>
);
export const TrashIcon = (p: IconProps) => (
  <Svg {...p}><path d="M4 7h16M10 11v6M14 11v6M5 7l1 13h12l1-13M9 7V4h6v3" /></Svg>
);
/** A paid feature the plan leaves out (v1.6): the small lock on its badge. */
export const LockIcon = (p: IconProps) => (
  <Svg {...p}><rect x="5" y="10.5" width="14" height="10" rx="2" /><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3" /></Svg>
);
/** Your own transcription key (v1.6). */
export const KeyIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="8" cy="15" r="4.5" /><path d="m11.2 11.8 8.3-8.3M16.5 6.5l2.5 2.5M14 9l2 2" /></Svg>
);
export const ShieldIcon = (p: IconProps) => (
  <Svg {...p}><path d="M12 3 4.5 6v6c0 4.5 3.2 8.2 7.5 9 4.3-.8 7.5-4.5 7.5-9V6L12 3z" /><path d="m9 12 2 2 4-4" /></Svg>
);
export const SearchIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="11" cy="11" r="6.5" /><path d="m20 20-4.2-4.2" /></Svg>
);
export const SunIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="12" cy="12" r="4" /><path d="M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M18.7 5.3l-1.6 1.6M6.9 17.1l-1.6 1.6" /></Svg>
);
export const MoonIcon = (p: IconProps) => (
  <Svg {...p}><path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z" /></Svg>
);
/** Screens (screen moments) and the system theme: a monitor on its stand. */
export const MonitorIcon = (p: IconProps) => (
  <Svg {...p}><rect x="3" y="4" width="18" height="12" rx="1.8" /><path d="M8 20h8M12 16v4" /></Svg>
);
export const AlertIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="12" cy="12" r="9" /><path d="M12 7.5v5.5M12 16.5h.01" /></Svg>
);
export const ClockIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></Svg>
);
/** Import: a file going up from this computer (the phone's upload arrow). */
export const ImportIcon = (p: IconProps) => (
  <Svg {...p}><path d="M12 15V4M7.5 8.5 12 4l4.5 4.5M5 19.5h14" /></Svg>
);
/** Export and save to this computer, and the Imported folder: the download arrow (the phone's). */
export const DownloadIcon = (p: IconProps) => (
  <Svg {...p}><path d="M12 4v11M7.5 10.5 12 15l4.5-4.5M5 19.5h14" /></Svg>
);
/** Request files: the tray with an arrow going in. */
export const RequestFilesIcon = (p: IconProps) => (
  <Svg {...p}><path d="M3.5 13.5V18A1.5 1.5 0 0 0 5 19.5h14a1.5 1.5 0 0 0 1.5-1.5v-4.5M3.5 13.5 5 9M20.5 13.5 19 9M3.5 13.5h4.8l1.4 2.3h4.6l1.4-2.3h4.8" /><path d="M12 3v8M8.8 7.8 12 11l3.2-3.2" /></Svg>
);

export function SourceDeviceIcon({ source, ...p }: IconProps & { source?: string }) {
  if (source === "phone") return <PhoneIcon {...p} />;
  if (source === "desktop") return <DesktopIcon {...p} />;
  return <GlobeIcon {...p} />;
}

export function SourceAppIcon({ app, ...p }: IconProps & { app?: string }) {
  switch (app) {
    case "whatsapp":
    case "telegram":
    case "signal":
    case "messenger":
    case "instagram":
    case "slack":
    case "discord": return <ChatIcon {...p} />;
    case "whatsapp_business": return <StoreIcon {...p} />;
    case "whatsapp_web": return <GlobeIcon {...p} />;
    case "google_meet":
    case "zoom":
    case "skype":
    case "microsoft_teams": return <VideoIcon {...p} />;
    case "phone_call": return <CallIcon {...p} />;
    case "voice_note": return <MicIcon {...p} />;
    default: return <DotsIcon {...p} />;
  }
}
/** Activity: the bell. */
export const BellIcon = ({ filled, ...p }: StateIconProps) => (
  <Svg {...p}><path d="M6 9.5a6 6 0 1 1 12 0c0 4.2 1.6 6.1 2.3 6.8a.4.4 0 0 1-.3.7H4a.4.4 0 0 1-.3-.7C4.4 15.6 6 13.7 6 9.5z" fill={fill(filled)} /><path d="M10 20.2a2.2 2.2 0 0 0 4 0" /></Svg>
);
/** People: Shared with me, a project's people. */
export const UsersIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="9" cy="8.5" r="3.5" /><path d="M2.5 20a6.5 6.5 0 0 1 13 0M15.5 5.2a3.5 3.5 0 0 1 0 6.6M18 14.2a6.5 6.5 0 0 1 3.5 5.8" /></Svg>
);
/** Settings → Account (v1.5): one person. */
export const UserIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="12" cy="8.5" r="3.8" /><path d="M4.5 20.5a7.5 7.5 0 0 1 15 0" /></Svg>
);
/** The sidebar's collapse / expand button: a panel with its left side marked. */
export const SidebarIcon = (p: IconProps) => (
  <Svg {...p}><rect x="3" y="4.5" width="18" height="15" rx="2.5" /><path d="M9 4.5v15" /></Svg>
);
export const PinIcon = (p: IconProps) => (
  <Svg {...p}><path d="M9 4h6M10 4v5.5L7 13v1.5h10V13l-3-3.5V4M12 14.5V20" /></Svg>
);
export const PlusIcon = (p: IconProps) => (
  <Svg {...p}><path d="M12 5v14M5 12h14" /></Svg>
);
export const FileIcon = (p: IconProps) => (
  <Svg {...p}><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5z" /><path d="M14 3v5h5M9 13h6M9 17h4" /></Svg>
);
/** Made by AI (brief, notes, answers). */
export const SparkleIcon = (p: IconProps) => (
  <Svg {...p}><path d="M12 3.5 13.8 9a2 2 0 0 0 1.2 1.2l5.5 1.8-5.5 1.8a2 2 0 0 0-1.2 1.2L12 20.5 10.2 15a2 2 0 0 0-1.2-1.2L3.5 12 9 10.2A2 2 0 0 0 10.2 9L12 3.5z" /></Svg>
);
export const ChevronRightIcon = (p: IconProps) => (
  <Svg {...p}><path d="m9 6 6 6-6 6" /></Svg>
);
/** Home (v1.6): recording, what needs you and the latest recordings. */
export const HomeIcon = ({ filled, ...p }: StateIconProps) => (
  <Svg {...p}><path d="M3.5 10.5 12 3.8l8.5 6.7V19a1.5 1.5 0 0 1-1.5 1.5h-4.2v-6h-5.6v6H5A1.5 1.5 0 0 1 3.5 19z" fill={fill(filled)} /></Svg>
);
/** Help & shortcuts (v1.6): a question in a circle. */
export const HelpIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="12" cy="12" r="9" /><path d="M9.6 9.3a2.5 2.5 0 0 1 4.8.9c0 1.7-2.4 2.2-2.4 3.8" /><Dot x={12} y={17} r={1.1} /></Svg>
);
/** Shared (v1.6): an arrow leaving a box. */
export const ShareIcon = ({ filled, ...p }: StateIconProps) => (
  <Svg {...p}><path d="M8 10H6.5A1.5 1.5 0 0 0 5 11.5v7A1.5 1.5 0 0 0 6.5 20h11a1.5 1.5 0 0 0 1.5-1.5v-7a1.5 1.5 0 0 0-1.5-1.5H16" fill={fill(filled)} /><path d="M12 14V3.5M8.5 7 12 3.5 15.5 7" /></Svg>
);
/** The Library as rows (v1.6). */
export const ListIcon = (p: IconProps) => (
  <Svg {...p}><path d="M9 6.5h11M9 12h11M9 17.5h11" /><Dot x={4.8} y={6.5} /><Dot x={4.8} y={12} /><Dot x={4.8} y={17.5} /></Svg>
);
/** The Library as a table (v1.6). */
export const TableIcon = (p: IconProps) => (
  <Svg {...p}><rect x="3.5" y="4.5" width="17" height="15" rx="2" /><path d="M3.5 9.5h17M3.5 14.5h17M9.5 9.5v10" /></Svg>
);
/** Columns of the table (v1.6). */
export const ColumnsIcon = (p: IconProps) => (
  <Svg {...p}><rect x="3.5" y="4.5" width="17" height="15" rx="2" /><path d="M9.2 4.5v15M14.8 4.5v15" /></Svg>
);
/** Signing out (v1.6 avatar menu). */
export const SignOutIcon = (p: IconProps) => (
  <Svg {...p}><path d="M14 4.5H6.5a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2H14M10.5 12h10M17 8.5l3.5 3.5-3.5 3.5" /></Svg>
);
/** Opens in the browser (v1.6): a box with an arrow out of its corner. */
export const ExternalIcon = (p: IconProps) => (
  <Svg {...p}><path d="M13.5 4.5h6v6M19.5 4.5 11 13M17.5 14v4a1.5 1.5 0 0 1-1.5 1.5H6A1.5 1.5 0 0 1 4.5 18V8A1.5 1.5 0 0 1 6 6.5h4" /></Svg>
);
/** A project (a folder, since v1.5). */
export const FolderIcon = ({ filled, ...p }: StateIconProps) => (
  <Svg {...p}><path d="M3.5 7a2 2 0 0 1 2-2h3.8l2 2.2h7.2a2 2 0 0 1 2 2V17a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2V7z" fill={fill(filled)} /></Svg>
);
/** The Inbox: recordings in no project (a tray). */
export const InboxIcon = (p: IconProps) => (
  <Svg {...p}><path d="M3.5 13.5 6.2 5.5h11.6l2.7 8M3.5 13.5V18a1.5 1.5 0 0 0 1.5 1.5h14a1.5 1.5 0 0 0 1.5-1.5v-4.5M3.5 13.5h4.8l1.4 2.3h4.6l1.4-2.3h4.8" /></Svg>
);
/** Share, and a share or request link: the link. */
export const LinkIcon = (p: IconProps) => (
  <Svg {...p}><path d="M10.2 13.8a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1.1 1.1M13.8 10.2a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1.1-1.1" /></Svg>
);
export const HourglassIcon = (p: IconProps) => (
  <Svg {...p}><path d="M6.5 3h11M6.5 21h11M8 3v3.5a4 4 0 0 0 1.6 3.2L12 11.5l2.4-1.8A4 4 0 0 0 16 6.5V3M8 21v-3.5a4 4 0 0 1 1.6-3.2l2.4-1.8 2.4 1.8a4 4 0 0 1 1.6 3.2V21" /></Svg>
);
/** Filters and Settings → General (the phone's "tune" glyph). */
export const TuneIcon = (p: IconProps) => (
  <Svg {...p}><path d="M4 7h9M17 7h3M4 17h3M11 17h9M15 5v4M9 15v4" /></Svg>
);
/** Send to a teammate: the paper plane. */
export const SendIcon = (p: IconProps) => (
  <Svg {...p}><path d="M4.5 12 20 4.5 15.5 20l-3.2-6.3L4.5 12zM12.3 13.7 20 4.5" /></Svg>
);
export const KeyboardIcon = (p: IconProps) => (
  <Svg {...p}><rect x="2.5" y="6" width="19" height="12" rx="2.5" /><path d="M6.5 10h.01M10 10h.01M14 10h.01M17.5 10h.01M8 14h8" /></Svg>
);
/** Calls: the phone handset (calls and meetings, a SIM phone call). */
export const CallIcon = (p: IconProps) => (
  <Svg {...p}><path d="M5 4h3.2l1.6 4-2 1.3a10.5 10.5 0 0 0 4.9 4.9l1.3-2 4 1.6V17a2 2 0 0 1-2.2 2A15 15 0 0 1 3 6.2 2 2 0 0 1 5 4z" /></Svg>
);
export const PaletteIcon = (p: IconProps) => (
  <Svg {...p}><path d="M12 3a9 9 0 1 0 0 18c1.1 0 1.6-.8 1.6-1.6 0-.5-.2-.9-.5-1.3-.3-.4-.5-.8-.5-1.3 0-.9.7-1.6 1.6-1.6H16a5 5 0 0 0 5-5C21 6.4 17 3 12 3z" /><Dot x={7.5} y={11.5} /><Dot x={10} y={7.5} /><Dot x={14.5} y={7.5} /></Svg>
);
/** Back or forward 10 seconds: a round arrow with the number inside. */
export function SkipIcon({ back, ...p }: IconProps & { back?: boolean }) {
  return (
    <Svg {...p}>
      <path d={back ? "M5.5 8.5A8 8 0 1 1 4 13" : "M18.5 8.5A8 8 0 1 0 20 13"} />
      <path d={back ? "M5.5 4v4.5H10" : "M18.5 4v4.5H14"} />
      <text x="12" y="15.8" textAnchor="middle" fontSize="7.5" fontWeight="700" fill="currentColor" stroke="none" fontFamily="Inter, sans-serif">10</text>
    </Svg>
  );
}

/** Which tile colour an app gets: video calls, chats, voice notes, phone calls and the rest. */
export function appKind(app?: string): "video" | "chat" | "voice" | "phone" | "other" {
  switch (app) {
    case "google_meet":
    case "zoom":
    case "skype":
    case "microsoft_teams": return "video";
    case "whatsapp":
    case "whatsapp_business":
    case "whatsapp_web":
    case "telegram":
    case "signal":
    case "messenger":
    case "instagram":
    case "slack":
    case "discord": return "chat";
    case "voice_note": return "voice";
    case "phone_call": return "phone";
    default: return "other";
  }
}

/** The glyph of a row's app tile: the app's line icon on the tile's tint (the phone's RecordingRow, a drawn glyph). */
export function AppTileIcon({ app, size = 22, ...rest }: IconProps & { app?: string }) {
  return <SourceAppIcon app={app} size={size} {...rest} />;
}

/** The small device badge on a tile: laptop, phone or web. */
export function DeviceBadgeIcon({ source, size = 12 }: { source?: string; size?: number }) {
  return <SourceDeviceIcon source={source} size={size} />;
}

// ---- Settings (v1.7): Lucide's outline glyphs (ISC licence, lucide.dev) on the same base ---------------------------
// One family, one weight, one neutral colour (currentColor, which the rows set to the muted text), on the root rows and
// the left list only, never on a tile.

const L = (paths: string[], extra?: (p: IconProps) => ReactNode) => (p: IconProps) => (
  <Svg {...p}>{paths.map((d) => <path key={d} d={d} />)}{extra?.(p)}</Svg>
);
const dot = (cx: number, cy: number) => <circle key={`${cx}-${cy}`} cx={cx} cy={cy} r={0.5} fill="currentColor" />;

export const LucidePhoneIcon = L(["M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"]);
export const LucideMicIcon = L(["M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z", "M19 10v2a7 7 0 0 1-14 0v-2", "M12 19v3"]);
export const LucideCaptionsIcon = (p: IconProps) => (
  <Svg {...p}><rect width="18" height="14" x="3" y="5" rx="2" ry="2" /><path d="M7 15h4M15 15h2M7 11h2M13 11h4" /></Svg>
);
export const LucideMonitorIcon = (p: IconProps) => (
  <Svg {...p}><rect width="20" height="14" x="2" y="3" rx="2" /><path d="M8 21h8" /><path d="M12 17v4" /></Svg>
);
export const LucideAudioLinesIcon = L(["M2 10v3", "M6 6v11", "M10 3v18", "M14 8v7", "M18 5v13", "M22 10v3"]);
export const LucideLanguagesIcon = L(["m5 8 6 6", "m4 14 6-6 2-3", "M2 5h12", "M7 2h1", "m22 22-5-10-5 10", "M14 18h6"]);
export const LucideBookAIcon = L(["M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20", "m8 13 4-7 4 7", "M9.1 11h5.7"]);
export const LucideKeyRoundIcon = L(["M2 18v3c0 .6.4 1 1 1h4v-3h3v-3h2l1.4-1.4a6.5 6.5 0 1 0-4-4Z"], () => dot(16.5, 7.5));
export const LucidePaletteIcon = L(["M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"],
  () => [dot(13.5, 6.5), dot(17.5, 10.5), dot(8.5, 7.5), dot(6.5, 12.5)]);
export const LucideBellIcon = L(["M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9", "M10.3 21a1.94 1.94 0 0 0 3.4 0"]);
export const LucideLockIcon = (p: IconProps) => (
  <Svg {...p}><rect width="18" height="11" x="3" y="11" rx="2" ry="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></Svg>
);
export const LucideHardDriveIcon = L(["M22 12H2", "M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z", "M6 16h.01", "M10 16h.01"]);
export const LucideKeyboardIcon = (p: IconProps) => (
  <Svg {...p}><path d="M10 8h.01M12 12h.01M14 8h.01M16 12h.01M18 8h.01M6 8h.01M7 16h10M8 12h.01" /><rect width="20" height="16" x="2" y="4" rx="2" /></Svg>
);
export const LucidePowerIcon = L(["M12 2v10", "M18.4 6.6a9 9 0 1 1-12.77.04"]);
export const LucideUserIcon = (p: IconProps) => (
  <Svg {...p}><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></Svg>
);
export const LucideCreditCardIcon = (p: IconProps) => (
  <Svg {...p}><rect width="20" height="14" x="2" y="5" rx="2" /><path d="M2 10h20" /></Svg>
);
export const LucideShieldIcon = L(["M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"]);
export const LucideMonitorSmartphoneIcon = (p: IconProps) => (
  <Svg {...p}><path d="M18 8V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h8" /><path d="M10 19v-3.96 3.15" /><path d="M7 19h5" /><rect width="6" height="10" x="16" y="12" rx="2" /></Svg>
);
export const LucideDatabaseIcon = (p: IconProps) => (
  <Svg {...p}><ellipse cx="12" cy="5" rx="9" ry="3" /><path d="M3 5V19A9 3 0 0 0 21 19V5" /><path d="M3 12A9 3 0 0 0 21 12" /></Svg>
);
export const LucideCircleHelpIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="12" cy="12" r="10" /><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" /><path d="M12 17h.01" /></Svg>
);
export const LucideInfoIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="12" cy="12" r="10" /><path d="M12 16v-4" /><path d="M12 8h.01" /></Svg>
);
export const LucideSmartphoneIcon = (p: IconProps) => (
  <Svg {...p}><rect width="14" height="20" x="5" y="2" rx="2" ry="2" /><path d="M12 18h.01" /></Svg>
);
export const LucideLaptopIcon = L(["M20 16V7a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v9m16 0H4m16 0 1.28 2.55a1 1 0 0 1-.9 1.45H3.62a1 1 0 0 1-.9-1.45L4 16"]);
export const LucideSearchIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></Svg>
);
export const LucideCopyIcon = (p: IconProps) => (
  <Svg {...p}><rect width="14" height="14" x="8" y="8" rx="2" ry="2" /><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" /></Svg>
);
export const LucideCheckIcon = L(["M20 6 9 17l-5-5"]);
export const LucideFileTextIcon = L(["M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z", "M14 2v4a2 2 0 0 0 2 2h4", "M10 9H8", "M16 13H8", "M16 17H8"]);
export const LucideXIcon = L(["M18 6 6 18", "m6 6 12 12"]);
export const LucideExternalLinkIcon = L(["M15 3h6v6", "M10 14 21 3", "M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"]);
export const LucideChevronRightIcon = L(["m9 18 6-6-6-6"]);
export const LucideChevronLeftIcon = L(["m15 18-6-6 6-6"]);
export const LucideTriangleAlertIcon = L(["m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3", "M12 9v4", "M12 17h.01"]);
export const LucideCircleAlertIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="12" cy="12" r="10" /><path d="M12 8v4" /><path d="M12 16h.01" /></Svg>
);
export const LucideClockIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="12" cy="12" r="10" /><path d="M12 6v6l4 2" /></Svg>
);
export const LucideCalendarIcon = (p: IconProps) => (
  <Svg {...p}><path d="M8 2v4" /><path d="M16 2v4" /><rect width="18" height="18" x="3" y="4" rx="2" /><path d="M3 10h18" /></Svg>
);
export const LucideCircleCheckIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="12" cy="12" r="10" /><path d="m9 12 2 2 4-4" /></Svg>
);
export const LucideImageIcon = (p: IconProps) => (
  <Svg {...p}><rect width="18" height="18" x="3" y="3" rx="2" ry="2" /><circle cx="9" cy="9" r="2" /><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" /></Svg>
);
export const LucideSettingsIcon = (p: IconProps) => (
  <Svg {...p}>
    <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z" />
    <circle cx="12" cy="12" r="3" />
  </Svg>
);
export const LucideUserPlusIcon = (p: IconProps) => (
  <Svg {...p}><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M19 8v6M22 11h-6" /></Svg>
);
export const LucideUploadIcon = L(["M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4", "m17 8-5-5-5 5", "M12 3v12"]);
export const LucideLogOutIcon = L(["M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4", "m16 17 5-5-5-5", "M21 12H9"]);

/** The icon of each root row and page (3.3 of the settings spec). */
export const ROOT_ICONS: Record<string, (p: IconProps) => ReactNode> = {
  calls: LucidePhoneIcon, mic: LucideMicIcon, captions: LucideCaptionsIcon, screen: LucideMonitorIcon, voice: LucideAudioLinesIcon,
  "transcription.language": LucideLanguagesIcon, words: LucideBookAIcon, key: LucideKeyRoundIcon,
  appearance: LucidePaletteIcon, notifications: LucideBellIcon, lock: LucideLockIcon, storage: LucideHardDriveIcon, shortcuts: LucideKeyboardIcon, "app.autostart": LucidePowerIcon,
  profile: LucideUserIcon, plan: LucideCreditCardIcon, security: LucideShieldIcon, devices: LucideMonitorSmartphoneIcon, privacy: LucideDatabaseIcon,
  help: LucideCircleHelpIcon, about: LucideInfoIcon,
};
