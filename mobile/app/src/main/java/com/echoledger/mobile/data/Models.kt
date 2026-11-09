package com.echoledger.mobile.data

import kotlinx.serialization.EncodeDefault
import kotlinx.serialization.ExperimentalSerializationApi
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement

/** Wire types for docs/API.md. */
val ApiJson = Json {
    ignoreUnknownKeys = true
    explicitNulls = false
    encodeDefaults = true
}

@Serializable
data class DeviceDto(val id: String, val name: String, val platform: String, @SerialName("source_device") val sourceDevice: String)

@Serializable
data class UploadConfig(
    @SerialName("chunk_size_bytes") val chunkSizeBytes: Long,
    @SerialName("max_recording_bytes") val maxRecordingBytes: Long,
    @SerialName("accepted_mimes") val acceptedMimes: List<String> = emptyList()
)

@Serializable
data class ProviderConfig(
    val provider: String,
    val mode: String,
    @SerialName("required_artifacts") val requiredArtifacts: List<String>? = null,
    @SerialName("preferred_primary") val preferredPrimary: String? = null
)

@Serializable
data class CaptionsConfig(val channels: String = "both")

@Serializable
data class ServerConfig(
    val device: DeviceDto,
    @SerialName("server_time") val serverTime: String,
    @SerialName("retention_days") val retentionDays: Int,
    val upload: UploadConfig,
    val providers: Map<String, ProviderConfig> = emptyMap(),
    val captions: CaptionsConfig = CaptionsConfig(),
    val pins: List<String> = emptyList(),
    /** "1.1" on a v1.1 server; absent on a v1 server (the app then uses the v1 flows). */
    @SerialName("api_version") val apiVersion: String? = null,
    val usage: UsageDto? = null,
    /** Bookmark tag presets from the console (since v1.1). */
    val markers: MarkersConfig? = null,
    /** How unsure words appear in copied text (since v1.3, plan A4). */
    val unsure: UnsureConfig? = null,
    /** The console's Live Translate defaults and this month's spending (since v1.3, Phase B). */
    @SerialName("live_translate") val liveTranslate: LiveTranslateConfig? = null,
    /** The workspace plan and this person's role there (since v1.5); null on older servers. */
    val plan: PlanDto? = null,
    val workspace: ConfigWorkspaceDto? = null,
    /** Since v1.6 (P2.7): this app is older than the admin's minimum; null when it is not. */
    @SerialName("app_update") val appUpdate: AppUpdateDto? = null,
    /** Since v1.6: the admin's banner for everyone; null when there is none. */
    val announcement: AnnouncementDto? = null,
    /** Since v1.6: the server is in maintenance; null when it is not. */
    val maintenance: MaintenanceDto? = null
)

/** `GET /api/config` `app_update` (since v1.6). [refused]: the server no longer takes this version's uploads. */
@Serializable
data class AppUpdateDto(
    val minimum: String = "",
    val required: Boolean = false,
    val refused: Boolean = false,
    val message: String = "",
    val url: String? = null
)

/** `GET /api/config` `announcement` (since v1.6): [level] `info` or `warning`, shown until [until] (ISO) or for good. */
@Serializable
data class AnnouncementDto(val text: String = "", val level: String = "info", val until: String? = null)

/** `GET /api/config` `maintenance` (since v1.6). */
@Serializable
data class MaintenanceDto(val message: String = "", val until: String? = null)

@Serializable
data class LiveTranslateConfig(
    @SerialName("monthly_usd") val monthlyUsd: Double? = null,
    val game: LiveTranslateModeConfig? = null,
    val watch: LiveTranslateModeConfig? = null
)

@Serializable
data class LiveTranslateModeConfig(val target: String? = null, val spoken: List<String>? = null, val speed: String? = null)

@Serializable
data class UnsureConfig(val style: String = "brackets", val threshold: Double = 0.5, val note: Boolean = true)

@Serializable
data class MarkersConfig(val tags: List<String> = emptyList())

/** A bookmark on the recording's timeline (since v1.1): recorded time, optional preset tag and short note. */
@Serializable
data class MarkerDto(@SerialName("at_ms") val atMs: Long, val tag: String? = null, val note: String? = null)

/** A paused stretch (since v1.1): at recorded time `at_ms` the recording paused for `paused_ms` of wall time. */
@Serializable
data class PauseDto(@SerialName("at_ms") val atMs: Long, @SerialName("paused_ms") val pausedMs: Long)

/** The month's speech-to-text use and the console limits (docs/API.md `GET /api/config` `usage`, since v1.1). */
@Serializable
data class UsageDto(
    val month: String? = null,
    val minutes: Double = 0.0,
    @SerialName("cost_usd") val costUsd: Double = 0.0,
    @SerialName("limit_minutes") val limitMinutes: Int? = null,
    @SerialName("limit_usd") val limitUsd: Double? = null,
    @SerialName("warn_at_percent") val warnAtPercent: Int = 80,
    val warn: Boolean = false,
    @SerialName("limit_reached") val limitReached: Boolean = false
)

