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
export function AppTileIcon({ app, size = 22 }: { app?: string; size?: number }) {
  return <SourceAppIcon app={app} size={size} />;
}

/** The small device badge on a tile: laptop, phone or web. */
export function DeviceBadgeIcon({ source, size = 12 }: { source?: string; size?: number }) {
  return <SourceDeviceIcon source={source} size={size} />;
}
