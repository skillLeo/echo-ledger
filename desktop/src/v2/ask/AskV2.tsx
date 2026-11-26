// Ask in the new look (ui_v2.ask, docs/v2/IA.md 4), the same as the phone's Ask v2: pick what to ask about, ask in
// your own words, get the answer with the recordings and files it comes from (a recording opens at its moment). The
// server answers per project today (`client_ask`, POST /api/clients/{id}/ask), so the scope is a project. "All
// recordings" isn't offered or mentioned at all until the server can ask across them. Its words are the window's
// language (D-261); the projects' names, the answer and its sources are as they are.
import { PanelToggle } from "../shell/NavRail";
import { useEffect, useState } from "react";
import { notifyWhenAway } from "../../lib/notify";
import { api, errorMessage, type AnswerCitation, type AnswerLanguage, type ClientAnswer } from "../../lib/ipc";
import { timestamp } from "../../lib/format";
import { Banner, Button, Card, EmptyState, Icons, List, ListRow, ListSection, Loading, Segmented, Select, Skeleton, TextArea } from "../components";
import { t, useLanguage, wordsIn, type Lang } from "../../i18n";

export type AskState = { kind: "idle" } | { kind: "asking" } | { kind: "answer"; answer: ClientAnswer } | { kind: "failed"; words: string };

/** What a failed Ask says offline, and otherwise when the reason isn't written for people: in the window's language or `lang`. */
export const offlineWords = (lang?: Lang): string => wordsIn(lang).t("The answer couldn't be made. Check the internet connection and try again.");
export const tryAgainWords = (lang?: Lang): string => wordsIn(lang).t("The answer couldn't be made. Try again.");
/** Their English. */
export const OFFLINE = offlineWords("en");
export const TRY_AGAIN = tryAgainWords("en");
/** The core's words that aren't written for people (lib/ipc errors, src-tauri error.rs). */
const TECHNICAL = /^(Server returned|Audio error|I\/O error|Data error|Tauri error|Something went wrong|The speech engine could not be reached)/;

/**
 * What a failed Ask says: the connection only when offline; the server's own sentence when it is written for people
 * (the month's AI budget, AI not set up, a question too short); otherwise "Try again". The app's own words are the
 * window's language.
 */
export function askFailureWords(e: unknown, online: boolean = typeof navigator === "undefined" ? true : navigator.onLine): string {
  const text = errorMessage(e);
  if (!online || /^Network error/.test(text)) return offlineWords();
  if (TECHNICAL.test(text)) return tryAgainWords();
  return text;
}

/** A source's value: the moment of a call ("12:40"), the page of a file ("Page 3", in the window's language). */
export function citationValue(c: AnswerCitation): string | undefined {
  if (c.type === "call" && c.at_ms != null) return timestamp(c.at_ms);
  if (c.type === "file" && c.page != null) return t("Page :page", { page: c.page });
  return undefined;
}

export type AskV2Props = {
  projects: { id: string; name: string }[];
  /** The project to start on (opened from a project's page). */
  initialProjectId?: string | null;
  /** A question to start from (the title row's search: Ask about "…"). */
  initialQuestion?: string;
  onOpenAt: (sessionId: string, atMs: number) => void;
  onOpenProject: (projectId: string) => void;
  onLibrary: () => void;
  /** Inside a project's page: no page title and no project choice (it asks that project). */
  embedded?: boolean;
};

/**
 * The answer languages the server takes (POST /api/clients/{id}/ask: en | roman_urdu); Urdu script waits for it. Their
 * names are the window's language (انگریزی, رومن اردو), read when shown.
 */
const LANGUAGES: { value: AnswerLanguage; label: string }[] = [
  { value: "en", get label() { return t("English"); } },
  { value: "roman_urdu", get label() { return t("Roman Urdu"); } },
];