@Serializable
data class RegisterResponse(
    val device: DeviceDto,
    val token: String,
    @SerialName("token_expires_at") val tokenExpiresAt: String? = null,
    val config: ServerConfig
)

@Serializable
data class AudioSpec(
    val encoding: String,
    @SerialName("sample_rate") val sampleRate: Int,
    val channels: Int,
    @SerialName("frame_ms") val frameMs: Int
)

@Serializable
data class Grant(
    val channel: String,
    val transport: String,
    val protocol: String? = null,
    @SerialName("ws_url") val wsUrl: String? = null,
    @SerialName("max_session_seconds") val maxSessionSeconds: Long? = null,
    val audio: AudioSpec? = null,
    @SerialName("init_messages") val initMessages: List<JsonElement> = emptyList()
)

@Serializable
data class SttTokenResponse(
    val provider: String? = null,
    val mode: String,
    @SerialName("expires_at") val expiresAt: String? = null,
    val keyterms: List<String> = emptyList(),
    val grants: List<Grant> = emptyList()
)

@Serializable
data class ArtifactDto(
    val role: String,
    val mime: String,
    val codec: String?,
    val channels: Int,
    @SerialName("sample_rate") val sampleRate: Int?,
    @SerialName("size_bytes") val sizeBytes: Long,
    val sha256: String,
    @SerialName("duration_ms") val durationMs: Long?
)

/** The language chip changed during the recording (since v1.2): the recording time and the new language (null = Auto). */
@Serializable
data class LanguageMarkDto(@SerialName("at_ms") val atMs: Long, val language: String? = null)

@Serializable
data class CreateSessionRequest(
    @SerialName("client_ref") val clientRef: String,
    @SerialName("source_app") val sourceApp: String,
    @SerialName("client_id") val clientId: String?,
    val title: String?,
    @SerialName("started_at") val startedAt: String,
    @SerialName("ended_at") val endedAt: String?,
    @SerialName("duration_ms") val durationMs: Long?,
    val language: String?,
    val artifacts: List<ArtifactDto>,
    /** Sent only when there are some, so a v1 server never sees the keys. */
    @OptIn(ExperimentalSerializationApi::class)
    @EncodeDefault(EncodeDefault.Mode.NEVER)
    val markers: List<MarkerDto> = emptyList(),
    @OptIn(ExperimentalSerializationApi::class)
    @EncodeDefault(EncodeDefault.Mode.NEVER)
    val pauses: List<PauseDto> = emptyList(),
    /** Sent only when the chip changed during the recording (since v1.2). */
    @OptIn(ExperimentalSerializationApi::class)
    @EncodeDefault(EncodeDefault.Mode.NEVER)
    @SerialName("language_marks") val languageMarks: List<LanguageMarkDto> = emptyList(),
    /** Who the call was with, when the phone saw it (since v1.2). */
    @OptIn(ExperimentalSerializationApi::class)
    @EncodeDefault(EncodeDefault.Mode.NEVER)
    val caller: CallerDto? = null,
    /** Imports (since 1.4): skip a file the Library already has (`409 duplicate_recording`). Left out when false. */
    @OptIn(ExperimentalSerializationApi::class)
    @EncodeDefault(EncodeDefault.Mode.NEVER)
    @SerialName("skip_duplicate") val skipDuplicate: Boolean = false
)

@Serializable
data class UploadState(
    @SerialName("chunk_size_bytes") val chunkSizeBytes: Long = 0,
    @SerialName("total_chunks") val totalChunks: Int,
    @SerialName("received_chunks") val receivedChunks: List<Int> = emptyList(),
    val status: String,
    /** Why the server gave up on the file (status `failed`): the device uploads it again from the start. */
    val error: String? = null
)

/** `POST /api/sessions` without artifacts: an open session for live captions (since v1.1). */
@Serializable
data class OpenSessionRequest(
    @SerialName("client_ref") val clientRef: String,
    @SerialName("source_app") val sourceApp: String,
    @SerialName("client_id") val clientId: String?,
    @SerialName("started_at") val startedAt: String,
    /** The language chosen in Settings (`en`, `ur`, `hi`, `en+ur`); left out for automatic. */
    @OptIn(ExperimentalSerializationApi::class)
    @EncodeDefault(EncodeDefault.Mode.NEVER)
    val language: String? = null
)

