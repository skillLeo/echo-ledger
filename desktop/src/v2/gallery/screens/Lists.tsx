import { useMemo, useState, type KeyboardEvent, type MouseEvent } from "react";
import {
  Button, ColourPicker, ContextMenu, Dialog, EmptyState, Icons, LABEL_COLOURS, LabelDot, List, ListChip, ListChipRow, ListRow, ListSection,
  NewListDialog, RenameListDialog, SearchField, Toast, ToastRegion, addToListItems, deleteListWords, labelColourName, listChipMenuItems,
  menuPointFor, type LabelColour, type NewListWords, type RenameListWords,
} from "../../components";
import { COPY, type Lang } from "../content";

type Place = "all" | "unread" | "important" | string;
type Lst = { id: string; name: string; colour: string };

/** The words of the lists (D14), English and Urdu; the Library's own words come from content.ts. */
const WORDS = {
  en: {
    row: "Lists", all: "All", unread: "Unread", important: "Important", newList: "New list", addTo: "Add to list",
    lists: ["Clients", "Home", "Family"],
    emptyTitle: (n: string) => `Nothing in ${n} yet`, emptyBody: "Add recordings from their ⋮ menu: Add to list.",
    added: (n: string) => `Added to ${n}`, removed: (n: string) => `Removed from ${n}`, undo: "Undo", dismiss: "Dismiss",
    rename: "Rename list", recolour: "Change colour", save: "Save",
    dialog: {} as Partial<NewListWords>,
    renameWords: {} as Partial<RenameListWords>,
    menu: {}, addWords: {}, del: {},
    cancel: "Cancel", close: "Close", colour: "Colour", name: "List name",
  },
  ur: {
    row: "فہرستیں", all: "سب", unread: "نہ پڑھی گئی", important: "اہم", newList: "نئی فہرست", addTo: "فہرست میں شامل کریں",
    lists: ["کلائنٹس", "گھر", "خاندان"],
    emptyTitle: (n: string) => `${n} میں ابھی کچھ نہیں`, emptyBody: "ریکارڈنگز کو ان کے ⋮ مینو سے شامل کریں: فہرست میں شامل کریں۔",
    added: (n: string) => `${n} میں شامل ہو گئی`, removed: (n: string) => `${n} سے نکال دی گئی`, undo: "واپس لیں", dismiss: "بند کریں",
    rename: "فہرست کا نام بدلیں", recolour: "رنگ بدلیں", save: "محفوظ کریں",
    dialog: {
      title: "نئی فہرست", name: "فہرست کا نام", colour: "رنگ", create: "بنائیں", cancel: "منسوخ کریں", close: "بند کریں",
      duplicate: (n: string) => `آپ کی ${n} نام کی فہرست پہلے سے موجود ہے۔`,
      colourNames: { grey: "سرمئی", red: "سرخ", orange: "نارنجی", amber: "کہربائی", green: "سبز", teal: "فیروزی", blue: "نیلا", pink: "گلابی" },
    } as Partial<NewListWords>,
    renameWords: {
      title: "فہرست کا نام بدلیں", name: "فہرست کا نام", save: "محفوظ کریں", cancel: "منسوخ کریں", close: "بند کریں",
      duplicate: (n: string) => `آپ کی ${n} نام کی فہرست پہلے سے موجود ہے۔`,
    } as Partial<RenameListWords>,
    menu: { rename: "نام بدلیں…", recolour: "رنگ بدلیں…", moveLeft: "بائیں لے جائیں", moveRight: "دائیں لے جائیں", remove: "فہرست حذف کریں…" },
    addWords: { newList: "نئی فہرست…" },
    del: { title: (n: string) => `${n} حذف کریں؟`, body: "اس کی ریکارڈنگز آپ کی لائبریری میں رہیں گی؛ صرف فہرست ہٹے گی۔", confirm: "حذف کریں", cancel: "منسوخ کریں" },
    cancel: "منسوخ کریں", close: "بند کریں", colour: "رنگ", name: "فہرست کا نام",
  },
};

/** Which recordings are in which list (by list), which are unread and which are marked important. */
const MEMBERS: Record<string, string[]> = { r1: ["l1"], r2: ["l1"], r3: ["l2"], r4: ["l1", "l2"], r6: ["l1", "l3"], r8: ["l3"] };
const UNREAD = new Set(["r2", "r6"]);
const IMPORTANT = new Set(["r1", "r5"]);
const START: [string, LabelColour][] = [["l1", "teal"], ["l2", "amber"], ["l3", "pink"]];

