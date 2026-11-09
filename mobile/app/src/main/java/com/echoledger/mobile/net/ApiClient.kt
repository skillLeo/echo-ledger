package com.echoledger.mobile.net

import com.echoledger.mobile.data.ActivityResponse
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.AuthStartDto
import com.echoledger.mobile.data.BinResponse
import com.echoledger.mobile.data.BulkAnswer
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.ClientResponse
import com.echoledger.mobile.data.ClientsResponse
import com.echoledger.mobile.data.CodeSentDto
import com.echoledger.mobile.data.CompleteAnswer
import com.echoledger.mobile.data.CreateSessionRequest
import com.echoledger.mobile.data.CreateSessionResponse
import com.echoledger.mobile.data.DeletedProjectAnswer
import com.echoledger.mobile.data.DeviceTranscript
import com.echoledger.mobile.data.FilingRuleDto
import com.echoledger.mobile.data.FilingRuleResponse
import com.echoledger.mobile.data.FilingRulesResponse
import com.echoledger.mobile.data.FinishSessionRequest
import com.echoledger.mobile.data.LibraryResponse
import com.echoledger.mobile.data.LiveTranslateToken
import com.echoledger.mobile.data.OpenSessionRequest
import com.echoledger.mobile.data.RecordingUpload
import com.echoledger.mobile.data.RegisterResponse
import com.echoledger.mobile.data.RequestLinkDto
import com.echoledger.mobile.data.RequestLinkResponse
import com.echoledger.mobile.data.RequestLinksResponse
import com.echoledger.mobile.data.SavedSharedAnswer
import com.echoledger.mobile.data.SearchResponse
import com.echoledger.mobile.data.SecurityResponse
import com.echoledger.mobile.data.SentAnswer
import com.echoledger.mobile.data.ServerConfig
import com.echoledger.mobile.data.SessionDetailDto
import com.echoledger.mobile.data.ShareLinkDto
import com.echoledger.mobile.data.ShareListResponse
import com.echoledger.mobile.data.SharedItemDto
import com.echoledger.mobile.data.SharedResponse
import com.echoledger.mobile.data.SignUpNextDto
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

class NotPairedException : IOException("This phone is not signed in to an EchoLedger server.")

