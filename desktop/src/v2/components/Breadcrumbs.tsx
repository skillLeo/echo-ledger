// Where a page is (Library › Zoom, Projects › Acme › 2026): each step before the last opens its page; the last is this
// page. The chevrons point the reading way, so they turn in right-to-left.
import { Icon } from "./Button";
import { ChevronEndIcon } from "./icons";
import { t } from "../../i18n";

export type Crumb = { label: string; onOpen?: () => void };

export function Breadcrumbs({ crumbs, label = t("Breadcrumbs") }: { crumbs: readonly Crumb[]; label?: string }) {
  if (crumbs.length < 2) return null;
  return (
    <nav className="el-crumbs" aria-label={label}>
      <ol className="el-crumbs__list">
        {crumbs.map((c, i) => {
          const last = i === crumbs.length - 1;
          return (
            <li key={`${c.label}-${i}`} className="el-crumbs__step">
              {i > 0 && <Icon icon={ChevronEndIcon} size="sm" directional />}
              {!last && c.onOpen
                ? <button type="button" className="el-crumbs__link" dir="auto" onClick={c.onOpen}>{c.label}</button>
                : <span className="el-crumbs__here" dir="auto" aria-current={last ? "page" : undefined}>{c.label}</span>}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