/** `POST /api/sessions/{id}/finish`: a null `client_id` is left out, so the server keeps the stored client. */
@Serializable
data class FinishSessionRequest(
    @SerialName("ended_at") val endedAt: String,
    @SerialName("duration_ms") val durationMs: Long?,
    @SerialName("client_id") val clientId: String? = null,
    val artifacts: List<ArtifactDto>,
    /** A title typed before Keep (since v1.3); null is left out, so a title set elsewhere is never cleared. */
    val title: String? = null,
    /** The Keep card's language (since v1.1.2): a code, or "" for automatic, so the choice always reaches the server. */
    val language: String = "",
    /** Sent only when there are some, so a v1 server never sees the keys. */
    @OptIn(ExperimentalSerializationApi::class)
    @EncodeDefault(EncodeDefault.Mode.NEVER)
    val markers: List<MarkerDto> = emptyList(),
    @OptIn(ExperimentalSerializationApi::class)
    @EncodeDefault(EncodeDefault.Mode.NEVER)
    val pauses: List<PauseDto> = emptyList(),
    /** Sent only when the chip changed during the recording (since v1.2). */
    @OptIn(ExperimentalSerializationApi::class)
    @EncodeDefault(EncodeDefault.Mode.NEVER)
    @SerialName("language_marks") val languageMarks: List<LanguageMarkDto> = emptyList(),
    /** Who the call was with, when the phone saw it (since v1.2). */
    @OptIn(ExperimentalSerializationApi::class)
    @EncodeDefault(EncodeDefault.Mode.NEVER)
    val caller: CallerDto? = null
)

/** Caller names (since v1.2): who a recording was with and where the phone saw the name. */
@Serializable
data class CallerDto(val name: String? = null, val number: String? = null, val source: String? = null)

/** One streaming period (socket open → close) for `POST /api/usage` (since v1.1). */
@Serializable
data class UsageReport(
    @SerialName("report_id") val reportId: String,
    @SerialName("use_case") val useCase: String,
    val provider: String,
    @SerialName("session_id") val sessionId: String? = null,
    @SerialName("streamed_ms") val streamedMs: Long,
    val channels: Int,
    @SerialName("ended_at") val endedAt: String? = null
)

/** `POST /api/recordings/{id}/complete`: `ready` or `assembling`. */
@Serializable
data class CompleteAnswer(val status: String = "assembling")

@Serializable
data class RecordingUpload(val id: String, val role: String, val upload: UploadState)

@Serializable
data class LibraryItem(
    val id: String,
    @SerialName("client_ref") val clientRef: String? = null,
    val deleted: Boolean = false,
    @SerialName("source_device") val sourceDevice: String? = null,
    @SerialName("source_device_label") val sourceDeviceLabel: String? = null,
    @SerialName("source_app") val sourceApp: String? = null,
    @SerialName("source_app_label") val sourceAppLabel: String? = null,
    val device: NamedRef? = null,
    val client: NamedRef? = null,
    val title: String? = null,
    /** The server wrote this title from what was said (since v1.5); a title a person set is false and never replaced. */
    @SerialName("title_auto") val titleAuto: Boolean = false,
    @SerialName("started_at") val startedAt: String? = null,
    @SerialName("duration_ms") val durationMs: Long? = null,
    val status: String? = null,
    val transcript: TranscriptSummary? = null,
    @SerialName("updated_at") val updatedAt: String,
    /** Bookmarks on the recording and the distinct tags among them (since v1.1). */
    @SerialName("marker_count") val markerCount: Int = 0,
    val tags: List<String> = emptyList(),
    /** Pinned to the top and the recording's labels (since v1.1, B15). */
    val pinned: Boolean = false,
    val labels: List<String> = emptyList(),
    /** Never deleted by the retention rules (B17). */
    val keep: Boolean = false,
    /** The recording's language chip, "UR+EN" (since v1.1.2). */
    @SerialName("language_chip") val languageChip: String? = null,
    /** The language chosen for the recording (since v1.1.2); null = automatic. */
    val language: String? = null,
    /** Whether this user has opened it (since v1.3); false shows the unopened dot, null (an older server) does not. */
    val opened: Boolean? = null,
    /** How it got its project (since v1.5): "user", "contact", "rule", or null (the Inbox). */
    @SerialName("filed_by") val filedBy: String? = null,
    /** Screen moments kept during the meeting (since v1.5); null from an older server. */
    @SerialName("screen_count") val screenCount: Int? = null
)

@Serializable
data class NamedRef(val id: String, val name: String, val color: String? = null, val avatar: String? = null)

@Serializable
data class TranscriptSummary(
    @SerialName("final_status") val finalStatus: String? = null,
    @SerialName("final_transcript_id") val finalTranscriptId: String? = null,
    @SerialName("live_available") val liveAvailable: Boolean = false,
    @SerialName("live_transcript_id") val liveTranscriptId: String? = null,
    val preview: String? = null,
    /** The language the final transcript came back in (since v1.1.2). */
    @SerialName("final_language") val finalLanguage: String? = null,
    /** Why the final transcript failed (since v1.1.2); `no_audio`: the recording has no sound. */
    @SerialName("final_error_code") val finalErrorCode: String? = null,
    /** The other version (since v1.2): the previous transcript after "Re-transcribe as", or a second engine's run. */
    @SerialName("alternate_transcript_id") val alternateTranscriptId: String? = null,
    @SerialName("alternate_status") val alternateStatus: String? = null,
    @SerialName("alternate_language") val alternateLanguage: String? = null,
    /** Parts done of all parts while a long recording is transcribed in pieces (since v1.2). */
    @SerialName("final_progress") val finalProgress: ProgressDto? = null,
    /** The views of the finished transcript (since v1.5): being made and how far, for "Improving Roman Urdu… 60%". */
    val views: RowViewsDto? = null
)

