package com.echoledger.mobile.net

import androidx.work.ListenableWorker
import androidx.work.testing.TestListenableWorkerBuilder
import androidx.work.workDataOf
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.DEFAULT_RECORDING_ID
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyBytes
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.sampleBytes
import com.echoledger.mobile.testsupport.storeRecording
import java.util.concurrent.atomic.AtomicInteger
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.int
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import mockwebserver3.MockResponse
import mockwebserver3.RecordedRequest
import mockwebserver3.SocketEffect
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** The resumable upload job, run through WorkManager's test builder against a scripted server. */
class UploadWorkerTest : AppTest() {
    private val id = DEFAULT_RECORDING_ID
    private val serverRecording = ServerFixtures.RECORDING_ID
    private val serverSession = ServerFixtures.SESSION_ID
    private val payload = sampleBytes(2_500)

    @Before
    fun pairWithServer() {
        runBlocking { app.pairLocally(server) }
    }

    private fun run(attempt: Int = 0, recordingId: String? = id): ListenableWorker.Result {
        val data = if (recordingId == null) workDataOf() else workDataOf("recording_id" to recordingId)
        val worker = TestListenableWorkerBuilder<UploadWorker>(app).setInputData(data).setRunAttemptCount(attempt).build()
        val future = worker.startWork()
        pumpUntil("the upload worker to finish") { future.isDone }
        return future.get()
    }

    private fun chunkRoute() = "/api/recordings/$serverRecording/chunks"

    private fun completeRoute() = "/api/recordings/$serverRecording/complete"

    private fun chunks(): List<RecordedRequest> = server.requestsTo("POST", chunkRoute())

    /** Routes for a healthy server: 3 chunks of 1000 bytes for the default 2,500 byte recording. */
    private fun serveHealthyUpload(received: List<Int> = emptyList(), status: String = "pending", total: Int = 3, chunkSize: Long = 1_000) {
        server.on(
            "POST",
            "/api/sessions",
            FakeServer.json(
                201,
                ServerFixtures.sessionCreated(chunkSize = chunkSize, totalChunks = total, received = received, uploadStatus = status)
            )
        )
        server.on("POST", chunkRoute()) { request ->
            FakeServer.json(200, ServerFixtures.chunkAck(listOf(request.headers["Upload-Chunk-Index"]!!.toInt()), total))
        }
        server.on("POST", completeRoute(), FakeServer.json(202, """{"status":"assembling"}"""))
        server.on("POST", "/api/sessions/$serverSession/transcripts", FakeServer.json(201, ServerFixtures.transcript(kind = "live")))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
    }

    private fun recording() = app.graph.recordings.get(id)

    // ---- success ----------------------------------------------------------------------------------------------------

    @Test
    fun `a healthy upload sends every chunk then completes and removes the local copy`() {
        app.storeRecording(bytes = payload)
        serveHealthyUpload()

        val result = run()

        assertEquals(ListenableWorker.Result.success(), result)
        assertEquals(listOf("0", "1", "2"), chunks().map { it.headers["Upload-Chunk-Index"] })
        assertEquals(listOf(1_000, 1_000, 500), chunks().map { it.bodyBytes().size })
        chunks().forEachIndexed { index, request ->
            val slice = payload.copyOfRange(index * 1_000, minOf(payload.size, (index + 1) * 1_000))
            assertTrue("chunk $index bytes", slice.contentEquals(request.bodyBytes()))
            assertEquals("chunk $index checksum", UploadWorker.sha256(slice), request.headers["Upload-Chunk-Sha256"])
            assertEquals("application/octet-stream", request.headers["Content-Type"]!!.substringBefore(';'))
        }
        assertEquals(1, server.requestsTo("POST", completeRoute()).size)
        val order = server.requests.map { it.method + " " + it.url.encodedPath }
        assertTrue(
            "complete must follow the last chunk: $order",
            order.indexOf("POST ${completeRoute()}") > order.lastIndexOf("POST ${chunkRoute()}")
        )
        assertNull("the encrypted copy is removed once the server holds it", recording())
        assertTrue("the library is refreshed so the new session shows up", server.requestsTo("GET", "/api/library").isNotEmpty())
        assertTrue(server.requests.all { it.headers["Authorization"] == "Bearer 7|eld_testtoken" })
    }

