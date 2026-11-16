import { useState } from "react";
import { Avatar, Badge, Button, Card, Icons, List, ListRow, SearchField, Status, Toggle, type IconType } from "../../components";
import { COPY, type Lang, type SettingRowData } from "../content";

const ICONS: Record<SettingRowData["icon"], IconType> = {
  record: Icons.MicIcon, after: Icons.PhoneIcon, captions: Icons.CaptionsIcon, language: Icons.TranscriptIcon,
  "app-language": Icons.LanguagesIcon, words: Icons.WaveIcon, private: Icons.ScreenIcon, storage: Icons.StorageIcon,
  lock: Icons.LockIcon, theme: Icons.AppearanceIcon, bell: Icons.BellIcon, health: Icons.PrivacyIcon,
  keyboard: Icons.KeyboardIcon, help: Icons.HelpIcon,
};

/** Gate 3 (c): Settings as one searchable list: the profile card with the plan and Upgrade, then plain grouped rows. */
export function SettingsScreen({ lang }: { lang: Lang }) {
  const t = COPY[lang].settings;
  const [query, setQuery] = useState("");
  const [on, setOn] = useState<Record<string, boolean>>({ captions: false, private: true });
  const q = query.trim().toLocaleLowerCase();
  const groups = t.groups
    .map((g) => ({ ...g, rows: g.rows.filter((r) => !q || `${g.title} ${r.title} ${r.value ?? ""} ${r.meta ?? ""}`.toLocaleLowerCase().includes(q)) }))
    .filter((g) => g.rows.length > 0);

  return (
    <div className="g-page g-page--narrow">
      <header className="g-header">
        <h1 className="el-title-l">{t.title}</h1>
      </header>
      <SearchField label={t.search} value={query} onChange={setQuery} />
      {!q && (
        <Card className="g-profile" label={t.name}>
          <Avatar initials={lang === "ur" ? "ع ص" : "AS"} />
          <div className="g-profile__who">
            <span className="el-title-s" dir="auto">{t.name}</span>
            <span className="el-secondary el-caption g-ltr">{t.email}</span>
            <span className="g-profile__plan">
              <Badge>{t.plan}</Badge>
              <span className="el-caption">{t.usage}</span>
            </span>
          </div>
          <Button variant="primary">{t.upgrade}</Button>
        </Card>
      )}
      {groups.map((g) => (
        <section key={g.title} className="g-group" aria-label={g.title}>
          <h2 className="el-caption el-body-strong g-group__title">{g.title}</h2>
          <Card>
            <List label={g.title} className="g-rows">
              {g.rows.map((r) => (
                <ListRow key={r.title} compact icon={ICONS[r.icon]} iconStyle="plain" title={r.title} meta={r.meta}
                  value={r.value ?? (r.status ? <Status kind="ready">{r.status}</Status> : undefined)}
                  control={r.toggle ? <Toggle label={r.title} hideLabel checked={on[r.icon] ?? false} onChange={(v) => setOn({ ...on, [r.icon]: v })} /> : undefined}
                  chevron={!r.toggle} onClick={r.toggle ? undefined : () => undefined} />
              ))}
            </List>
          </Card>
        </section>
      ))}
    </div>
  );
}
