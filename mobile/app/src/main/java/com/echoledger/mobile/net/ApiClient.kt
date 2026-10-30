package com.echoledger.mobile.net

import com.echoledger.mobile.data.ActivityResponse
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.BinResponse
import com.echoledger.mobile.data.BulkAnswer
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.ClientResponse
import com.echoledger.mobile.data.ClientsResponse
import com.echoledger.mobile.data.CompleteAnswer
import com.echoledger.mobile.data.CreateSessionRequest
import com.echoledger.mobile.data.CreateSessionResponse
import com.echoledger.mobile.data.DeviceTranscript
import com.echoledger.mobile.data.FinishSessionRequest
import com.echoledger.mobile.data.LibraryResponse
import com.echoledger.mobile.data.LiveTranslateToken
import com.echoledger.mobile.data.OpenSessionRequest
import com.echoledger.mobile.data.RecordingUpload
import com.echoledger.mobile.data.RegisterResponse
import com.echoledger.mobile.data.SearchResponse
import com.echoledger.mobile.data.SecurityResponse
import com.echoledger.mobile.data.ServerConfig
import com.echoledger.mobile.data.SessionDetailDto
import com.echoledger.mobile.data.ShareLinkDto
import com.echoledger.mobile.data.ShareListResponse
import com.echoledger.mobile.data.StorageDto
import com.echoledger.mobile.data.SttTokenResponse
import com.echoledger.mobile.data.TranscriptDto
import com.echoledger.mobile.data.TranscriptViewsDto
import com.echoledger.mobile.data.UnreadAnswer
import com.echoledger.mobile.data.UploadState
import com.echoledger.mobile.data.UsageReport
import java.io.IOException
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.KSerializer
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response

class NotPairedException : IOException("This phone is not paired with an EchoLedger server.")

/** A server answer other than 2xx/401: `code` is the machine-readable reason, `retryAfterSeconds` the `Retry-After` delay. */
class ApiException(val status: Int, message: String, val code: String? = null, val retryAfterSeconds: Long? = null) : IOException(message)

/**
 * Typed client for docs/API.md over a pinned OkHttp client (pins apply to the server host only).
 */
class ApiClient(baseUrl: String, pins: List<String>, private val token: String?, client: OkHttpClient? = null) {
    private val base = baseUrl.trimEnd('/')

    val http: OkHttpClient = client ?: OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(60, TimeUnit.SECONDS)
        .writeTimeout(120, TimeUnit.SECONDS)
        .apply { Pins.pinnerFor(base, pins)?.let { certificatePinner(it) } }
        .build()

    init {
        require(base.startsWith("https://") || Pins.isLocalDev(base)) { "The server address must use https://" }
    }

    private fun url(path: String) = "$base/api/${path.trimStart('/')}"

    private fun request(path: String): Request.Builder {
        val builder = Request.Builder().url(url(path)).header("Accept", "application/json")
        token?.let { builder.header("Authorization", "Bearer $it") }
        return builder
    }

    private suspend fun <T> call(req: Request, serializer: KSerializer<T>?): T? = withContext(Dispatchers.IO) {
        http.newCall(req).execute().use { resp -> parse(resp, serializer) }
    }

    private fun <T> parse(resp: Response, serializer: KSerializer<T>?): T? {
        val body = resp.body.string()
        if (resp.isSuccessful) {
            return if (serializer == null || body.isBlank()) null else ApiJson.decodeFromString(serializer, body)
        }
        if (resp.code == 401) throw NotPairedException()
        val json = runCatching { ApiJson.parseToJsonElement(body).jsonObject }.getOrNull()
        val message = json?.get("message")?.let { (it as? JsonPrimitive)?.contentOrNull }
        val code = json?.get("code")?.let { (it as? JsonPrimitive)?.contentOrNull }
        val retryAfter = resp.header("Retry-After")?.trim()?.toLongOrNull()?.coerceAtLeast(0)
        throw ApiException(resp.code, message ?: "Request failed (${resp.code})", code, retryAfter)
    }

    private val jsonType = "application/json".toMediaType()

