// Settings › Captions in the new look, with today's data and rules (CaptureSettings.tsx): live captions while
// recording and the style page (size, lines, position, width, hiding, background, compact, the speakers' colours),
// each change saved at once, with the caption drawn as the captions window draws it.
import { useId } from "react";
import type { CaptionStyle, Corner, Settings } from "../../../lib/ipc";
import { setting } from "../../../lib/settingsRegistry";
import { COLOUR_PRESETS, CaptionPreview, CAPTIONS_SHARE_LINE } from "../../../views/main/CaptureSettings";
import { ListRow } from "../../components";
import { Group, LinkSetting, ResetDefaults, SegmentedSetting, SelectSetting, SliderSetting, SwitchSetting, type Patch } from "./rows";
import { t } from "../../../i18n";

export function CaptionsPageV2({ settings, patch, onStyle }: { settings: Settings; patch: Patch; onStyle: () => void }) {
  return (
    <>
      <CaptionPreview style={settings.captionStyle} />
      <Group footer={<><p>{t(CAPTIONS_SHARE_LINE)}</p><p>{t("You can also right-click the captions while recording to change their style.")}</p></>}>
        <SwitchSetting id="captions.enabled" on={settings.captionsOnRecord} onChange={(v) => void patch({ captionsOnRecord: v })} />
        <LinkSetting id="captions.style" onOpen={onStyle} />
      </Group>
      <ResetDefaults page={t("Captions")} onReset={() => void patch({ captionsOnRecord: true })} />
    </>
  );
}

/** Automatic plus the six swatches (a colour picked earlier that is none of them shows as a seventh). */
function ColourSetting({ id, value, onChange }: { id: string; value: string | null; onChange: (c: string | null) => void }) {
  const labelId = useId();
  const presets: string[] = [...COLOUR_PRESETS];
  if (value && !presets.includes(value.toLowerCase())) presets.push(value.toLowerCase());
  const title = setting(id).title;
  return (
    <ListRow compact anchor={id} title={<span id={labelId}>{title}</span>}
      control={
        <span className="el-swatches" role="radiogroup" aria-labelledby={labelId}>
          <button type="button" role="radio" aria-checked={value === null} className="el-swatch el-swatch--auto" onClick={() => onChange(null)}>{t("Automatic")}</button>
          {presets.map((c) => (
            <button key={c} type="button" role="radio" aria-checked={value?.toLowerCase() === c} aria-label={c} className="el-swatch"
              style={{ background: c }} onClick={() => onChange(c)} />
          ))}
        </span>
      } />
  );
}

export function CaptionStylePageV2({ settings, patch }: { settings: Settings; patch: Patch }) {
  const style = settings.captionStyle;
  const save = (next: Partial<CaptionStyle>) => void patch({ captionStyle: { ...style, ...next } });
  return (
    <>
      <CaptionPreview style={style} />
      <Group>
        <SegmentedSetting<CaptionStyle["size"]> id="captions.size" value={style.size} onChange={(size) => save({ size })} />
        <SegmentedSetting id="captions.lines" value={String(settings.captionLines)} onChange={(v) => void patch({ captionLines: Number(v) })} />
        <SelectSetting<Corner> id="captions.position" value={settings.overlayCorner} onChange={(v) => void patch({ overlayCorner: v })} />
        <SegmentedSetting<CaptionStyle["width"]> id="captions.width" value={style.width} onChange={(width) => save({ width })} />
        <SelectSetting id="captions.autohide" value={String(style.autoHideSeconds)} onChange={(v) => save({ autoHideSeconds: Number(v) })} />
        <SliderSetting id="captions.opacity" value={style.opacity} min={40} max={100} step={5} format={(v) => `${v}%`} onChange={(opacity) => save({ opacity })} />
        <SwitchSetting id="captions.compact" on={style.compact} onChange={(compact) => save({ compact })} />
        <ColourSetting id="captions.me_colour" value={style.meColor} onChange={(meColor) => save({ meColor })} />
        <ColourSetting id="captions.client_colour" value={style.clientColor} onChange={(clientColor) => save({ clientColor })} />
      </Group>
      <ResetDefaults page={t("Style")} onReset={() => void patch({
        captionLines: 3, overlayCorner: "bottom_right",
        captionStyle: { ...style, size: "medium", width: "normal", opacity: 85, autoHideSeconds: 0, compact: false, meColor: null, clientColor: null },
      })} />
    </>
  );
}
