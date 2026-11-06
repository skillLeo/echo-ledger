//! What the workspace's plan and the person's role allow (docs/API.md "Plans and features", "Workspaces, roles and
//! privacy", since v1.5), as the windows show it: the features they hide, the limits shown next to the usage, and
//! whether this computer may record. A server older than plans sends neither: every feature is on, there are no plan
//! limits, and the role is unknown (the app acts as a member).

use serde::Serialize;

use crate::net::models::ServerConfig;

/// The plan and role in `AppStatus.plan`.
#[derive(Clone, Debug, Default, PartialEq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct PlanInfo {
    /// The server sent a plan (a v1.5 server).
    pub known: bool,
    pub name: Option<String>,
    pub live_translate: bool,
    pub screen_moments: bool,
    pub ai_notes: bool,
    pub connector: bool,
    /// The plan's limits; none is no limit.
    pub monthly_minutes: Option<f64>,
    pub storage_bytes: Option<u64>,
    pub retention_days: Option<u32>,
    pub seats: Option<u32>,
    /// This month's minutes and the storage held, next to the limits.
    pub used_minutes: Option<f64>,
    pub used_storage_bytes: Option<u64>,
    pub workspace: Option<String>,
    /// `owner`, `admin`, `member` or `viewer`; none when the server did not say.
    pub role: Option<String>,
}

impl PlanInfo {
    /// From the last server config (none before the first one arrived). `screens_allowed` is false once the server
    /// answered `403 feature_not_in_plan` for screens, until a config says otherwise.
    pub fn from_config(config: Option<&ServerConfig>, screens_allowed: bool) -> Self {
        let Some(config) = config else {
            return Self { live_translate: true, screen_moments: screens_allowed, ai_notes: true, connector: true, ..Self::default() };
        };
        let plan = config.plan.as_ref();
        let limits = plan.and_then(|p| p.limits.clone()).unwrap_or_default();
        let used = plan.and_then(|p| p.used.clone()).unwrap_or_default();
        let usage = config.usage.as_ref();
        let role = config.workspace.as_ref().and_then(|w| w.role.as_deref()).map(|r| r.trim().to_ascii_lowercase()).filter(|r| ROLES.contains(&r.as_str()));
        Self {
            known: plan.is_some(),
            name: plan.and_then(|p| p.name.clone().or_else(|| p.key.clone())).filter(|n| !n.trim().is_empty()),
            live_translate: config.has_feature("live_translate"),
            screen_moments: config.has_feature("screen_moments") && screens_allowed,
            ai_notes: config.has_feature("ai_notes"),
            connector: config.has_feature("connector"),
            // Without a plan the server's own monthly cap (B20) is still the limit the usage runs against.
            monthly_minutes: if plan.is_some() { limits.monthly_minutes } else { usage.and_then(|u| u.limit_minutes).map(|m| m as f64) },
            storage_bytes: limits.storage_bytes,
            retention_days: limits.retention_days,
            seats: limits.seats,
            used_minutes: used.minutes.or(usage.map(|u| u.minutes)),
            used_storage_bytes: used.storage_bytes,
            workspace: config.workspace.as_ref().and_then(|w| w.name.clone()).filter(|n| !n.trim().is_empty()),
            role,
        }
    }

    /// A viewer opens what is shared with them and records nothing (`403 not_allowed` otherwise).
    pub fn can_record(&self) -> bool {
        self.role.as_deref() != Some("viewer")
    }
}

const ROLES: [&str; 4] = ["owner", "admin", "member", "viewer"];

