// The apps a recording comes from, as their own marks (PLAN_V2_PARITY 3: "real logos, never generic glyphs for known
// sources"): simplified, flat versions on a 24-unit tile in each app's own colours, so a row reads at a glance as the
// WhatsApp call or the Zoom meeting it is. They identify the source only. The colours are the apps', not ours, which is
// why they live here and not in the design values (src/v2 uses tokens only). A source without a mark of its own (an
// unknown app, an import) gets null, and the caller draws the neutral glyph.
import type { ReactNode } from "react";

const WHITE = "#FFFFFF";

function Tile({ size, label, children, round = false, bg }: { size: number; label?: string; children: ReactNode; round?: boolean; bg: string }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" role={label ? "img" : undefined} aria-label={label} aria-hidden={label ? undefined : true}
      focusable="false" className="source-logo">
      {round ? <circle cx="12" cy="12" r="12" fill={bg} /> : <rect width="24" height="24" rx="6" fill={bg} />}
      {children}
    </svg>
  );
}

/** WhatsApp's handset, as drawn inside its bubble. */
const handset = "M9.3 7.6c.2 0 .4 0 .5.4l.7 1.7c.1.2 0 .4-.1.6l-.5.6c-.1.1-.1.3 0 .5.4.7.9 1.3 1.5 1.8.5.4 1 .7 1.6 1 .2.1.4 0 .5-.1l.6-.7c.2-.2.4-.2.6-.1l1.6.8c.2.1.3.3.3.5 0 .5-.2 1-.6 1.3-.5.4-1.1.6-1.7.5-1.1-.2-2.2-.7-3.1-1.4-1-.8-1.9-1.8-2.5-2.9-.4-.7-.5-1.5-.3-2.2.2-.4.4-.8.8-1.1.2-.2.4-.2.6-.2z";
/** WhatsApp's speech bubble with its tail at the bottom start. */
const bubble = "M12 4.6a7.4 7.4 0 0 0-6.4 11.1L4.6 19.4l3.8-1a7.4 7.4 0 1 0 3.6-13.8z";

