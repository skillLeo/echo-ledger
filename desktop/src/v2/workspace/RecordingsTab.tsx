// A project's Recordings tab in the new look, with everything today's has: search (titles, and what was said in its
// transcripts), the sort, "Include its folders", its folders, dragging a recording onto another project in the
// sidebar (moved there), and its filing rules.
import { RowMetaText } from "../library/RecordingRow";
import { useEffect, useMemo, useState, type ReactNode } from "react";
import { api, type ClientFolder, type LibraryItem, type SearchResult } from "../../lib/ipc";
import { recordingName, searchItems, sortItems, type SortKey } from "../../lib/library";
import { projectRecordings } from "../../lib/projects";
import { beginRecordingDrag, dragJustEnded, dragLabel } from "../../lib/dragMove";
import { snippetParts } from "../../lib/player";
import { timestamp } from "../../lib/format";
import { Button, Card, Checkbox, EmptyState, Icons, List, ListRow, ListSection, Loading, SearchField, Select, SkeletonRow } from "../components";
import { dayGroups, rowModel } from "../library/model";
import { ScreensMark } from "../library/screens";

export const PROJECT_SORTS: readonly { value: SortKey; label: string }[] = [
  { value: "newest", label: "Newest first" }, { value: "oldest", label: "Oldest first" }, { value: "title", label: "Name A–Z" }, { value: "longest", label: "Longest first" },
];

export function RecordingsTab({ project, projects, items, itemsFailed, folders, onRetry, onOpenRecording, onOpenProject, onMoveRecordings, onLibrary, rules }: {
  project: { id: string; name: string };
  projects: readonly ClientFolder[];
  items: LibraryItem[] | null;
  itemsFailed: boolean;
  folders: { id: string; name: string; count: number }[];
  onRetry: () => void;
  onOpenRecording: (id: string, ms?: number) => void;
  onOpenProject?: (id: string) => void;
  onMoveRecordings?: (ids: string[], projectId: string | null) => void;
  onLibrary: () => void;
  rules?: ReactNode;
}) {
  const [query, setQuery] = useState("");
  const [sort, setSort] = useState<SortKey>("newest");
  const [nested, setNested] = useState(false);
  const [found, setFound] = useState<SearchResult["data"]>([]);
  const withFolders = nested && folders.length > 0;
  const all = useMemo(() => (items ? projectRecordings(items, projects.length ? projects : [project as ClientFolder], project.id, withFolders) : null), [items, projects, project, withFolders]);
  const shown = useMemo(() => (all ? sortItems(searchItems(all, query), sort) : null), [all, query, sort]);

  // What was said: the server's transcript search in this project.
  useEffect(() => {
    const q = query.trim();
    if (q.length < 2) {
      setFound([]);
      return;
    }
    let alive = true;
    const timer = window.setTimeout(() => {
      api.searchLibrary({ q, clientId: project.id }).then((r) => alive && setFound((r?.data ?? []).filter((d) => d.hits.length > 0)), () => alive && setFound([]));
    }, 350);
    return () => {
      alive = false;
      window.clearTimeout(timer);
    };
  }, [query, project.id]);

  const row = (item: LibraryItem) => {
    const r = rowModel(item);
    const inFolder = item.client && item.client.id !== project.id ? item.client.name : null;
    return (
      <ListRow key={item.id} icon={(p) => <Icons.SourceAppIcon app={r.app} {...p} />} title={r.title} meta={<>{inFolder ? `${inFolder} · ` : null}<RowMetaText r={r} /></>}
        mark={r.screens ? <ScreensMark count={r.screens} /> : undefined}
        endTop={r.when} endBottom={r.duration ? <span className="el-ltr">{r.duration}</span> : undefined}
        onPointerDown={onMoveRecordings ? (e) => beginRecordingDrag(e, () => ({ ids: [item.id], label: dragLabel([item.title]) }), onMoveRecordings) : undefined}
        onClick={() => { if (!dragJustEnded()) onOpenRecording(item.id); }} />
    );
  };

  return (
    <div className="el-workspace-tab">
      {folders.length > 0 && (
        <Card className="el-workspace-folders">
          <List label="Folders" className="el-group__rows">
            <ListSection title="Folders" />
            {folders.map((f) => (
              <ListRow key={f.id} compact iconStyle="plain" icon={Icons.FolderIcon} title={f.name} value={f.count ? String(f.count) : undefined} chevron
                onClick={onOpenProject ? () => onOpenProject(f.id) : undefined} />
            ))}
          </List>
        </Card>
      )}
      <div className="el-workspace-tools">
        <SearchField label={`Search ${project.name}`} value={query} onChange={setQuery} />
        <Select label="Sort recordings" hideLabel value={sort} onChange={(v) => setSort(v as SortKey)} options={PROJECT_SORTS.map((s) => ({ value: s.value, label: s.label }))} className="el-workspace-sort" />
        {folders.length > 0 && <Checkbox label="Include its folders" checked={nested} onChange={(e) => setNested(e.target.checked)} />}
      </div>
      {found.length > 0 && (
        <Card>
          <List label="Matches in transcripts" className="el-group__rows">
            <ListSection title="In transcripts" />
            {found.flatMap((r) => r.hits.slice(0, 3).map((h) => (
              <ListRow key={`${h.transcript_id}-${h.segment_index}`} compact iconStyle="plain" icon={Icons.PlayIcon}
                title={recordingName(r.session.title)} label={`Play ${recordingName(r.session.title)} from ${timestamp(h.start_ms)}`}
                meta={<>{h.speaker}: {snippetParts(h.snippet, h.highlights).map((p, i) => (p.hit ? <mark key={i} className="el-mark">{p.text}</mark> : <span key={i}>{p.text}</span>))}</>}
                value={timestamp(h.start_ms)} onClick={() => onOpenRecording(r.session.id, h.start_ms)} />
            )))}
          </List>
        </Card>
      )}
      {itemsFailed && !items ? (
        <EmptyState tone="error" icon={Icons.ErrorIcon} title="The recordings didn't load" body="EchoLedger couldn't read the library. Recordings on this computer are safe."
          action={<Button onClick={onRetry}>Try again</Button>} />
      ) : !shown ? (
        <Loading label="Loading recordings">{Array.from({ length: 4 }, (_, i) => <SkeletonRow key={i} />)}</Loading>
      ) : all!.length === 0 ? (
        <EmptyState icon={Icons.FolderIcon} title="No recordings in this project yet"
          body={`Recordings filed under ${project.name} show up here. Drag recordings from the Library onto it in the sidebar, or use Move.`}
          action={<Button onClick={onLibrary}>Open Library</Button>} />
      ) : shown.length === 0 ? (
        <EmptyState icon={Icons.SearchIcon} title="No recordings match" body="Try another word, or clear the search." action={<Button onClick={() => setQuery("")}>Clear search</Button>} />
      ) : sort === "newest" || sort === "oldest" ? (
        <List label="Recordings">
          {dayGroups(shown).map((g) => [<ListSection key={g.label} title={g.label} />, ...g.items.map(row)])}
        </List>
      ) : (
        <List label="Recordings">{shown.map(row)}</List>
      )}
      {rules}
    </div>
  );
}
