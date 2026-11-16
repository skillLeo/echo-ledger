//! Clock helpers: unix seconds, ISO-8601 UTC timestamps (the API's format) and milliseconds with one decimal.

use std::time::{Duration, SystemTime, UNIX_EPOCH};

pub fn unix_now() -> i64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map_or(0, |d| i64::try_from(d.as_secs()).unwrap_or(i64::MAX))
}

/// `2026-11-16T09:00:00+00:00` for a unix time (the API's timestamp form).
pub fn iso8601(unix: i64) -> String {
    let days = unix.div_euclid(86_400);
    let seconds = unix.rem_euclid(86_400);
    let (year, month, day) = civil_from_days(days);
    format!(
        "{year:04}-{month:02}-{day:02}T{:02}:{:02}:{:02}+00:00",
        seconds / 3600,
        seconds % 3600 / 60,
        seconds % 60
    )
}

/// Howard Hinnant's days-to-civil conversion (proleptic Gregorian calendar).
fn civil_from_days(days: i64) -> (i64, u32, u32) {
    let z = days + 719_468;
    let era = z.div_euclid(146_097);
    let doe = z.rem_euclid(146_097);
    let yoe = (doe - doe / 1460 + doe / 36_524 - doe / 146_096) / 365;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let day = (doy - (153 * mp + 2) / 5 + 1) as u32;
    let month = if mp < 10 { mp + 3 } else { mp - 9 } as u32;
    let year = yoe + era * 400 + i64::from(month <= 2);
    (year, month, day)
}

/// Milliseconds with one decimal.
pub fn ms(duration: Duration) -> f64 {
    (duration.as_secs_f64() * 10_000.0).round() / 10.0
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn timestamps_use_the_api_format() {
        assert_eq!(iso8601(0), "1970-01-01T00:00:00+00:00");
        assert_eq!(iso8601(1_763_283_600), "2025-11-16T09:00:00+00:00");
        assert_eq!(iso8601(951_782_400), "2000-02-29T00:00:00+00:00");
        assert_eq!(iso8601(4_107_542_399), "2100-02-28T23:59:59+00:00");
    }

    #[test]
    fn milliseconds_keep_one_decimal() {
        assert_eq!(ms(Duration::from_micros(1_234_567)), 1234.6);
        assert_eq!(ms(Duration::from_micros(40)), 0.0);
        assert_eq!(ms(Duration::from_micros(50)), 0.1);
    }
}
