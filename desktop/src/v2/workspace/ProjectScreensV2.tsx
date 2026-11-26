// A project's screens on its Files tab, in the new design (D-267): the keep switch as a settings row with the app's own
// Toggle, each recording's screens as thumbnails under its title, Show more, and the new design's screen viewer (its
// Play from here opens the recording at that moment). The data and the switch are classic's (useProjectScreens), so
// both designs keep the same behaviour; only the drawing is the new design's.
import { useState } from "react";
import type { ClientFolder, ProjectScreen, ProjectScreensPage } from "../../lib/ipc";
import { formatWhen } from "../../lib/format";
import { recordingName } from "../../lib/library";
import { screensBySession } from "../../lib/screens";
import type { Notify } from "../../lib/notice";
import { sessionLine, useProjectScreens } from "../../views/main/ScreenMoments";
import { lineDir, t, useLanguage } from "../../i18n";
import { Button, Card, EmptyState, Icons, List, ListRow, Toggle } from "../components";
import { ScreenThumb, ScreenViewerV2 } from "../library/screens";

export function ProjectScreensV2({ client, first, notify, onOpenRecording, onClientChanged }: {
  client: ClientFolder;
  first: ProjectScreensPage;
  notify: Notify;
  onOpenRecording: (id: string, ms?: number) => void;
  onClientChanged: (client: Partial<ClientFolder> & { id: string }) => void;
}) {
  useLanguage();
  const [open, setOpen] = useState<number | null>(null);
  const { screens, cursor, loading, more, renew, keeps, saving, toggle } = useProjectScreens({ client, first, notify, onClientChanged });
  const hint = keeps
    ? t("The desktop keeps what was on screen during :name's meetings.", { name: client.name })
    : t("Nothing on screen is kept for meetings filed under :name.", { name: client.name });
  const titleOf = (title: string | null) => (title?.trim() ? recordingName(title) : t("Recording"));
  const empty = t("When a meeting of :name is recorded on the desktop, what was on the shared screen shows here, next to its moment in the transcript.", { name: client.name });
  return (
    <div className="el-project-screens" data-testid="project-screens-v2">
      {keeps !== undefined && (
        <section aria-label={t("Screen moments of this project")}>
          <Card>
          <List label={t("Screen moments of this project")} className="el-group__rows">
            <ListRow compact icon={Icons.ScreenIcon} iconStyle="plain" title={t("Keep screen moments")} meta={hint}
              control={<Toggle label={t("Keep screen moments")} hideLabel checked={keeps} disabled={saving} onChange={() => void toggle()} />} />
          </List>
          </Card>
        </section>
      )}
      {screens.length === 0 ? (
        <EmptyState icon={Icons.ScreenIcon} title={t("No screen moments yet")} body={<span dir={lineDir(empty)}>{empty}</span>} />
      ) : (
        <div className="el-project-screens__groups" data-testid="project-screens">
          {screensBySession(screens).map((group) => (
            <section key={group.sessionId} className="el-project-screens__group" aria-label={titleOf(group.title)}>
              <h3 className="el-body-strong el-project-screens__title">
                <bdi>{titleOf(group.title)}</bdi>
                {group.startedAt && <span className="el-caption el-secondary"> · {formatWhen(group.startedAt)}</span>}
              </h3>
              <ul className="el-project-screens__grid">
                {group.screens.map((s) => (
                  <li key={s.id}><ScreenThumb screen={s} onOpen={() => setOpen(screens.indexOf(s))} /></li>
                ))}
              </ul>
            </section>
          ))}
          {cursor && (
            <div className="el-project-screens__more">
              <Button loading={loading} loadingLabel={t("Loading…")} onClick={() => void more()}>{t("Show more")}</Button>
            </div>
          )}
        </div>
      )}
      {open !== null && screens[open] && (
        <ScreenViewerV2 screens={screens} index={open} said={(s) => s.said ?? null} heading={(s) => sessionLine(s as ProjectScreen)} onIndex={setOpen} onClose={() => setOpen(null)} onExpired={renew}
          onPlay={(s) => { setOpen(null); onOpenRecording((s as ProjectScreen).session_id, s.at_ms); }} />
      )}
    </div>
  );
}
