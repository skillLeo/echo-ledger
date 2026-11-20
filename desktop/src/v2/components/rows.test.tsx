import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { List, ListRow } from "./ListRow";
import { EmptyState } from "./EmptyState";
import { Banner } from "./Banner";
import { Dialog } from "./Dialog";
import { Button } from "./Button";

describe("ListRow options for lists whose rows act only through ⋮ (Shared)", () => {
  const row = (extra: Record<string, unknown>) => render(
    <List label="Shared with me">
      <ListRow title="Call with Omar" meta="Recording from Imran · Today · 8:45 AM" onClick={() => undefined}
        actions={<Button variant="quiet" size="sm" aria-label="More for Call with Omar" />} {...extra} />
    </List>,
  ).container.querySelector(".el-row")!;

  it("keeps the actions on hover by default, and on screen with actionsShown=\"always\"", () => {
    expect(row({})).not.toHaveClass("el-row--actions-always");
    expect(row({ actionsShown: "always" })).toHaveClass("el-row--actions-always");
  });

  it("lets the meta line take two lines with metaLines={2}", () => {
    expect(row({})).not.toHaveClass("el-row--meta-2");
    expect(row({ metaLines: 2 })).toHaveClass("el-row--meta-2");
  });

  it("never marks a row without actions as always showing them", () => {
    const { container } = render(<List label="x"><ListRow title="Plain" actionsShown="always" /></List>);
    expect(container.querySelector(".el-row")).not.toHaveClass("el-row--actions-always");
  });
});

describe("free text in the shared components takes its own direction (D4 4)", () => {
  it("an empty state's body, a banner's words and a dialog's description are dir=auto", () => {
    render(
      <>
        <EmptyState title="Nothing shared yet" body="When a teammate sends you a recording, it shows here." />
        <Banner>Calls are not being recorded.</Banner>
        <Dialog open title="Request files" description="Anyone with a request link can send you voice notes." onClose={() => undefined} />
      </>,
    );
    expect(screen.getByText("When a teammate sends you a recording, it shows here.")).toHaveAttribute("dir", "auto");
    expect(screen.getByText("Calls are not being recorded.")).toHaveAttribute("dir", "auto");
    expect(screen.getByText("Anyone with a request link can send you voice notes.")).toHaveAttribute("dir", "auto");
  });
});
