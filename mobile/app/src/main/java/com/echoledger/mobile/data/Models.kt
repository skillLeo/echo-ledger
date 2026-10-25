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
    val markers: MarkersConfig? = null
)

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
    val pauses: List<PauseDto> = emptyList()
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
    /** Sent only when there are some, so a v1 server never sees the keys. */
    @OptIn(ExperimentalSerializationApi::class)
    @EncodeDefault(EncodeDefault.Mode.NEVER)
    val markers: List<MarkerDto> = emptyList(),
    @OptIn(ExperimentalSerializationApi::class)
    @EncodeDefault(EncodeDefault.Mode.NEVER)
    val pauses: List<PauseDto> = emptyList()
)

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
    val keep: Boolean = false
)

@Serializable
data class NamedRef(val id: String, val name: String, val color: String? = null, val avatar: String? = null)

@Serializable
data class TranscriptSummary(
    @SerialName("final_status") val finalStatus: String? = null,
    @SerialName("final_transcript_id") val finalTranscriptId: String? = null,
    @SerialName("live_available") val liveAvailable: Boolean = false,
    @SerialName("live_transcript_id") val liveTranscriptId: String? = null,
    val preview: String? = null
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
    val words: List<WordDto> = emptyList()
)

@Serializable
data class WordDto(val text: String = "", @SerialName("start_ms") val startMs: Long = 0, @SerialName("end_ms") val endMs: Long = 0)

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
    @SerialName("created_at") val createdAt: String? = null
)

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
    @SerialName("error_code") val errorCode: String? = null
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
    @SerialName("session_count") val sessionCount: Int = 0
)

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
