import { useCallback, useEffect, useRef, useState, type AnimationEvent, type MouseEvent } from "react";
import { announceReady, api, errorMessage, listenTo, type AskAnswer, type AskPayload, type PendingItem, type ReviewPayload } from "../../lib/ipc";
import { useHitRegions } from "../../lib/hitRegions";
import { MAIN_LANGUAGES, MORE_LANGUAGES, englishOnlyRisk, languageChip, languageLabel, learnPrompt, menuLabel, type LanguageChoice } from "../../lib/languages";
import { alwaysIgnoreLabel, askSeconds, askSubject, countdownText, reviewDuration, reviewTitle } from "../../lib/controls";
import { MicIcon, SourceAppIcon } from "../../components/Icons";
import { CheckIcon, ChevronDownIcon, ChevronLeftIcon, MoreIcon, OpenIcon, PencilIcon, UserIcon } from "../../components/ControlIcons";
import "../controls.css";

/** How long a failed action's message replaces the countdown. */
const ERROR_MS = 4000;

/**
 * The `review` window (360 x 168, bottom-right): the Snipping-Tool-style review card for a finished recording and,
 * when an unrecognised app uses the microphone, the ask chip. The window never takes focus, so everything works on
 * click and only the visible card or chip is reported as a hit region; with nothing to show it is click-through.
 * The chip is time-critical (a call is starting) and lasts 8 s, so it shows in front of a waiting card, which
 * comes back when the chip goes.
 */
export default function ReviewApp() {
  const [review, setReview] = useState<ReviewPayload | null>(null);
  const [ask, setAsk] = useState<AskPayload | null>(null);
  const shownId = useRef<string | null>(null);
  useEffect(() => {
    shownId.current = review?.item.id ?? null;
  }, [review]);

  const sync = useCallback(() => api.reviewState().then(setReview).catch(() => undefined), []);

  const updateItem = useCallback((item: PendingItem) => {
    setReview((current) => (current && current.item.id === item.id ? { ...current, item } : current));
  }, []);

  useEffect(() => {
    let alive = true;
    let heardEvent = false;
    api.reviewState().then((payload) => {
      if (alive && !heardEvent) setReview(payload);
    }).catch(() => undefined);
    const subscriptions = [
      listenTo("review:show", (payload) => {
        heardEvent = true;
        setReview(payload);
      }),
      listenTo("review:hide", () => {
        heardEvent = true;
        setReview(null);
      }),
      listenTo("ask:show", setAsk),
      listenTo("ask:hide", () => setAsk(null)),
      // Renamed or assigned in the main window: show the new title and client; gone from the list: ask the core.
      listenTo("pending:changed", (items) => {
        if (!shownId.current) return;
        const fresh = items.find((i) => i.id === shownId.current);
        if (fresh) updateItem(fresh);
        else void sync();
      }),
      // The core applies the policy in force when the time runs out, so the countdown follows the setting.
      listenTo("settings:changed", (settings) => {
        setReview((current) => (current && current.timeout !== settings.decisionTimeout ? { ...current, timeout: settings.decisionTimeout } : current));
      }),
    ];
    announceReady(subscriptions, () => alive);
    return () => {
      alive = false;
      subscriptions.forEach((p) => p.then((unlisten) => unlisten()).catch(() => undefined));
    };
  }, [sync, updateItem]);

  useHitRegions([review?.item.id ?? null, ask?.id ?? null]);

  const closeAsk = useCallback((id: string) => setAsk((current) => (current?.id === id ? null : current)), []);

  return (
    <div className="review-root">
      {ask ? (
        <AskChip key={ask.id} ask={ask} onClose={closeAsk} />
      ) : review ? (
        <ReviewCard key={review.item.id} review={review} onItem={updateItem} onSync={sync} />
      ) : null}
    </div>
  );
}