    suspend fun register(code: String, name: String, appVersion: String, osVersion: String): RegisterResponse {
        val body = buildJsonObject {
            put("pairing_code", code)
            put("name", name)
            put("platform", "android")
            put("app_version", appVersion)
            put("os_version", osVersion)
        }.toString().toRequestBody(jsonType)
        return call(request("devices/register").post(body).build(), RegisterResponse.serializer())!!
    }

    suspend fun config(): ServerConfig = call(request("config").get().build(), ServerConfig.serializer())!!

    suspend fun clients(): ClientsResponse = call(request("clients").get().build(), ClientsResponse.serializer())!!

    suspend fun sttToken(
        useCase: String,
        channels: List<String>,
        clientId: String?,
        sessionId: String? = null,
        language: String? = null
    ): SttTokenResponse {
        val body = buildJsonObject {
            put("use_case", useCase)
            sessionId?.let { put("session_id", it) }
            // The recording's language chip (since v1.1.2); left out, the server uses the session's or the client's.
            language?.let { put("language", it) }
            put("channels", buildJsonArray { channels.forEach { add(kotlinx.serialization.json.JsonPrimitive(it)) } })
            clientId?.let { put("client_id", it) }
            put("sample_rate", 16_000)
        }.toString().toRequestBody(jsonType)
        return call(request("stt/token").post(body).build(), SttTokenResponse.serializer())!!
    }

    /** `POST /api/live-translate/token` (since v1.3, Phase B): a short-lived Soniox key for Live Translate. */
    suspend fun liveTranslateToken(
        mode: String,
        targetLanguage: String,
        languageHints: List<String>,
        continueOverBudget: Boolean = false
    ): LiveTranslateToken {
        val body = buildJsonObject {
            put("mode", mode)
            if (continueOverBudget) put("continue_over_budget", true)
            put("target_language", targetLanguage)
            put("language_hints", buildJsonArray { languageHints.forEach { add(kotlinx.serialization.json.JsonPrimitive(it)) } })
        }.toString().toRequestBody(jsonType)
        return call(request("live-translate/token").post(body).build(), LiveTranslateToken.serializer())!!
    }

    suspend fun createSession(req: CreateSessionRequest): CreateSessionResponse {
        val body = ApiJson.encodeToString(CreateSessionRequest.serializer(), req).toRequestBody(jsonType)
        return call(request("sessions").post(body).build(), CreateSessionResponse.serializer())!!
    }

    suspend fun recordingStatus(id: String): RecordingUpload = call(request("recordings/$id").get().build(), RecordingUpload.serializer())!!

    suspend fun uploadChunk(recordingId: String, index: Int, bytes: ByteArray, sha256Hex: String): UploadState {
        val req = request("recordings/$recordingId/chunks")
            .header("Upload-Chunk-Index", index.toString())
            .header("Upload-Chunk-Sha256", sha256Hex)
            .post(bytes.toRequestBody("application/octet-stream".toMediaType()))
            .build()
        return call(req, UploadState.serializer())!!
    }

    /** Returns the upload status after `complete`: `ready`, or `assembling` while the server is still verifying. */
    suspend fun complete(recordingId: String): String = call(
        request("recordings/$recordingId/complete").post(ByteArray(0).toRequestBody(null)).build(),
        CompleteAnswer.serializer()
    )?.status ?: "assembling"

    /** Opens a session for live captions (status `recording`, no artifacts; since v1.1). */
    suspend fun openSession(req: OpenSessionRequest): CreateSessionResponse {
        val body = ApiJson.encodeToString(OpenSessionRequest.serializer(), req).toRequestBody(jsonType)
        return call(request("sessions").post(body).build(), CreateSessionResponse.serializer())!!
    }

    /** Finishes an open session with its artifacts and returns the upload slots (idempotent; since v1.1). */
    suspend fun finishSession(id: String, req: FinishSessionRequest): CreateSessionResponse {
        val body = ApiJson.encodeToString(FinishSessionRequest.serializer(), req).toRequestBody(jsonType)
        return call(request("sessions/$id/finish").post(body).build(), CreateSessionResponse.serializer())!!
    }