const val EMPTY_ANSWER = "Your server sent an empty answer. Try again in a minute."
const val UNREADABLE_ANSWER = "Your server sent an answer this app cannot read. Check the server address in You → General → This phone, " +
    "or update EchoLedger."

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

    private suspend fun <T> call(req: Request, serializer: KSerializer<T>?, allowEmpty: Boolean = false): T? = withContext(Dispatchers.IO) {
        http.newCall(req).execute().use { resp -> parse(resp, serializer, allowEmpty) }
    }

    /**
     * A success with an empty or unreadable body (a proxy page, a cut connection) is an [IOException] with words for
     * people, never a null or a parser exception that callers do not expect (since v1.3: those crashed the app).
     */
    private fun <T> parse(resp: Response, serializer: KSerializer<T>?, allowEmpty: Boolean = false): T? {
        val body = resp.body.string()
        if (resp.isSuccessful) {
            if (serializer == null) return null
            if (body.isBlank()) {
                if (allowEmpty) return null
                throw IOException(EMPTY_ANSWER)
            }
            return try {
                ApiJson.decodeFromString(serializer, body)
            } catch (e: kotlinx.serialization.SerializationException) {
                throw IOException(UNREADABLE_ANSWER, e)
            } catch (e: IllegalArgumentException) {
                throw IOException(UNREADABLE_ANSWER, e)
            }
        }
        val json = runCatching { ApiJson.parseToJsonElement(body).jsonObject }.getOrNull()
        val message = json?.get("message")?.let { (it as? JsonPrimitive)?.contentOrNull }
        val code = json?.get("code")?.let { (it as? JsonPrimitive)?.contentOrNull }
        // A 401 means the token is gone, except signing in's "otp_required" (since v1.5): ask for the code instead.
        if (resp.code == 401 && code != "otp_required") throw NotPairedException()
        val retryAfter = resp.header("Retry-After")?.trim()?.toLongOrNull()?.coerceAtLeast(0)
        throw ApiException(resp.code, message ?: "Request failed (${resp.code})", code, retryAfter)
    }

    private val jsonType = "application/json".toMediaType()

    /** `POST /api/auth/password` (since v1.5): this phone signs in to an account; [otp] when it has two-factor sign-in. */
    suspend fun signInWithPassword(
        email: String,
        password: String,
        otp: String?,
        name: String,
        appVersion: String,
        osVersion: String,
        installId: String? = null
    ): RegisterResponse {
        val body = buildJsonObject {
            put("email", email)
            put("password", password)
            otp?.takeIf { it.isNotBlank() }?.let { put("otp", it) }
            put("name", name)
            put("platform", "android")
            put("app_version", appVersion)
            put("os_version", osVersion)
            installId?.let { put("install_id", it) }
        }.toString().toRequestBody(jsonType)
        return call(request("auth/password").post(body).build(), RegisterResponse.serializer())!!
    }

    /** `POST /api/auth/device/token` (since v1.5): the browser sign-in's one-time code and the PKCE verifier. */
    suspend fun deviceToken(
        code: String,
        verifier: String,
        name: String,
        appVersion: String,
        osVersion: String,
        installId: String? = null
    ): RegisterResponse {
        val body = buildJsonObject {
            put("code", code)
            put("code_verifier", verifier)
            put("name", name)
            put("platform", "android")
            put("app_version", appVersion)
            put("os_version", osVersion)
            installId?.let { put("install_id", it) }
        }.toString().toRequestBody(jsonType)
        return call(request("auth/device/token").post(body).build(), RegisterResponse.serializer())!!
    }

    suspend fun account(): com.echoledger.mobile.data.AccountDto =
        call(request("account").get().build(), com.echoledger.mobile.data.AccountDto.serializer())!!

    /** `POST /api/devices/report` (since v1.5): the device check, only when the person taps Send to my admin. */
    suspend fun reportDevice(facts: com.echoledger.mobile.core.PhoneFacts, rows: List<com.echoledger.mobile.core.CheckRow>) {
        val body = buildJsonObject {
            put("platform", "android")
            put("app_version", facts.appVersion.take(32))
            put("brand", facts.brand.take(64))
            put("model", facts.model.take(64))
            put("os_version", facts.release.take(64))
            put(
                "checks",
                buildJsonArray {
                    rows.forEach { row ->
                        add(
                            buildJsonObject {
                                put("key", row.key)
                                put("state", row.state.wire)
                                put("detail", row.detail.take(300))
                            }
                        )
                    }
                }
            )
        }.toString().toRequestBody(jsonType)
        call(request("devices/report").post(body).build(), null as KSerializer<Unit>?, allowEmpty = true)
    }

    suspend fun signOutEverywhere() {
        call(request("account/sign-out-everywhere").post("{}".toRequestBody(jsonType)).build(), null as KSerializer<Unit>?)
    }

    /** `DELETE /api/account` (since v1.5), confirmed with the password or a two-factor code; when it will be deleted. */
    suspend fun deleteAccount(password: String?, otp: String?): String? {
        val body = buildJsonObject {
            password?.takeIf { it.isNotEmpty() }?.let { put("password", it) }
            otp?.takeIf { it.isNotBlank() }?.let { put("otp", it) }
        }.toString().toRequestBody(jsonType)
        return call(request("account").delete(body).build(), com.echoledger.mobile.data.AccountDeletionDto.serializer())?.deletesAt
    }

    suspend fun startExport(): com.echoledger.mobile.data.AccountExportDto = call(
        request("account/export").post("{}".toRequestBody(jsonType)).build(),
        com.echoledger.mobile.data.AccountExportDto.serializer()
    )!!

    suspend fun exportStatus(id: String): com.echoledger.mobile.data.AccountExportDto =
        call(request("account/export/$id").get().build(), com.echoledger.mobile.data.AccountExportDto.serializer())!!

    /** The newest website build of the phone app (since v1.5; a public page, so no token): null while none is published. */
    suspend fun androidRelease(): com.echoledger.mobile.data.AndroidReleaseDto? = withContext(Dispatchers.IO) {
        val req = Request.Builder().url("$base/downloads/android.json").header("Accept", "application/json").get().build()
        http.newCall(req).execute().use { resp ->
            if (resp.code == 404) null else parse(resp, com.echoledger.mobile.data.AndroidReleaseDto.serializer())
        }
    }

    /** Whether [url] is on this server (same scheme, host and port): updates are downloaded only from there. */
    fun onServer(url: String): Boolean {
        val server = runCatching { base.toHttpUrl() }.getOrNull() ?: return false
        val other = runCatching { url.toHttpUrl() }.getOrNull() ?: return false
        return other.scheme == server.scheme && other.host == server.host && other.port == server.port
    }

    suspend fun register(code: String, name: String, appVersion: String, osVersion: String, installId: String? = null): RegisterResponse {
        val body = buildJsonObject {
            put("pairing_code", code)
            put("name", name)
            put("platform", "android")
            put("app_version", appVersion)
            put("os_version", osVersion)
            installId?.let { put("install_id", it) }
        }.toString().toRequestBody(jsonType)
        return call(request("devices/register").post(body).build(), RegisterResponse.serializer())!!
    }

    /** `GET /api/auth/options` (since v1.5, Phase 1C): what the sign-in screen offers here. */
    suspend fun authOptions(): com.echoledger.mobile.data.AuthOptionsDto =
        call(request("auth/options").get().build(), com.echoledger.mobile.data.AuthOptionsDto.serializer())!!

    /** `GET /api/me/entitlements` (since v1.6): the plan, its features and limits; an older server answers 404. */
    suspend fun entitlements(): com.echoledger.mobile.data.EntitlementsDto =
        call(request("me/entitlements").get().build(), com.echoledger.mobile.data.EntitlementsDto.serializer())!!

    /** `POST /api/upgrade-requests` (since v1.6): asks the server's owner for [plan]; one pending at a time. */
    suspend fun requestUpgrade(plan: String, message: String?): com.echoledger.mobile.data.UpgradeRequestDto {
        val body = buildJsonObject {
            put("plan", plan)
            message?.takeIf { it.isNotBlank() }?.let { put("message", it.take(1000)) }
        }.toString().toRequestBody(jsonType)
        return call(request("upgrade-requests").post(body).build(), com.echoledger.mobile.data.UpgradeRequestDto.serializer())!!
    }

    /** `PUT /api/me/transcription-key` (since v1.6, the Free plan): this person's own Groq key, checked by the server. */
    suspend fun setTranscriptionKey(key: String): com.echoledger.mobile.data.TranscriptionKeyDto {
        val body = buildJsonObject {
            put("provider", "groq")
            put("key", key.trim())
        }.toString().toRequestBody(jsonType)
        return call(request("me/transcription-key").put(body).build(), com.echoledger.mobile.data.TranscriptionKeyDto.serializer())!!
    }

    suspend fun deleteTranscriptionKey() {
        call(request("me/transcription-key").delete().build(), null as KSerializer<Unit>?)
    }

    /** `POST /api/auth/start` (since v1.6): the email first; whether it has an account and what it can use. */
    suspend fun authStart(email: String): AuthStartDto {
        val body = buildJsonObject { put("email", email) }.toString().toRequestBody(jsonType)
        return call(request("auth/start").post(body).build(), AuthStartDto.serializer())!!
    }

    /** `POST /api/auth/code` (since v1.6): emails a 6-digit code ([purpose] `sign_in` or `sign_up`). */
    suspend fun authCode(email: String, purpose: String): CodeSentDto {
        val body = buildJsonObject {
            put("email", email)
            put("purpose", purpose)
        }.toString().toRequestBody(jsonType)
        return call(request("auth/code").post(body).build(), CodeSentDto.serializer())!!
    }

    /** What `POST /api/auth/code/verify` answers: signed in, or (a new email) the profile step's token. */
    sealed interface CodeResult {
        data class SignedIn(val response: RegisterResponse) : CodeResult

        data class NewAccount(val next: SignUpNextDto) : CodeResult
    }

    /** `POST /api/auth/code/verify` (since v1.6): the emailed code (and the two-factor code once asked). */
    suspend fun authCodeVerify(
        email: String,
        code: String,
        otp: String?,
        name: String,
        appVersion: String,
        osVersion: String,
        installId: String?
    ): CodeResult {
        val body = buildJsonObject {
            put("email", email)
            put("code", code)
            otp?.takeIf { it.isNotBlank() }?.let { put("otp", it) }
            put("name", name)
            put("platform", "android")
            put("app_version", appVersion)
            put("os_version", osVersion)
            installId?.let { put("install_id", it) }
        }.toString().toRequestBody(jsonType)
        val answer = call(request("auth/code/verify").post(body).build(), kotlinx.serialization.json.JsonObject.serializer())!!
        return if (answer.containsKey("token")) {
            CodeResult.SignedIn(ApiJson.decodeFromJsonElement(RegisterResponse.serializer(), answer))
        } else {
            CodeResult.NewAccount(ApiJson.decodeFromJsonElement(SignUpNextDto.serializer(), answer))
        }
    }

    /** `POST /api/auth/sign-up` (since v1.6): the new account, on the default plan, and this phone signed in to it. */
    suspend fun authSignUp(
        signUpToken: String,
        name: String,
        deviceName: String,
        appVersion: String,
        osVersion: String,
        installId: String?
    ): RegisterResponse {
        val body = buildJsonObject {
            put("sign_up_token", signUpToken)
            put("name", name)
            put("accept_terms", true)
            put("device_name", deviceName)
            put("platform", "android")
            put("app_version", appVersion)
            put("os_version", osVersion)
            installId?.let { put("install_id", it) }
        }.toString().toRequestBody(jsonType)
        return call(request("auth/sign-up").post(body).build(), RegisterResponse.serializer())!!
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
        CompleteAnswer.serializer(),
        allowEmpty = true // a v1 server answers 202 without a body
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

    /** `GET /api/sessions/{id}/screens` (since v1.5): the recording's screen moments in time order. */
    suspend fun screens(sessionId: String): List<com.echoledger.mobile.data.ScreenDto> =
        call(request("sessions/$sessionId/screens").get().build(), com.echoledger.mobile.data.ScreensResponse.serializer())!!.data

    /** The smart folders' counts on the server (since v1.5); null from an older server. */
    suspend fun libraryFolderCounts(): Map<String, Int>? =
        call(request("library/views").get().build(), com.echoledger.mobile.data.LibraryViewsDto.serializer())!!.folders

    /** `GET /api/clients/{id}/screens` (since v1.5): a project's screens, newest recording first, 60 per page. */
    suspend fun projectScreens(
        clientId: String,
        includeSubfolders: Boolean,
        cursor: String?
    ): com.echoledger.mobile.data.ProjectScreensResponse {
        val target = "$base/api/clients/$clientId/screens".toHttpUrl().newBuilder()
            .addQueryParameter("include_subfolders", if (includeSubfolders) "1" else "0")
            .apply { cursor?.let { addQueryParameter("cursor", it) } }
            .build()
        val req = Request.Builder().url(target).header("Accept", "application/json")
            .apply { token?.let { header("Authorization", "Bearer $it") } }.get().build()
        return call(req, com.echoledger.mobile.data.ProjectScreensResponse.serializer())!!
    }

    suspend fun deleteScreen(sessionId: String, screenId: String) {
        call(request("sessions/$sessionId/screens/$screenId").delete().build(), null as KSerializer<Unit>?)
    }

    /** A screen's picture from its signed link (no token: the link carries its own signature). */
    suspend fun screenImage(url: String): ByteArray = withContext(Dispatchers.IO) {
        http.newCall(Request.Builder().url(url).get().build()).execute().use { resp ->
            if (!resp.isSuccessful) throw java.io.IOException("The screen could not be loaded (${resp.code}).")
            resp.body.bytes()
        }
    }

    /** `GET /api/sessions/{id}/pack` (since v1.5): the meeting pack, a PDF or a ZIP, in [view]. */
    suspend fun meetingPack(sessionId: String, format: String, view: String?): Pair<ByteArray, String> = withContext(Dispatchers.IO) {
        val url = url("sessions/$sessionId/pack").toHttpUrl().newBuilder().addQueryParameter("format", format)
            .apply { view?.let { addQueryParameter("view", it) } }.build()
        val req = Request.Builder().url(url).apply { token?.let { header("Authorization", "Bearer $it") } }.get().build()
        http.newCall(req).execute().use { resp ->
            if (!resp.isSuccessful) parse(resp, null as KSerializer<Unit>?)
            val name = ExportNames.fromDisposition(resp.header("Content-Disposition")) ?: "Meeting pack.$format"
            resp.body.bytes() to name
        }
    }

    /** A smart folder the phone cannot work out from its own copy (since v1.5: `imported`, `shared`), first 100. */
    suspend fun libraryFolder(folder: String): LibraryResponse {
        val url = url("library").toHttpUrl().newBuilder().addQueryParameter("folder", folder).addQueryParameter("limit", "100").build()
        val req = Request.Builder().url(url).header("Accept", "application/json")
            .apply { token?.let { header("Authorization", "Bearer $it") } }.get().build()
        return call(req, LibraryResponse.serializer())!!
    }

    /** `GET /api/links/resolve` (since v1.5): what a share, project or request link is for this phone; null = open the web page. */
    suspend fun resolveLink(link: String): com.echoledger.mobile.data.ResolvedLinkDto? = runCatching {
        val url = url("links/resolve").toHttpUrl().newBuilder().addQueryParameter("url", link).build()
        val req = Request.Builder().url(url).header("Accept", "application/json")
            .apply { token?.let { header("Authorization", "Bearer $it") } }.get().build()
        call(req, com.echoledger.mobile.data.ResolvedLinkDto.serializer())
    }.getOrNull()

    suspend fun transcript(id: String): TranscriptDto = call(request("transcripts/$id").get().build(), TranscriptDto.serializer())!!

    /** `POST /api/transcripts/{id}/suggestions/{n}` (since 1.4): accept (the words change) or dismiss a suggested fix. */
    suspend fun decideSuggestion(id: String, suggestion: Int, accept: Boolean): TranscriptDto {
        val body = buildJsonObject { put("accept", accept) }.toString().toRequestBody(jsonType)
        return call(request("transcripts/$id/suggestions/$suggestion").post(body).build(), TranscriptDto.serializer())!!
    }

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
        keep: Boolean? = null,
        title: String? = null
    ) {
        val body = buildJsonObject {
            title?.let { put("title", it) }
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

    // ---- Projects, filing rules and sharing (since v1.5) ----------------------------------------------------------

    /** `POST /api/clients` with a parent: a new project, inside [parentId] when given. */
    suspend fun createProject(name: String, parentId: String?): ClientDto {
        val body = buildJsonObject {
            put("name", name)
            parentId?.let { put("parent_id", it) }
        }.toString().toRequestBody(jsonType)
        return call(request("clients").post(body).build(), ClientResponse.serializer())!!.data
    }

    /** `PATCH /api/clients/{id}`: rename and/or move a project ([toTop] moves it to the top level). */
    suspend fun updateProject(id: String, name: String? = null, parentId: String? = null, toTop: Boolean = false): ClientDto {
        val body = buildJsonObject {
            name?.let { put("name", it) }
            if (toTop) put("parent_id", kotlinx.serialization.json.JsonNull) else parentId?.let { put("parent_id", it) }
        }.toString().toRequestBody(jsonType)
        return call(request("clients/$id").patch(body).build(), ClientResponse.serializer())!!.data
    }

    /** `DELETE /api/clients/{id}`: its recordings go to the Inbox, its subfolders up a level. */
    suspend fun deleteProject(id: String): DeletedProjectAnswer =
        call(request("clients/$id").delete().build(), DeletedProjectAnswer.serializer())!!

    suspend fun filingRules(clientId: String): List<FilingRuleDto> =
        call(request("clients/$clientId/rules").get().build(), FilingRulesResponse.serializer())!!.data

    suspend fun addFilingRule(clientId: String, kind: String, value: String): FilingRuleDto {
        val body = buildJsonObject {
            put("kind", kind)
            put("value", value)
        }.toString().toRequestBody(jsonType)
        return call(request("clients/$clientId/rules").post(body).build(), FilingRuleResponse.serializer())!!.data
    }

    suspend fun deleteFilingRule(clientId: String, ruleId: Long) {
        call(request("clients/$clientId/rules/$ruleId").delete().build(), null as KSerializer<Unit>?)
    }

    /**
     * A share link of a recording ([sessionId]) or a project ([clientId]): [audience] `public`, `password` or `people`
     * ([people] are emails), [allowDownload], and when it ends ([expiresAt] ISO, null = never). The URL is shown once.
     */
    suspend fun createShareLink(
        sessionId: String?,
        clientId: String?,
        audience: String,
        password: String?,
        people: List<String>,
        allowDownload: Boolean,
        expiresAt: String?
    ): ShareLinkDto {
        val body = buildJsonObject {
            put("audience", audience)
            if (audience == "password") password?.let { put("password", it) }
            if (audience == "people") put("people", buildJsonArray { people.forEach { add(kotlinx.serialization.json.JsonPrimitive(it)) } })
            put("allow_download", allowDownload)
            expiresAt?.let { put("expires_at", it) }
        }.toString().toRequestBody(jsonType)
        val path = if (clientId != null) "clients/$clientId/shares" else "sessions/$sessionId/shares"
        return call(request(path).post(body).build(), ShareLinkDto.serializer())!!
    }

    /** `GET /api/shares?client_id=`: a project's links. */
    suspend fun projectShares(clientId: String): ShareListResponse =
        call(request("shares?client_id=$clientId").get().build(), ShareListResponse.serializer())!!

    /** Send a recording ([sessionId]) or a project ([clientId]) to teammates by email; how many got it. */
    suspend fun sendToTeammates(sessionId: String?, clientId: String?, to: List<String>, note: String?): Int {
        val body = buildJsonObject {
            put("to", buildJsonArray { to.forEach { add(kotlinx.serialization.json.JsonPrimitive(it)) } })
            note?.takeIf { it.isNotBlank() }?.let { put("note", it) }
        }.toString().toRequestBody(jsonType)
        val path = if (clientId != null) "clients/$clientId/send" else "sessions/$sessionId/send"
        return call(request(path).post(body).build(), SentAnswer.serializer())!!.sent
    }

    /** `GET /api/sent` (since v1.5): what you sent of this recording or project. */
    suspend fun sentOf(sessionId: String?, clientId: String?): List<com.echoledger.mobile.data.SentItemDto> {
        val query = if (clientId != null) "client_id=$clientId" else "session_id=$sessionId"
        return call(request("sent?$query").get().build(), com.echoledger.mobile.data.SentListResponse.serializer())!!.data
    }

    /** `DELETE /api/sent/{id}` (since v1.5): stop sharing what you sent; a copy they saved stays theirs. */
    suspend fun stopSharing(id: String) {
        call(request("sent/$id").delete().build(), null as KSerializer<Unit>?)
    }

    suspend fun sharedWithMe(): List<SharedItemDto> = call(request("shared-with-me").get().build(), SharedResponse.serializer())!!.data

    suspend fun saveShared(id: String): SavedSharedAnswer =
        call(request("shared-with-me/$id/save").post("{}".toRequestBody(jsonType)).build(), SavedSharedAnswer.serializer())!!

    suspend fun removeShared(id: String) {
        call(request("shared-with-me/$id").delete().build(), null as KSerializer<Unit>?)
    }

    suspend fun requestLinks(): List<RequestLinkDto> =
        call(request("request-links").get().build(), RequestLinksResponse.serializer())!!.data

    suspend fun createRequestLink(title: String, clientId: String?, expiresAt: String?): RequestLinkDto {
        val body = buildJsonObject {
            put("title", title)
            put("client_id", clientId?.let { kotlinx.serialization.json.JsonPrimitive(it) } ?: kotlinx.serialization.json.JsonNull)
            expiresAt?.let { put("expires_at", it) }
        }.toString().toRequestBody(jsonType)
        return call(request("request-links").post(body).build(), RequestLinkResponse.serializer())!!.data
    }

    suspend fun revokeRequestLink(id: String) {
        call(request("request-links/$id").delete().build(), null as KSerializer<Unit>?)
    }

    // ---- Client workspaces (since 1.4) -------------------------------------------------------------------------

    /** `GET /api/clients/{id}/workspace`: brief, memory, call notes, files and the AI budget. */
    suspend fun workspace(id: String): com.echoledger.mobile.data.WorkspaceDto =
        call(request("clients/$id/workspace").get().build(), com.echoledger.mobile.data.WorkspaceResponse.serializer())!!.data

    /** `PATCH /api/clients/{id}` with `ai_notes`: call notes and memory after each call for this client. */
    suspend fun setClientAiNotes(id: String, on: Boolean) {
        val body = buildJsonObject { put("ai_notes", on) }.toString().toRequestBody(jsonType)
        call(request("clients/$id").patch(body).build(), null as KSerializer<Unit>?)
    }

    /** `PUT /api/clients/{id}/brief`: a person's edit (never rewritten by itself afterwards). */
    suspend fun updateBrief(id: String, sections: com.echoledger.mobile.data.BriefSections): com.echoledger.mobile.data.BriefDto {
        val payload = buildJsonObject {
            put("sections", ApiJson.encodeToJsonElement(com.echoledger.mobile.data.BriefSections.serializer(), sections))
        }
        val body = payload.toString().toRequestBody(jsonType)
        return call(request("clients/$id/brief").put(body).build(), com.echoledger.mobile.data.BriefResponse.serializer())!!.data
    }

    /** `POST /api/clients/{id}/brief/refresh`: write the brief again from the files (or calls), on the server's queue. */
    suspend fun refreshBrief(id: String) {
        call(request("clients/$id/brief/refresh").post("{}".toRequestBody(jsonType)).build(), null as KSerializer<Unit>?, allowEmpty = true)
    }

    suspend fun addMemory(id: String, kind: String, text: String): com.echoledger.mobile.data.MemoryItemDto {
        val body = buildJsonObject {
            put("kind", kind)
            put("text", text)
        }.toString().toRequestBody(jsonType)
        return call(request("clients/$id/memory").post(body).build(), com.echoledger.mobile.data.MemoryItemResponse.serializer())!!.data
    }

    suspend fun updateMemory(id: String, item: String, text: String? = null, pinned: Boolean? = null) {
        val body = buildJsonObject {
            text?.let { put("text", it) }
            pinned?.let { put("pinned", it) }
        }.toString().toRequestBody(jsonType)
        call(request("clients/$id/memory/$item").patch(body).build(), null as KSerializer<Unit>?)
    }

    suspend fun deleteMemory(id: String, item: String) {
        call(request("clients/$id/memory/$item").delete().build(), null as KSerializer<Unit>?, allowEmpty = true)
    }

    /** `POST /api/clients/{id}/files` (multipart): a PDF, Word, text or Markdown file for the brief and Ask. */
    suspend fun uploadClientFile(id: String, name: String, mime: String, bytes: ByteArray): com.echoledger.mobile.data.ClientFileDto {
        val body = okhttp3.MultipartBody.Builder().setType(okhttp3.MultipartBody.FORM)
            .addFormDataPart("file", name, bytes.toRequestBody(mime.toMediaType()))
            .build()
        return call(request("clients/$id/files").post(body).build(), com.echoledger.mobile.data.ClientFileResponse.serializer())!!.data
    }

    /** `POST /api/clients/{id}/files` with pasted instructions. */
    suspend fun addInstructions(id: String, name: String, text: String): com.echoledger.mobile.data.ClientFileDto {
        val body = buildJsonObject {
            put("name", name)
            put("text", text)
        }.toString().toRequestBody(jsonType)
        return call(request("clients/$id/files").post(body).build(), com.echoledger.mobile.data.ClientFileResponse.serializer())!!.data
    }

    suspend fun deleteClientFile(id: String, file: String) {
        call(request("clients/$id/files/$file").delete().build(), null as KSerializer<Unit>?, allowEmpty = true)
    }

    /** `POST /api/clients/{id}/ask`: a short answer with citations ("en" or "roman_urdu"). */
    suspend fun ask(id: String, question: String, language: String): com.echoledger.mobile.data.AskAnswerDto {
        val body = buildJsonObject {
            put("question", question)
            put("language", language)
        }.toString().toRequestBody(jsonType)
        return call(request("clients/$id/ask").post(body).build(), com.echoledger.mobile.data.AskResponse.serializer())!!.data
    }

    /** `GET /api/clients/{id}/bundle`: "Copy for AI" text. */
    suspend fun bundle(id: String, notes: Int = 5): String =
        call(request("clients/$id/bundle?notes=$notes").get().build(), com.echoledger.mobile.data.BundleResponse.serializer())!!.data.text

    /** `GET /api/sessions/{id}/notes`, or null when the recording has no notes yet. */
    suspend fun sessionNotes(id: String): com.echoledger.mobile.data.CallNotesDto? = try {
        call(request("sessions/$id/notes").get().build(), com.echoledger.mobile.data.CallNotesResponse.serializer())!!.data
    } catch (e: ApiException) {
        if (e.code == "notes_not_found") null else throw e
    }

    /** `POST /api/sessions/{id}/notes`: write the notes (again) on the server's queue. */
    suspend fun makeNotes(id: String, language: String): com.echoledger.mobile.data.CallNotesDto {
        val body = buildJsonObject { put("language", language) }.toString().toRequestBody(jsonType)
        return call(request("sessions/$id/notes").post(body).build(), com.echoledger.mobile.data.CallNotesResponse.serializer())!!.data
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