/** A Library row's views (since v1.5, Phase 1C): `{"status": "making", "default": "roman", "progress": {"roman": {…}}}`. */
@Serializable
data class RowViewsDto(val status: String = "none", val default: String = "original", val progress: Map<String, ProgressDto> = emptyMap())

/**
 * Parts done of all parts while a recording is transcribed (since v1.2 for runs in pieces, since v1.5 for every run), and
 * the seconds left from the engine's measured speed (null until the server has a measure). Also lines done of lines of a
 * view being made (`views.progress`, since v1.5).
 */
@Serializable
data class ProgressDto(val done: Int = 0, val total: Int = 0, @SerialName("eta_seconds") val etaSeconds: Long? = null)

/** `GET /api/me/entitlements` (since v1.6, P1): what this person's plan allows; the server alone decides. */
@Serializable
data class EntitlementsDto(
    val plan: EntitlementPlanDto = EntitlementPlanDto(),
    val features: Map<String, FeatureDto> = emptyMap(),
    val limits: Map<String, LimitDto> = emptyMap(),
    val engines: List<String> = emptyList(),
    @SerialName("transcription_key") val transcriptionKey: TranscriptionKeyDto? = null,
    @SerialName("upgrade_request") val upgradeRequest: UpgradeRequestDto? = null,
    val plans: List<PlanOfferDto> = emptyList()
)

@Serializable
data class EntitlementPlanDto(
    val key: String = "",
    val name: String = "",
    val badge: String = "",
    val color: String? = null,
    @SerialName("expires_at") val expiresAt: String? = null,
    @SerialName("trial_minutes_left") val trialMinutesLeft: Long = 0
)

@Serializable
data class FeatureDto(
    val enabled: Boolean = true,
    val name: String = "",
    val description: String = "",
    val cost: String = "free",
    @SerialName("lowest_plan") val lowestPlan: String? = null
)

@Serializable
data class LimitDto(val limit: Long? = null, val used: Long? = null)

@Serializable
data class TranscriptionKeyDto(val provider: String = "groq", val set: Boolean = false, val last4: String? = null)

@Serializable
data class UpgradeRequestDto(
    val id: String,
    val plan: String,
    val status: String = "pending",
    val message: String? = null,
    @SerialName("payment_instructions") val paymentInstructions: String? = null,
    val reply: String? = null,
    @SerialName("created_at") val createdAt: String? = null,
    @SerialName("decided_at") val decidedAt: String? = null,
    @SerialName("approved_plan") val approvedPlan: String? = null,
    @SerialName("approved_until") val approvedUntil: String? = null,
    /** Since v1.6.2: the promo code used, its discount and what is left to pay. */
    @SerialName("promo_code") val promoCode: String? = null,
    val percent: Int? = null,
    @SerialName("amount_due") val amountDue: String? = null
)

/** `POST /api/promo-codes/check` (since v1.6.2): what a promo code does for its one plan. */
@Serializable
data class PromoCheckDto(
    val code: String,
    val plan: String,
    @SerialName("plan_name") val planName: String = "",
    val percent: Int = 0,
    val price: String? = null,
    @SerialName("final_price") val finalPrice: String? = null,
    val free: Boolean = false,
    val months: Int = 1
)

@Serializable
data class PlanOfferDto(
    val key: String,
    val name: String,
    val badge: String = "",
    val color: String? = null,
    val price: String? = null,
    val description: String? = null,
    val features: List<String> = emptyList(),
    val limits: Map<String, Long?> = emptyMap()
)

/** `POST /api/auth/start` (since v1.6): whether the email has an account, and what it can sign in with. */
@Serializable
data class AuthStartDto(
    val account: String = "existing",
    val methods: List<String> = emptyList(),
    @SerialName("sign_up") val signUp: Boolean = false
)

/** `POST /api/auth/code` (since v1.6): a code was emailed; again after [resendAfter] s, valid [expiresIn] s. */
@Serializable
data class CodeSentDto(@SerialName("resend_after") val resendAfter: Int = 60, @SerialName("expires_in") val expiresIn: Int = 600)

/** `POST /api/auth/code/verify` for a new email (since v1.6): the name and Terms come next. */
@Serializable
data class SignUpNextDto(val next: String = "profile", @SerialName("sign_up_token") val signUpToken: String)

