// Meeting assets in the v2 look (v1.8 P4.3, as the phone shows them): the screens mark on a Library row, the Screens
// strip under a recording's title, the screens between its transcript lines at their moments, and the viewer with
// what was said around each screen, Play from here and Delete.
import { Icon, Icons } from "../components";

/** The Library row's mark: a screen icon and the count, read as "3 screens". */
export function ScreensMark({ count }: { count: number }) {
  if (count <= 0) return null;
  return (
    <span className="el-screens-mark">
      <Icon icon={Icons.ScreenIcon} />
      <span className="el-num" aria-hidden="true">{count}</span>
      <span className="el-visually-hidden">{count === 1 ? "1 screen" : `${count} screens`}</span>
    </span>
  );
}
