import { useMemo, useState } from "react";
import { Badge, Button, Chip, EmptyState, FilterBar, Icons, List, ListRow, ListSection, Loading, SearchField, SkeletonRow, Toast, ToastRegion } from "../../components";
import { COPY, type Lang } from "../content";
import { STATE_COPY, type ScreenState } from "../states";

type Filter = "all" | "today" | "untranscribed" | "voice";

/** Gate 3 (a): the Library as a list of recordings, WhatsApp-style rows, quick filters and an Undo toast. */
export function LibraryScreen({ lang, state = "ready", toast = state === "ready" }: { lang: Lang; state?: ScreenState; toast?: boolean }) {
  const t = COPY[lang].library;
  const st = STATE_COPY[lang].library;
  const rows = state === "ready" ? COPY[lang].rows : [];
  const [filter, setFilter] = useState<Filter>("all");
  const [query, setQuery] = useState("");
  const [showToast, setShowToast] = useState(toast);

  const shown = useMemo(() => rows.filter((r) => {
    if (filter === "today" && r.day !== "today") return false;
    if (filter === "untranscribed" && !r.untranscribed) return false;
    if (filter === "voice" && r.app !== "voice_note") return false;
    const q = query.trim().toLocaleLowerCase();
    return !q || `${r.title} ${r.meta}`.toLocaleLowerCase().includes(q);
  }), [rows, filter, query]);

  const count = (f: Filter) => rows.filter((r) => (f === "today" ? r.day === "today" : f === "untranscribed" ? r.untranscribed : r.app === "voice_note")).length;
  const chip = (f: Filter, label: string, withCount = true) => (
    <Chip label={label} selected={filter === f} count={withCount ? count(f) : undefined} onToggle={() => setFilter(filter === f && f !== "all" ? "all" : f)} />
  );
  const days = (["today", "yesterday"] as const).filter((d) => shown.some((r) => r.day === d));

  return (
    <div className="g-page g-page--wide">
      <header className="g-header">
        <h1 className="el-title-l">{t.title}</h1>
        <div className="g-header__actions">
          <Button variant="secondary">{t.importFiles}</Button>
          <Button variant="primary" icon={Icons.RecordIcon}>{t.record}</Button>
        </div>
      </header>
      <div className="g-tools">
        <SearchField label={t.search} value={query} onChange={setQuery} disabled={state !== "ready"} />
        {state === "ready" && <FilterBar label={t.filters}>
          {chip("all", t.all, false)}
          {chip("today", t.today)}
          {chip("untranscribed", t.untranscribed)}
          {chip("voice", t.voiceNotes)}
        </FilterBar>}
      </div>
      {state === "loading" ? (
        <Loading label={st.loading} className="g-list-loading">
          {Array.from({ length: 7 }, (_, i) => <SkeletonRow key={i} />)}
        </Loading>
      ) : state === "error" ? (
        <EmptyState tone="error" icon={Icons.ErrorIcon} title={st.errorTitle} body={st.errorBody} action={<Button variant="primary">{st.retry}</Button>} />
      ) : rows.length === 0 ? (
        <EmptyState icon={Icons.LibraryIcon} title={st.emptyTitle} body={st.emptyBody} action={<Button>{t.importFiles}</Button>} />
      ) : shown.length === 0 ? (
        <EmptyState icon={Icons.SearchIcon} title={t.emptyTitle} body={t.emptyBody}
          action={<Button onClick={() => { setFilter("all"); setQuery(""); }}>{t.clear}</Button>} />
      ) : (
        <List label={t.listLabel}>
          {days.map((d) => [
            <ListSection key={d} title={d === "today" ? t.today : t.yesterday} />,
            ...shown.filter((r) => r.day === d).map((r) => (
              <ListRow key={r.id} icon={(p) => <Icons.SourceAppIcon app={r.app} {...p} />} title={r.title} meta={r.meta}
                badge={r.untranscribed ? <Badge tone="warning">{t.untranscribedBadge}</Badge> : undefined}
                endTop={r.time} endBottom={<span className="el-num">{r.duration}</span>} onClick={() => undefined} />
            )),
          ])}
        </List>
      )}
      <ToastRegion>
        {showToast && <Toast message={t.toast} actionLabel={t.undo} undo closeLabel={t.dismiss} duration={0} onAction={() => undefined} onClose={() => setShowToast(false)} />}
      </ToastRegion>
    </div>
  );
}
