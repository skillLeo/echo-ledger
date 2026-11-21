// The top of the Library when Library is where the app starts (PLAN_V2_PARITY 2: Home is removed as a tab): the
// get-started checklist as a card that can be hidden, until setup is done, and what needs action as one quiet banner.
// The same rules as Home's (home/HomeV2.tsx checklist and needsAction), from the same data.
import { useEffect, useState } from "react";
import { api, type AppStatus, type LibraryItem, type SkippedItem } from "../../lib/ipc";
import { useReadyReport } from "../../views/main/ReadyCheck";
import { accessOf, usePermissions } from "../../views/main/Permissions";
import { permissionKinds } from "../setup/SetupHealth";
import { checklist, needsAction, type ChecklistItem, type NeedsAction } from "../home/HomeV2";
import { Banner, Button, Card, Icon, Icons } from "../components";
import { Menu } from "../components/Menu";
import { isUntranscribed } from "./model";

const HIDDEN_KEY = "echoledger-library-setup-hidden";
const readHidden = () => {
  try {
    return window.localStorage.getItem(HIDDEN_KEY) === "1";
  } catch {
    return false;
  }
};

export type LibraryTopProps = {
  status: AppStatus;
  items: readonly LibraryItem[] | null;
  onSetup: () => void;
  onReadyCheck: () => void;
  onRecord?: () => void;
  onAction: (id: NeedsAction["id"]) => void;
  /** What the queue under it already shows with its own Review (waiting calls, uploads that need you): left out here. */
  covered?: { waiting: boolean; failed: boolean };
};

export function LibraryTop({ status, items, onSetup, onReadyCheck, onRecord, onAction, covered }: LibraryTopProps) {
  const [report] = useReadyReport();
  const [permissions] = usePermissions();
  const [skipped, setSkipped] = useState<SkippedItem[]>([]);
  const [hidden, setHidden] = useState(readHidden);
  useEffect(() => {
    api.skippedList().then((s) => setSkipped(Array.isArray(s) ? s : []), () => undefined);
  }, []);
  const live = (items ?? []).filter((i) => !i.deleted);
  const kinds = permissionKinds(status.platform);
  const permissionsOk = permissions ? kinds.every((k) => accessOf(permissions, k) === "allowed") : null;
  const known = permissions !== null && (report !== null || status.callsReady != null) && items !== null;
  const steps = known && !hidden ? checklist({ permissionsOk, ready: report?.ready ?? status.callsReady ?? null, recordings: live.length }) : null;
  const actions = needsAction({
    failed: covered?.failed ? 0 : status.failedUploads,
    waiting: covered?.waiting ? 0 : status.pendingDecisions,
    untranscribed: live.filter(isUntranscribed).length,
    missed: skipped.length,
  });
  const run = (id: ChecklistItem["id"]) => (id === "permissions" ? onSetup() : id === "ready" ? onReadyCheck() : onRecord?.());
  const hide = () => {
    setHidden(true);
    try {
      window.localStorage.setItem(HIDDEN_KEY, "1");
    } catch {
      // Storage can be unavailable: hidden until the window closes.
    }
  };
  if (!steps && actions.length === 0) return null;
  return (
    <div className="el-library-top">
      {steps && (
        <Card className="el-library-setup" label="Get started">
          <div className="el-group__head">
            <h2 className="el-body-strong">Get started</h2>
            <Button size="sm" variant="quiet" icon={Icons.CloseIcon} aria-label="Hide Get started" onClick={hide} />
          </div>
          <ol className="el-steps" aria-label="Get started">
            {steps.map((item, i) => (
              <li key={item.id} className={`el-step${item.done ? " el-step--done" : ""}`}>
                <span className="el-step__number" aria-hidden="true">{item.done ? <Icon icon={Icons.CheckIcon} /> : i + 1}</span>
                <div className="el-step__main el-home-check">
                  <span className="el-step__title">{item.title}</span>
                  <span className="el-caption">{item.done ? "Done" : "To do"}</span>
                </div>
                {!item.done && <Button size="sm" onClick={() => run(item.id)}>{item.action}</Button>}
              </li>
            ))}
          </ol>
        </Card>
      )}
      {actions.length > 0 && (
        <Banner icon={Icons.InfoIcon} testId="needs-action"
          action={actions.length === 1 ? <Button size="sm" onClick={() => onAction(actions[0].id)}>Review</Button>
            : <Menu label="Needs action" button={{ size: "sm", text: "Review", iconEnd: Icons.ChevronDownIcon }}
              items={actions.map((a) => ({ label: a.title, onPick: () => onAction(a.id) }))} />}>
          {actions.length === 1 ? actions[0].title : `${actions[0].title} · ${actions.length - 1} more need${actions.length === 2 ? "s" : ""} you`}
        </Banner>
      )}
    </div>
  );
}
