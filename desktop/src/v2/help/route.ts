// Which Help page Settings › Help opens: the new one while the new Settings is on (ui_v2.settings).
export const helpV2Reachable = (flags: Readonly<Record<string, boolean>>): boolean => flags["ui_v2.settings"] === true;
