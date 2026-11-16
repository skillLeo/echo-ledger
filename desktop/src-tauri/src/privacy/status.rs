//! The privacy badge every floating window shows, from the last real check (pure): Private (a lock) when Windows read
//! the flag back on build 19041 or later, or the last Share Check passed on this system version and app version; Black
//! box on Windows before 19041 with that setting (people see a black box instead of EchoLedger); Not checked when
//! nothing was measured yet on this system version; Visible in screen share (amber) on old Windows shown as it is, in
//! Remote Desktop, after a failed check or a flag changed from outside, or while EchoLedger is shown on purpose (the
//! setting Off, the demo).

use serde::Serialize;

use super::pixels::Outcome;
use crate::settings::ShareHiding;

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum Badge {
    Private,
    /// Windows before 19041: a black box where EchoLedger is.
    BlackBox,
    NotChecked,
    Visible,
}

impl Badge {
    pub fn label(self) -> &'static str {
        match self {
            Badge::Private => "Private",
            Badge::BlackBox => "Black box",
            Badge::NotChecked => "Not checked",
            Badge::Visible => "Visible in screen share",
        }
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Platform {
    Windows,
    Mac,
    Other,
}

impl Platform {
    pub fn here() -> Self {
        if cfg!(windows) {
            Platform::Windows
        } else if cfg!(target_os = "macos") {
            Platform::Mac
        } else {
            Platform::Other
        }
    }
}

/// What the badge is decided from.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Facts {
    pub platform: Platform,
    pub hiding: ShareHiding,
    pub demo: bool,
    /// Windows' build number.
    pub build: Option<u32>,
    pub remote: bool,
    /// Some window's flag did not read back as set.
    pub mismatch: bool,
    /// At least one window's flag read back as set.
    pub held: bool,
    /// The last Share Check on this system version and app version.
    pub check: Option<Outcome>,
    /// Windows before 19041 with "Black box" chosen.
    pub black_box: bool,
}

pub fn badge(f: &Facts) -> Badge {
    if f.hiding == ShareHiding::Off || f.demo {
        return Badge::Visible;
    }
    match f.platform {
        Platform::Windows => {
            if f.remote || f.mismatch || f.check == Some(Outcome::Fail) {
                Badge::Visible
            } else if f.build.is_none_or(|b| b < super::win::EXCLUDE_BUILD) {
                if f.black_box { Badge::BlackBox } else { Badge::Visible }
            } else if f.held || f.check == Some(Outcome::Pass) {
                Badge::Private
            } else {
                Badge::NotChecked
            }
        }
        Platform::Mac => match f.check {
            Some(Outcome::Pass) => Badge::Private,
            Some(Outcome::Fail) => Badge::Visible,
            _ => Badge::NotChecked,
        },
        Platform::Other => Badge::NotChecked,
    }
}

/// The system's name as people know it: "Windows 11 24H2", "Windows 10 22H2", "macOS 27.3".
pub fn system_name(platform: Platform, build: Option<u32>, mac_version: &str) -> String {
    match platform {
        Platform::Mac => format!("macOS {mac_version}"),
        Platform::Windows => match build {
            Some(b) if b >= 22_000 => {
                let release = match b {
                    26_200.. => "25H2",
                    26_100.. => "24H2",
                    22_631.. => "23H2",
                    22_621.. => "22H2",
                    _ => "21H2",
                };
                format!("Windows 11 {release}")
            }
            Some(b) if b >= 10_240 => {
                let release = match b {
                    19_045.. => Some("22H2"),
                    19_044 => Some("21H2"),
                    19_043 => Some("21H1"),
                    19_042 => Some("20H2"),
                    19_041 => Some("2004"),
                    _ => None,
                };
                release.map_or_else(|| format!("Windows 10 (build {b})"), |r| format!("Windows 10 {r}"))
            }
            Some(b) => format!("Windows (build {b})"),
            None => "Windows".into(),
        },
        Platform::Other => "this system".into(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn windows(build: u32) -> Facts {
        Facts { platform: Platform::Windows, hiding: ShareHiding::All, demo: false, build: Some(build), remote: false, mismatch: false, held: true, check: None, black_box: false }
    }

    fn mac(check: Option<Outcome>) -> Facts {
        Facts { platform: Platform::Mac, hiding: ShareHiding::All, demo: false, build: None, remote: false, mismatch: false, held: false, check, black_box: false }
    }

    #[test]
    fn windows_badges_follow_the_read_back_the_build_and_remote_desktop() {
        assert_eq!(badge(&windows(26_100)), Badge::Private, "read back on 24H2");
        assert_eq!(badge(&Facts { held: false, ..windows(26_100) }), Badge::NotChecked, "nothing read back yet");
        assert_eq!(badge(&windows(18_363)), Badge::Visible, "old Windows shown as it is");
        assert_eq!(badge(&Facts { black_box: true, ..windows(18_363) }), Badge::BlackBox, "old Windows with Black box: private as a black box");
        assert_eq!(badge(&Facts { black_box: true, check: Some(Outcome::Fail), ..windows(18_363) }), Badge::Visible, "a failed check wins");
        assert_eq!(badge(&Facts { remote: true, ..windows(26_100) }), Badge::Visible, "Remote Desktop");
        assert_eq!(badge(&Facts { mismatch: true, ..windows(26_100) }), Badge::Visible, "a flag did not hold");
        assert_eq!(badge(&Facts { check: Some(Outcome::Fail), ..windows(26_100) }), Badge::Visible, "a failed Share Check");
    }

    #[test]
    fn mac_badges_come_from_the_share_check_of_this_version() {
        assert_eq!(badge(&mac(Some(Outcome::Pass))), Badge::Private);
        assert_eq!(badge(&mac(Some(Outcome::Fail))), Badge::Visible);
        assert_eq!(badge(&mac(Some(Outcome::Error))), Badge::NotChecked, "an error measured nothing");
        assert_eq!(badge(&mac(None)), Badge::NotChecked);
    }

    #[test]
    fn showing_echoledger_on_purpose_is_visible() {
        assert_eq!(badge(&Facts { hiding: ShareHiding::Off, ..mac(Some(Outcome::Pass)) }), Badge::Visible);
        assert_eq!(badge(&Facts { demo: true, ..windows(26_100) }), Badge::Visible);
        assert_eq!(badge(&Facts { hiding: ShareHiding::Floating, ..windows(26_100) }), Badge::Private, "the badge sits on floating windows");
        assert_eq!(Badge::Visible.label(), "Visible in screen share");
        assert_eq!(Badge::BlackBox.label(), "Black box");
    }

    #[test]
    fn systems_are_named_as_people_know_them() {
        assert_eq!(system_name(Platform::Windows, Some(26_100), ""), "Windows 11 24H2");
        assert_eq!(system_name(Platform::Windows, Some(22_631), ""), "Windows 11 23H2");
        assert_eq!(system_name(Platform::Windows, Some(19_045), ""), "Windows 10 22H2");
        assert_eq!(system_name(Platform::Windows, Some(18_363), ""), "Windows 10 (build 18363)");
        assert_eq!(system_name(Platform::Mac, None, "27.3"), "macOS 27.3");
    }
}