function ReviewCard({ review, onItem, onSync }: { review: ReviewPayload; onItem: (item: PendingItem) => void; onSync: () => Promise<void> }) {
  const { item, timeout, queueLength } = review;
  const [now, setNow] = useState(() => Date.now());
  const [menu, setMenu] = useState(false);
  const [clients, setClients] = useState(false);
  const [languages, setLanguages] = useState(false);
  const [learn, setLearn] = useState<{ question: string; clientId: string; language: LanguageChoice } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [entered, setEntered] = useState(false);
  // The recording whose "English only" was warned about (once per recording).
  const [englishNote, setEnglishNote] = useState<string | null>(null);
  const warnedEnglish = useRef<Set<string>>(new Set());
  const busy = useRef(false);

  const counting = timeout !== "wait" && item.deadlineAt !== null;
  useEffect(() => {
    if (!counting) return;
    setNow(Date.now());
    const timer = window.setInterval(() => setNow(Date.now()), 250);
    return () => window.clearInterval(timer);
  }, [counting]);

  useEffect(() => {
    if (!error) return;
    const timer = window.setTimeout(() => setError(null), ERROR_MS);
    return () => window.clearTimeout(timer);
  }, [error]);

  useEffect(() => {
    if (!menu && !clients && !languages) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== "Escape") return;
      setMenu(false);
      setClients(false);
      setLanguages(false);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [menu, clients, languages]);

  /** One command at a time; afterwards the core says what the card shows next (the next item, or nothing). */
  const act = async (command: () => Promise<unknown>) => {
    if (busy.current) return;
    busy.current = true;
    setMenu(false);
    setError(null);
    try {
      await command();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      busy.current = false;
      await onSync();
    }
  };

  const assign = async (clientId: string | null) => {
    if (busy.current) return;
    busy.current = true;
    setError(null);
    try {
      onItem(await api.pendingAssignClient(item.id, clientId));
    } catch (e) {
      setError(errorMessage(e));
      await onSync();
    } finally {
      busy.current = false;
      setClients(false);
    }
  };

  const openClients = () => {
    setMenu(false);
    setLanguages(false);
    setClients(true);
  };

  /** The language chip: Keep transcribes in the chosen language; a client using another one gets the learn prompt. */
  const pickLanguage = async (language: LanguageChoice) => {
    if (busy.current) return;
    busy.current = true;
    setError(null);
    try {
      const updated = await api.pendingSetLanguage(item.id, language);
      onItem(updated);
      if (language === "en" && !warnedEnglish.current.has(item.id) && englishOnlyRisk(null, item.firstLine)) {
        warnedEnglish.current.add(item.id);
        setEnglishNote(item.id);
      }
      if (updated.clientId) {
        const client = (await api.clients().catch(() => null))?.data.find((c) => c.id === updated.clientId);
        const question = client ? learnPrompt(client.name, client.language, language) : null;
        setLearn(question && client ? { question, clientId: client.id, language } : null);
      }
    } catch (e) {
      setError(errorMessage(e));
      await onSync();
    } finally {
      busy.current = false;
      setLanguages(false);
    }
  };

  const answerLearn = async (yes: boolean) => {
    const asked = learn;
    setLearn(null);
    if (!yes || !asked) return;
    await api.updateClient(asked.clientId, { language: asked.language }).catch((e) => setError(errorMessage(e)));
  };

  // A click anywhere else on the card closes the menu (there is no focus to lose).
  const closeMenuOutside = (e: MouseEvent<HTMLElement>) => {
    if (menu && !(e.target as Element).closest(".review-menu, .review-more")) setMenu(false);
  };

  // Re-measures the hit region once the slide-in transform is gone (the class change is a DOM mutation).
  const onAnimationEnd = (e: AnimationEvent<HTMLElement>) => {
    if (e.target === e.currentTarget) setEntered(true);
  };

  const title = reviewTitle(item);
  const countdown = counting ? countdownText(timeout, item.deadlineAt, now) : null;

  return (
    <section className={`review-card${entered ? " entered" : ""}`} data-hit data-testid="review-card" aria-label="Review recording"
      onMouseDown={closeMenuOutside} onAnimationEnd={onAnimationEnd}>
      <div className="review-head">
        <span className="review-tile"><SourceAppIcon app={item.sourceApp} size={18} /></span>
        <div className="review-heading">
          <div className="review-title" title={title}>{title}</div>
          <div className="review-meta">
            <span className="review-duration">{reviewDuration(item.durationMs)}</span>
            <span className="review-sep" aria-hidden="true">·</span>
            <button type="button" className={`client-chip${item.clientName ? "" : " empty"}`} title="Assign client"
              aria-haspopup="menu" aria-expanded={clients} onClick={openClients}>
              <UserIcon size={12} />
              <span className="client-chip-name">{item.clientName ?? "No client"}</span>
              <ChevronDownIcon size={12} />
            </button>
            <button type="button" className="client-chip lang" data-testid="review-language" title={`Language: ${languageLabel(item.language)}`}
              aria-label={`Language: ${languageLabel(item.language)}`} aria-haspopup="menu" aria-expanded={languages}
              onClick={() => { setMenu(false); setClients(false); setLanguages(true); }}>
              {languageChip(item.language)}
              <ChevronDownIcon size={12} />
            </button>
          </div>
        </div>
        {queueLength > 1 && <span className="review-count">1 of {queueLength}</span>}
      </div>
      {learn ? (
        <div className="review-learn" role="group" aria-label="Remember the language" data-testid="review-learn">
          <span className="review-learn-q" title={learn.question}>{learn.question}</span>
          <button type="button" className="ctl-btn" onClick={() => answerLearn(true)}>Yes</button>
          <button type="button" className="ctl-btn" onClick={() => answerLearn(false)}>No</button>
        </div>
      ) : englishNote === item.id && item.language === "en" ? (
        <div className="review-line" role="alert" data-testid="english-only-warning" title={ENGLISH_ONLY_NOTE}>{ENGLISH_ONLY_NOTE}</div>
      ) : (
        <div className={`review-line${item.firstLine ? "" : " none"}`} title={item.firstLine ?? undefined}>{item.firstLine ?? "No captions"}</div>
      )}
      <div className="review-foot">
        <button type="button" className="ctl-btn primary" onClick={() => act(() => api.pendingKeep(item.id))}>Keep</button>
        <button type="button" className="ctl-btn" onClick={() => act(() => api.pendingDiscard(item.id))}>Discard</button>
        {error ? (
          <span className="review-status error" role="alert" title={error}>{error}</span>
        ) : (
          <span className="review-status" data-testid="review-countdown">{countdown}</span>
        )}
        <button type="button" className="ctl-btn icon review-more" aria-label="More actions" title="More actions"
          aria-haspopup="menu" aria-expanded={menu} onClick={() => setMenu((open) => !open)}>
          <MoreIcon size={16} />
        </button>
      </div>
      {menu && (
        <div className="review-menu" role="menu" aria-label="More actions">
          <button type="button" role="menuitem" onClick={() => act(() => api.pendingOpen(item.id, true))}><PencilIcon size={14} />Rename</button>
          <button type="button" role="menuitem" onClick={openClients}><UserIcon size={14} />Assign client</button>
          <button type="button" role="menuitem" onClick={() => act(() => api.pendingOpen(item.id, false))}><OpenIcon size={14} />Open</button>
        </div>
      )}
      {clients && <ClientList selected={item.clientId} onPick={assign} onBack={() => setClients(false)} />}
      {languages && <LanguageList selected={item.language ?? null} onPick={pickLanguage} onBack={() => setLanguages(false)} />}
    </section>
  );
}

