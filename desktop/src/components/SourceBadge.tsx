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

export function SourceAppBadge({ app, label }: { app?: string; label?: string }) {
  const key = app ?? "other";
  return (
    <span className="badge" data-testid="source-app">
      <SourceAppIcon app={key} size={14} />
      {label ?? SOURCE_APP_LABELS[key] ?? key}
    </span>
  );
}
