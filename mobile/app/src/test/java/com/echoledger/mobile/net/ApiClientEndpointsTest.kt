package com.echoledger.mobile.net

import com.echoledger.mobile.data.ArtifactDto
import com.echoledger.mobile.data.CreateSessionRequest
import com.echoledger.mobile.data.DeviceTranscript
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyBytes
import com.echoledger.mobile.testsupport.bodyText
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.int
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import mockwebserver3.RecordedRequest
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** Every typed endpoint of docs/API.md against server-shaped responses. */
class ApiClientEndpointsTest {
    private lateinit var server: FakeServer

    @Before
    fun setUp() {
        server = FakeServer()
    }

    @After
    fun tearDown() {
        server.close()
    }

    private val token = "7|eld_0123456789abcdef"

    private fun client(withToken: String? = token) = ApiClient(server.baseUrl, pins = emptyList(), token = withToken)

    private fun RecordedRequest.bodyJson(): JsonObject = Json.parseToJsonElement(bodyText()).jsonObject

    private fun only(method: String, path: String): RecordedRequest {
        val matching = server.requestsTo(method, path)
        assertEquals("requests to $method $path", 1, matching.size)
        return matching.single()
    }

    // ---- pairing and configuration ------------------------------------------------------------------------------

    @Test
    fun `register posts the pairing request without credentials and parses the registration`() = runBlocking {
        server.on("POST", "/api/devices/register", FakeServer.json(201, ServerFixtures.registerResponse()))

        val response = client(withToken = null).register("TEST-2345", "Pixel 9", "0.1.3", "16")

        val request = only("POST", "/api/devices/register")
        assertNull("registration must not send a bearer token", request.headers["Authorization"])
        assertEquals("application/json", request.headers["Accept"])
        assertTrue(request.headers["Content-Type"]!!.startsWith("application/json"))
        val body = request.bodyJson()
        assertEquals("TEST-2345", body["pairing_code"]!!.jsonPrimitive.content)
        assertEquals("Pixel 9", body["name"]!!.jsonPrimitive.content)
        assertEquals("android", body["platform"]!!.jsonPrimitive.content)
        assertEquals("0.1.3", body["app_version"]!!.jsonPrimitive.content)
        assertEquals("16", body["os_version"]!!.jsonPrimitive.content)

        assertEquals("7|eld_0123456789abcdef", response.token)
        assertEquals("2027-10-24T09:00:00+00:00", response.tokenExpiresAt)
        assertEquals(ServerFixtures.DEVICE_ID, response.device.id)
        assertEquals("phone", response.device.sourceDevice)
        assertEquals(listOf(ServerFixtures.PIN_A, ServerFixtures.PIN_B), response.config.pins)
        assertEquals(30, response.config.retentionDays)
        assertEquals("cloud", response.config.providers.getValue("live_captions").mode)
        assertEquals(listOf("primary"), response.config.providers.getValue("final_transcript").requiredArtifacts)
        assertEquals("ogg_opus", response.config.providers.getValue("final_transcript").preferredPrimary)
        assertEquals("both", response.config.captions.channels)
    }

    @Test
    fun `register preserves unicode device names and a pairing code with spaces is sent verbatim`() = runBlocking {
        server.on("POST", "/api/devices/register", FakeServer.json(201, ServerFixtures.registerResponse()))

        client(null).register(" TEST 2345", "Saïd’s Téléphone 📱", "0.1.3", "16")

        val body = only("POST", "/api/devices/register").bodyJson()
        assertEquals(" TEST 2345", body["pairing_code"]!!.jsonPrimitive.content)
        assertEquals("Saïd’s Téléphone 📱", body["name"]!!.jsonPrimitive.content)
    }