    @Test
    fun `the session request describes the recording`() {
        val stored = app.storeRecording(
            bytes = payload,
            sourceApp = "whatsapp_business",
            clientId = "01JCLIENT0000000000000000A",
            durationMs = 61_000,
            channels = 2,
            sampleRate = 48_000
        )
        serveHealthyUpload()

        run()

        val body = Json.parseToJsonElement(server.requestsTo("POST", "/api/sessions").single().bodyText()).jsonObject
        assertEquals(id, body["client_ref"]!!.jsonPrimitive.content)
        assertEquals("whatsapp_business", body["source_app"]!!.jsonPrimitive.content)
        assertEquals("01JCLIENT0000000000000000A", body["client_id"]!!.jsonPrimitive.content)
        assertEquals("2026-10-24T09:00:00Z", body["started_at"]!!.jsonPrimitive.content)
        assertEquals("2026-10-24T09:01:00Z", body["ended_at"]!!.jsonPrimitive.content)
        assertEquals(61_000, body["duration_ms"]!!.jsonPrimitive.int)
        val artifact = body["artifacts"]!!.jsonArray.single().jsonObject
        assertEquals("primary", artifact["role"]!!.jsonPrimitive.content)
        assertEquals("audio/ogg", artifact["mime"]!!.jsonPrimitive.content)
        assertEquals("opus", artifact["codec"]!!.jsonPrimitive.content)
        assertEquals(2, artifact["channels"]!!.jsonPrimitive.int)
        assertEquals(48_000, artifact["sample_rate"]!!.jsonPrimitive.int)
        assertEquals(payload.size.toLong(), artifact["size_bytes"]!!.jsonPrimitive.content.toLong())
        assertEquals(stored.sha256, artifact["sha256"]!!.jsonPrimitive.content)
        assertEquals(UploadWorker.sha256(payload), stored.sha256)
        assertEquals(61_000, artifact["duration_ms"]!!.jsonPrimitive.int)
    }

    @Test
    fun `an unknown sample rate of zero is left out of the request`() {
        app.storeRecording(
            bytes = payload,
            sampleRate = 0,
            durationMs = 0,
            mime = "audio/ogg",
            codec = "unknown",
            channels = 1,
            sourceApp = "voice_note"
        )
        serveHealthyUpload()

        assertEquals(ListenableWorker.Result.success(), run())

        val body = Json.parseToJsonElement(server.requestsTo("POST", "/api/sessions").single().bodyText()).jsonObject
        val artifact = body["artifacts"]!!.jsonArray.single().jsonObject
        assertFalse("sample_rate 0 must be sent as absent, not 0: $artifact", artifact.containsKey("sample_rate"))
        assertFalse("unknown duration must be absent, not 0", artifact.containsKey("duration_ms"))
        assertFalse(body.containsKey("duration_ms"))
        assertEquals("voice_note", body["source_app"]!!.jsonPrimitive.content)
        assertEquals("unknown", artifact["codec"]!!.jsonPrimitive.content)
    }

    @Test
    fun `live captions are posted after the upload completes and a failure to post them is ignored`() {
        val segments = listOf(SegmentDto(1, "Me", 0, 900, "Hello."), SegmentDto(2, "Client", 1_000, 2_000, "Hi there."))
        app.storeRecording(bytes = payload, liveSegments = segments, liveProvider = "elevenlabs")
        serveHealthyUpload()
        server.on(
            "POST",
            "/api/sessions/$serverSession/transcripts",
            FakeServer.json(422, ServerFixtures.validationError("Segment timing exceeds the session duration.", "segments"))
        )

        assertEquals(ListenableWorker.Result.success(), run())

        val post = server.requestsTo("POST", "/api/sessions/$serverSession/transcripts").single()
        val body = Json.parseToJsonElement(post.bodyText()).jsonObject
        assertEquals("live", body["kind"]!!.jsonPrimitive.content)
        assertEquals("elevenlabs", body["provider"]!!.jsonPrimitive.content)
        assertEquals(2, body["segments"]!!.jsonArray.size)
        val order = server.requests.map { it.url.encodedPath }
        assertTrue(order.indexOf("/api/sessions/$serverSession/transcripts") > order.indexOf(completeRoute()))
        assertNull("the recording is still released after a rejected caption transcript", recording())
    }

