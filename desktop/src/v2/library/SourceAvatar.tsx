// A recording's leading mark in the v2 Library (PLAN_V2_PARITY 3 "Rows and sections"): the app's own logo
// (components/SourceLogos.tsx) for a known source, the neutral glyph otherwise, and a small badge for the device it was
// recorded on (phone, laptop or the web), as classic's tile and the phone's row. It names both for assistive tools.
import { hasSourceLogo, SourceLogo } from "../../components/SourceLogos";
import { sourceLabel } from "../../lib/libraryGroups";
import { Icon, Icons } from "../components";
import type { LibraryItem } from "../../lib/ipc";
import { t } from "../../i18n";

/** "WhatsApp, on a phone": the words the mark stands for, in the window's language. */
export function sourceWords(item: Pick<LibraryItem, "source_app" | "source_app_label" | "source_device">): string {
  const app = item.source_app === "voice_note" ? t("Voice note") : sourceLabel(item);
  switch (item.source_device) {
    case "phone": return t(":app, on a phone", { app });
    case "desktop": return t(":app, on a computer", { app });
    case "web": return t(":app, on the web", { app });
    default: return app;
  }
}

export function SourceAvatar({ item, size = "md" }: { item: Pick<LibraryItem, "source_app" | "source_app_label" | "source_device">; size?: "sm" | "md" }) {
  const app = item.source_app ?? null;
  const device = item.source_device === "phone" ? Icons.DeviceIcon : item.source_device === "desktop" ? Icons.LaptopIcon : item.source_device === "web" ? Icons.WebIcon : null;
  return (
    <span className={`el-source el-source--${size}${hasSourceLogo(app) ? " el-source--logo" : ""}`} role="img" aria-label={sourceWords(item)}>
      {hasSourceLogo(app) ? <SourceLogo app={app} size={size === "md" ? 40 : 24} />
        : <Icon icon={(p) => <Icons.SourceAppIcon app={app ?? undefined} {...p} />} size={size === "md" ? "md" : "sm"} />}
      {device && <span className="el-source__device" aria-hidden="true"><Icon icon={device} /></span>}
    </span>
  );
}
