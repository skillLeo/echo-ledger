import { useState } from "react";
import { Avatar, Badge, Button, Card, EmptyState, Icons, List, ListRow, Loading, SearchField, Skeleton, SkeletonRow, Status, Toggle, type IconType } from "../../components";
import { COPY, type Lang, type SettingRowData } from "../content";
import { STATE_COPY, type ScreenState } from "../states";

const ICONS: Record<SettingRowData["icon"], IconType> = {
  record: Icons.MicIcon, after: Icons.PhoneIcon, captions: Icons.CaptionsIcon, language: Icons.TranscriptIcon,
  "app-language": Icons.LanguagesIcon, words: Icons.WaveIcon, private: Icons.ScreenIcon, storage: Icons.StorageIcon,
  lock: Icons.LockIcon, theme: Icons.AppearanceIcon, bell: Icons.BellIcon, health: Icons.PrivacyIcon,
  keyboard: Icons.KeyboardIcon, help: Icons.HelpIcon,
};

/** Gate 3 (c): Settings as one searchable list: the profile card with the plan and Upgrade, then plain grouped rows. */
export function SettingsScreen({ lang, state = "ready" }: { lang: Lang; state?: ScreenState }) {
  const t = COPY[lang].settings;
  const st = STATE_COPY[lang].settings;
  const [query, setQuery] = useState(state === "empty" ? st.emptyQuery : "");
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
      {state === "loading" ? (
        <Loading label={st.loading} className="g-settings-loading">
          <Card className="g-profile">
            <Skeleton shape="circle" />
            <span className="g-profile__who"><Skeleton shape="title" width="medium" /><Skeleton width="long" /></span>
          </Card>
          <Card className="g-rows">{Array.from({ length: 5 }, (_, i) => <SkeletonRow key={i} end={false} />)}</Card>
        </Loading>
      ) : state === "error" && !q ? (
        <Card className="g-profile-error">
          <EmptyState tone="error" icon={Icons.ErrorIcon} title={st.errorTitle} body={st.errorBody} action={<Button>{st.retry}</Button>} />
        </Card>
      ) : !q && (
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
      {state !== "loading" && q && groups.length === 0 && (
        <EmptyState icon={Icons.SearchIcon} title={st.emptyTitle} body={st.emptyBody} action={<Button onClick={() => setQuery("")}>{st.clear}</Button>} />
      )}
      {state !== "loading" && groups.map((g) => (
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