const MARKS: Record<string, (size: number, label?: string) => ReactNode> = {
  whatsapp: (size, label) => (
    <Tile size={size} label={label} round bg="#25D366">
      <path d={bubble} fill="none" stroke={WHITE} strokeWidth="1.5" strokeLinejoin="round" />
      <path d={handset} fill={WHITE} />
    </Tile>
  ),
  whatsapp_business: (size, label) => (
    <Tile size={size} label={label} round bg="#25D366">
      <path d={bubble} fill="none" stroke={WHITE} strokeWidth="1.5" strokeLinejoin="round" />
      <path d="M10 8.2h2.4c1.2 0 2 .6 2 1.6 0 .6-.3 1-.8 1.3.7.2 1.1.7 1.1 1.4 0 1.1-.8 1.8-2.2 1.8H10zm1.3 1.1v1.5h1c.5 0 .8-.3.8-.8s-.3-.7-.8-.7zm0 2.5v1.6h1.2c.6 0 .9-.3.9-.8s-.3-.8-.9-.8z" fill={WHITE} />
    </Tile>
  ),
  whatsapp_web: (size, label) => (
    <Tile size={size} label={label} round bg="#25D366">
      <path d={bubble} fill="none" stroke={WHITE} strokeWidth="1.5" strokeLinejoin="round" />
      <path d={handset} fill={WHITE} />
    </Tile>
  ),
  phone_call: (size, label) => (
    <Tile size={size} label={label} bg="#34C759">
      <path d="M8.6 5.5c.3 0 .6.1.7.5l1 2.4c.1.3 0 .6-.2.8l-.8.9c-.1.2-.1.4 0 .6.6 1 1.3 1.8 2.1 2.6.7.6 1.5 1.1 2.3 1.5.2.1.5.1.7-.1l.8-1c.2-.3.6-.3.9-.2l2.3 1.1c.3.1.5.4.5.7 0 .7-.3 1.4-.9 1.9-.7.6-1.6.9-2.5.7-1.7-.3-3.2-1-4.5-2.1-1.5-1.2-2.7-2.6-3.6-4.2-.5-1-.7-2.2-.4-3.2.2-.6.6-1.1 1.1-1.5.2-.2.4-.2.5-.2z" fill={WHITE} />
    </Tile>
  ),
  google_meet: (size, label) => (
    <Tile size={size} label={label} bg="#FFFFFF">
      <rect x="0.5" y="0.5" width="23" height="23" rx="5.5" fill="none" stroke="#DADCE0" />
      <rect x="4" y="7" width="11" height="10" rx="1.5" fill="#00AC47" />
      <path d="M4 10V8.5C4 7.7 4.7 7 5.5 7H8L4 10z" fill="#EA4335" />
      <path d="M4 10h3v7H5.5C4.7 17 4 16.3 4 15.5z" fill="#4285F4" />
      <path d="M7 14h8v1.5c0 .8-.7 1.5-1.5 1.5H7z" fill="#0066DA" />
      <path d="M15 10.5 20 7v10l-5-3.5z" fill="#00832D" />
    </Tile>
  ),
  zoom: (size, label) => (
    <Tile size={size} label={label} bg="#0B5CFF">
      <rect x="4.5" y="8" width="10" height="8" rx="2" fill={WHITE} />
      <path d="M15.5 11 19.5 8.4v7.2l-4-2.6z" fill={WHITE} />
    </Tile>
  ),
  microsoft_teams: (size, label) => (
    <Tile size={size} label={label} bg="#5059C9">
      <circle cx="17.2" cy="7.6" r="2.1" fill="#7B83EB" />
      <path d="M14.6 10.6h5c.5 0 .9.4.9.9v3.9a2.7 2.7 0 0 1-5.4.2z" fill="#7B83EB" />
      <rect x="3.5" y="6.5" width="11" height="11" rx="1.5" fill="#4B53BC" />
      <path d="M6.2 9.2h5.6v1.5H9.8v5h-1.6v-5h-2z" fill={WHITE} />
    </Tile>
  ),
  slack: (size, label) => (
    <Tile size={size} label={label} bg="#FFFFFF">
      <rect x="0.5" y="0.5" width="23" height="23" rx="5.5" fill="none" stroke="#DADCE0" />
      <rect x="8.4" y="4.5" width="2.6" height="9" rx="1.3" fill="#36C5F0" />
      <rect x="10.5" y="8.4" width="9" height="2.6" rx="1.3" fill="#2EB67D" />
      <rect x="13" y="10.5" width="2.6" height="9" rx="1.3" fill="#ECB22E" />
      <rect x="4.5" y="13" width="9" height="2.6" rx="1.3" fill="#E01E5A" />
    </Tile>
  ),
  telegram: (size, label) => (
    <Tile size={size} label={label} round bg="#26A5E4">
      <path d="m5.4 11.6 11.7-4.5c.5-.2 1 .1.8.9l-2 9.4c-.1.7-.5.8-1.1.5l-3-2.2-1.5 1.4c-.2.2-.3.3-.6.3l.2-3.1 5.6-5.1c.2-.2 0-.3-.4-.1l-6.9 4.4-3-.9c-.6-.2-.6-.6.2-1z" fill={WHITE} />
    </Tile>
  ),
  voice_note: (size, label) => (
    <Tile size={size} label={label} round bg="#5F6B73">
      <rect x="9.5" y="5" width="5" height="9" rx="2.5" fill={WHITE} />
      <path d="M7.2 11.5a4.8 4.8 0 0 0 9.6 0M12 16.4v2.6" fill="none" stroke={WHITE} strokeWidth="1.5" strokeLinecap="round" />
    </Tile>
  ),
};

/** Which sources have a mark of their own. */
export function hasSourceLogo(app: string | null | undefined): boolean {
  return !!app && app in MARKS;
}

/** The app's mark at `size` px (decorative unless `label` names it), or null when the source has none. */
export function SourceLogo({ app, size = 40, label }: { app: string | null | undefined; size?: number; label?: string }) {
  const mark = app ? MARKS[app] : undefined;
  return mark ? <>{mark(size, label)}</> : null;
}