    /** Reports one streaming period (idempotent on `report_id`; since v1.1). */
    suspend fun reportUsage(report: UsageReport) {
        val body = ApiJson.encodeToString(UsageReport.serializer(), report).toRequestBody(jsonType)
        call(request("usage").post(body).build(), null as KSerializer<Unit>?)
    }

    suspend fun postTranscript(sessionId: String, transcript: DeviceTranscript) {
        val body = ApiJson.encodeToString(DeviceTranscript.serializer(), transcript).toRequestBody(jsonType)
        call(request("sessions/$sessionId/transcripts").post(body).build(), null as KSerializer<Unit>?)
    }

    suspend fun library(since: String?, cursor: String?): LibraryResponse {
        val builder = url("library").toHttpUrl().newBuilder().addQueryParameter("limit", "100")
        since?.let { builder.addQueryParameter("since", it) }
        cursor?.let { builder.addQueryParameter("cursor", it) }
        val req = Request.Builder().url(builder.build()).header("Accept", "application/json")
            .apply { token?.let { header("Authorization", "Bearer $it") } }.get().build()
        return call(req, LibraryResponse.serializer())!!
    }

    suspend fun transcript(id: String): TranscriptDto = call(request("transcripts/$id").get().build(), TranscriptDto.serializer())!!

    suspend fun sessionDetail(id: String): SessionDetailDto = call(request("sessions/$id").get().build(), SessionDetailDto.serializer())!!

    suspend fun deleteSession(id: String) {
        call(request("sessions/$id").delete().build(), null as KSerializer<Unit>?)
    }

    /** `GET /api/transcripts/{id}/views` (since v1.2): the Show as views and their state. */
    suspend fun transcriptViews(transcriptId: String): TranscriptViewsDto =
        call(request("transcripts/$transcriptId/views").get().build(), TranscriptViewsDto.serializer())!!

    /** `POST /api/transcripts/{id}/views` (since v1.2): starts making the Show as views. */
    suspend fun makeTranscriptViews(transcriptId: String): TranscriptViewsDto =
        call(request("transcripts/$transcriptId/views").post("{}".toRequestBody(jsonType)).build(), TranscriptViewsDto.serializer())!!

    /** `POST /api/sessions/{id}/retranscribe` (since v1.2): "Re-transcribe as <language>"; null = Auto. */
    suspend fun retranscribe(sessionId: String, language: String?) {
        val body = buildJsonObject { put("language", language) }.toString().toRequestBody(jsonType)
        call(request("sessions/$sessionId/retranscribe").post(body).build(), null as KSerializer<Unit>?)
    }

    /** `GET /api/search` (since v1.1): every recording's title and transcript. */
    suspend fun search(query: String, tag: String? = null, bookmarked: Boolean = false): SearchResponse {
        val builder = url("search").toHttpUrl().newBuilder().addQueryParameter("q", query.trim())
        tag?.let { builder.addQueryParameter("tag", it) }
        if (bookmarked) builder.addQueryParameter("bookmarked", "1")
        val req = Request.Builder().url(builder.build()).header("Accept", "application/json")
            .apply { token?.let { header("Authorization", "Bearer $it") } }.get().build()
        return call(req, SearchResponse.serializer())!!
    }

    /** `GET /api/transcripts/{id}/export`: the file and the name the server gives it; [view] a Show as view (since v1.2). */
    suspend fun export(transcriptId: String, format: String, view: String? = null): Pair<ByteArray, String> = withContext(Dispatchers.IO) {
        val target = url("transcripts/$transcriptId/export").toHttpUrl().newBuilder().addQueryParameter("format", format)
            .apply { view?.let { addQueryParameter("view", it) } }.build()
        val req = Request.Builder().url(target).apply { token?.let { header("Authorization", "Bearer $it") } }.get().build()
        http.newCall(req).execute().use { resp ->
            if (!resp.isSuccessful) parse(resp, null as KSerializer<Unit>?)
            val name = ExportNames.fromDisposition(resp.header("Content-Disposition")) ?: "transcript.$format"
            resp.body.bytes() to name
        }
    }