/** `GET /api/auth/options` (since v1.5, Phase 1C): what the sign-in screen offers on this server. */
@Serializable
data class AuthOptionsDto(
    val password: Boolean = true,
    val google: Boolean = false,
    @SerialName("sign_up") val signUp: Boolean = false,
    @SerialName("sign_up_url") val signUpUrl: String? = null,
    @SerialName("reset_url") val resetUrl: String? = null,
    @SerialName("browser_sign_in") val browserSignIn: Boolean = true
)

@Serializable
data class CreateSessionResponse(
    val session: LibraryItem,
    val recordings: List<RecordingUpload>,
    @SerialName("required_artifacts") val requiredArtifacts: List<String> = emptyList()
)

@Serializable
data class LibraryResponse(
    val data: List<LibraryItem>,
    @SerialName("next_cursor") val nextCursor: String? = null,
    @SerialName("server_time") val serverTime: String
)

@Serializable
data class SegmentDto(
    val channel: Int,
    val speaker: String,
    @SerialName("start_ms") val startMs: Long,
    @SerialName("end_ms") val endMs: Long,
    val text: String,
    /** Word timings when the engine gave them; never sent by the phone. */
    @OptIn(ExperimentalSerializationApi::class)
    @EncodeDefault(EncodeDefault.Mode.NEVER)
    val words: List<WordDto> = emptyList(),
    /** The engine was not sure of this line (since v1.2); kept as heard. Never sent by the phone. */
    @OptIn(ExperimentalSerializationApi::class)
    @EncodeDefault(EncodeDefault.Mode.NEVER)
    val uncertain: Boolean = false,
    /** Double-check: what a second engine heard on this line when it disagreed (since v1.2). Never sent by the phone. */
    @OptIn(ExperimentalSerializationApi::class)
    @EncodeDefault(EncodeDefault.Mode.NEVER)
    val check: SegmentCheckDto? = null
)

/** What a second engine heard on a line (Double-check): shown under it, never merged into the transcript. */
@Serializable
data class SegmentCheckDto(val provider: String = "", val label: String = "", val text: String = "")

@Serializable
data class WordDto(
    val text: String = "",
    @SerialName("start_ms") val startMs: Long = 0,
    @SerialName("end_ms") val endMs: Long = 0,
    /** 0-1 when the engine scored the word (since v1.2: below [com.echoledger.mobile.core.Uncertain.BELOW] it is uncertain). */
    val confidence: Double? = null
)

/** One transcript match of `GET /api/search` (since v1.1): jump to `start_ms`; highlights are [start, length]. */
@Serializable
data class SearchHitDto(
    @SerialName("transcript_id") val transcriptId: String,
    @SerialName("segment_index") val segmentIndex: Int = 0,
    @SerialName("start_ms") val startMs: Long = 0,
    val speaker: String = "",
    val snippet: String = "",
    val highlights: List<List<Int>> = emptyList()
)

@Serializable
data class SearchItemDto(
    val session: LibraryItem,
    val hits: List<SearchHitDto> = emptyList(),
    @SerialName("hit_count") val hitCount: Int = 0
)

@Serializable
data class SearchResponse(val data: List<SearchItemDto> = emptyList(), @SerialName("next_page") val nextPage: Int? = null)

/** A view-only share link (since v1.1); `url` only right after it was made. */
@Serializable
data class ShareLinkDto(
    val id: String,
    val active: Boolean = false,
    val url: String? = null,
    @SerialName("expires_at") val expiresAt: String? = null,
    @SerialName("revoked_at") val revokedAt: String? = null,
    @SerialName("view_count") val viewCount: Int = 0,
    @SerialName("has_password") val hasPassword: Boolean = false,
    @SerialName("include_audio") val includeAudio: Boolean = false,
    @SerialName("created_at") val createdAt: String? = null,
    /** Since v1.5: a recording's or a project's link, who may open it, and whether files can be downloaded. */
    val kind: String = "session",
    @SerialName("session_id") val sessionId: String? = null,
    @SerialName("client_id") val clientId: String? = null,
    val audience: String = "public",
    /** Null unless the link is for chosen people. */
    val people: List<String>? = null,
    @SerialName("allow_download") val allowDownload: Boolean? = null,
    @SerialName("last_viewed_at") val lastViewedAt: String? = null
)

@Serializable
data class ShareResponse(val data: ShareLinkDto)

@Serializable
data class ShareListResponse(val data: List<ShareLinkDto> = emptyList())

