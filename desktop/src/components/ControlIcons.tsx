// Glyphs for the recording pill, the player and the after-call toast and chip, on the same base as Icons.tsx (one
// stroke, round ends). Media controls (record, pause, play, stop) and the "more" dots are filled shapes so they stay
// legible at 14 px on the translucent surfaces.
import type { ReactNode } from "react";
import { IconSvg, type IconProps } from "./Icons";

function Svg({ size = 16, children, ...rest }: IconProps & { children: ReactNode }) {
  return <IconSvg size={size} {...rest}>{children}</IconSvg>;
}

/** The record control's dot (Record = a filled dot, as on the phone's and the console's record controls). */
export const RecordDotIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="12" cy="12" r="6.5" fill="currentColor" stroke="none" /></Svg>
);
export const PauseIcon = (p: IconProps) => (
  <Svg {...p}><rect x="6.5" y="5" width="3.8" height="14" rx="1.2" fill="currentColor" stroke="none" /><rect x="13.7" y="5" width="3.8" height="14" rx="1.2" fill="currentColor" stroke="none" /></Svg>
);
export const PlayIcon = (p: IconProps) => (
  <Svg {...p}><path d="M8 5.8v12.4a1 1 0 0 0 1.5.86l10-6.2a1 1 0 0 0 0-1.72l-10-6.2A1 1 0 0 0 8 5.8z" fill="currentColor" stroke="none" /></Svg>
);
export const StopIcon = (p: IconProps) => (
  <Svg {...p}><rect x="6" y="6" width="12" height="12" rx="2.6" fill="currentColor" stroke="none" /></Svg>
);
export const MinimizeIcon = (p: IconProps) => (
  <Svg {...p}><path d="M6 12h12" /></Svg>
);
/** More actions (a row's or a project's menu): three dots, as the phone's. */
export const MoreIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="6" cy="12" r="1.6" fill="currentColor" stroke="none" /><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none" /><circle cx="18" cy="12" r="1.6" fill="currentColor" stroke="none" /></Svg>
);
export const ChevronDownIcon = (p: IconProps) => (
  <Svg {...p}><path d="m7 10 5 5 5-5" /></Svg>
);
export const ChevronLeftIcon = (p: IconProps) => (
  <Svg {...p}><path d="m14.5 6-6 6 6 6" /></Svg>
);
export const CheckIcon = (p: IconProps) => (
  <Svg {...p}><path d="m5 12.5 4.5 4.5L19 7.5" /></Svg>
);
export const PencilIcon = (p: IconProps) => (
  <Svg {...p}><path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17v3zM14.5 7.5l2 2" /></Svg>
);
export const OpenIcon = (p: IconProps) => (
  <Svg {...p}><path d="M14 4h6v6M20 4l-8.5 8.5M18 14v4.5a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 4 18.5v-11A1.5 1.5 0 0 1 5.5 6H10" /></Svg>
);
export const BookmarkIcon = (p: IconProps) => (
  <Svg {...p}><path d="M7 4.5h10a1 1 0 0 1 1 1V20l-6-4-6 4V5.5a1 1 0 0 1 1-1z" /></Svg>
);
/** A warning (a Bluetooth microphone, a screen paused): the triangle; an error is Icons.tsx's AlertIcon. */
export const WarningIcon = (p: IconProps) => (
  <Svg {...p}><path d="M12 4 3 19.5h18L12 4zM12 10v4.5M12 17.2v.3" /></Svg>
);
