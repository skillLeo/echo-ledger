import { hasSourceLogo, SourceLogo } from "../../components/SourceLogos";
import { Icon } from "./Button";
import { DeviceIcon, LaptopIcon, SourceAppIcon, WaveIcon, WebIcon } from "./icons";

export type SourceDevice = "phone" | "desktop" | "web";

const DEVICE_GLYPHS = { phone: DeviceIcon, desktop: LaptopIcon, web: WebIcon } as const;

/**
 * A recording's leading mark (D12 "The marks"): the app's own logo (components/SourceLogos.tsx) for a known source,
 * the calm voice-note mark for the app's own voice notes (accent-soft with the audio lines in accent-text, drawn with
 * tokens so it follows the theme), and the neutral glyph on bg-subtle for any other source. The device it was recorded
 * on (a phone outline, a laptop, the globe) sits at the bottom-end corner on a 16 px chip, 2 px outside the mark, with
 * a 2 px ring in the row's background so the logo's edge is never cut. The small size has no device chip.
 */
export function SourceMark({ app, device, size = "md", label, id }: {
  app: string | null | undefined;
  device?: SourceDevice | null;
  size?: "md" | "sm";
  /** What the mark stands for ("WhatsApp, on a phone"). */
  label: string;
  /** Lets a row read the mark with it (ListRow's library variant gives one). */
  id?: string;
}) {
  const voice = app === "voice_note";
  const logo = !voice && hasSourceLogo(app);
  const kind = voice ? " el-source--voice" : logo ? " el-source--logo" : " el-source--neutral";
  const glyph = device ? DEVICE_GLYPHS[device] : null;
  const glyphSize = size === "md" ? "md" : "sm";
  return (
    <span className={`el-source el-source--mark el-source--${size}${kind}`} role="img" aria-label={label} id={id}>
      {voice ? <Icon icon={WaveIcon} size={glyphSize} />
        : logo ? <SourceLogo app={app} size={size === "md" ? 40 : 24} />
          : <span className={`el-icon${size === "md" ? " el-icon--md" : ""}`} aria-hidden="true"><SourceAppIcon app={app ?? undefined} /></span>}
      {glyph && size === "md" && <span className="el-source__device" aria-hidden="true"><Icon icon={glyph} /></span>}
    </span>
  );
}