    @Test
    fun `config sends the bearer token and parses limits providers and pins`() = runBlocking {
        server.on("GET", "/api/config", FakeServer.json(200, ServerFixtures.config()))

        val config = client().config()

        val request = only("GET", "/api/config")
        assertEquals("Bearer $token", request.headers["Authorization"])
        assertEquals("application/json", request.headers["Accept"])
        assertEquals(5_242_880L, config.upload.chunkSizeBytes)
        assertEquals(2_147_483_648L, config.upload.maxRecordingBytes)
        assertTrue("audio/ogg" in config.upload.acceptedMimes)
        assertEquals(4, config.providers.size)
        assertEquals(2, config.pins.size)
    }

    @Test
    fun `config accepts the minimal body and falls back to defaults`() = runBlocking {
        server.on(
            "GET",
            "/api/config",
            FakeServer.json(
                200,
                """{"device":{"id":"d","name":"n","platform":"android","source_device":"phone"},""" +
                    """"server_time":"2026-10-24T09:00:00+00:00","retention_days":7,""" +
                    """"upload":{"chunk_size_bytes":1,"max_recording_bytes":2}}"""
            )
        )

        val config = client().config()

        assertTrue(config.providers.isEmpty())
        assertTrue(config.pins.isEmpty())
        assertEquals("both", config.captions.channels)
        assertTrue(config.upload.acceptedMimes.isEmpty())
    }

    @Test
    fun `config reports the client-only and off caption modes`() = runBlocking {
        server.on("GET", "/api/config", FakeServer.json(200, ServerFixtures.config(captions = "client_only")))
        assertEquals("client_only", client().config().captions.channels)
        server.on("GET", "/api/config", FakeServer.json(200, ServerFixtures.config(captions = "off")))
        assertEquals("off", client().config().captions.channels)
    }

