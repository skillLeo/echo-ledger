// The top of the Library when Library is where the app starts (PLAN_V2_PARITY 2: Home is removed as a tab): the
// get-started checklist as a card that can be hidden, until setup is done. What needs the person is the sidebar's
// Needs you now (PLAN_LIBRARY_FILTERS: it took the Library header's "6 need you" button's place).
import { useEffect, useState } from "react";
import type { AppStatus, LibraryItem } from "../../lib/ipc";
import { useReadyReport } from "../../views/main/ReadyCheck";
import { accessOf, usePermissions } from "../../views/main/Permissions";
import { permissionKinds } from "../setup/SetupHealth";
import { checklist, type ChecklistItem } from "../home/HomeV2";
import { Button, Card, Icon, Icons } from "../components";

const HIDDEN_KEY = "echoledger-library-setup-hidden";
const readHidden = () => {
  try {
    return window.localStorage.getItem(HIDDEN_KEY) === "1";
  } catch {
    return false;
  }
};

/**
 * Where the Library's needs-action banner, now gone, kept what its × dismissed: one key per account under this prefix.
 * Nothing reads it any more; it is cleared once so an old dismissal can never hide anything.
 */
export const DISMISSED_PREFIX = "echoledger-library-needs-action-dismissed";
export function forgetDismissed(): void {
  try {
    const store = window.localStorage;
    const stale: string[] = [];
    for (let i = 0; i < store.length; i++) {
      const key = store.key(i);
      if (key?.startsWith(DISMISSED_PREFIX)) stale.push(key);
    }
    stale.forEach((key) => store.removeItem(key));
  } catch {
    // Storage can be unavailable: then there is nothing stored to clear.
  }
}


export type LibraryTopProps = {
  status: AppStatus;
  items: readonly LibraryItem[] | null;
  onSetup: () => void;
  onReadyCheck: () => void;
  onRecord?: () => void;
};

export function LibraryTop({ status, items, onSetup, onReadyCheck, onRecord }: LibraryTopProps) {
  // An old needs-action dismissal is cleared once (the banner it hid is gone).
  useEffect(() => forgetDismissed(), []);
  const [report] = useReadyReport();
  const [permissions] = usePermissions();
  const [hidden, setHidden] = useState(readHidden);
  const live = (items ?? []).filter((i) => !i.deleted);
  const kinds = permissionKinds(status.platform);
  const permissionsOk = permissions ? kinds.every((k) => accessOf(permissions, k) === "allowed") : null;
  const known = permissions !== null && (report !== null || status.callsReady != null) && items !== null;
  const steps = known && !hidden ? checklist({ permissionsOk, ready: report?.ready ?? status.callsReady ?? null, recordings: live.length }) : null;
  const run = (id: ChecklistItem["id"]) => (id === "permissions" ? onSetup() : id === "ready" ? onReadyCheck() : onRecord?.());
  const hide = () => {
    setHidden(true);
    try {
      window.localStorage.setItem(HIDDEN_KEY, "1");
    } catch {
      // Storage can be unavailable: hidden until the window closes.
    }
  };
  if (!steps) return null;
  return (
    <div className="el-library-top">
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
    </div>
  );
}

