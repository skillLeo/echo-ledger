// Ask in the new look (ui_v2.ask, docs/v2/IA.md 4), the same as the phone's Ask v2: pick what to ask about, ask in
// your own words, get the answer with the recordings and files it comes from (a recording opens at its moment). The
// server answers per project today (`client_ask`, POST /api/clients/{id}/ask), so the scope is a project; "All
// recordings" is said, not offered, until the server can.
import { useState } from "react";
import { api, errorMessage, type AnswerCitation, type AnswerLanguage, type ClientAnswer } from "../../lib/ipc";
import { timestamp } from "../../lib/format";
import { Banner, Button, Card, EmptyState, Icons, List, ListRow, ListSection, Loading, Segmented, Select, Skeleton, TextArea } from "../components";

export type AskState = { kind: "idle" } | { kind: "asking" } | { kind: "answer"; answer: ClientAnswer } | { kind: "failed"; words: string };

export const OFFLINE = "The answer couldn't be made. Check the internet connection and try again.";
export const TRY_AGAIN = "The answer couldn't be made. Try again.";
/** The core's words that aren't written for people (lib/ipc errors, src-tauri error.rs). */
const TECHNICAL = /^(Server returned|Audio error|I\/O error|Data error|Tauri error|Something went wrong|The speech engine could not be reached)/;

/**
 * What a failed Ask says: the connection only when offline; the server's own sentence when it is written for people
 * (the month's AI budget, AI not set up, a question too short); otherwise "Try again".
 */
export function askFailureWords(e: unknown, online: boolean = typeof navigator === "undefined" ? true : navigator.onLine): string {
  const text = errorMessage(e);
  if (!online || /^Network error/.test(text)) return OFFLINE;
  if (TECHNICAL.test(text)) return TRY_AGAIN;
  return text;
}

/** A source's line: "Call · 12:40", "Proforma March.pdf · page 3". */
export function citationValue(c: AnswerCitation): string | undefined {
  if (c.type === "call" && c.at_ms != null) return timestamp(c.at_ms);
  if (c.type === "file" && c.page != null) return `Page ${c.page}`;
  return undefined;
}

export type AskV2Props = {
  projects: { id: string; name: string }[];
  /** The project to start on (opened from a project's page). */
  initialProjectId?: string | null;
  onOpenAt: (sessionId: string, atMs: number) => void;
  onOpenProject: (projectId: string) => void;
  onLibrary: () => void;
  /** Inside a project's page: no page title and no project choice (it asks that project). */
  embedded?: boolean;
};

/** The answer languages the server takes (POST /api/clients/{id}/ask: en | roman_urdu); Urdu script waits for it. */
const LANGUAGES: { value: AnswerLanguage; label: string }[] = [{ value: "en", label: "English" }, { value: "roman_urdu", label: "Roman Urdu" }];

export function AskV2({ projects, initialProjectId = null, onOpenAt, onOpenProject, onLibrary, embedded = false }: AskV2Props) {
  const [projectId, setProjectId] = useState<string | null>(initialProjectId);
  const [question, setQuestion] = useState("");
  const [language, setLanguage] = useState<AnswerLanguage>("en");
  const [state, setState] = useState<AskState>({ kind: "idle" });
  const [copied, setCopied] = useState(false);
  const chosen = projects.find((p) => p.id === projectId) ?? projects[0] ?? null;
  // Another project: the answer on screen was about the last one.
  const choose = (id: string) => {
    if (id !== chosen?.id) setState({ kind: "idle" });
    setProjectId(id);
  };

  if (projects.length === 0) {
    return (
      <div className="el-page el-page--narrow" data-v2="ask">
        <header className="el-page__head"><h1 className="el-title-l">Ask</h1></header>
        <EmptyState icon={Icons.FolderIcon} title="No projects yet"
          body="Ask works on a project's recordings. Put recordings in a project from Library first."
          action={<Button onClick={onLibrary}>Open Library</Button>} />
      </div>
    );
  }

  const ask = async () => {
    if (!chosen || !question.trim()) return;
    setState({ kind: "asking" });
    setCopied(false);
    try {
      const r = await api.askClient(chosen.id, question.trim(), language);
      setState(r?.data ? { kind: "answer", answer: r.data } : { kind: "failed", words: TRY_AGAIN });
    } catch (e) {
      setState({ kind: "failed", words: askFailureWords(e) });
    }
  };

  return (
    <div className={embedded ? "el-ask el-ask--embedded" : "el-page el-page--narrow"} data-v2="ask">
      {!embedded && <header className="el-page__head"><h1 className="el-title-l">Ask</h1></header>}
      <Card className="el-form">
        {!embedded && <div className="el-field">
          <Select label="Ask about" value={chosen?.id ?? ""} onChange={choose} options={projects.map((p) => ({ value: p.id, label: p.name }))} />
          <span className="el-field__hint">All recordings (not available yet)</span>
        </div>}
        <TextArea label="Your question" value={question} placeholder="What did Bilal Traders agree on payment?" rows={3}
          onChange={(e) => setQuestion(e.target.value)}
          onKeyDown={(e) => { if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) void ask(); }} />
        <div className="el-ask__row">
          <div className="el-field">
            <span className="el-field__label">Answer in</span>
            <Segmented label="Answer in" options={LANGUAGES} value={language} onChange={setLanguage} />
          </div>
          <Button variant="primary" disabled={!question.trim()} loading={state.kind === "asking"} loadingLabel="Asking" onClick={() => void ask()}>Ask</Button>
        </div>
      </Card>

      {state.kind === "idle" && (
        <EmptyState icon={Icons.AskIcon} title="Ask your recordings" body="Pick a project, then ask in your own words. Answers say which recording they come from." />
      )}
      {state.kind === "asking" && (
        <Loading label="Asking"><Card className="el-form"><Skeleton width="long" /><Skeleton width="full" /><Skeleton width="medium" /></Card></Loading>
      )}
      {state.kind === "failed" && (
        <Banner tone="danger" icon={Icons.ErrorIcon} action={<Button size="sm" onClick={() => void ask()}>Ask</Button>}>
          {state.words}
        </Banner>
      )}
      {state.kind === "answer" && (
        <section className="el-group" aria-label="Answer">
          <Card className="el-form">
            <p className="el-ask__answer" dir="auto" aria-live="polite">{state.answer.answer}</p>
            <span>
              <Button size="sm" variant="quiet" icon={Icons.CopyIcon}
                onClick={() => void navigator.clipboard.writeText(state.answer.answer).then(() => setCopied(true), () => setCopied(false))}>
                {copied ? "Copied" : "Copy answer"}
              </Button>
              <Button size="sm" variant="quiet" icon={Icons.CloseIcon} aria-label="Clear the answer" onClick={() => setState({ kind: "idle" })} />
            </span>
          </Card>
          {state.answer.citations.length > 0 && (
            <Card>
              <List label="From" className="el-group__rows">
                <ListSection title="From" />
                {state.answer.citations.map((c, i) => (
                  <ListRow key={`${c.session_id ?? c.file_id ?? c.title}-${i}`} compact iconStyle="plain"
                    icon={c.type === "file" ? Icons.FolderIcon : Icons.PlayIcon}
                    title={c.title} meta={c.quote?.trim() || undefined} value={citationValue(c)} chevron
                    onClick={c.type === "call" && c.session_id ? () => onOpenAt(c.session_id!, c.at_ms ?? 0)
                      : c.type === "file" && chosen ? () => onOpenProject(chosen.id) : undefined} />
                ))}
              </List>
            </Card>
          )}
        </section>
      )}
    </div>
  );
}