export function AskV2({ projects, initialProjectId = null, initialQuestion = "", onOpenAt, onOpenProject, onLibrary, embedded = false }: AskV2Props) {
  useLanguage();
  const [projectId, setProjectId] = useState<string | null>(initialProjectId);
  const [question, setQuestion] = useState(initialQuestion);
  const [language, setLanguage] = useState<AnswerLanguage>("en");
  const [state, setState] = useState<AskState>({ kind: "idle" });
  const [copied, setCopied] = useState(false);
  const chosen = projects.find((p) => p.id === projectId) ?? projects[0] ?? null;
  // Whether the server has AI set up, read from the chosen project's workspace: said before anything is asked.
  const [aiReady, setAiReady] = useState<boolean | null>(null);
  useEffect(() => {
    setAiReady(null);
    if (!chosen) return;
    let live = true;
    api.clientWorkspace(chosen.id).then((r) => live && setAiReady(r?.data?.ai?.configured !== false), () => live && setAiReady(null));
    return () => {
      live = false;
    };
  }, [chosen?.id]); // eslint-disable-line react-hooks/exhaustive-deps
  // Another project: the answer on screen was about the last one.
  const choose = (id: string) => {
    if (id !== chosen?.id) setState({ kind: "idle" });
    setProjectId(id);
  };

  if (projects.length === 0) {
    return (
      <div className="el-page el-page--narrow" data-v2="ask">
        <header className="el-page__head"><PanelToggle /><h1 className="el-title-l">{t("Ask")}</h1></header>
        <EmptyState icon={Icons.FolderIcon} title={t("No projects yet")}
          body={t("Ask works on a project's recordings. Put recordings in a project from Library first.")}
          action={<Button onClick={onLibrary}>{t("Open Library")}</Button>} />
      </div>
    );
  }

  const ask = async () => {
    if (!chosen || !question.trim() || aiReady === false) return;
    setState({ kind: "asking" });
    setCopied(false);
    try {
      const q = question.trim();
      const r = await api.askClient(chosen.id, q, language);
      setState(r?.data ? { kind: "answer", answer: r.data } : { kind: "failed", words: tryAgainWords() });
      // Asked, then looked elsewhere: the answer says it is ready (classic's).
      if (r?.data) void notifyWhenAway(t("Answer ready"), q);
    } catch (e) {
      setState({ kind: "failed", words: askFailureWords(e) });
    }
  };

  return (
    <div className={embedded ? "el-ask el-ask--embedded" : "el-page el-page--narrow"} data-v2="ask">
      {!embedded && <header className="el-page__head"><PanelToggle /><h1 className="el-title-l">{t("Ask")}</h1></header>}
      <Card className="el-form">
        {!embedded && <div className="el-field">
          {/* Projects only: asking across all recordings comes when the server can; until then it isn't offered at all. */}
          <Select label={t("Ask about")} value={chosen?.id ?? ""} onChange={choose} options={projects.map((p) => ({ value: p.id, label: p.name }))} />
        </div>}
        <TextArea label={t("Your question")} value={question} placeholder={t("What did Bilal Traders agree on payment?")} rows={3}
          onChange={(e) => setQuestion(e.target.value)}
          onKeyDown={(e) => { if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) void ask(); }} />
        <div className="el-ask__row">
          <div className="el-field">
            <span className="el-field__label">{t("Answer in")}</span>
            <Segmented label={t("Answer in")} options={LANGUAGES} value={language} onChange={setLanguage} />
          </div>
          <Button variant="primary" disabled={!question.trim() || aiReady === false} loading={state.kind === "asking"} loadingLabel={t("Asking")} onClick={() => void ask()}>{t("Ask")}</Button>
        </div>
      </Card>
      {aiReady === false && (
        <Banner tone="warning" icon={Icons.WarningIcon} testId="ask-no-ai">
          {t("Ask needs AI set up on the server. Ask the person who runs the console to set it up.")}
        </Banner>
      )}

      {state.kind === "idle" && (
        <EmptyState icon={Icons.AskIcon} title={t("Ask your recordings")} body={t("Pick a project, then ask in your own words. Answers say which recording they come from.")} />
      )}
      {state.kind === "asking" && (
        <Loading label={t("Asking")}><Card className="el-form"><Skeleton width="long" /><Skeleton width="full" /><Skeleton width="medium" /></Card></Loading>
      )}
      {state.kind === "failed" && (
        <Banner tone="danger" icon={Icons.ErrorIcon} action={<Button size="sm" onClick={() => void ask()}>{t("Ask")}</Button>}>
          {state.words}
        </Banner>
      )}
      {state.kind === "answer" && (
        <section className="el-group" aria-label={t("Answer")}>
          <Card className="el-form">
            <p className="el-ask__answer" dir="auto" aria-live="polite">{state.answer.answer}</p>
            <span>
              <Button size="sm" variant="quiet" icon={Icons.CopyIcon}
                onClick={() => void navigator.clipboard.writeText(state.answer.answer).then(() => setCopied(true), () => setCopied(false))}>
                {copied ? t("Copied") : t("Copy answer")}
              </Button>
              <Button size="sm" variant="quiet" icon={Icons.CloseIcon} aria-label={t("Clear the answer")} onClick={() => setState({ kind: "idle" })} />
            </span>
          </Card>
          {state.answer.citations.length > 0 && (
            <Card>
              {/* Where the answer comes from: its sources (ur.json's "From" is ماخذ). */}
              <List label={t("From")} className="el-group__rows">
                <ListSection title={t("From")} />
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
