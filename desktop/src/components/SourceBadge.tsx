import { SourceAppIcon, SourceDeviceIcon } from "./Icons";
import { SOURCE_APP_LABELS, SOURCE_DEVICE_LABELS } from "../lib/format";

/** Always icon + text (never icon only), per the shared labeling rule. */
export function SourceDeviceBadge({ source, label }: { source?: string; label?: string }) {
  const key = source ?? "web";
  return (
    <span className={`badge ${key}`} data-testid="source-device">
      <SourceDeviceIcon source={key} size={14} />
      {label ?? SOURCE_DEVICE_LABELS[key] ?? key}
    </span>
  );
}

/** `motion` names the icon for a transition (the recording page's app tile, lib/motion.ts). */
export function SourceAppBadge({ app, label, motion }: { app?: string; label?: string; motion?: string }) {
  const key = app ?? "other";
  return (
    <span className="badge" data-testid="source-app">
      <SourceAppIcon app={key} size={14} data-motion={motion} />
      <span>{label ?? SOURCE_APP_LABELS[key] ?? key}</span>
    </span>
  );
}