@Serializable
data class TranscriptDto(
    val id: String,
    val kind: String,
    val provider: String,
    val status: String,
    val language: String? = null,
    val text: String? = null,
    val segments: List<SegmentDto> = emptyList(),
    /** Set only when `status` is `failed`: the reason, written for the user. */
    val error: String? = null,
    /** Machine-readable reason of a failure (`no_audio`, `provider_rejected`, `budget_exceeded`, ...). */
    @SerialName("error_code") val errorCode: String? = null,
    /** Parts done of all parts while it is being made in pieces (since v1.2). */
    val progress: ProgressDto? = null,
    /** Show as: the views this transcript can have and whether they are made (since v1.2). */
    val views: ViewsSummaryDto? = null,
    /** Likely mishearings Claude flagged with the client's vocabulary (since 1.4); nothing changes until accepted. */
    val suggestions: List<SuggestionDto> = emptyList(),
    /**
     * Since v1.5: an Urdu or Auto recording made by a lower-ranked engine (the preferred one stayed unavailable or
     * refused it): "Backup engine — Re-transcribe?". [backupReason] is `waited` or `rejected`.
     */
    @SerialName("backup_engine") val backupEngine: Boolean = false,
    @SerialName("backup_reason") val backupReason: String? = null,
    /** Since v1.5: while an Urdu or Auto transcript waits for its preferred engine (`rate_limited` or `unavailable`). */
    @SerialName("retry_at") val retryAt: String? = null,
    @SerialName("retry_reason") val retryReason: String? = null,
    @SerialName("provider_label") val providerLabel: String? = null
)

@Serializable
data class SuggestionDto(val id: Int, val index: Int, val from: String, val to: String, val reason: String = "")

/** Show as (since v1.2): `roman` and/or `english`, and `status` unavailable | none | making | ready | failed. */
@Serializable
data class ViewsSummaryDto(
    val available: List<String> = emptyList(),
    val status: String = "unavailable",
    val default: String = "original",
    /** While views are being made (since v1.5): lines done of lines per view, `{"roman": {"done": 12, "total": 20}}`. */
    val progress: Map<String, ProgressDto> = emptyMap()
)

/**
 * `GET /api/transcripts/{id}/views` (since v1.2): the Roman Urdu and English reading views, each a list aligned with the
 * segments (null for an empty line). The transcript itself never changes.
 */
@Serializable
data class TranscriptViewsDto(
    val status: String = "none",
    val available: List<String> = emptyList(),
    val roman: List<String?>? = null,
    val english: List<String?>? = null,
    val urdu: List<String?>? = null,
    val model: String? = null,
    val error: String? = null,
    /** Lines shown with their original words in a view because the view failed the checks twice (since v1.3). */
    val kept: Map<String, List<Int>> = emptyMap(),
    /** While being made (since v1.5): lines done of lines per view. */
    val progress: Map<String, ProgressDto> = emptyMap()
)

/** The part of GET /api/sessions/{id} the app needs: a temporary signed URL to stream the decrypted recording. */
@Serializable
data class SessionDetailDto(
    @SerialName("playback_url") val playbackUrl: String? = null,
    /** Bookmarks on the recording, in time order (since v1.1). */
    val markers: List<SessionMarkerDto> = emptyList(),
    /** Renamed speakers: label in the segments → name (since v1.1). */
    @SerialName("speaker_names") val speakerNames: Map<String, String> = emptyMap()
)

@Serializable
data class SessionMarkerDto(val id: String, @SerialName("at_ms") val atMs: Long, val tag: String? = null, val note: String? = null)

@Serializable
data class DeviceTranscript(val kind: String, val provider: String, val language: String?, val segments: List<SegmentDto>)

@Serializable
data class ClientDto(
    val id: String,
    val name: String,
    @SerialName("keyterm_count") val keytermCount: Int = 0,
    /** Client folder look (since v1.1, B15): `#rrggbb`, one emoji or two letters, listed first when pinned. */
    val color: String? = null,
    val avatar: String? = null,
    val pinned: Boolean = false,
    @SerialName("session_count") val sessionCount: Int = 0,
    /** The client's default language (since v1.1.2); null = the console default. */
    val language: String? = null,
    /** Since v1.5 (Projects): the folder it sits in (null = top level), its ancestors from the top, and counts. */
    @SerialName("parent_id") val parentId: String? = null,
    val path: List<NamedRef> = emptyList(),
    @SerialName("children_count") val childrenCount: Int = 0,
    @SerialName("nested_session_count") val nestedSessionCount: Int? = null
)

/** A filing rule of a project (since v1.5): "title" contains, "app" (a source_app), or a "contact". */
@Serializable
data class FilingRuleDto(val id: Long, val kind: String, val value: String, @SerialName("created_at") val createdAt: String? = null)

@Serializable
data class FilingRulesResponse(val data: List<FilingRuleDto> = emptyList())

@Serializable
data class FilingRuleResponse(val data: FilingRuleDto)

/** DELETE /api/clients/{id} (since v1.5): its recordings went to the Inbox, its subfolders up a level. */
@Serializable
data class DeletedProjectAnswer(
    @SerialName("moved_sessions") val movedSessions: Int = 0,
    @SerialName("moved_projects") val movedProjects: Int = 0
)

/** Something a teammate sent (since v1.5): a recording or a project, read-only until saved. */
@Serializable
data class SharedItemDto(
    val id: String,
    val kind: String = "session",
    val from: SenderDto? = null,
    val note: String? = null,
    val title: String? = null,
    @SerialName("sent_at") val sentAt: String? = null,
    @SerialName("saved_at") val savedAt: String? = null,
    val session: LibraryItem? = null,
    val client: ClientDto? = null
)

