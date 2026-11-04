// Filing rules (docs/API.md "Filing rules", since v1.5): how a recording that ends in no project finds one by itself,
// and where a recording's page says how it got into its project.
import { useEffect, useState } from "react";
import { api, type FilingRule, type LibraryItem } from "../../lib/ipc";
import { filedByRuleText } from "../../lib/sharing";

/**
 * "Filed by rule: title contains Acme" on a recording's page, when a filing rule put it in its project; "Filed by
 * contact" when its caller is one of the project's contacts. Nothing for a recording moved by hand.
 */
export function FiledByLine({ item }: { item: Pick<LibraryItem, "id" | "title" | "source_app" | "filed_by" | "client"> }) {
  const projectId = item.client?.id ?? null;
  const byRule = item.filed_by === "rule" && !!projectId;
  const [rules, setRules] = useState<FilingRule[] | null>(null);
  useEffect(() => {
    setRules(null);
    if (!byRule || !projectId) return;
    let alive = true;
    api.clientRules(projectId).then((r) => alive && setRules(r?.data ?? []), () => alive && setRules([]));
    return () => {
      alive = false;
    };
  }, [byRule, projectId, item.id]);
  if (item.filed_by === "contact" && item.client) {
    return <div className="filed-by small muted" data-testid="filed-by">Filed by contact: the caller is one of {item.client.name}'s contacts</div>;
  }
  if (!byRule) return null;
  return <div className="filed-by small muted" data-testid="filed-by">{filedByRuleText(item, rules)}</div>;
}