    @Test
    fun `captions default to the AssemblyAI provider name when none was recorded`() {
        app.storeRecording(bytes = payload, liveSegments = listOf(SegmentDto(1, "Me", 0, 900, "Hello.")), liveProvider = null)
        serveHealthyUpload()

        run()

        val body = Json.parseToJsonElement(
            server.requestsTo("POST", "/api/sessions/$serverSession/transcripts").single().bodyText()
        ).jsonObject
        assertEquals("assemblyai", body["provider"]!!.jsonPrimitive.content)
    }

    @Test
    fun `no transcript is posted for a recording without captions`() {
        app.storeRecording(bytes = payload)
        serveHealthyUpload()

        run()

        assertTrue(server.requestsTo("POST", "/api/sessions/$serverSession/transcripts").isEmpty())
    }

    // ---- resume ------------------------------------------------------------------------------------------------------

    @Test
    fun `only the chunks the server is missing are sent`() {
        val bigger = sampleBytes(4_000)
        app.storeRecording(bytes = bigger)
        serveHealthyUpload(received = listOf(0, 2), status = "uploading", total = 4)

        assertEquals(ListenableWorker.Result.success(), run())

        assertEquals(listOf("1", "3"), chunks().map { it.headers["Upload-Chunk-Index"] })
        assertTrue(bigger.copyOfRange(1_000, 2_000).contentEquals(chunks()[0].bodyBytes()))
        assertTrue(bigger.copyOfRange(3_000, 4_000).contentEquals(chunks()[1].bodyBytes()))
        assertEquals(1, server.requestsTo("POST", completeRoute()).size)
    }

    @Test
    fun `an upload the server already holds sends nothing more and just releases the local copy`() {
        listOf("assembling", "ready").forEach { status ->
            server.requests.clear()
            app.storeRecording(bytes = payload)
            serveHealthyUpload(received = listOf(0, 1, 2), status = status)

            assertEquals(status, ListenableWorker.Result.success(), run())

            assertTrue("$status: no chunks expected", chunks().isEmpty())
            assertTrue("$status: no second complete expected", server.requestsTo("POST", completeRoute()).isEmpty())
            assertNull(status, recording())
        }
    }