interface ClientOption {
  id: string;
  name: string;
}

/** Click list of clients over the card body, `None` first; it scrolls with the wheel inside the card. */
function ClientList({ selected, onPick, onBack }: { selected: string | null; onPick: (id: string | null) => void; onBack: () => void }) {
  const [clients, setClients] = useState<ClientOption[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let alive = true;
    api.clients()
      .then((response) => {
        if (alive) setClients(response.data.map(({ id, name }) => ({ id, name })));
      })
      .catch((e) => {
        if (alive) setError(errorMessage(e));
      });
    return () => {
      alive = false;
    };
  }, []);

  const option = (id: string | null, name: string) => (
    <button type="button" role="menuitemradio" aria-checked={selected === id} className="review-option" key={id ?? "none"}
      onClick={() => onPick(id)}>
      <span className="review-option-name">{name}</span>
      {selected === id && <CheckIcon size={14} />}
    </button>
  );

  return (
    <div className="review-panel" data-testid="client-list">
      <div className="review-panel-head">
        <button type="button" className="ctl-btn icon small" aria-label="Back" title="Back" onClick={onBack}><ChevronLeftIcon size={15} /></button>
        <span>Assign client</span>
      </div>
      <div className="review-panel-list" role="menu" aria-label="Clients">
        {option(null, "None")}
        {clients?.map((c) => option(c.id, c.name))}
        {!clients && !error && <div className="review-panel-note">Loading clients…</div>}
        {error && <div className="review-panel-note error" role="alert">{error}</div>}
      </div>
    </div>
  );
}