    /** `POST /api/sessions/{id}/shares`: the new link with its URL (shown once). */
    suspend fun createShare(sessionId: String, expiresIn: Long, password: String?, includeAudio: Boolean): ShareLinkDto {
        val body = buildJsonObject {
            put("expires_in", expiresIn)
            password?.takeIf { it.isNotEmpty() }?.let { put("password", it) }
            put("include_audio", includeAudio)
        }.toString().toRequestBody(jsonType)
        return call(request("sessions/$sessionId/shares").post(body).build(), ShareLinkDto.serializer())!!
    }

    suspend fun shares(sessionId: String): ShareListResponse =
        call(request("sessions/$sessionId/shares").get().build(), ShareListResponse.serializer())!!

    suspend fun revokeShare(id: String) {
        call(request("shares/$id").delete().build(), null as KSerializer<Unit>?)
    }

    /**
     * `PATCH /api/sessions/{id}` (since v1.1): pin, client folder or labels. Only the arguments given are sent;
     * [clearClient] sends `client_id: null` (no client).
     */
    suspend fun updateSession(
        id: String,
        pinned: Boolean? = null,
        clientId: String? = null,
        clearClient: Boolean = false,
        labels: List<String>? = null,
        keep: Boolean? = null
    ) {
        val body = buildJsonObject {
            pinned?.let { put("pinned", it) }
            keep?.let { put("keep", it) }
            if (clearClient) put("client_id", kotlinx.serialization.json.JsonNull) else clientId?.let { put("client_id", it) }
            labels?.let { list -> put("labels", buildJsonArray { list.forEach { add(kotlinx.serialization.json.JsonPrimitive(it)) } }) }
        }.toString().toRequestBody(jsonType)
        call(request("sessions/$id").patch(body).build(), null as KSerializer<Unit>?)
    }

    /** `POST /api/sessions/bulk` (since v1.1): one action for up to 200 recordings; how many changed. */
    suspend fun bulk(ids: List<String>, action: String, clientId: String? = null, label: String? = null): Int {
        val body = buildJsonObject {
            put("ids", buildJsonArray { ids.forEach { add(kotlinx.serialization.json.JsonPrimitive(it)) } })
            put("action", action)
            put("client_id", clientId?.let { kotlinx.serialization.json.JsonPrimitive(it) } ?: kotlinx.serialization.json.JsonNull)
            label?.let { put("label", it) }
        }.toString().toRequestBody(jsonType)
        return call(request("sessions/bulk").post(body).build(), BulkAnswer.serializer())!!.changed
    }

    /** `POST /api/sessions/export` (since v1.1): the finished transcripts of many recordings in one zip file. */
    suspend fun exportSessions(ids: List<String>, format: String): Pair<ByteArray, String> = withContext(Dispatchers.IO) {
        val body = buildJsonObject {
            put("ids", buildJsonArray { ids.forEach { add(kotlinx.serialization.json.JsonPrimitive(it)) } })
            put("format", format)
        }.toString().toRequestBody(jsonType)
        val req = Request.Builder().url(url("sessions/export")).apply {
            token?.let { header("Authorization", "Bearer $it") }
        }.post(body).build()
        http.newCall(req).execute().use { resp ->
            if (!resp.isSuccessful) parse(resp, null as KSerializer<Unit>?)
            val name = ExportNames.fromDisposition(resp.header("Content-Disposition")) ?: "EchoLedger transcripts.zip"
            resp.body.bytes() to name
        }
    }

    /** `POST /api/clients` (since v1.1): a new client folder. */
    suspend fun createClient(name: String): ClientDto {
        val body = buildJsonObject { put("name", name) }.toString().toRequestBody(jsonType)
        return call(request("clients").post(body).build(), ClientResponse.serializer())!!.data
    }

