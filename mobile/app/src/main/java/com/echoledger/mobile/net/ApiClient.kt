package com.echoledger.mobile.net

import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.ClientsResponse
import com.echoledger.mobile.data.CreateSessionRequest
import com.echoledger.mobile.data.CreateSessionResponse
import com.echoledger.mobile.data.DeviceTranscript
import com.echoledger.mobile.data.LibraryResponse
import com.echoledger.mobile.data.RecordingUpload
import com.echoledger.mobile.data.RegisterResponse
import com.echoledger.mobile.data.ServerConfig
import com.echoledger.mobile.data.SttTokenResponse
import com.echoledger.mobile.data.TranscriptDto
import com.echoledger.mobile.data.UploadState
import java.io.IOException
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.KSerializer
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

class ApiException(val status: Int, message: String) : IOException(message)

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
        val message = runCatching { ApiJson.parseToJsonElement(body).jsonObject["message"]?.jsonPrimitive?.contentOrNull }.getOrNull()
        throw ApiException(resp.code, message ?: "Request failed (${resp.code})")
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

    suspend fun sttToken(useCase: String, channels: List<String>, clientId: String?): SttTokenResponse {
        val body = buildJsonObject {
            put("use_case", useCase)
            put("channels", buildJsonArray { channels.forEach { add(kotlinx.serialization.json.JsonPrimitive(it)) } })
            clientId?.let { put("client_id", it) }
            put("sample_rate", 16_000)
        }.toString().toRequestBody(jsonType)
        return call(request("stt/token").post(body).build(), SttTokenResponse.serializer())!!
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

    suspend fun complete(recordingId: String) {
        call(request("recordings/$recordingId/complete").post(ByteArray(0).toRequestBody(null)).build(), null as KSerializer<Unit>?)
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

    suspend fun deleteSession(id: String) {
        call(request("sessions/$id").delete().build(), null as KSerializer<Unit>?)
    }
}