/** Said once when English only is picked for a call whose captions show Urdu (the engine would translate it). */
const ENGLISH_ONLY_NOTE = "This call has Urdu in it: English only would translate it. Pick Urdu (with English words) to keep every word.";

/** The language list over the card body (More languages behind one row); the final transcript uses the choice at Keep. */
function LanguageList({ selected, onPick, onBack }: { selected: LanguageChoice; onPick: (language: LanguageChoice) => void; onBack: () => void }) {
  const [more, setMore] = useState(MORE_LANGUAGES.some((o) => o.value === selected));
  const shown = more ? [...MAIN_LANGUAGES, ...MORE_LANGUAGES] : MAIN_LANGUAGES;
  return (
    <div className="review-panel" data-testid="language-list">
      <div className="review-panel-head">
        <button type="button" className="ctl-btn icon small" aria-label="Back" title="Back" onClick={onBack}><ChevronLeftIcon size={15} /></button>
        <span>Language of this recording</span>
      </div>
      <div className="review-panel-list" role="menu" aria-label="Languages">
        {shown.map((o) => (
          <button type="button" role="menuitemradio" aria-checked={selected === o.value} className="review-option" key={o.chip}
            onClick={() => onPick(o.value)}>
            <span className="review-option-name">{menuLabel(o)}</span>
            {selected === o.value && <CheckIcon size={14} />}
          </button>
        ))}
        {!more && (
          <button type="button" role="menuitem" className="review-option" onClick={() => setMore(true)}>
            <span className="review-option-name">More languages…</span>
          </button>
        )}
      </div>
    </div>
  );
}

/** The unknown-app chip (A1.5): Record / Ignore / Always ignore, gone after `seconds` (8) without an answer. */
function AskChip({ ask, onClose }: { ask: AskPayload; onClose: (id: string) => void }) {
  const seconds = askSeconds(ask);
  const [error, setError] = useState<string | null>(null);
  const busy = useRef(false);

  useEffect(() => {
    const timer = window.setTimeout(() => onClose(ask.id), seconds * 1000);
    return () => window.clearTimeout(timer);
  }, [ask.id, seconds, onClose]);

  const answer = async (value: AskAnswer) => {
    if (busy.current) return;
    busy.current = true;
    setError(null);
    try {
      await api.askAnswer(ask.id, value);
      onClose(ask.id);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      busy.current = false;
    }
  };

  const subject = askSubject(ask);
  return (
    <section className="ask-chip" data-hit data-testid="ask-chip" aria-label="Microphone in use">
      <div className="ask-head">
        <span className="ask-icon"><MicIcon size={15} /></span>
        <div className="ask-text" data-testid="ask-text" title={`${subject} is using the microphone`}>
          <b>{subject}</b> is using the microphone
        </div>
      </div>
      {error && <div className="ask-error" role="alert">{error}</div>}
      <div className="ask-actions">
        <button type="button" className="ctl-btn primary" onClick={() => answer("record")}><span className="ask-rec-dot" aria-hidden="true" />Record</button>
        <button type="button" className="ctl-btn" onClick={() => answer("ignore")}>Ignore</button>
        <button type="button" className="ctl-btn" onClick={() => answer("always_ignore")}>{alwaysIgnoreLabel(ask)}</button>
      </div>
      <div className="ask-progress" aria-hidden="true"><span style={{ animationDuration: `${seconds}s` }} /></div>
    </section>
  );
}
