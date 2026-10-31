// Minimal inline SVG icon set (no icon font / CDN). Stroke icons in the Heroicons outline style.
import type { SVGProps } from "react";

type IconProps = SVGProps<SVGSVGElement> & { size?: number };

/** One stroke width for every icon in the app (C1); the control icons use the same value. */
export const ICON_STROKE = 1.75;

function Svg({ size = 18, children, ...rest }: IconProps & { children: React.ReactNode }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={ICON_STROKE}
      strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" {...rest}>
      {children}
    </svg>
  );
}

export const PhoneIcon = (p: IconProps) => (
  <Svg {...p}><rect x="7" y="2.5" width="10" height="19" rx="2.2" /><path d="M11 18.5h2" /></Svg>
);
export const DesktopIcon = (p: IconProps) => (
  <Svg {...p}><rect x="3" y="4" width="18" height="12" rx="1.8" /><path d="M8 20h8M12 16v4" /></Svg>
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
export const MicIcon = (p: IconProps) => (
  <Svg {...p}><rect x="9" y="2.5" width="6" height="12" rx="3" /><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21" /></Svg>
);
export const DotsIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="12" cy="12" r="9" /><path d="M8 12h.01M12 12h.01M16 12h.01" /></Svg>
);
export const CaptionsIcon = (p: IconProps) => (
  <Svg {...p}><rect x="3" y="5" width="18" height="14" rx="2.5" /><path d="M7 11h3M12 11h5M7 15h7" /></Svg>
);
export const SettingsIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z" /></Svg>
);
export const LibraryIcon = (p: IconProps) => (
  <Svg {...p}><path d="M4 5h16M4 10h16M4 15h10M4 20h7" /></Svg>
);
export const RefreshIcon = (p: IconProps) => (
  <Svg {...p}><path d="M20 11a8 8 0 0 0-14.3-4.9L4 8M4 4v4h4M4 13a8 8 0 0 0 14.3 4.9L20 16M20 20v-4h-4" /></Svg>
);
export const CloseIcon = (p: IconProps) => (
  <Svg {...p}><path d="M6 6l12 12M18 6 6 18" /></Svg>
);
export const GripIcon = (p: IconProps) => (
  <Svg {...p}><path d="M9 6h.01M15 6h.01M9 12h.01M15 12h.01M9 18h.01M15 18h.01" strokeWidth={2.6} /></Svg>
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
export const MonitorIcon = (p: IconProps) => (
  <Svg {...p}><rect x="3" y="4" width="18" height="12" rx="1.8" /><path d="M8 20h8M12 16v4" /></Svg>
);
export const AlertIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="12" cy="12" r="9" /><path d="M12 7.5v5.5M12 16.5h.01" /></Svg>
);
export const ClockIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></Svg>
);
/** Import: a file going up into the tray. */
export const ImportIcon = (p: IconProps) => (
  <Svg {...p}><path d="M12 15V4M7.5 8.5 12 4l4.5 4.5M4 14v4.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V14" /></Svg>
);
/** Save to this computer. */
export const DownloadIcon = (p: IconProps) => (
  <Svg {...p}><path d="M12 4v11M7.5 10.5 12 15l4.5-4.5M4 14v4.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V14" /></Svg>
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
    case "phone_call": return <PhoneIcon {...p} />;
    case "voice_note": return <MicIcon {...p} />;
    default: return <DotsIcon {...p} />;
  }
}
export const BellIcon = (p: IconProps) => (
  <Svg {...p}><path d="M6 9.5a6 6 0 1 1 12 0c0 4.2 1.6 6.1 2.3 6.8a.4.4 0 0 1-.3.7H4a.4.4 0 0 1-.3-.7C4.4 15.6 6 13.7 6 9.5z" /><path d="M10 20.2a2.2 2.2 0 0 0 4 0" /></Svg>
);
export const UsersIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="9" cy="8.5" r="3.5" /><path d="M2.5 20a6.5 6.5 0 0 1 13 0M15.5 5.2a3.5 3.5 0 0 1 0 6.6M18 14.2a6.5 6.5 0 0 1 3.5 5.8" /></Svg>
);
/** The sidebar's collapse / expand button: a panel with its left side marked. */
export const SidebarIcon = (p: IconProps) => (
  <Svg {...p}><rect x="3" y="4.5" width="18" height="15" rx="2.5" /><path d="M9 4.5v15" /></Svg>
);
export const ShareIcon = (p: IconProps) => (
  <Svg {...p}><path d="M12 15V4M7.5 8.5 12 4l4.5 4.5M6 11H5a1 1 0 0 0-1 1v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7a1 1 0 0 0-1-1h-1" /></Svg>
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
export const HourglassIcon = (p: IconProps) => (
  <Svg {...p}><path d="M6.5 3h11M6.5 21h11M8 3v3.5a4 4 0 0 0 1.6 3.2L12 11.5l2.4-1.8A4 4 0 0 0 16 6.5V3M8 21v-3.5a4 4 0 0 1 1.6-3.2l2.4-1.8 2.4 1.8a4 4 0 0 1 1.6 3.2V21" /></Svg>
);
/** Filters (the phone's "tune" glyph). */
export const TuneIcon = (p: IconProps) => (
  <Svg {...p}><path d="M4 7h9M17 7h3M4 17h3M11 17h9M15 5v4M9 15v4" /></Svg>
);
export const SendIcon = (p: IconProps) => (
  <Svg {...p}><path d="M4.5 12 20 4.5 15.5 20l-3.2-6.3L4.5 12zM12.3 13.7 20 4.5" /></Svg>
);
export const KeyboardIcon = (p: IconProps) => (
  <Svg {...p}><rect x="2.5" y="6" width="19" height="12" rx="2.5" /><path d="M6.5 10h.01M10 10h.01M14 10h.01M17.5 10h.01M8 14h8" /></Svg>
);
export const CallIcon = (p: IconProps) => (
  <Svg {...p}><path d="M5 4h3.2l1.6 4-2 1.3a10.5 10.5 0 0 0 4.9 4.9l1.3-2 4 1.6V17a2 2 0 0 1-2.2 2A15 15 0 0 1 3 6.2 2 2 0 0 1 5 4z" /></Svg>
);
export const PaletteIcon = (p: IconProps) => (
  <Svg {...p}><path d="M12 3a9 9 0 1 0 0 18c1.1 0 1.6-.8 1.6-1.6 0-.5-.2-.9-.5-1.3-.3-.4-.5-.8-.5-1.3 0-.9.7-1.6 1.6-1.6H16a5 5 0 0 0 5-5C21 6.4 17 3 12 3z" /><path d="M7.5 11.5h.01M10 7.5h.01M14.5 7.5h.01" strokeWidth={2.6} /></Svg>
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

/** Filled Material glyphs for the app tiles of rows (the phone's RecordingRow uses these on a 12 % tint). */
function Fill({ size = 20, d }: { size?: number; d: string }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d={d} /></svg>
  );
}
const TILE_GLYPHS = {
  video: "M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z",
  chat: "M21 6h-2v9H6v2c0 .55.45 1 1 1h11l4 4V7c0-.55-.45-1-1-1zm-4 6V3c0-.55-.45-1-1-1H3c-.55 0-1 .45-1 1v14l4-4h10c.55 0 1-.45 1-1z",
  voice: "M12 14c1.66 0 2.99-1.34 2.99-3L15 5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.48 6-3.3 6-6.72h-1.7z",
  phone: "M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z",
  store: "M20 4H4v2h16V4zm1 10v-2l-1-5H4l-1 5v2h1v6h10v-6h4v6h2v-6h1zm-9 4H6v-4h6v4z",
  web: "M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z",
  other: "M7 18h2V6H7v12zm4 4h2V2h-2v20zm-8-8h2v-4H3v4zm12 4h2V6h-2v12zm4-8v4h2v-4h-2z",
  laptop: "M20 18c1.1 0 1.99-.9 1.99-2L22 6c0-1.1-.9-2-2-2H4c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2H0v2h24v-2h-4zM4 6h16v10H4V6z",
  smartphone: "M17 1.01 7 1c-1.1 0-2 .9-2 2v18c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-1.99-2-1.99zM17 19H7V5h10v14z",
} as const;

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

/** The filled glyph of a row's app tile. */
export function AppTileIcon({ app, size = 22 }: { app?: string; size?: number }) {
  const glyph = app === "whatsapp_business" ? TILE_GLYPHS.store : app === "whatsapp_web" ? TILE_GLYPHS.web : TILE_GLYPHS[appKind(app)];
  return <Fill size={size} d={glyph} />;
}

/** The small device badge on a tile: laptop, phone or web. */
export function DeviceBadgeIcon({ source, size = 11 }: { source?: string; size?: number }) {
  return <Fill size={size} d={source === "phone" ? TILE_GLYPHS.smartphone : source === "desktop" ? TILE_GLYPHS.laptop : TILE_GLYPHS.web} />;
}
