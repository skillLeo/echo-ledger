// A project's filing rules in the new look: calls whose title contains a word, from an app, or with a contact land in
// this project by themselves. The same server rules as today's page (client_rules, client_rule_add,
// client_rule_delete); a removed rule can be put back with Undo.
import { useEffect, useState } from "react";
import { api, errorMessage, type FilingRule, type RuleKind } from "../../lib/ipc";
import { SOURCE_APP_LABELS } from "../../lib/format";
import { RULE_APPS, RULE_KINDS, ruleText } from "../../lib/sharing";
import { Button, Card, Icons, List, ListRow, Select, TextField } from "../components";

export function RulesV2({ projectId, projectName, say }: {
  projectId: string;
  projectName: string;
  /** One message with at most one action (the page's Toast). */
  say: (text: string, action?: { label: string; run: () => void }) => void;
}) {
  const [rules, setRules] = useState<FilingRule[] | null | undefined>(undefined);
  const [kind, setKind] = useState<RuleKind>("title");
  const [value, setValue] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let alive = true;
    setRules(undefined);
    api.clientRules(projectId).then((r) => alive && setRules(r ? r.data : null), (e) => {
      if (!alive) return;
      setRules([]);
      setError(errorMessage(e));
    });
    return () => {
      alive = false;
    };
  }, [projectId]);
  // An older server has no filing rules: nothing to show.
  if (rules === null) return null;

  const add = async (k: RuleKind, v: string) => {
    setBusy(true);
    setError(null);
    try {
      const { data } = await api.addClientRule(projectId, k, v);
      setRules((list) => [...(list ?? []).filter((r) => r.id !== data.id), data]);
      return data;
    } catch (e) {
      setError(errorMessage(e));
      return null;
    } finally {
      setBusy(false);
    }
  };
  /** Undo: the rule back, and what happened said through the page's one message. */
  const restore = async (rule: FilingRule) => {
    try {
      const { data } = await api.addClientRule(projectId, rule.kind, rule.value);
      setRules((list) => [...(list ?? []).filter((r) => r.id !== data.id), data]);
      say("Rule restored");
    } catch (e) {
      say(`The rule couldn't be restored: ${errorMessage(e)}`);
    }
  };
  const remove = async (rule: FilingRule) => {
    setBusy(true);
    setError(null);
    try {
      await api.deleteClientRule(projectId, String(rule.id));
      setRules((list) => (list ?? []).filter((r) => r.id !== rule.id));
      say(`Rule removed: ${ruleText(rule)}`, {
        label: "Undo",
        run: () => void restore(rule),
      });
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  const list = rules ?? [];
  const text = kind === "app" ? value || RULE_APPS[0] : value.trim();

  return (
    <section className="el-group el-workspace-rules" aria-label="Filing rules">
      <h2 className="el-caption el-body-strong el-group__title">Filing rules</h2>
      <Card>
        <p className="el-group__note el-home-empty el-secondary">{`Calls that match a rule go into ${projectName} by themselves.`}</p>
        {list.length > 0 && (
          <List label={`${projectName} filing rules`} className="el-group__rows">
            {list.map((rule) => (
              <ListRow key={String(rule.id)} compact iconStyle="plain" title={ruleText(rule)}
                icon={rule.kind === "app" ? (p) => <Icons.SourceAppIcon app={rule.value} {...p} /> : rule.kind === "contact" ? Icons.PhoneIcon : Icons.TranscriptIcon}
                control={<Button size="sm" variant="quiet" icon={Icons.TrashIcon} aria-label={`Remove the rule ${ruleText(rule)}`} disabled={busy} onClick={() => void remove(rule)} />} />
            ))}
          </List>
        )}
        <form className="el-form el-workspace-add" onSubmit={(e) => {
          e.preventDefault();
          if (!text) return;
          void add(kind, text).then((rule) => {
            if (!rule) return;
            setValue("");
            say(`Rule added: ${ruleText(rule)}`);
          });
        }}>
          <div className="el-form__row">
            <Select label="Kind of rule" value={kind} onChange={(v) => { setKind(v as RuleKind); setValue(""); }}
              options={RULE_KINDS.map(([k, label]) => ({ value: k, label }))} />
            {kind === "app" ? (
              <Select label="App" value={value || RULE_APPS[0]} onChange={setValue}
                options={RULE_APPS.map((a) => ({ value: a, label: SOURCE_APP_LABELS[a] ?? a }))} />
            ) : (
              <TextField label={kind === "contact" ? "Contact number or email" : "Title contains"} value={value}
                placeholder={kind === "contact" ? "+92 300 1234567" : "Qureshi"} onChange={(e) => setValue(e.target.value)} />
            )}
          </div>
          {error && <p className="el-field__error" role="alert">{error}</p>}
          <span><Button type="submit" disabled={!text || busy}>Add rule</Button></span>
        </form>
      </Card>
    </section>
  );
}