/// What the app says for `403 feature_not_in_plan`, by the answer's `feature`.
pub fn feature_text(feature: Option<&str>) -> String {
    let what = match feature {
        Some("live_translate") => "Live Translate is",
        Some("screen_moments") => "Screen moments are",
        Some("ai_notes") => "Call notes, memory and Ask are",
        Some("connector") => "The Claude and ChatGPT connector is",
        _ => "This is",
    };
    format!("{what} not in your workspace's plan. The workspace owner can change the plan in the console.")
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn config(extra: serde_json::Value) -> ServerConfig {
        let mut base = json!({
            "device": {"id": "d", "name": "Mac", "platform": "macos", "source_device": "desktop"},
            "server_time": "2026-11-06T09:00:00+00:00", "retention_days": 30,
            "upload": {"chunk_size_bytes": 5242880, "max_recording_bytes": 2147483648u64},
            "usage": {"month": "2026-11", "time_zone": "UTC", "period_start": "", "period_end": "", "minutes": 812.5, "cost_usd": 1.0,
                      "limit_minutes": 3000, "limit_usd": null, "warn_at_percent": 80, "warn": false, "limit_reached": false}
        });
        base.as_object_mut().unwrap().extend(extra.as_object().unwrap().clone());
        serde_json::from_value(base).unwrap()
    }

    #[test]
    fn a_plan_hides_what_it_leaves_out_and_gives_its_limits() {
        let c = config(json!({
            "plan": {"key": "personal", "name": "Personal", "features": {"live_translate": false, "screen_moments": true, "ai_notes": false, "connector": true},
                     "limits": {"monthly_minutes": 600, "storage_bytes": 5368709120u64, "retention_days": 90, "seats": null},
                     "used": {"minutes": 123, "storage_bytes": 456}},
            "workspace": {"id": 1, "name": "Acme team", "role": "owner"}
        }));
        let info = PlanInfo::from_config(Some(&c), true);
        assert!(info.known);
        assert_eq!(info.name.as_deref(), Some("Personal"));
        assert!(!info.live_translate && info.screen_moments && !info.ai_notes && info.connector);
        assert_eq!(info.monthly_minutes, Some(600.0));
        assert_eq!(info.storage_bytes, Some(5_368_709_120));
        assert_eq!(info.retention_days, Some(90));
        assert_eq!(info.seats, None);
        assert_eq!(info.used_minutes, Some(123.0));
        assert_eq!(info.used_storage_bytes, Some(456));
        assert_eq!(info.workspace.as_deref(), Some("Acme team"));
        assert_eq!(info.role.as_deref(), Some("owner"));
        assert!(info.can_record());
        // A 403 for screens turns them off until a config says otherwise.
        assert!(!PlanInfo::from_config(Some(&c), false).screen_moments);
    }

    #[test]
    fn an_older_server_has_every_feature_and_its_own_cap() {
        let info = PlanInfo::from_config(Some(&config(json!({}))), true);
        assert!(!info.known);
        assert!(info.live_translate && info.screen_moments && info.ai_notes && info.connector);
        assert_eq!(info.monthly_minutes, Some(3000.0), "the server's monthly cap");
        assert_eq!(info.used_minutes, Some(812.5));
        assert_eq!(info.role, None);
        assert!(info.can_record(), "an unknown role acts as a member");
        let none = PlanInfo::from_config(None, true);
        assert!(none.live_translate && none.screen_moments && !none.known);
    }

    #[test]
    fn a_viewer_records_nothing_and_odd_roles_count_as_unknown() {
        let viewer = PlanInfo::from_config(Some(&config(json!({"plan": {"features": {}}, "workspace": {"name": "Acme", "role": "Viewer"}}))), true);
        assert_eq!(viewer.role.as_deref(), Some("viewer"));
        assert!(!viewer.can_record());
        // A plan with unnamed features and no limits block: everything on, no limits, the usage's minutes.
        assert!(viewer.live_translate && viewer.screen_moments);
        assert_eq!(viewer.monthly_minutes, None);
        assert_eq!(viewer.used_minutes, Some(812.5));
        let odd = PlanInfo::from_config(Some(&config(json!({"plan": {"features": {}}, "workspace": {"role": "superuser"}}))), true);
        assert_eq!(odd.role, None);
        assert!(odd.can_record());
        // A malformed limits block is dropped on its own.
        let bad = PlanInfo::from_config(Some(&config(json!({"plan": {"features": {"live_translate": false}, "limits": "lots"}}))), true);
        assert!(!bad.live_translate);
        assert_eq!(bad.monthly_minutes, None);
    }

    #[test]
    fn feature_not_in_plan_reads_as_plain_words() {
        assert_eq!(feature_text(Some("live_translate")), "Live Translate is not in your workspace's plan. The workspace owner can change the plan in the console.");
        assert!(feature_text(Some("screen_moments")).starts_with("Screen moments are not in your workspace's plan."));
        assert!(feature_text(None).starts_with("This is not in your workspace's plan."));
    }
}
