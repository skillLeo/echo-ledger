// Glyphs for the recording pill and the review card, in the same 24 px outline style as Icons.tsx. Media controls
// (pause, play, stop) are filled so they stay legible at 14 px on the translucent surfaces.
import type { ReactNode, SVGProps } from "react";

type IconProps = SVGProps<SVGSVGElement> & { size?: number };

function Svg({ size = 16, children, ...rest }: IconProps & { children: ReactNode }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8}
      strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false" {...rest}>
      {children}
    </svg>
  );
}

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
  <Svg {...p}><path d="M6 12h12" strokeWidth={2} /></Svg>
);
export const MoreIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="6" cy="12" r="1.6" fill="currentColor" stroke="none" /><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none" /><circle cx="18" cy="12" r="1.6" fill="currentColor" stroke="none" /></Svg>
);
export const ChevronDownIcon = (p: IconProps) => (
  <Svg {...p}><path d="m7 10 5 5 5-5" /></Svg>
);
export const ChevronLeftIcon = (p: IconProps) => (
  <Svg {...p}><path d="m14.5 6-6 6 6 6" /></Svg>
);
export const UserIcon = (p: IconProps) => (
  <Svg {...p}><circle cx="12" cy="8.5" r="3.6" /><path d="M5 20a7 7 0 0 1 14 0" /></Svg>
);
export const CheckIcon = (p: IconProps) => (
  <Svg {...p}><path d="m5 12.5 4.5 4.5L19 7.5" strokeWidth={2} /></Svg>
);
export const PencilIcon = (p: IconProps) => (
  <Svg {...p}><path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17v3zM14.5 7.5l2 2" /></Svg>
);
export const OpenIcon = (p: IconProps) => (
  <Svg {...p}><path d="M14 4h6v6M20 4l-8.5 8.5M18 14v4.5a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 4 18.5v-11A1.5 1.5 0 0 1 5.5 6H10" /></Svg>
);