    @Test
    fun `clients parses names and counts and tolerates null colour and timestamps`() = runBlocking {
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))

        val response = client().clients()

        assertEquals("Bearer $token", only("GET", "/api/clients").headers["Authorization"])
        assertEquals(listOf("Acme Holdings", "Zed Logistics"), response.data.map { it.name })
        assertEquals(listOf(12, 0), response.data.map { it.keytermCount })
    }

    @Test
    fun `clients parses an empty list`() = runBlocking {
        server.on("GET", "/api/clients", FakeServer.json(200, """{"data":[]}"""))
        assertTrue(client().clients().data.isEmpty())
    }

    // ---- live captions ------------------------------------------------------------------------------------------

    @Test
    fun `sttToken posts the use case channels and sample rate and parses AssemblyAI grants`() = runBlocking {
        server.on("POST", "/api/stt/token", FakeServer.json(200, ServerFixtures.assemblyAiGrants()))

        val response = client().sttToken("live_captions", listOf("me", "client"), clientId = "01JCLIENT0000000000000000A")

        val body = only("POST", "/api/stt/token").bodyJson()
        assertEquals("live_captions", body["use_case"]!!.jsonPrimitive.content)
        assertEquals(listOf("me", "client"), body["channels"]!!.jsonArray.map { it.jsonPrimitive.content })
        assertEquals("01JCLIENT0000000000000000A", body["client_id"]!!.jsonPrimitive.content)
        assertEquals(16_000, body["sample_rate"]!!.jsonPrimitive.int)

        assertEquals("assemblyai", response.provider)
        assertEquals("cloud", response.mode)
        assertEquals(listOf("Acme Holdings", "Farah Khan"), response.keyterms)
        assertEquals(listOf("me", "client"), response.grants.map { it.channel })
        val grant = response.grants.first()
        assertEquals("assemblyai_v3", grant.protocol)
        assertTrue(grant.wsUrl!!.startsWith("wss://streaming.assemblyai.com/v3/ws?"))
        assertEquals(10_800L, grant.maxSessionSeconds)
        assertEquals(50, grant.audio!!.frameMs)
        assertEquals(1, grant.initMessages.size)
    }

    @Test
    fun `sttToken sends the recording language chip only when one is chosen`() = runBlocking {
        server.on("POST", "/api/stt/token", FakeServer.json(200, ServerFixtures.assemblyAiGrants()))

        client().sttToken("live_captions", listOf("me", "client"), null, sessionId = "S1", language = "ur")
        client().sttToken("live_captions", listOf("me", "client"), null, sessionId = "S1")

        val bodies = server.requestsTo("POST", "/api/stt/token").map { it.bodyJson() }
        assertEquals("ur", bodies[0]["language"]!!.jsonPrimitive.content)
        assertEquals(null, bodies[1]["language"])
    }

    @Test
    fun `setClientLanguage patches the client and sends null for the console default`() = runBlocking {
        server.on("PATCH", "/api/clients/C1", FakeServer.json(200, """{"data":{"id":"C1","name":"Acme"}}"""))

        client().setClientLanguage("C1", "ur")
        client().setClientLanguage("C1", null)

        val bodies = server.requestsTo("PATCH", "/api/clients/C1").map { it.bodyText() }
        assertEquals(listOf("""{"language":"ur"}""", """{"language":null}"""), bodies)
    }

    @Test
    fun `sttToken leaves out the client id when none is selected`() = runBlocking {
        server.on(
            "POST",
            "/api/stt/token",
            FakeServer.json(200, ServerFixtures.assemblyAiGrants(channels = listOf("me"), keyterms = emptyList()))
        )

        val response = client().sttToken("dictation", listOf("me"), clientId = null)

        assertFalse(only("POST", "/api/stt/token").bodyJson().containsKey("client_id"))
        assertTrue(response.grants.single().initMessages.isEmpty())
        assertTrue(response.keyterms.isEmpty())
    }

    @Test
    fun `sttToken parses the captions-off answer`() = runBlocking {
        server.on(
            "POST",
            "/api/stt/token",
            FakeServer.json(200, """{"provider":null,"mode":"off","expires_at":null,"keyterms":[],"grants":[]}""")
        )

        val response = client().sttToken("live_captions", listOf("me", "client"), null)

        assertNull(response.provider)
        assertEquals("off", response.mode)
        assertNull(response.expiresAt)
        assertTrue(response.grants.isEmpty())
    }

    @Test
    fun `sttToken parses on-device engines without grants`() = runBlocking {
        server.on("POST", "/api/stt/token", FakeServer.json(200, """{"provider":"parakeet_local","mode":"on_device","grants":[]}"""))

        val response = client().sttToken("live_captions", listOf("me"), null)

        assertEquals("on_device", response.mode)
        assertTrue(response.grants.isEmpty())
        assertTrue(response.keyterms.isEmpty())
    }

    @Test
    fun `sttToken parses ElevenLabs grants with empty init messages`() = runBlocking {
        val grant = """{"channel":"me","transport":"websocket","protocol":"elevenlabs_realtime",""" +
            """"ws_url":"wss://api.elevenlabs.io/v1/speech-to-text/realtime?token=abc&keyterms=Acme&keyterms=Farah",""" +
            """"redeem_by":"2026-10-24T09:01:00+00:00",""" +
            """"max_session_seconds":null,"audio":{"encoding":"pcm_s16le","sample_rate":16000,"channels":1,"frame_ms":100},"init_messages":[]}"""
        server.on(
            "POST",
            "/api/stt/token",
            FakeServer.json(
                200,
                """{"provider":"elevenlabs","mode":"cloud","expires_at":"2026-10-24T09:01:00+00:00","keyterms":["Acme","Farah"],"grants":[$grant]}"""
            )
        )

        val response = client().sttToken("live_captions", listOf("me"), null)

        val parsed = response.grants.single()
        assertEquals("elevenlabs_realtime", parsed.protocol)
        assertEquals(100, parsed.audio!!.frameMs)
        assertNull(parsed.maxSessionSeconds)
        assertTrue(parsed.initMessages.isEmpty())
    }

    @Test
    fun `sttToken tolerates Azure SDK grants whose token fields are not modelled`() = runBlocking {
        val grant = """{"channel":"me","transport":"azure_speech_sdk","token":"jwt","region":"westeurope",""" +
            """"expires_in":600,"refresh_after":540,"phrases":["Acme"]}"""
        server.on(
            "POST",
            "/api/stt/token",
            FakeServer.json(200, """{"provider":"azure_mai","mode":"cloud","keyterms":[],"grants":[$grant]}""")
        )

        val parsed = client().sttToken("live_captions", listOf("me"), null).grants.single()

        assertEquals("azure_speech_sdk", parsed.transport)
        assertNull(parsed.protocol)
        assertNull(parsed.wsUrl)
    }

    // ---- sessions, recordings and uploads ----------------------------------------------------------------------

    private fun sessionRequest(sampleRate: Int? = 48_000) = CreateSessionRequest(
        clientRef = "3f2b6c1e-8d4a-4d3e-9a51-2c7f0e9b1a22",
        sourceApp = "whatsapp",
        clientId = null,
        title = null,
        startedAt = "2026-10-24T09:00:00Z",
        endedAt = "2026-10-24T09:42:10Z",
        durationMs = 2_530_000,
        language = null,
        artifacts = listOf(
            ArtifactDto("primary", "audio/ogg", "opus", 2, sampleRate, 10_485_760, "a".repeat(64), 2_530_000)
        )
    )

    @Test
    fun `createSession posts the documented body and parses the upload slot`() = runBlocking {
        server.on("POST", "/api/sessions", FakeServer.json(201, ServerFixtures.sessionCreated(totalChunks = 2)))

        val response = client().createSession(sessionRequest())

        val body = only("POST", "/api/sessions").bodyJson()
        assertEquals("3f2b6c1e-8d4a-4d3e-9a51-2c7f0e9b1a22", body["client_ref"]!!.jsonPrimitive.content)
        assertEquals("whatsapp", body["source_app"]!!.jsonPrimitive.content)
        assertEquals("2026-10-24T09:00:00Z", body["started_at"]!!.jsonPrimitive.content)
        assertEquals("2026-10-24T09:42:10Z", body["ended_at"]!!.jsonPrimitive.content)
        assertEquals(2_530_000, body["duration_ms"]!!.jsonPrimitive.int)
        assertFalse("unset optional fields must be omitted, not sent as null", body.containsKey("client_id"))
        assertFalse(body.containsKey("title"))
        assertFalse(body.containsKey("language"))
        val artifact = body["artifacts"]!!.jsonArray.single().jsonObject
        assertEquals("primary", artifact["role"]!!.jsonPrimitive.content)
        assertEquals("audio/ogg", artifact["mime"]!!.jsonPrimitive.content)
        assertEquals("opus", artifact["codec"]!!.jsonPrimitive.content)
        assertEquals(2, artifact["channels"]!!.jsonPrimitive.int)
        assertEquals(48_000, artifact["sample_rate"]!!.jsonPrimitive.int)
        assertEquals(10_485_760L, artifact["size_bytes"]!!.jsonPrimitive.content.toLong())
        assertEquals("a".repeat(64), artifact["sha256"]!!.jsonPrimitive.content)

        assertEquals(ServerFixtures.SESSION_ID, response.session.id)
        assertEquals("uploading", response.session.status)
        val slot = response.recordings.single()
        assertEquals(ServerFixtures.RECORDING_ID, slot.id)
        assertEquals("primary", slot.role)
        assertEquals(5_242_880L, slot.upload.chunkSizeBytes)
        assertEquals(2, slot.upload.totalChunks)
        assertTrue(slot.upload.receivedChunks.isEmpty())
        assertEquals("pending", slot.upload.status)
        assertEquals(listOf("primary"), response.requiredArtifacts)
    }

    @Test
    fun `createSession omits the sample rate when it is unknown`() = runBlocking {
        server.on("POST", "/api/sessions", FakeServer.json(201, ServerFixtures.sessionCreated()))

        client().createSession(sessionRequest(sampleRate = null))

        val artifact = only("POST", "/api/sessions").bodyJson()["artifacts"]!!.jsonArray.single().jsonObject
        assertFalse(artifact.containsKey("sample_rate"))
    }

    @Test
    fun `createSession accepts the 200 answer for an already known client reference with received chunks`() = runBlocking {
        server.on(
            "POST",
            "/api/sessions",
            FakeServer.json(200, ServerFixtures.sessionCreated(totalChunks = 4, received = listOf(0, 2), uploadStatus = "uploading"))
        )

        val slot = client().createSession(sessionRequest()).recordings.single()

        assertEquals(listOf(0, 2), slot.upload.receivedChunks)
        assertEquals("uploading", slot.upload.status)
    }

    @Test
    fun `recordingStatus returns the received chunk indexes for resuming`() = runBlocking {
        server.on(
            "GET",
            "/api/recordings/${ServerFixtures.RECORDING_ID}",
            FakeServer.json(200, ServerFixtures.recordingStatus(received = listOf(0), total = 2))
        )

        val status = client().recordingStatus(ServerFixtures.RECORDING_ID)

        assertEquals(ServerFixtures.RECORDING_ID, status.id)
        assertEquals(listOf(0), status.upload.receivedChunks)
        assertEquals(2, status.upload.totalChunks)
        assertEquals("Bearer $token", only("GET", "/api/recordings/${ServerFixtures.RECORDING_ID}").headers["Authorization"])
    }

    @Test
    fun `uploadChunk sends raw bytes with the index and checksum headers`() = runBlocking {
        server.on(
            "POST",
            "/api/recordings/${ServerFixtures.RECORDING_ID}/chunks",
            FakeServer.json(200, ServerFixtures.chunkAck(listOf(0, 1), 2))
        )
        val bytes = ByteArray(1024) { (it * 7).toByte() } + ByteArray(256) { it.toByte() }
        val sha = UploadWorker.sha256(bytes)

        val state = client().uploadChunk(ServerFixtures.RECORDING_ID, 1, bytes, sha)

        val request = only("POST", "/api/recordings/${ServerFixtures.RECORDING_ID}/chunks")
        assertEquals("application/octet-stream", request.headers["Content-Type"]!!.substringBefore(';'))
        assertEquals("1", request.headers["Upload-Chunk-Index"])
        assertEquals(sha, request.headers["Upload-Chunk-Sha256"])
        assertEquals(64, sha.length)
        assertEquals(bytes.size.toLong(), request.bodySize)
        assertTrue("the body must be the raw bytes, not multipart or base64", bytes.contentEquals(request.bodyBytes()))
        assertEquals("Bearer $token", request.headers["Authorization"])
        assertEquals(listOf(0, 1), state.receivedChunks)
        assertEquals(2, state.totalChunks)
        assertEquals("uploading", state.status)
    }

    @Test
    fun `uploadChunk carries a multi-megabyte chunk intact`() = runBlocking {
        server.on(
            "POST",
            "/api/recordings/${ServerFixtures.RECORDING_ID}/chunks",
            FakeServer.json(200, ServerFixtures.chunkAck(listOf(0), 1, "uploading"))
        )
        val bytes = ByteArray(5 * 1024 * 1024) { (it % 251).toByte() }

        client().uploadChunk(ServerFixtures.RECORDING_ID, 0, bytes, UploadWorker.sha256(bytes))

        val request = only("POST", "/api/recordings/${ServerFixtures.RECORDING_ID}/chunks")
        assertEquals(bytes.size.toLong(), request.bodySize)
        assertEquals(UploadWorker.sha256(bytes), UploadWorker.sha256(request.bodyBytes()))
    }

    @Test
    fun `complete accepts the 202 body, an empty body and a ready status`() = runBlocking {
        val path = "/api/recordings/${ServerFixtures.RECORDING_ID}/complete"
        server.on("POST", path, FakeServer.json(202, """{"status":"assembling"}"""))
        client().complete(ServerFixtures.RECORDING_ID)
        server.on("POST", path, FakeServer.empty(202))
        client().complete(ServerFixtures.RECORDING_ID)

        val requests = server.requestsTo("POST", path)
        assertEquals(2, requests.size)
        assertEquals(0L, requests.first().bodySize)
        assertEquals("Bearer $token", requests.first().headers["Authorization"])
    }

    @Test
    fun `postTranscript sends the live segments in the documented shape`() = runBlocking {
        server.on(
            "POST",
            "/api/sessions/${ServerFixtures.SESSION_ID}/transcripts",
            FakeServer.json(201, ServerFixtures.transcript(kind = "live"))
        )
        val segments = listOf(
            SegmentDto(1, "Me", 250, 2600, "Hello there."),
            SegmentDto(2, "Client", 12_400, 15_000, "Café — 你好")
        )

        client().postTranscript(ServerFixtures.SESSION_ID, DeviceTranscript("live", "assemblyai", null, segments))

        val body = only("POST", "/api/sessions/${ServerFixtures.SESSION_ID}/transcripts").bodyJson()
        assertEquals("live", body["kind"]!!.jsonPrimitive.content)
        assertEquals("assemblyai", body["provider"]!!.jsonPrimitive.content)
        assertFalse(body.containsKey("language"))
        val sent = body["segments"] as JsonArray
        assertEquals(2, sent.size)
        val second = sent[1].jsonObject
        assertEquals(2, second["channel"]!!.jsonPrimitive.int)
        assertEquals("Client", second["speaker"]!!.jsonPrimitive.content)
        assertEquals(12_400, second["start_ms"]!!.jsonPrimitive.int)
        assertEquals(15_000, second["end_ms"]!!.jsonPrimitive.int)
        assertEquals("Café — 你好", second["text"]!!.jsonPrimitive.content)
    }

    // ---- library --------------------------------------------------------------------------------------------------

    @Test
    fun `library requests the page size and omits since and cursor on the first sync`() = runBlocking {
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))

        val page = client().library(since = null, cursor = null)

        val request = only("GET", "/api/library")
        assertEquals("100", request.url.queryParameter("limit"))
        assertNull(request.url.queryParameter("since"))
        assertNull(request.url.queryParameter("cursor"))
        assertTrue(page.data.isEmpty())
        assertNull(page.nextCursor)
    }

    @Test
    fun `library percent-encodes the plus of the UTC offset so the server does not read it as a space`() = runBlocking {
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        val since = "2026-10-24T09:05:00+00:00"

        client().library(since = since, cursor = "eyJ1cGRhdGVkX2F0IjoiMjAyNiJ9")

        val request = only("GET", "/api/library")
        val rawQuery = request.url.encodedQuery.orEmpty()
        assertFalse("a literal + would decode to a space on the server: $rawQuery", rawQuery.contains('+'))
        assertTrue(rawQuery, rawQuery.contains("%2B"))
        assertEquals(since, request.url.queryParameter("since"))
        assertEquals("eyJ1cGRhdGVkX2F0IjoiMjAyNiJ9", request.url.queryParameter("cursor"))
    }

    @Test
    fun `library parses full items, a tombstone and the next cursor`() = runBlocking {
        val items = listOf(
            ServerFixtures.libraryItem(
                "01JA",
                sourceDevice = "desktop",
                sourceApp = "zoom",
                title = null,
                client = null,
                preview = null,
                finalStatus = null
            ),
            ServerFixtures.tombstone("01JB")
        )
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(items, nextCursor = "next-page")))

        val page = client().library(null, null)

        assertEquals("next-page", page.nextCursor)
        assertEquals("2026-10-24T09:05:00+00:00", page.serverTime)
        val live = page.data[0]
        assertEquals("desktop", live.sourceDevice)
        assertEquals("Zoom", live.sourceAppLabel)
        assertEquals("Pixel 9", live.device!!.name)
        assertNull(live.client)
        assertNull(live.title)
        assertEquals(2_530_000L, live.durationMs)
        assertNull(live.transcript!!.finalStatus)
        assertTrue(live.transcript.liveAvailable)
        val tombstone = page.data[1]
        assertTrue(tombstone.deleted)
        assertEquals("2026-10-21T08:00:00+00:00", tombstone.updatedAt)
        assertNull(tombstone.title)
        assertNull(tombstone.sourceDevice)
    }

    // ---- transcripts, session detail, delete ----------------------------------------------------------------------

    @Test
    fun `transcript parses segments and their word timings, ignoring bookkeeping fields`() = runBlocking {
        server.on("GET", "/api/transcripts/${ServerFixtures.TRANSCRIPT_ID}", FakeServer.json(200, ServerFixtures.transcript()))

        val transcript = client().transcript(ServerFixtures.TRANSCRIPT_ID)

        assertEquals("final", transcript.kind)
        assertEquals("completed", transcript.status)
        assertEquals("en", transcript.language)
        assertEquals(2, transcript.segments.size)
        assertEquals(SegmentDto(1, "Me", 250, 2600, "Hello there."), transcript.segments[0].copy(words = emptyList()))
        assertEquals("Hello", transcript.segments[0].words.first().text)
        assertEquals(250L, transcript.segments[0].words.first().startMs)
        assertNull(transcript.error)
    }

    @Test
    fun `transcript carries the failure reason for a failed transcription`() = runBlocking {
        val body = ServerFixtures.transcript(
            status = "failed",
            error = "The speech provider could not decode this file.",
            segments = "[]",
            text = null
        )
        server.on("GET", "/api/transcripts/${ServerFixtures.TRANSCRIPT_ID}", FakeServer.json(200, body))

        val transcript = client().transcript(ServerFixtures.TRANSCRIPT_ID)

        assertEquals("failed", transcript.status)
        assertEquals("The speech provider could not decode this file.", transcript.error)
        assertNull(transcript.text)
        assertTrue(transcript.segments.isEmpty())
    }

    @Test
    fun `sessionDetail exposes the signed playback url`() = runBlocking {
        server.on("GET", "/api/sessions/${ServerFixtures.SESSION_ID}", FakeServer.json(200, ServerFixtures.sessionDetail()))

        val detail = client().sessionDetail(ServerFixtures.SESSION_ID)

        assertNotNull(detail.playbackUrl)
        assertTrue(detail.playbackUrl!!.contains("signature="))
    }

    @Test
    fun `sessionDetail yields no playback url while the recording is not ready`() = runBlocking {
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null))
        )

        assertNull(client().sessionDetail(ServerFixtures.SESSION_ID).playbackUrl)
    }

    @Test
    fun `deleteSession sends DELETE and accepts a bodiless 204`() = runBlocking {
        server.on("DELETE", "/api/sessions/${ServerFixtures.SESSION_ID}", FakeServer.empty(204))

        client().deleteSession(ServerFixtures.SESSION_ID)

        val request = only("DELETE", "/api/sessions/${ServerFixtures.SESSION_ID}")
        assertEquals("Bearer $token", request.headers["Authorization"])
    }

    // ---- request construction ------------------------------------------------------------------------------------

    @Test
    fun `a trailing slash on the server address does not double the slash in request paths`() = runBlocking {
        server.on("GET", "/api/config", FakeServer.json(200, ServerFixtures.config()))

        ApiClient(server.baseUrl + "/", emptyList(), token).config()

        assertEquals("/api/config", only("GET", "/api/config").url.encodedPath)
    }

    @Test
    fun `a server installed under a sub-path keeps the prefix`() = runBlocking {
        server.on("GET", "/echo/api/config", FakeServer.json(200, ServerFixtures.config()))

        ApiClient(server.baseUrl + "/echo", emptyList(), token).config()

        assertEquals(1, server.requestsTo("GET", "/echo/api/config").size)
    }

    @Test
    fun `clients without a token send no authorization header at all`() = runBlocking {
        server.on("GET", "/api/config", FakeServer.json(401, """{"message":"Unauthenticated."}"""))

        runCatching { client(withToken = null).config() }

        assertNull(only("GET", "/api/config").headers["Authorization"])
    }
}