    /** `PATCH /api/clients/{id}` with the client's default language (since v1.1.2); null = the console default. */
    suspend fun setClientLanguage(id: String, language: String?) {
        val body = buildJsonObject {
            if (language == null) put("language", kotlinx.serialization.json.JsonNull) else put("language", language)
        }.toString().toRequestBody(jsonType)
        call(request("clients/$id").patch(body).build(), null as KSerializer<Unit>?)
    }

    /** `GET /api/activity` (since v1.1): the activity centre, newest first. */
    suspend fun activity(limit: Int = 30): ActivityResponse {
        val target = url("activity").toHttpUrl().newBuilder().addQueryParameter("limit", limit.toString()).build()
        return call(
            Request.Builder().url(target).apply {
                token?.let { header("Authorization", "Bearer $it") }
            }.get().build(),
            ActivityResponse.serializer()
        )!!
    }

    /** `POST /api/activity/read` (since v1.1): those ids, or everything when null. */
    suspend fun markActivityRead(ids: List<String>? = null): Int {
        val body = buildJsonObject {
            ids?.let { list -> put("ids", buildJsonArray { list.forEach { add(kotlinx.serialization.json.JsonPrimitive(it)) } }) }
        }.toString().toRequestBody(jsonType)
        return call(request("activity/read").post(body).build(), UnreadAnswer.serializer())!!.unreadCount
    }

    /** `GET /api/bin` (since v1.1): recordings deleted in the last 48 hours. */
    suspend fun bin(): BinResponse = call(request("bin").get().build(), BinResponse.serializer())!!

    /** `POST /api/sessions/{id}/restore` (since v1.1): out of the undo bin. */
    suspend fun restoreSession(id: String) {
        call(request("sessions/$id/restore").post(ByteArray(0).toRequestBody(jsonType)).build(), null as KSerializer<Unit>?)
    }

    /** `POST /api/sessions/{id}/opened` (since v1.3): this user has opened the recording (the dot goes away everywhere). */
    suspend fun markOpened(id: String) {
        call(request("sessions/$id/opened").post(ByteArray(0).toRequestBody(jsonType)).build(), null as KSerializer<Unit>?)
    }

    /** `DELETE /api/bin/{id}` (since v1.1): erase now instead of after 48 hours. */
    suspend fun eraseFromBin(id: String) {
        call(request("bin/$id").delete().build(), null as KSerializer<Unit>?)
    }

    /** `GET /api/storage` (since v1.1): the storage meter. */
    suspend fun storage(): StorageDto = call(request("storage").get().build(), StorageDto.serializer())!!

    /** `GET /api/security` (since v1.1): paired devices and the recent audit log. */
    suspend fun security(): SecurityResponse = call(request("security").get().build(), SecurityResponse.serializer())!!

    /** `DELETE /api/devices/{id}` (since v1.1): revokes another paired device. */
    suspend fun revokeDevice(id: String) {
        call(request("devices/$id").delete().build(), null as KSerializer<Unit>?)
    }

    /** `POST /api/vocabulary/keyterms`: for the client, or for every recording without one. */
    suspend fun addKeyterm(term: String, clientId: String?) {
        val body = buildJsonObject {
            put("term", term)
            clientId?.let { put("client_id", it) }
        }.toString().toRequestBody(jsonType)
        call(request("vocabulary/keyterms").post(body).build(), null as KSerializer<Unit>?)
    }
}

/** File names of exports (`Content-Disposition`), safe for the phone's storage. */
object ExportNames {
    fun fromDisposition(header: String?): String? {
        if (header == null) return null
        var plain: String? = null
        var extended: String? = null
        for (part in header.split(';').map { it.trim() }) {
            when {
                part.startsWith("filename*=") -> extended = java.net.URLDecoder.decode(
                    part.removePrefix("filename*=").trim('"').substringAfter("''").replace("+", "%2B"),
                    "UTF-8"
                )

                part.startsWith("filename=") -> plain = part.removePrefix("filename=").trim('"')
            }
        }
        val name = (extended ?: plain ?: return null).filterNot { it in "/\\:*?\"<>|" || it.isISOControl() }.trim().trimStart('.')
        return name.ifEmpty { null }
    }
}