type At = { x: number; y: number };
const pointOf = (e: MouseEvent<HTMLElement> | KeyboardEvent<HTMLButtonElement>): At =>
  "clientX" in e ? { x: e.clientX, y: e.clientY } : menuPointFor(e.currentTarget);

/**
 * D14 lists in the Library: the chip row under the search (All, Unread, Important, then the person's lists and "+"),
 * the chosen list's recordings with their lists' dots, the new-list dialog, Add to list on a row's right-click, and a
 * list chip's own menu.
 */
export function ListsScreen({ lang }: { lang: Lang }) {
  const t = COPY[lang].library;
  const w = WORDS[lang];
  const rows = COPY[lang].rows;
  const [lists, setLists] = useState<Lst[]>(() => START.map(([id, c], i) => ({ id, name: w.lists[i], colour: LABEL_COLOURS[c] })));
  const [members, setMembers] = useState<Record<string, string[]>>(MEMBERS);
  const [place, setPlace] = useState<Place>("all");
  const [query, setQuery] = useState("");
  const [creating, setCreating] = useState<{ forRow?: string } | null>(null);
  const [rowMenu, setRowMenu] = useState<{ id: string; at: At } | null>(null);
  const [chipMenu, setChipMenu] = useState<{ id: string; at: At } | null>(null);
  const [editing, setEditing] = useState<{ kind: "rename" | "recolour" | "delete"; id: string } | null>(null);
  const [draft, setDraft] = useState<{ name: string; colour: LabelColour }>({ name: "", colour: "grey" });
  const [toast, setToast] = useState<{ message: string; undo: () => void; key: number } | null>(null);

  const byId = (id: string) => lists.find((l) => l.id === id);
  const shown = useMemo(() => rows.filter((r) => {
    if (place === "unread" && !UNREAD.has(r.id)) return false;
    if (place === "important" && !IMPORTANT.has(r.id)) return false;
    if (place !== "all" && place !== "unread" && place !== "important" && !(members[r.id] ?? []).includes(place)) return false;
    const q = query.trim().toLocaleLowerCase();
    return !q || `${r.title} ${r.meta}`.toLocaleLowerCase().includes(q);
  }), [rows, place, members, query]);
  const days = (["today", "yesterday"] as const).filter((d) => shown.some((r) => r.day === d));

  const toggle = (rowId: string, listId: string) => {
    const before = members[rowId] ?? [];
    const on = before.includes(listId);
    setMembers({ ...members, [rowId]: on ? before.filter((x) => x !== listId) : [...before, listId] });
    const name = byId(listId)?.name ?? "";
    setToast({ message: on ? w.removed(name) : w.added(name), undo: () => setMembers((m) => ({ ...m, [rowId]: before })), key: Date.now() });
  };
  const move = (id: string, by: -1 | 1) => {
    const i = lists.findIndex((l) => l.id === id);
    const next = [...lists];
    [next[i], next[i + by]] = [next[i + by], next[i]];
    setLists(next);
  };
  const open = (kind: "rename" | "recolour" | "delete", id: string) => {
    const list = byId(id);
    if (!list) return;
    setDraft({ name: list.name, colour: labelColourName(list.colour) ?? "grey" });
    setEditing({ kind, id });
  };
  const save = () => {
    if (!editing) return;
    if (editing.kind === "delete") {
      setLists(lists.filter((l) => l.id !== editing.id));
      if (place === editing.id) setPlace("all");
    } else {
      setLists(lists.map((l) => (l.id !== editing.id ? l : { ...l, colour: LABEL_COLOURS[draft.colour] })));
    }
    setEditing(null);
  };

  const fixedNames = [w.all, w.unread, w.important];
  const placeName = place === "all" ? w.all : place === "unread" ? w.unread : place === "important" ? w.important : byId(place)?.name ?? "";
  const editingList = editing ? byId(editing.id) : undefined;
  const removal = editingList ? deleteListWords(editingList.name, lang === "ur" ? w.del : undefined) : null;

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
        <SearchField label={t.search} value={query} onChange={setQuery} />
        <ListChipRow label={w.row} addLabel={w.newList} onAdd={() => setCreating({})}>
          <ListChip name={w.all} fixed selected={place === "all"} onClick={() => setPlace("all")} />
          <ListChip name={w.unread} fixed count={UNREAD.size} selected={place === "unread"} onClick={() => setPlace("unread")} />
          <ListChip name={w.important} fixed selected={place === "important"} onClick={() => setPlace("important")} />
          {lists.map((l) => (
            <ListChip key={l.id} name={l.name} colour={l.colour} selected={place === l.id} onClick={() => setPlace(l.id)}
              onMenu={(at) => setChipMenu({ id: l.id, at })} />
          ))}
        </ListChipRow>
      </div>

      {shown.length === 0 ? (
        <EmptyState icon={Icons.LibraryIcon} title={w.emptyTitle(placeName)} body={w.emptyBody} />
      ) : (
        <List label={`${t.listLabel}: ${placeName}`}>
          {days.map((d) => [
            <ListSection key={d} title={d === "today" ? t.today : t.yesterday} />,
            ...shown.filter((r) => r.day === d).map((r) => {
              const own = (members[r.id] ?? []).map(byId).filter((l): l is Lst => !!l);
              return (
                <ListRow key={r.id} rowId={r.id} icon={(p) => <Icons.SourceAppIcon app={r.app} {...p} />} title={r.title} meta={r.meta}
                  mark={own.length ? <span className="g-lists-marks">{own.slice(0, 3).map((l) => <LabelDot key={l.id} name={l.name} colour={l.colour} />)}</span> : undefined}
                  endTop={r.time} endBottom={<span className="el-num">{r.duration}</span>} onClick={() => undefined}
                  onContextMenu={(e) => setRowMenu({ id: r.id, at: pointOf(e) })} />
              );
            }),
          ])}
        </List>
      )}

      {rowMenu && (
        <ContextMenu label={w.addTo} at={rowMenu.at} onClose={() => setRowMenu(null)}
          items={addToListItems({
            lists, checked: new Set((members[rowMenu.id] ?? []).map((id) => byId(id)?.name ?? "")), words: lang === "ur" ? w.addWords : undefined,
            onToggle: (name) => { const l = lists.find((x) => x.name === name); if (l) toggle(rowMenu.id, l.id); },
            onNew: () => setCreating({ forRow: rowMenu.id }),
          })} />
      )}
      {chipMenu && (() => {
        const i = lists.findIndex((l) => l.id === chipMenu.id);
        const list = lists[i];
        if (!list) return null;
        return (
          <ContextMenu label={list.name} at={chipMenu.at} onClose={() => setChipMenu(null)}
            items={listChipMenuItems({
              name: list.name, canMoveEarlier: i > 0, canMoveLater: i < lists.length - 1, rtl: lang === "ur", words: lang === "ur" ? w.menu : undefined,
              onRename: () => open("rename", list.id), onRecolour: () => open("recolour", list.id),
              onMoveEarlier: () => move(list.id, -1), onMoveLater: () => move(list.id, 1), onDelete: () => open("delete", list.id),
            })} />
        );
      })()}

      <NewListDialog open={!!creating} onClose={() => setCreating(null)} existing={lists.map((l) => l.name)} reserved={fixedNames} usedColours={lists.map((l) => l.colour)}
        words={lang === "ur" ? w.dialog : undefined}
        onCreate={(name, colour) => {
          const id = `l${Date.now()}`;
          setLists([...lists, { id, name, colour }]);
          if (creating?.forRow) setMembers({ ...members, [creating.forRow]: [...(members[creating.forRow] ?? []), id] });
          else setPlace(id);
          setCreating(null);
        }} />

      <RenameListDialog open={editing?.kind === "rename"} name={editingList?.name ?? ""} existing={lists.map((l) => l.name)} reserved={fixedNames}
        words={lang === "ur" ? w.renameWords : undefined} onClose={() => setEditing(null)}
        onRename={(name) => { setLists(lists.map((l) => (l.id === editing?.id ? { ...l, name } : l))); setEditing(null); }} />
      <Dialog open={editing?.kind === "recolour"} title={w.recolour} closeLabel={w.close} onClose={() => setEditing(null)}
        actions={<><Button onClick={() => setEditing(null)}>{w.cancel}</Button><Button variant="primary" onClick={save}>{w.save}</Button></>}>
        <ColourPicker label={w.colour} hideLabel value={draft.colour} onChange={(colour) => setDraft({ ...draft, colour })}
          names={lang === "ur" ? w.dialog.colourNames : undefined} />
      </Dialog>
      <Dialog open={editing?.kind === "delete"} alert title={removal?.title} description={removal?.body} closeLabel={w.close} onClose={() => setEditing(null)}
        actions={<><Button onClick={() => setEditing(null)}>{removal?.cancel}</Button><Button variant="danger" onClick={save}>{removal?.confirm}</Button></>} />

      <ToastRegion>
        {toast && <Toast key={toast.key} message={toast.message} actionLabel={w.undo} undo closeLabel={w.dismiss}
          onAction={() => { toast.undo(); setToast(null); }} onClose={() => setToast(null)} />}
      </ToastRegion>
    </div>
  );
}
