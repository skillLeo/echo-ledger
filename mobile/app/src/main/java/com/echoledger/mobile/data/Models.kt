package com.echoledger.mobile.data

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
    val pins: List<String> = emptyList()
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
    val artifacts: List<ArtifactDto>
)

@Serializable
data class UploadState(
    @SerialName("chunk_size_bytes") val chunkSizeBytes: Long = 0,
    @SerialName("total_chunks") val totalChunks: Int,
    @SerialName("received_chunks") val receivedChunks: List<Int> = emptyList(),
    val status: String
)

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
    @SerialName("updated_at") val updatedAt: String
)

@Serializable
data class NamedRef(val id: String, val name: String)

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
    val text: String
)

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
    val error: String? = null
)

/** The part of GET /api/sessions/{id} the app needs: a temporary signed URL to stream the decrypted recording. */
@Serializable
data class SessionDetailDto(@SerialName("playback_url") val playbackUrl: String? = null)

@Serializable
data class DeviceTranscript(val kind: String, val provider: String, val language: String?, val segments: List<SegmentDto>)

@Serializable
data class ClientDto(val id: String, val name: String, @SerialName("keyterm_count") val keytermCount: Int = 0)

@Serializable
data class ClientsResponse(val data: List<ClientDto>)