@Serializable
data class SenderDto(val name: String? = null, val email: String? = null)

/** What you sent of a recording or a project (`GET /api/sent`, since v1.5). */
@Serializable
data class SentItemDto(
    val id: String,
    val kind: String = "session",
    val to: SenderDto? = null,
    val title: String? = null,
    val note: String? = null,
    @SerialName("sent_at") val sentAt: String? = null,
    @SerialName("saved_at") val savedAt: String? = null
)

@Serializable
data class SentListResponse(val data: List<SentItemDto> = emptyList())

@Serializable
data class SharedResponse(val data: List<SharedItemDto> = emptyList())

@Serializable
data class SentAnswer(val sent: Int = 0)

@Serializable
data class SavedSharedAnswer(@SerialName("session_id") val sessionId: String? = null, @SerialName("client_id") val clientId: String? = null)

/** A "Request files" link (since v1.5): anyone with it can send files into the Inbox or a project. */
@Serializable
data class RequestLinkDto(
    val id: String,
    val url: String? = null,
    val title: String? = null,
    @SerialName("client_id") val clientId: String? = null,
    @SerialName("expires_at") val expiresAt: String? = null,
    @SerialName("max_files") val maxFiles: Int? = null,
    @SerialName("max_mb") val maxMb: Int? = null,
    val received: Int = 0,
    @SerialName("revoked_at") val revokedAt: String? = null
)

@Serializable
data class RequestLinksResponse(val data: List<RequestLinkDto> = emptyList())

@Serializable
data class RequestLinkResponse(val data: RequestLinkDto)

@Serializable
data class ClientResponse(val data: ClientDto)

@Serializable
data class BulkAnswer(val changed: Int = 0)

/** `GET /api/activity` (since v1.1, B18): the activity centre. */
@Serializable
data class ActivityItemDto(
    val id: String,
    val kind: String? = null,
    val title: String = "",
    val body: String = "",
    @SerialName("session_id") val sessionId: String? = null,
    val read: Boolean = false,
    val at: String? = null
)

@Serializable
data class ActivityResponse(val data: List<ActivityItemDto> = emptyList(), @SerialName("unread_count") val unreadCount: Int = 0)

@Serializable
data class UnreadAnswer(@SerialName("unread_count") val unreadCount: Int = 0)

/** `GET /api/bin` (since v1.1, B17): recordings deleted in the last 48 hours. */
@Serializable
data class BinItemDto(
    val id: String,
    val title: String = "",
    val client: NamedRef? = null,
    @SerialName("started_at") val startedAt: String? = null,
    @SerialName("deleted_at") val deletedAt: String? = null,
    @SerialName("erase_at") val eraseAt: String? = null
)

@Serializable
data class BinResponse(val data: List<BinItemDto> = emptyList())

/** `GET /api/storage` (since v1.1, B17): the storage meter. */
@Serializable
data class StorageDto(
    @SerialName("recordings_bytes") val recordingsBytes: Long = 0,
    @SerialName("recordings_count") val recordingsCount: Int = 0,
    @SerialName("disk_free_bytes") val diskFreeBytes: Long? = null,
    @SerialName("disk_total_bytes") val diskTotalBytes: Long? = null,
    @SerialName("retention_days") val retentionDays: Int = 30
)

/** `GET /api/security` (since v1.1, B16): paired devices and the recent audit log. */
@Serializable
data class SecurityResponse(val devices: List<SecurityDeviceDto> = emptyList(), val events: List<SecurityEventDto> = emptyList())

@Serializable
data class SecurityDeviceDto(
    val id: String,
    val name: String,
    val platform: String = "",
    @SerialName("app_version") val appVersion: String? = null,
    @SerialName("last_seen_at") val lastSeenAt: String? = null,
    val current: Boolean = false
)

@Serializable
data class SecurityEventDto(
    val id: Long,
    val event: String,
    val label: String = "",
    val summary: String = "",
    val actor: String = "system",
    val device: String? = null,
    val at: String = ""
)

@Serializable
data class ClientsResponse(val data: List<ClientDto>)

/** `POST /api/live-translate/token` (since v1.3, Phase B): a temporary Soniox key and how to open its WebSocket. */
@Serializable
data class LiveTranslateToken(
    val provider: String,
    @SerialName("api_key") val apiKey: String,
    @SerialName("expires_at") val expiresAt: String,
    @SerialName("websocket_url") val websocketUrl: String,
    val model: String,
    val translation: LiveTranslation,
    @SerialName("language_hints") val languageHints: List<String> = emptyList()
)

@Serializable
data class LiveTranslation(val type: String, @SerialName("target_language") val targetLanguage: String)