    @Test
    fun `a retry after a failed chunk continues where the server stopped`() {
        app.storeRecording(bytes = payload)
        val received = mutableListOf<Int>()
        val sessionCalls = AtomicInteger()
        server.on("POST", "/api/sessions") {
            val first = sessionCalls.incrementAndGet() == 1
            FakeServer.json(
                if (first) 201 else 200,
                ServerFixtures.sessionCreated(chunkSize = 1_000, totalChunks = 3, received = received.toList(), uploadStatus = "uploading")
            )
        }
        server.on("POST", chunkRoute()) { request ->
            val index = request.headers["Upload-Chunk-Index"]!!.toInt()
            if (index == 2 && sessionCalls.get() == 1) {
                FakeServer.json(503, """{"message":"Service Unavailable"}""")
            } else {
                received += index
                FakeServer.json(200, ServerFixtures.chunkAck(received.toList(), 3))
            }
        }
        server.on("POST", completeRoute(), FakeServer.json(202, """{"status":"assembling"}"""))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))

        assertEquals(ListenableWorker.Result.retry(), run(attempt = 0))
        assertEquals("queued", recording()!!.state)
        assertEquals(listOf("0", "1", "2"), chunks().map { it.headers["Upload-Chunk-Index"] })

        server.requests.clear()
        assertEquals(ListenableWorker.Result.success(), run(attempt = 1))
        assertEquals("only the missing chunk is re-sent", listOf("2"), chunks().map { it.headers["Upload-Chunk-Index"] })
        assertNull(recording())
    }

    @Test
    fun `a recording that fits in a single chunk uploads in one request`() {
        val small = sampleBytes(300)
        app.storeRecording(bytes = small)
        serveHealthyUpload(total = 1, chunkSize = 5_242_880)

        assertEquals(ListenableWorker.Result.success(), run())

        assertEquals(1, chunks().size)
        assertTrue(small.contentEquals(chunks().single().bodyBytes()))
    }

    @Test
    fun `a recording of fifty chunks arrives complete and in order`() {
        val large = sampleBytes(50 * 1_000 - 123)
        app.storeRecording(bytes = large)
        serveHealthyUpload(total = 50, chunkSize = 1_000)

        assertEquals(ListenableWorker.Result.success(), run())

        assertEquals((0 until 50).map { it.toString() }, chunks().map { it.headers["Upload-Chunk-Index"] })
        val reassembled = chunks().map { it.bodyBytes() }.reduce { a, b -> a + b }
        assertTrue(large.contentEquals(reassembled))
    }

    // ---- transient failures --------------------------------------------------------------------------------------------

    @Test
    fun `a server error while creating the session is retried and the recording stays queued`() {
        app.storeRecording(bytes = payload)
        server.on("POST", "/api/sessions", FakeServer.json(500, """{"message":"Server Error"}"""))

        assertEquals(ListenableWorker.Result.retry(), run())

        val kept = recording()!!
        assertEquals("queued", kept.state)
        assertEquals("Server Error", kept.lastError)
        assertTrue(chunks().isEmpty())
    }

    @Test
    fun `a dropped connection is retried and keeps the recording`() {
        app.storeRecording(bytes = payload)
        server.on("POST", "/api/sessions", MockResponse.Builder().onResponseStart(SocketEffect.CloseSocket()).build())

        assertEquals(ListenableWorker.Result.retry(), run())

        assertEquals("queued", recording()!!.state)
    }

    @Test
    fun `rate limiting and request timeouts are transient`() {
        listOf(408, 429, 500, 502, 503, 504).forEach { status ->
            app.storeRecording(bytes = payload)
            server.on("POST", "/api/sessions", FakeServer.json(status, """{"message":"try later"}"""))

            assertEquals("status $status", ListenableWorker.Result.retry(), run())

            val kept = recording()
            assertNotNull("status $status must keep the recording", kept)
            assertEquals("status $status", "queued", kept!!.state)
            assertEquals("try later", kept.lastError)
        }
    }

    @Test
    fun `a chunk rejected with a server error is retried and earlier chunks stay uploaded`() {
        app.storeRecording(bytes = payload)
        serveHealthyUpload()
        server.on("POST", chunkRoute()) { request ->
            if (request.headers["Upload-Chunk-Index"] ==
                "1"
            ) {
                FakeServer.json(503, """{"message":"busy"}""")
            } else {
                FakeServer.json(200, ServerFixtures.chunkAck(listOf(0), 3))
            }
        }

        assertEquals(ListenableWorker.Result.retry(), run())

        assertEquals(listOf("0", "1"), chunks().map { it.headers["Upload-Chunk-Index"] })
        assertTrue("complete must not be called with chunks missing", server.requestsTo("POST", completeRoute()).isEmpty())
        assertEquals("queued", recording()!!.state)
    }

    @Test
    fun `after twelve attempts a transient failure becomes final but the recording is kept`() {
        app.storeRecording(bytes = payload)
        server.on("POST", "/api/sessions", FakeServer.json(503, """{"message":"down"}"""))

        assertEquals(ListenableWorker.Result.retry(), run(attempt = 11))
        assertEquals(ListenableWorker.Result.failure(), run(attempt = 12))

        assertEquals("queued", recording()!!.state)
    }

    @Test
    fun `after twelve attempts a network failure becomes final as well`() {
        app.storeRecording(bytes = payload)
        server.on("POST", "/api/sessions", MockResponse.Builder().onResponseStart(SocketEffect.CloseSocket()).build())

        assertEquals(ListenableWorker.Result.retry(), run(attempt = 11))
        assertEquals(ListenableWorker.Result.failure(), run(attempt = 12))
    }

    // ---- permanent failures ----------------------------------------------------------------------------------------------

    private fun assertPermanent(status: Int, message: String) {
        app.storeRecording(bytes = payload)
        server.on("POST", "/api/sessions", FakeServer.json(status, """{"message":"$message"}"""))

        assertEquals("status $status", ListenableWorker.Result.failure(), run())

        val kept = recording()
        assertNotNull("a refused recording is kept so the user can retry or discard it", kept)
        assertEquals("failed", kept!!.state)
        assertEquals(message, kept.lastError)
    }

    @Test
    fun `a validation error is final and shows the server reason`() =
        assertPermanent(422, "The artifacts.0.sha256 field format is invalid.")

    @Test
    fun `a bad request is final`() = assertPermanent(400, "Bad request.")

    @Test
    fun `a forbidden request is final`() = assertPermanent(403, "Invalid ability provided.")

    @Test
    fun `an unknown resource is final`() = assertPermanent(404, "No query results for model.")

    @Test
    fun `an oversized body is final`() = assertPermanent(413, "Payload too large.")

    @Test
    fun `a chunk refused with 422 is final`() {
        app.storeRecording(bytes = payload)
        serveHealthyUpload()
        server.on("POST", chunkRoute(), FakeServer.json(422, """{"message":"Chunk checksum mismatch."}"""))

        assertEquals(ListenableWorker.Result.failure(), run())

        assertEquals("failed", recording()!!.state)
        assertEquals("Chunk checksum mismatch.", recording()!!.lastError)
    }

    @Test
    fun `a revoked device stops the upload but keeps the recording queued with an explanation`() {
        app.storeRecording(bytes = payload)
        server.on("POST", "/api/sessions", FakeServer.json(401, """{"message":"Unauthenticated."}"""))

        assertEquals(ListenableWorker.Result.failure(), run())

        val kept = recording()!!
        assertEquals("queued", kept.state)
        assertEquals("This phone is not paired with an EchoLedger server.", kept.lastError)
    }

    // ---- preconditions -------------------------------------------------------------------------------------------------------

    @Test
    fun `an unpaired phone retries later without touching the recording`() {
        runBlocking { app.graph.settings.clearPairing() }
        app.graph.invalidateApi()
        app.storeRecording(bytes = payload)

        assertEquals(ListenableWorker.Result.retry(), run())

        assertEquals("queued", recording()!!.state)
        assertTrue(server.requests.isEmpty())
    }

    @Test
    fun `a recording that no longer exists finishes successfully without any request`() {
        assertEquals(ListenableWorker.Result.success(), run(recordingId = "does-not-exist"))
        assertTrue(server.requests.isEmpty())
    }

    @Test
    fun `work without a recording id fails`() {
        assertEquals(ListenableWorker.Result.failure(), run(recordingId = null))
        assertTrue(server.requests.isEmpty())
    }

    // ---- hostile server answers ------------------------------------------------------------------------------------------------

    @Test
    fun `an HTML page answered to the session request does not leave the recording stuck as uploading`() {
        app.storeRecording(bytes = payload)
        server.on(
            "POST",
            "/api/sessions",
            MockResponse.Builder().code(200).addHeader("Content-Type", "text/html").body("<html>Sign in to continue</html>").build()
        )

        runCatching { run() }

        assertNotEquals("uploading", recording()!!.state)
    }

    @Test
    fun `a session answer without a primary upload slot does not leave the recording stuck as uploading`() {
        app.storeRecording(bytes = payload)
        val withoutSlot = """{"session":${ServerFixtures.libraryItem(serverSession)},"recordings":[],"required_artifacts":["primary"]}"""
        server.on("POST", "/api/sessions", FakeServer.json(201, withoutSlot))

        runCatching { run() }

        assertNotEquals("uploading", recording()!!.state)
    }

    @Test
    fun `a missing encrypted file is reported as a final failure instead of retrying for hours`() {
        app.storeRecording(bytes = payload)
        app.graph.recordings.encryptedFile(id).delete()
        serveHealthyUpload()

        val result = run()

        assertEquals("a file that is gone can never be uploaded", ListenableWorker.Result.failure(), result)
        assertEquals("failed", recording()!!.state)
    }
}
