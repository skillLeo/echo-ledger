// Filing rules (docs/API.md "Filing rules", since v1.5): how a recording that ends in no project finds one by itself,
// edited on the project's page, and where a recording's page says how it got into its project.
import { useEffect, useId, useState } from "react";
import { api, errorMessage, type ClientFolder, type FilingRule, type LibraryItem, type RuleKind } from "../../lib/ipc";
import { SOURCE_APP_LABELS } from "../../lib/format";
import { filedByRuleText, RULE_APPS, RULE_KINDS, ruleText } from "../../lib/sharing";
import { PlusIcon, TrashIcon } from "../../components/Icons";

const PLACEHOLDER: Record<RuleKind, string> = {
  title: "Words in the title, like Acme",
  app: "",
  contact: "+92 300 1234567 or ana@example.com",
};

/**
 * The project's filing rules: the list with Remove, and Add (title contains, app, contact number or email). A server
 * without filing rules shows nothing. What the core refuses (the same rule on another project, a number that is not
 * one) is said under the form.
 */
export function RulesSection({ project, notify }: { project: Pick<ClientFolder, "id" | "name">; notify: (text: string, error?: boolean) => void }) {
  const [rules, setRules] = useState<FilingRule[] | null | undefined>(undefined);
  const [open, setOpen] = useState(false);
  const [kind, setKind] = useState<RuleKind>("title");
  const [value, setValue] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [unread, setUnread] = useState(false);
  const kindId = useId();
  const valueId = useId();
  useEffect(() => {
    let alive = true;
    setRules(undefined);
    setUnread(false);
    api.clientRules(project.id).then((r) => alive && setRules(r ? r.data : null), () => {
      if (!alive) return;
      setRules([]);
      setUnread(true);
    });
    return () => {
      alive = false;
    };
  }, [project.id]);
  // An older server: no filing rules at all.
  if (rules === null) return null;

  const add = async () => {
    const text = kind === "app" ? value : value.trim();
    if (!text || busy) return;
    setBusy(true);
    setError(null);
    try {
      const { data } = await api.addClientRule(project.id, kind, text);
      setRules((list) => [...(list ?? []).filter((r) => r.id !== data.id), data]);
      setValue("");
      notify(`Rule added: ${ruleText(data)}`);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  const remove = async (rule: FilingRule) => {
    setBusy(true);
    try {
      await api.deleteClientRule(project.id, String(rule.id));
      setRules((list) => (list ?? []).filter((r) => r.id !== rule.id));
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setBusy(false);
    }
  };
  const list = rules ?? [];
  return (
    <section className="card rules-card" aria-labelledby={`${kindId}-title`}>
      <div className="row">
        <b id={`${kindId}-title`}>Filing rules</b>
        <span className="small muted rules-summary">
          {rules === undefined ? "Loading…" : unread ? "Couldn't load them. Check the connection." : list.length === 0 ? "None yet" : list.map(ruleText).join(" · ")}
        </span>
        <span className="spacer" />
        <button type="button" className="btn ghost compact" aria-expanded={open} onClick={() => setOpen(!open)}>{open ? "Done" : "Edit rules"}</button>
      </div>
      {open && (
        <>
          <p className="small muted" style={{ margin: "8px 0" }}>
            A recording that ends in no project comes to {project.name} when one of these matches: its caller is a contact, its title contains the words, or it comes from the app. A recording already in a project is never moved.
          </p>
          {list.length > 0 && (
            <ul className="rules-list" aria-label={`${project.name} filing rules`}>
              {list.map((rule) => (
                <li key={rule.id} className="rule-row">
                  <span className="rule-text" dir="auto">{ruleText(rule)}</span>
                  <button type="button" className="btn ghost compact icon-only" aria-label={`Remove the rule ${ruleText(rule)}`} disabled={busy} onClick={() => void remove(rule)}><TrashIcon size={15} /></button>
                </li>
              ))}
            </ul>
          )}
          <form className="rule-add" onSubmit={(e) => { e.preventDefault(); void add(); }}>
            <label htmlFor={kindId} className="sr-only">Kind of rule</label>
            <select id={kindId} className="select" value={kind} onChange={(e) => { setKind(e.target.value as RuleKind); setValue(""); setError(null); }}>
              {RULE_KINDS.map(([k, label]) => <option key={k} value={k}>{label}</option>)}
            </select>
            <label htmlFor={valueId} className="sr-only">{kind === "app" ? "App" : kind === "contact" ? "Contact number or email" : "Title contains"}</label>
            {kind === "app" ? (
              <select id={valueId} className="select" value={value} onChange={(e) => setValue(e.target.value)}>
                <option value="">Choose the app</option>
                {RULE_APPS.map((app) => <option key={app} value={app}>{SOURCE_APP_LABELS[app] ?? app}</option>)}
              </select>
            ) : (
              <input id={valueId} className="input" maxLength={120} placeholder={PLACEHOLDER[kind]} value={value} dir="auto" onChange={(e) => setValue(e.target.value)} />
            )}
            <button type="submit" className="btn" disabled={busy || !value.trim()}><PlusIcon size={16} />Add rule</button>
          </form>
          {error && <div className="notice error small" role="alert" style={{ marginTop: 8 }}>{error}</div>}
        </>
      )}
    </section>
  );
}

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