/** `GET /api/links/resolve` (since v1.5): a link opened in the app goes to this recording, project or list. */
@Serializable
data class ResolvedLinkDto(
    val kind: String,
    @SerialName("session_id") val sessionId: String? = null,
    @SerialName("client_id") val clientId: String? = null,
    @SerialName("shared_id") val sharedId: String? = null,
    @SerialName("request_link_id") val requestLinkId: String? = null
)

/** A screen moment (since v1.5): what was on the shared screen at [atMs] of the recording ("Screen 7 at 14:32"). */
@Serializable
data class ScreenDto(
    val id: String,
    val index: Int = 0,
    @SerialName("at_ms") val atMs: Long = 0,
    val width: Int = 0,
    val height: Int = 0,
    val trigger: String? = null,
    @SerialName("ocr_text") val ocrText: String? = null,
    val description: String? = null,
    @SerialName("image_url") val imageUrl: String? = null,
    @SerialName("thumb_url") val thumbUrl: String? = null
)

@Serializable
data class ScreensResponse(val data: List<ScreenDto> = emptyList())

/** `GET /api/account` (since v1.5): who this phone is signed in as, and their workspace and role. */
@Serializable
data class AccountDto(
    val name: String? = null,
    val email: String? = null,
    @SerialName("email_verified") val emailVerified: Boolean = false,
    @SerialName("two_factor") val twoFactor: Boolean = false,
    val google: Boolean = false,
    @SerialName("created_at") val createdAt: String? = null,
    val workspace: AccountWorkspaceDto? = null,
    @SerialName("deletes_at") val deletesAt: String? = null
)

@Serializable
data class AccountWorkspaceDto(val id: Long? = null, val name: String? = null, val role: String? = null)

@Serializable
data class AccountDeletionDto(@SerialName("deletes_at") val deletesAt: String? = null)

/** A data export (since v1.5): one ZIP of everything the account owns; [url] works for 24 hours once ready. */
@Serializable
data class AccountExportDto(
    val id: String,
    val status: String = "making",
    val url: String? = null,
    @SerialName("expires_at") val expiresAt: String? = null
)

/** The newest website build of the phone app (since v1.5, GET /downloads/android.json; the full flavor only). */
@Serializable
data class AndroidReleaseDto(
    val version: String,
    val code: Int,
    val url: String,
    val sha256: String,
    val bytes: Long,
    @SerialName("min_sdk") val minSdk: Int? = null,
    val notes: String? = null,
    @SerialName("published_at") val publishedAt: String? = null
)

/** A screen on a project's Screens tab (since v1.5): the screen and the recording it belongs to. */
@Serializable
data class ProjectScreenDto(
    val id: String,
    val index: Int = 0,
    @SerialName("at_ms") val atMs: Long = 0,
    val width: Int = 0,
    val height: Int = 0,
    val trigger: String? = null,
    @SerialName("ocr_text") val ocrText: String? = null,
    val description: String? = null,
    @SerialName("image_url") val imageUrl: String? = null,
    @SerialName("thumb_url") val thumbUrl: String? = null,
    @SerialName("session_id") val sessionId: String,
    @SerialName("session_title") val sessionTitle: String? = null,
    @SerialName("session_started_at") val sessionStartedAt: String? = null
) {
    fun screen() = ScreenDto(id, index, atMs, width, height, trigger, ocrText, description, imageUrl, thumbUrl)
}

@Serializable
data class ProjectScreensResponse(val data: List<ProjectScreenDto> = emptyList(), @SerialName("next_cursor") val nextCursor: String? = null)

/** The workspace's plan in `GET /api/config` (since v1.5); a missing feature counts as on, a null limit as none. */
@Serializable
data class PlanDto(
    val key: String? = null,
    val name: String? = null,
    val features: PlanFeaturesDto = PlanFeaturesDto(),
    val limits: PlanLimitsDto? = null,
    val used: PlanUsedDto? = null
)

@Serializable
data class PlanFeaturesDto(
    @SerialName("live_translate") val liveTranslate: Boolean = true,
    @SerialName("screen_moments") val screenMoments: Boolean = true,
    @SerialName("ai_notes") val aiNotes: Boolean = true,
    val connector: Boolean = true
)

@Serializable
data class PlanLimitsDto(
    @SerialName("monthly_minutes") val monthlyMinutes: Long? = null,
    @SerialName("storage_bytes") val storageBytes: Long? = null,
    @SerialName("retention_days") val retentionDays: Int? = null,
    val seats: Int? = null
)

@Serializable
data class PlanUsedDto(val minutes: Long? = null, @SerialName("storage_bytes") val storageBytes: Long? = null)

/** The device's workspace and its person's role there, in `GET /api/config` (since v1.5). */
@Serializable
data class ConfigWorkspaceDto(val id: Long? = null, val name: String? = null, val role: String? = null)

/** `GET /api/library/views` (since v1.5 its `folders`): how many recordings each smart folder lists on the server. */
@Serializable
data class LibraryViewsDto(val folders: Map<String, Int>? = null)
