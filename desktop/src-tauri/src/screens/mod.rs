//! Screen moments (docs/API.md "Screen moments", since v1.5): during a meeting the desktop keeps what was on the
//! shared screen at each moment, on the recording's clock, so the transcript and an AI reading it know what "this
//! page" meant.

pub mod detect;
pub mod encode;
pub mod words;
